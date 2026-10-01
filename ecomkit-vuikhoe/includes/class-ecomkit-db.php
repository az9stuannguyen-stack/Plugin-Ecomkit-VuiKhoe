<?php
/**
 * Database schema and diagnostics.
 */

defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_DB {
	public const SCHEMA_OPTION = 'ecomkit_vuikhoe_db_version';
	public const PLUGIN_VERSION_OPTION = 'ecomkit_vuikhoe_plugin_version';
	public const INSTALL_ERROR_OPTION = 'ecomkit_vuikhoe_install_error';
	public const INNODB_MIGRATION_OPTION = 'ecomkit_vuikhoe_innodb_migration_state';
	private const ORDER_CONTRACT = array(
		'batch_id'              => array( 'nullable' => false, 'required' => true ),
		'connection_id'         => array( 'nullable' => true, 'required' => false ),
		'platform'              => array( 'nullable' => false, 'required' => true ),
		'marketplace_order_id'  => array( 'nullable' => false, 'required' => true ),
		'raw_order_code'        => array( 'nullable' => false, 'required' => true ),
		'normalized_order_code' => array( 'nullable' => false, 'required' => true ),
		'matching_status'       => array( 'nullable' => true, 'required' => false ),
		'source_refs'           => array( 'nullable' => false, 'required' => true ),
		'created_at'            => array( 'nullable' => false, 'required' => true ),
		'updated_at'            => array( 'nullable' => false, 'required' => true ),
	);

	/**
	 * Returns table names for the current WordPress site prefix.
	 *
	 * @return array<string,string>
	 */
	public static function table_names(): array {
		global $wpdb;

		return array(
			'batches'                 => $wpdb->prefix . 'ecomkit_batches',
			'orders'                  => $wpdb->prefix . 'ecomkit_orders',
			'order_items'             => $wpdb->prefix . 'ecomkit_order_items',
			'errors'                  => $wpdb->prefix . 'ecomkit_errors',
			'marketplace_connections' => $wpdb->prefix . 'ecomkit_marketplace_connections',
			'sync_runs'               => $wpdb->prefix . 'ecomkit_sync_runs',
		);
	}

	/**
	 * Creates or incrementally updates the foundation schema with dbDelta().
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$tables  = self::table_names();
		$before  = self::diagnose();
		if ( $before['tables_ok'] ) {
			self::migrate_tables_to_innodb();
			self::migrate_orders_contract();
		}
		$collate = $wpdb->get_charset_collate();
		$sql     = self::schema_sql( $tables, $collate );

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		$diagnostic = self::diagnose();
		if ( ! $diagnostic['tables_ok'] ) {
			update_option( self::INSTALL_ERROR_OPTION, 'ECOMKIT_DB_SCHEMA_INITIALIZATION_FAILED', false );
			throw new RuntimeException( 'ECOMKIT_DB_SCHEMA_INITIALIZATION_FAILED' );
		}

		self::migrate_tables_to_innodb();
		if ( ! self::database_runtime_diagnostic()['ready'] ) {
			update_option( self::INSTALL_ERROR_OPTION, 'ECOMKIT_DB_INNODB_MIGRATION_FAILED', false );
			throw new RuntimeException( 'ECOMKIT_DB_INNODB_MIGRATION_FAILED' );
		}
		if ( ! self::orders_schema_diagnostic()['ready'] ) {
			update_option( self::INSTALL_ERROR_OPTION, 'ECOMKIT_ORDER_SCHEMA_INCOMPATIBLE', false );
			throw new RuntimeException( 'ECOMKIT_ORDER_SCHEMA_INCOMPATIBLE' );
		}

		update_option( self::SCHEMA_OPTION, ECOMKIT_VUIKHOE_DB_VERSION, false );
		update_option( self::PLUGIN_VERSION_OPTION, ECOMKIT_VUIKHOE_VERSION, false );
		delete_option( self::INSTALL_ERROR_OPTION );
	}

	/**
	 * Upgrades schema during normal plugin updates without reactivation.
	 */
	public static function maybe_upgrade(): void {
		$stored = (int) get_option( self::SCHEMA_OPTION, 0 );

		if ( $stored >= ECOMKIT_VUIKHOE_DB_VERSION ) {
			return;
		}

		try {
			self::install();
		} catch ( Throwable $exception ) {
			update_option( self::INSTALL_ERROR_OPTION, 'ECOMKIT_DB_SCHEMA_INITIALIZATION_FAILED', false );
		}
	}

	/**
	 * Provides non-destructive, administrator-safe schema diagnostics.
	 *
	 * @return array{tables_ok:bool,tables:array<string,bool>,stored_version:int,expected_version:int}
	 */
	public static function diagnose(): array {
		global $wpdb;

		$status = array();
		foreach ( self::table_names() as $key => $table ) {
			$found          = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			$status[ $key ] = $table === $found;
		}

		return array(
			'tables_ok'        => ! in_array( false, $status, true ),
			'tables'           => $status,
			'stored_version'   => (int) get_option( self::SCHEMA_OPTION, 0 ),
			'expected_version' => ECOMKIT_VUIKHOE_DB_VERSION,
		);
	}

	/**
	 * Confirms that tables participating in an import transaction use a
	 * transactional engine. Unknown or missing engines fail closed.
	 */
	public static function supports_import_transactions(): bool {
		return self::database_runtime_diagnostic()['ready'];
	}

	/** @return array{db_type:string,innodb_supported:bool,tables:array<string,array<string,mixed>>,ready:bool} */
	public static function database_runtime_diagnostic(): array {
		global $wpdb;

		$server = method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '';
		$db_type = false !== stripos( $server, 'mariadb' ) ? 'MariaDB' : ( '' !== $server ? 'MySQL' : 'Unknown' );
		$engines = $wpdb->get_results( 'SHOW ENGINES', ARRAY_A );
		$innodb_supported = false;
		foreach ( is_array( $engines ) ? $engines : array() as $engine ) {
			if ( 'INNODB' === strtoupper( (string) ( $engine['Engine'] ?? '' ) ) && ! in_array( strtoupper( (string) ( $engine['Support'] ?? '' ) ), array( 'NO', 'DISABLED', '' ), true ) ) {
				$innodb_supported = true;
				break;
			}
		}

		$table_status = array();
		foreach ( self::table_names() as $key => $table ) {
			$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );
			$engine = is_array( $status ) ? strtoupper( (string) ( $status['Engine'] ?? '' ) ) : '';
			$table_status[ $key ] = array( 'engine' => $engine ?: 'UNKNOWN', 'transactional' => 'INNODB' === $engine );
		}

		return array(
			'db_type'          => $db_type,
			'innodb_supported' => $innodb_supported,
			'tables'           => $table_status,
			'ready'            => $innodb_supported && ! in_array( false, array_column( $table_status, 'transactional' ), true ),
		);
	}

	/**
	 * Reports the safe, metadata-only WP.2 Order persistence contract.
	 *
	 * @return array{ready:bool,fields:array<string,array<string,mixed>>}
	 */
	public static function orders_schema_diagnostic(): array {
		$columns = self::orders_columns();
		$fields  = array();
		foreach ( self::ORDER_CONTRACT as $name => $expected ) {
			$column = $columns[ $name ] ?? null;
			$actual_nullable = is_array( $column ) && 'YES' === strtoupper( (string) ( $column['Null'] ?? '' ) );
			$compatible = is_array( $column ) && ( $expected['required'] || $actual_nullable );
			$fields[ $name ] = array(
				'required'   => $expected['required'],
				'nullable'   => $actual_nullable,
				'compatible' => $compatible,
				'type'       => is_array( $column ) ? (string) ( $column['Type'] ?? '' ) : '',
				'default'    => is_array( $column ) ? ( $column['Default'] ?? null ) : null,
				'auto_increment' => is_array( $column ) && false !== stripos( (string) ( $column['Extra'] ?? '' ), 'auto_increment' ),
			);
		}

		return array( 'ready' => ! in_array( false, array_column( $fields, 'compatible' ), true ), 'fields' => $fields );
	}

	/**
	 * Explicitly repairs legacy WP.1 nullability without replacing tables or rows.
	 * Types are taken from server metadata rather than hard-coded.
	 *
	 * @return array{altered:string[],counts:array<string,int>}
	 */
	public static function migrate_orders_contract(): array {
		global $wpdb;

		$tables = self::table_names();
		$orders = self::safe_table_identifier( $tables['orders'], $tables );
		$count_keys = array( 'orders', 'order_items', 'batches', 'errors' );
		$before = array();
		foreach ( $count_keys as $key ) {
			$identifier = self::safe_table_identifier( $tables[ $key ], $tables );
			$before[ $key ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$identifier`" );
		}

		$columns = self::orders_columns();
		$altered = array();
		foreach ( array( 'connection_id', 'matching_status' ) as $name ) {
			$column = $columns[ $name ] ?? null;
			if ( ! is_array( $column ) ) {
				throw new RuntimeException( 'ECOMKIT_ORDER_SCHEMA_COLUMN_MISSING_' . strtoupper( $name ) );
			}
			if ( 'YES' === strtoupper( (string) ( $column['Null'] ?? '' ) ) ) {
				continue;
			}
			$type = (string) ( $column['Type'] ?? '' );
			if ( 1 !== preg_match( '/^[a-zA-Z0-9(), ]+(?: unsigned)?$/', $type ) ) {
				throw new RuntimeException( 'ECOMKIT_ORDER_SCHEMA_TYPE_UNSAFE_' . strtoupper( $name ) );
			}
			if ( false === $wpdb->query( "ALTER TABLE `$orders` MODIFY `$name` $type NULL DEFAULT NULL" ) ) {
				throw new RuntimeException( 'ECOMKIT_ORDER_SCHEMA_ALTER_FAILED_' . strtoupper( $name ) );
			}
			$altered[] = $name;
			$columns = self::orders_columns();
			if ( 'YES' !== strtoupper( (string) ( $columns[ $name ]['Null'] ?? '' ) ) ) {
				throw new RuntimeException( 'ECOMKIT_ORDER_SCHEMA_VERIFY_FAILED_' . strtoupper( $name ) );
			}
		}

		$after = array();
		foreach ( $count_keys as $key ) {
			$identifier = self::safe_table_identifier( $tables[ $key ], $tables );
			$after[ $key ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$identifier`" );
		}
		if ( $before !== $after ) {
			throw new RuntimeException( 'ECOMKIT_ORDER_SCHEMA_ROW_COUNT_MISMATCH' );
		}
		if ( ! self::orders_schema_diagnostic()['ready'] ) {
			throw new RuntimeException( 'ECOMKIT_ORDER_SCHEMA_INCOMPATIBLE' );
		}

		return array( 'altered' => $altered, 'counts' => $after );
	}

	/** @return array<string,array<string,mixed>> */
	private static function orders_columns(): array {
		global $wpdb;
		$tables = self::table_names();
		$table  = self::safe_table_identifier( $tables['orders'], $tables );
		$rows   = $wpdb->get_results( "SHOW FULL COLUMNS FROM `$table`", ARRAY_A );
		$columns = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( isset( $row['Field'] ) ) {
				$columns[ (string) $row['Field'] ] = $row;
			}
		}
		return $columns;
	}

	/**
	 * Converts only plugin-controlled tables, preserving data and definitions.
	 * The operation is resumable because already-InnoDB tables are never altered.
	 *
	 * @return array{converted:string[],counts:array<string,int>}
	 */
	public static function migrate_tables_to_innodb(): array {
		global $wpdb;

		$runtime = self::database_runtime_diagnostic();
		if ( ! $runtime['innodb_supported'] ) {
			throw new RuntimeException( 'ECOMKIT_INNODB_UNSUPPORTED' );
		}

		$tables = self::table_names();
		$before = get_option( self::INNODB_MIGRATION_OPTION, array() );
		if ( ! is_array( $before ) || array_keys( $before ) !== array_keys( $tables ) ) {
			$before = array();
			foreach ( $tables as $key => $table ) {
				$before[ $key ] = self::table_fingerprint( $table );
			}
			update_option( self::INNODB_MIGRATION_OPTION, $before, false );
		}

		$converted = array();
		foreach ( $tables as $key => $table ) {
			$current = self::table_fingerprint( $table );
			if ( 'INNODB' === $current['engine'] ) {
				continue;
			}
			$identifier = self::safe_table_identifier( $table, $tables );
			if ( false === $wpdb->query( "ALTER TABLE `$identifier` ENGINE=InnoDB" ) ) {
				throw new RuntimeException( 'ECOMKIT_INNODB_ALTER_FAILED_' . strtoupper( $key ) );
			}
			$converted[] = $key;
		}

		$counts = array();
		foreach ( $tables as $key => $table ) {
			$after = self::table_fingerprint( $table );
			if ( 'INNODB' !== $after['engine'] ) {
				throw new RuntimeException( 'ECOMKIT_INNODB_VERIFY_FAILED_' . strtoupper( $key ) );
			}
			if ( $before[ $key ]['count'] !== $after['count'] ) {
				throw new RuntimeException( 'ECOMKIT_INNODB_ROW_COUNT_MISMATCH_' . strtoupper( $key ) );
			}
			if ( $before[ $key ]['columns'] !== $after['columns'] || $before[ $key ]['indexes'] !== $after['indexes'] ) {
				throw new RuntimeException( 'ECOMKIT_INNODB_DEFINITION_MISMATCH_' . strtoupper( $key ) );
			}
			if ( $before[ $key ]['collation'] !== $after['collation'] ) {
				throw new RuntimeException( 'ECOMKIT_INNODB_COLLATION_MISMATCH_' . strtoupper( $key ) );
			}
			$counts[ $key ] = $after['count'];
		}

		delete_option( self::INNODB_MIGRATION_OPTION );
		return array( 'converted' => $converted, 'counts' => $counts );
	}

	/** @return array{engine:string,count:int,columns:string,indexes:string,collation:string} */
	private static function table_fingerprint( string $table ): array {
		global $wpdb;

		$tables = self::table_names();
		$identifier = self::safe_table_identifier( $table, $tables );
		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );
		if ( ! is_array( $status ) ) {
			throw new RuntimeException( 'ECOMKIT_TABLE_STATUS_MISSING' );
		}
		$columns = $wpdb->get_results( "SHOW COLUMNS FROM `$identifier`", ARRAY_A );
		$indexes = $wpdb->get_results( "SHOW INDEX FROM `$identifier`", ARRAY_A );
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM `$identifier`" );
		$column_definition = array_map(
			static fn( array $column ): array => array_intersect_key( $column, array_flip( array( 'Field', 'Type', 'Null', 'Key', 'Default', 'Extra' ) ) ),
			is_array( $columns ) ? $columns : array()
		);
		$index_definition = array_map(
			static fn( array $index ): array => array_intersect_key( $index, array_flip( array( 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Collation', 'Sub_part', 'Packed', 'Null', 'Index_type', 'Comment', 'Index_comment', 'Visible', 'Expression' ) ) ),
			is_array( $indexes ) ? $indexes : array()
		);
		return array(
			'engine'    => strtoupper( (string) ( $status['Engine'] ?? '' ) ),
			'count'     => (int) $count,
			'columns'   => hash( 'sha256', wp_json_encode( $column_definition ) ),
			'indexes'   => hash( 'sha256', wp_json_encode( $index_definition ) ),
			'collation' => (string) ( $status['Collation'] ?? '' ),
		);
	}

	/** @param array<string,string> $allowed */
	private static function safe_table_identifier( string $table, array $allowed ): string {
		if ( ! in_array( $table, $allowed, true ) || 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			throw new RuntimeException( 'ECOMKIT_INVALID_TABLE_IDENTIFIER' );
		}
		return $table;
	}

	/**
	 * Returns dbDelta-compatible CREATE TABLE statements.
	 *
	 * @param array<string,string> $tables Table names.
	 * @param string               $collate Charset/collation clause.
	 * @return string[]
	 */
	public static function schema_sql( array $tables, string $collate ): array {
		return array(
			"CREATE TABLE {$tables['batches']} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	source_type varchar(32) NOT NULL DEFAULT 'MANUAL',
	status varchar(32) NOT NULL DEFAULT 'PENDING',
	source_filename varchar(255) DEFAULT NULL,
	source_metadata longtext DEFAULT NULL,
	window_start datetime DEFAULT NULL,
	window_end datetime DEFAULT NULL,
	file_count int(10) unsigned NOT NULL DEFAULT 0,
	order_count int(10) unsigned NOT NULL DEFAULT 0,
	success_count int(10) unsigned NOT NULL DEFAULT 0,
	warning_count int(10) unsigned NOT NULL DEFAULT 0,
	error_count int(10) unsigned NOT NULL DEFAULT 0,
	created_by bigint(20) unsigned DEFAULT NULL,
	started_at datetime DEFAULT NULL,
	finished_at datetime DEFAULT NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY status (status),
	KEY created_at (created_at)
) ENGINE=InnoDB $collate;",
			"CREATE TABLE {$tables['marketplace_connections']} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	platform varchar(32) NOT NULL,
	external_shop_id varchar(191) NOT NULL,
	shop_name varchar(255) DEFAULT NULL,
	status varchar(32) NOT NULL DEFAULT 'PENDING_AUTH',
	credential_source varchar(32) DEFAULT NULL,
	credential_envelope longtext DEFAULT NULL,
	metadata longtext DEFAULT NULL,
	sync_cursor longtext DEFAULT NULL,
	last_attempted_sync_at datetime DEFAULT NULL,
	last_successful_sync_at datetime DEFAULT NULL,
	created_by bigint(20) unsigned DEFAULT NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY platform_shop (platform,external_shop_id),
	KEY status (status),
	KEY created_at (created_at)
) ENGINE=InnoDB $collate;",
			"CREATE TABLE {$tables['orders']} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	batch_id bigint(20) unsigned NOT NULL,
	connection_id bigint(20) unsigned DEFAULT NULL,
	platform varchar(32) NOT NULL DEFAULT 'UNKNOWN',
	marketplace_order_id varchar(191) DEFAULT NULL,
	raw_order_code varchar(191) DEFAULT NULL,
	normalized_order_code varchar(191) DEFAULT NULL,
	matching_status varchar(32) DEFAULT NULL,
	order_date datetime DEFAULT NULL,
	eshop_order_code varchar(191) DEFAULT NULL,
	sales_channel varchar(64) DEFAULT NULL,
	order_status varchar(100) DEFAULT NULL,
	customer_name varchar(255) DEFAULT NULL,
	phone varchar(100) DEFAULT NULL,
	address text DEFAULT NULL,
	province_city varchar(255) DEFAULT NULL,
	vat_issued_date datetime DEFAULT NULL,
	note text DEFAULT NULL,
	amount_collected decimal(20,4) DEFAULT NULL,
	receivable_status varchar(100) DEFAULT NULL,
	difference_amount decimal(20,4) DEFAULT NULL,
	product_price_vat_8 decimal(20,4) DEFAULT NULL,
	total_cost_percent decimal(9,4) DEFAULT NULL,
	total_amount_to_collect decimal(20,4) DEFAULT NULL,
	affiliate_fee_vuikhoe decimal(20,4) DEFAULT NULL,
	discount_vuikhoe decimal(20,4) DEFAULT NULL,
	discount_percent_vuikhoe decimal(9,4) DEFAULT NULL,
	fixed_platform_fee decimal(20,4) DEFAULT NULL,
	service_platform_fee decimal(20,4) DEFAULT NULL,
	transaction_platform_fee decimal(20,4) DEFAULT NULL,
	platform_cost_percent decimal(9,4) DEFAULT NULL,
	canonical_data longtext DEFAULT NULL,
	raw_source_metadata longtext DEFAULT NULL,
	source_refs longtext DEFAULT NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY connection_order (connection_id,marketplace_order_id),
	KEY batch_id (batch_id),
	KEY normalized_order_code (normalized_order_code),
	KEY matching_status (matching_status),
	KEY created_at (created_at)
) ENGINE=InnoDB $collate;",
			"CREATE TABLE {$tables['order_items']} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	order_id bigint(20) unsigned NOT NULL,
	external_item_id varchar(191) DEFAULT NULL,
	product_name varchar(255) DEFAULT NULL,
	sku varchar(191) DEFAULT NULL,
	quantity int(11) DEFAULT NULL,
	price decimal(20,4) DEFAULT NULL,
	variant varchar(255) DEFAULT NULL,
	raw_product_metadata longtext DEFAULT NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY order_id (order_id)
) ENGINE=InnoDB $collate;",
			"CREATE TABLE {$tables['errors']} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	batch_id bigint(20) unsigned DEFAULT NULL,
	order_id bigint(20) unsigned DEFAULT NULL,
	source varchar(32) DEFAULT NULL,
	stage varchar(64) DEFAULT NULL,
	marketplace varchar(32) DEFAULT NULL,
	filename varchar(255) DEFAULT NULL,
	sheet_name varchar(255) DEFAULT NULL,
	page_number int(11) DEFAULT NULL,
	row_number int(11) DEFAULT NULL,
	column_name varchar(255) DEFAULT NULL,
	field_name varchar(255) DEFAULT NULL,
	order_code varchar(191) DEFAULT NULL,
	error_code varchar(100) NOT NULL,
	severity varchar(20) NOT NULL DEFAULT 'ERROR',
	friendly_message text NOT NULL,
	safe_raw_value text DEFAULT NULL,
	suggestion text DEFAULT NULL,
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY batch_id (batch_id),
	KEY error_code (error_code),
	KEY severity (severity),
	KEY created_at (created_at)
) ENGINE=InnoDB $collate;",
			"CREATE TABLE {$tables['sync_runs']} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	connection_id bigint(20) unsigned NOT NULL,
	batch_id bigint(20) unsigned DEFAULT NULL,
	sync_type varchar(32) NOT NULL,
	trigger_type varchar(32) NOT NULL DEFAULT 'MANUAL',
	status varchar(32) NOT NULL DEFAULT 'PENDING',
	window_start datetime DEFAULT NULL,
	window_end datetime DEFAULT NULL,
	start_cursor longtext DEFAULT NULL,
	result_cursor longtext DEFAULT NULL,
	orders_fetched int(10) unsigned NOT NULL DEFAULT 0,
	orders_created int(10) unsigned NOT NULL DEFAULT 0,
	orders_updated int(10) unsigned NOT NULL DEFAULT 0,
	error_count int(10) unsigned NOT NULL DEFAULT 0,
	started_at datetime DEFAULT NULL,
	completed_at datetime DEFAULT NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY connection_id (connection_id),
	KEY batch_id (batch_id),
	KEY status (status),
	KEY created_at (created_at)
) ENGINE=InnoDB $collate;",
		);
	}
}

