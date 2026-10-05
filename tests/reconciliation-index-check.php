<?php
/** WP.6H.2T: re-importing one marketplace order into another Batch must not collide. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' ); define( 'ARRAY_A', 'ARRAY_A' );
function index_check( bool $ok, string $message ): void { if ( ! $ok ) { throw new RuntimeException( $message ); } }
final class IndexDb {
	public string $prefix = 'test_'; public array $columns = array( 'connection_id', 'marketplace_order_id' ); public int $rows = 42; public int $alters = 0; public bool $fail = false;
	public function get_results( string $sql, string $mode ): array { if ( ! str_contains( $sql, 'SHOW INDEX' ) ) { return array(); } $result = array(); foreach ( $this->columns as $i => $name ) { $result[] = array( 'Key_name' => 'connection_order', 'Seq_in_index' => $i + 1, 'Column_name' => $name, 'Non_unique' => 0 ); } return $result; }
	public function get_var( string $sql ): int { return $this->rows; }
	public function query( string $sql ): int|false { index_check( str_contains( $sql, 'DROP INDEX `connection_order`' ) && str_contains( $sql, '(`batch_id`,`connection_id`,`marketplace_order_id`)' ), 'Migration changed the wrong index.' ); if ( $this->fail ) { return false; } $this->alters++; $this->columns = array( 'batch_id', 'connection_id', 'marketplace_order_id' ); return 1; }
}
$db = new IndexDb(); $GLOBALS['wpdb'] = $db;
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-db.php';
index_check( Ecomkit_Vuikhoe_DB::migrate_connection_order_index(), 'Legacy cross-Batch unique index was not repaired.' );
index_check( 1 === $db->alters && 42 === $db->rows && ! Ecomkit_Vuikhoe_DB::migrate_connection_order_index() && 1 === $db->alters, 'Migration was not row-preserving/idempotent.' );
$schema = implode( "\n", Ecomkit_Vuikhoe_DB::schema_sql( Ecomkit_Vuikhoe_DB::table_names(), '' ) );
index_check( str_contains( $schema, 'UNIQUE KEY connection_order (batch_id,connection_id,marketplace_order_id)' ), 'Fresh install retained cross-Batch uniqueness.' );
index_check( str_contains( $schema, 'source_metadata longtext DEFAULT NULL' ), 'Batch metadata capacity unexpectedly narrowed.' );
$ids = array_map( static fn( int $i ): string => 'TEST-SHP-' . str_pad( (string) $i, 8, '0', STR_PAD_LEFT ), range( 1, 10000 ) );
$large_state = array( 'auto_pipeline' => array( 'reconcile_continuation' => array( 'provider_ids' => array_fill_keys( $ids, true ), 'page_count' => 100, 'request_ids' => array_fill( 0, 100, 'safe_request_id' ) ) ), 'shopee_reconciliation' => array( 'windows' => array( array( 'excel_order_ids' => array_slice( $ids, 0, 11 ), 'provider_order_ids' => $ids, 'intersection' => array_slice( $ids, 0, 11 ) ) ) ) );
$large_json = json_encode( $large_state ); index_check( is_string( $large_json ) && strlen( $large_json ) > 100000 && strlen( $large_json ) < 4000000 && $large_state === json_decode( $large_json, true ), 'Large pagination state did not round-trip within LONGTEXT.' );
echo 'Representative 10k-ID metadata bytes: ' . strlen( $large_json ) . "\n";
$db->columns = array( 'connection_id', 'marketplace_order_id' ); $db->fail = true;
try { Ecomkit_Vuikhoe_DB::migrate_connection_order_index(); throw new RuntimeException( 'ALTER_FAILURE_IGNORED' ); } catch ( RuntimeException $error ) { index_check( 'ECOMKIT_CONNECTION_ORDER_INDEX_MIGRATION_FAILED' === $error->getMessage(), 'Migration failure not fail-closed.' ); }
echo "WP.6H.2T Batch-scoped connection/order index: PASS\n";
