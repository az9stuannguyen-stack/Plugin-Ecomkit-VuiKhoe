<?php
/** WP.2D resumable InnoDB migration checks. */
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ECOMKIT_VUIKHOE_DB_VERSION', 3 );
define( 'ECOMKIT_VUIKHOE_VERSION', '0.2.4' );
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
	private array $altered = array();

	public function __construct( string $default_engine = 'InnoDB' ) {
		foreach ( array( 'batches', 'orders', 'order_items', 'errors', 'marketplace_connections', 'sync_runs' ) as $key ) {
			$table = $this->prefix . 'ecomkit_' . $key;
			$this->engines[ $table ] = $default_engine;
			$this->counts[ $table ] = strlen( $key );
		}
	}
	public function db_server_info(): string { return '10.11-MariaDB'; }
	public function esc_like( string $value ): string { return $value; }
	public function prepare( string $query, mixed ...$args ): string { return vsprintf( str_replace( '%s', "'%s'", $query ), $args ); }
	public function get_results( string $query, string $output ): array {
		if ( 'SHOW ENGINES' === $query ) { return array( array( 'Engine' => 'InnoDB', 'Support' => $this->innodb_supported ? 'DEFAULT' : 'NO' ) ); }
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

echo "WP.2D database migration checks passed.\n";
