<?php
/** WP.2D resumable InnoDB migration checks. */
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ECOMKIT_VUIKHOE_DB_VERSION', 7 );
define( 'ECOMKIT_VUIKHOE_VERSION', '0.6.3' );
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
$GLOBALS['migration_options'] = array();
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['migration_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool $autoload = false ): bool { $GLOBALS['migration_options'][ $key ] = $value; return true; }
function delete_option( string $key ): bool { unset( $GLOBALS['migration_options'][ $key ] ); return true; }
require __DIR__ . '/../ecomkit-vuikhoe/vendor/autoload.php';

function migration_check( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }

final class MigrationWpdb {
	public string $prefix = 'tenant_9_';
	public bool $innodb_supported = true;
	public ?string $fail_table = null;
	public ?string $count_mismatch_table = null;
	public array $queries = array();
	public array $engines = array();
	public array $counts = array();
	public array $order_columns = array();
	private array $altered = array();

	public function __construct( string $default_engine = 'InnoDB' ) {
		foreach ( array( 'batches', 'orders', 'order_items', 'errors', 'marketplace_connections', 'sync_runs' ) as $key ) {
			$table = $this->prefix . 'ecomkit_' . $key;
			$this->engines[ $table ] = $default_engine;
			$this->counts[ $table ] = strlen( $key );
		}
		foreach ( array( 'batch_id', 'platform', 'marketplace_order_id', 'raw_order_code', 'normalized_order_code', 'source_refs', 'created_at', 'updated_at' ) as $name ) {
			$this->order_columns[ $name ] = array( 'Field' => $name, 'Type' => 'varchar(191)', 'Null' => 'NO', 'Default' => null, 'Extra' => '' );
		}
		$this->order_columns['connection_id'] = array( 'Field' => 'connection_id', 'Type' => 'bigint(20) unsigned', 'Null' => 'YES', 'Default' => null, 'Extra' => '' );
		$this->order_columns['matching_status'] = array( 'Field' => 'matching_status', 'Type' => 'varchar(32)', 'Null' => 'YES', 'Default' => null, 'Extra' => '' );
		$this->order_columns['canonical_data'] = array( 'Field' => 'canonical_data', 'Type' => 'longtext', 'Null' => 'YES', 'Default' => null, 'Extra' => '' );
	}
	public function db_server_info(): string { return '10.11-MariaDB'; }
	public function esc_like( string $value ): string { return $value; }
	public function prepare( string $query, mixed ...$args ): string { return vsprintf( str_replace( '%s', "'%s'", $query ), $args ); }
	public function get_results( string $query, string $output ): array {
		if ( 'SHOW ENGINES' === $query ) { return array( array( 'Engine' => 'InnoDB', 'Support' => $this->innodb_supported ? 'DEFAULT' : 'NO' ) ); }
		if ( preg_match( '/SHOW FULL COLUMNS FROM `([^`]+ecomkit_orders)`/', $query ) ) { return array_values( $this->order_columns ); }
		if ( preg_match( '/SHOW COLUMNS FROM `([^`]+)`/', $query, $match ) ) { return array( array( 'Field' => 'id', 'Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Key' => 'PRI' ) ); }
		if ( preg_match( '/SHOW INDEX FROM `([^`]+)`/', $query, $match ) ) { return array( array( 'Key_name' => 'PRIMARY', 'Column_name' => 'id', 'Non_unique' => '0', 'Seq_in_index' => '1' ) ); }
		return array();
	}
	public function get_row( string $query, string $output ): ?array {
		if ( preg_match( "/SHOW TABLE STATUS LIKE '([^']+)'/", $query, $match ) && isset( $this->engines[ $match[1] ] ) ) {
			return array( 'Engine' => $this->engines[ $match[1] ], 'Collation' => 'utf8mb4_unicode_ci' );
		}
		return null;
	}
	public function get_var( string $query ): int|string|null {
		if ( preg_match( '/SELECT COUNT\(\*\) FROM `([^`]+)`/', $query, $match ) ) {
			$count = $this->counts[ $match[1] ];
			return $match[1] === $this->count_mismatch_table && isset( $this->altered[ $match[1] ] ) ? $count + 1 : $count;
		}
		return null;
	}
	public function query( string $query ): int|false {
		$this->queries[] = $query;
		if ( preg_match( '/^ALTER TABLE `([^`]+)` ENGINE=InnoDB$/', $query, $match ) ) {
			if ( $match[1] === $this->fail_table ) { return false; }
			$this->engines[ $match[1] ] = 'InnoDB';
			$this->altered[ $match[1] ] = true;
		}
		if ( preg_match( '/^ALTER TABLE `([^`]+ecomkit_orders)` MODIFY `(connection_id|matching_status)` .* NULL DEFAULT NULL$/', $query, $match ) ) {
			$this->order_columns[ $match[2] ]['Null'] = 'YES';
			$this->order_columns[ $match[2] ]['Default'] = null;
		}
		if ( preg_match( '/^ALTER TABLE `([^`]+ecomkit_orders)` ADD COLUMN `(provider_raw_data|provider_normalized_data|provider_updated_at|matched_at)` (.+)$/', $query, $match ) ) {
			$type = str_starts_with( $match[3], 'longtext' ) ? 'longtext' : 'datetime';
			$this->order_columns[ $match[2] ] = array( 'Field' => $match[2], 'Type' => $type, 'Null' => 'YES', 'Default' => null, 'Extra' => '' );
		}
		if ( preg_match( '/^ALTER TABLE `([^`]+ecomkit_orders)` ADD COLUMN `(canonical_result_version|canonical_materialized_at|canonical_source_fingerprint)` (.+)$/', $query, $match ) ) {
			$type = str_starts_with( $match[3], 'datetime' ) ? 'datetime' : 'varchar';
			$this->order_columns[ $match[2] ] = array( 'Field' => $match[2], 'Type' => $type, 'Null' => 'YES', 'Default' => null, 'Extra' => '' );
		}
		if ( preg_match( '/^ALTER TABLE `([^`]+ecomkit_orders)` ADD COLUMN `(payment_raw_data|payment_normalized_data|payment_fetched_at|payment_request_id)` (.+)$/', $query, $match ) ) {
			$type = str_starts_with( $match[3], 'longtext' ) ? 'longtext' : ( str_starts_with( $match[3], 'datetime' ) ? 'datetime' : 'varchar' );
			$this->order_columns[ $match[2] ] = array( 'Field' => $match[2], 'Type' => $type, 'Null' => 'YES', 'Default' => null, 'Extra' => '' );
		}
		return 1;
	}
}

$GLOBALS['wpdb'] = new MigrationWpdb( 'InnoDB' );
$GLOBALS['migration_options'] = array();
$already = Ecomkit_Vuikhoe_DB::migrate_tables_to_innodb();
migration_check( array() === $already['converted'] && array() === $GLOBALS['wpdb']->queries, 'Already-InnoDB tables must not be altered.' );

$one = new MigrationWpdb( 'InnoDB' );
$GLOBALS['migration_options'] = array();
$one_table = $one->prefix . 'ecomkit_orders'; $one->engines[ $one_table ] = 'MyISAM'; $GLOBALS['wpdb'] = $one;
$one_result = Ecomkit_Vuikhoe_DB::migrate_tables_to_innodb();
migration_check( array( 'orders' ) === $one_result['converted'] && 1 === count( $one->queries ), 'Only one MyISAM table should be converted.' );

$all = new MigrationWpdb( 'MyISAM' ); $before_counts = $all->counts; $GLOBALS['wpdb'] = $all;
$GLOBALS['migration_options'] = array();
$all_result = Ecomkit_Vuikhoe_DB::migrate_tables_to_innodb();
migration_check( 6 === count( $all_result['converted'] ) && $before_counts === $all->counts, 'All tables must convert without count changes.' );
migration_check( ! array_filter( $all->queries, static fn( string $query ): bool => str_contains( $query, 'wp_posts' ) || str_contains( $query, 'wp_options' ) ), 'Core table alteration detected.' );

$mixed = new MigrationWpdb( 'InnoDB' );
$GLOBALS['migration_options'] = array();
$mixed->engines[ $mixed->prefix . 'ecomkit_errors' ] = 'MyISAM';
$mixed->engines[ $mixed->prefix . 'ecomkit_sync_runs' ] = 'MyISAM';
$GLOBALS['wpdb'] = $mixed;
migration_check( array( 'errors', 'sync_runs' ) === Ecomkit_Vuikhoe_DB::migrate_tables_to_innodb()['converted'], 'Mixed engines were not selectively converted.' );

$unsupported = new MigrationWpdb( 'MyISAM' ); $unsupported->innodb_supported = false; $GLOBALS['wpdb'] = $unsupported;
$GLOBALS['migration_options'] = array();
try { Ecomkit_Vuikhoe_DB::migrate_tables_to_innodb(); throw new RuntimeException( 'Unsupported InnoDB should fail.' ); } catch ( RuntimeException $exception ) { migration_check( 'ECOMKIT_INNODB_UNSUPPORTED' === $exception->getMessage() && array() === $unsupported->queries, 'Unsupported InnoDB was not blocked safely.' ); }

$partial = new MigrationWpdb( 'MyISAM' ); $partial->fail_table = $partial->prefix . 'ecomkit_order_items'; $GLOBALS['wpdb'] = $partial;
$GLOBALS['migration_options'] = array();
try { Ecomkit_Vuikhoe_DB::migrate_tables_to_innodb(); throw new RuntimeException( 'Halfway ALTER should fail.' ); } catch ( RuntimeException $exception ) { migration_check( str_starts_with( $exception->getMessage(), 'ECOMKIT_INNODB_ALTER_FAILED_' ), 'Halfway failure classification is wrong.' ); }
$partial->fail_table = null;
$resumed = Ecomkit_Vuikhoe_DB::migrate_tables_to_innodb();
migration_check( ! in_array( 'batches', $resumed['converted'], true ) && ! in_array( 'orders', $resumed['converted'], true ) && 4 === count( $resumed['converted'] ), 'Migration did not resume from remaining tables.' );

$mismatch = new MigrationWpdb( 'MyISAM' ); $mismatch->count_mismatch_table = $mismatch->prefix . 'ecomkit_batches'; $GLOBALS['wpdb'] = $mismatch;
$GLOBALS['migration_options'] = array();
try { Ecomkit_Vuikhoe_DB::migrate_tables_to_innodb(); throw new RuntimeException( 'Count mismatch should fail.' ); } catch ( RuntimeException $exception ) { migration_check( str_contains( $exception->getMessage(), 'ROW_COUNT_MISMATCH' ), 'Row-count mismatch was not detected.' ); }

$guard = new MigrationWpdb( 'InnoDB' ); $guard->engines[ $guard->prefix . 'ecomkit_marketplace_connections' ] = 'MyISAM'; $GLOBALS['wpdb'] = $guard;
$GLOBALS['migration_options'] = array();
migration_check( ! Ecomkit_Vuikhoe_DB::supports_import_transactions(), 'Runtime guard accepted a non-transactional Ecomkit table.' );
Ecomkit_Vuikhoe_DB::migrate_tables_to_innodb();
migration_check( Ecomkit_Vuikhoe_DB::supports_import_transactions(), 'Runtime guard did not pass after migration.' );

$legacy = new MigrationWpdb( 'InnoDB' );
$legacy->order_columns['matching_status']['Null'] = 'NO';
$legacy->order_columns['matching_status']['Default'] = 'PARSE_ERROR';
$legacy_counts = array();
foreach ( array( 'orders', 'order_items', 'batches', 'errors' ) as $key ) { $legacy_counts[ $key ] = $legacy->counts[ 'tenant_9_ecomkit_' . $key ]; }
$GLOBALS['wpdb'] = $legacy;
$contract_result = Ecomkit_Vuikhoe_DB::migrate_orders_contract();
migration_check( array( 'matching_status' ) === $contract_result['altered'], 'Legacy matching_status was not explicitly repaired.' );
$legacy_after = array();
foreach ( array( 'orders', 'order_items', 'batches', 'errors' ) as $key ) { $legacy_after[ $key ] = $legacy->counts[ 'tenant_9_ecomkit_' . $key ]; }
migration_check( $legacy_counts === $legacy_after && $legacy_counts === $contract_result['counts'], 'Order contract migration changed business row counts.' );
migration_check( Ecomkit_Vuikhoe_DB::orders_schema_diagnostic()['ready'], 'Order schema readiness did not pass after repair.' );
$query_count = count( $legacy->queries );
migration_check( array() === Ecomkit_Vuikhoe_DB::migrate_orders_contract()['altered'] && $query_count === count( $legacy->queries ), 'Order contract migration is not idempotent.' );

$provider_schema = new MigrationWpdb( 'InnoDB' );
$provider_counts = $provider_schema->counts;
$GLOBALS['wpdb'] = $provider_schema;
$provider_result = Ecomkit_Vuikhoe_DB::migrate_provider_evidence_columns();
migration_check( array( 'provider_raw_data', 'provider_normalized_data', 'provider_updated_at', 'matched_at' ) === $provider_result['added'], 'WP.5 provider evidence columns were not added exactly.' );
migration_check( $provider_counts === $provider_schema->counts, 'WP.5 provider schema migration changed business row counts.' );
migration_check( Ecomkit_Vuikhoe_DB::provider_evidence_schema_ready(), 'WP.5 provider evidence schema did not verify after migration.' );
$provider_query_count = count( $provider_schema->queries );
migration_check( array() === Ecomkit_Vuikhoe_DB::migrate_provider_evidence_columns()['added'] && $provider_query_count === count( $provider_schema->queries ), 'WP.5 provider schema migration is not idempotent.' );

$canonical_schema = new MigrationWpdb( 'InnoDB' ); $canonical_counts = $canonical_schema->counts; $GLOBALS['wpdb'] = $canonical_schema;
$canonical_result = Ecomkit_Vuikhoe_DB::migrate_canonical_result_columns();
migration_check( array( 'canonical_result_version', 'canonical_materialized_at', 'canonical_source_fingerprint' ) === $canonical_result['added'], 'WP.6 canonical metadata columns were not added exactly.' );
migration_check( $canonical_counts === $canonical_schema->counts && Ecomkit_Vuikhoe_DB::canonical_result_schema_ready(), 'WP.6 canonical migration did not preserve/verify Orders.' );
$canonical_query_count = count( $canonical_schema->queries );
migration_check( array() === Ecomkit_Vuikhoe_DB::migrate_canonical_result_columns()['added'] && $canonical_query_count === count( $canonical_schema->queries ), 'WP.6 canonical migration is not idempotent.' );

$payment_schema = new MigrationWpdb( 'InnoDB' ); $payment_counts = $payment_schema->counts; $GLOBALS['wpdb'] = $payment_schema;
$payment_result = Ecomkit_Vuikhoe_DB::migrate_payment_columns();
migration_check( array( 'payment_raw_data', 'payment_normalized_data', 'payment_fetched_at', 'payment_request_id' ) === $payment_result['added'], 'WP.6B payment fields were not added exactly.' );
migration_check( $payment_counts === $payment_schema->counts && Ecomkit_Vuikhoe_DB::payment_schema_ready(), 'WP.6B payment migration changed rows or failed readiness.' );
$payment_query_count = count( $payment_schema->queries );
migration_check( array() === Ecomkit_Vuikhoe_DB::migrate_payment_columns()['added'] && $payment_query_count === count( $payment_schema->queries ), 'WP.6B payment migration is not idempotent.' );

echo "WP.2D database migration checks passed.\n";
