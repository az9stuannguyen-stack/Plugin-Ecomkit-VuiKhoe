<?php
/**
 * Database schema and diagnostics.
 */

defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_DB {
	public const SCHEMA_OPTION = 'ecomkit_vuikhoe_db_version';
	public const PLUGIN_VERSION_OPTION = 'ecomkit_vuikhoe_plugin_version';
	public const INSTALL_ERROR_OPTION = 'ecomkit_vuikhoe_install_error';

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
) $collate;",
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
) $collate;",
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
) $collate;",
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
) $collate;",
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
) $collate;",
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
) $collate;",
		);
	}
}

