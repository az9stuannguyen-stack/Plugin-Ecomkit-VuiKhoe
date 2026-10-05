<?php
/** WP.6H.2T: exact persistence error phase and rollback, without provider transport. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' ); define( 'ARRAY_A', 'ARRAY_A' );
function persist_check( bool $ok, string $message ): void { if ( ! $ok ) { throw new RuntimeException( $message ); } }
function current_time( string $format, bool $utc = false ): string { return '2026-10-05 10:00:00'; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return ! empty( $GLOBALS['encode_failure'] ) ? false : json_encode( $value, $flags ); }
function wp_remote_get(): never { throw new RuntimeException( 'PROVIDER_CALLED' ); }
function wp_remote_post(): never { throw new RuntimeException( 'PROVIDER_CALLED' ); }
final class PersistDb {
	public string $prefix = 'test_'; public string $last_error = ''; public array $queries = array(); public array $metadata = array(); public bool $fail_order = false; public bool $fail_batch = false; public int $provider_calls = 0;
	public function prepare( string $sql, mixed ...$args ): string { return json_encode( array( 'sql' => $sql, 'args' => $args ) ); }
	public function query( string $sql ): int { $this->queries[] = $sql; return 1; }
	public function get_row( string $sql, string $mode ): array { return array( 'source_metadata' => json_encode( $this->metadata ) ); }
	public function update( string $table, array $data, array $where ): int|false {
		if ( str_ends_with( $table, 'ecomkit_orders' ) && $this->fail_order ) { $this->last_error = "Duplicate entry 'SHOP-ORDER' for key 'connection_order'"; return false; }
		if ( str_ends_with( $table, 'ecomkit_batches' ) ) { if ( $this->fail_batch ) { $this->last_error = 'Data too long for column source_metadata'; return false; } $this->metadata = json_decode( (string) $data['source_metadata'], true ) ?: array(); }
		return 1;
	}
	public function insert( string $table, array $data ): int { return 1; }
}
$db = new PersistDb(); $GLOBALS['wpdb'] = $db; $GLOBALS['encode_failure'] = false;
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-db.php';
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-shopee-reconciliation-service.php';
$service = ( new ReflectionClass( Ecomkit_Vuikhoe_Shopee_Reconciliation_Service::class ) )->newInstanceWithoutConstructor();
$method = new ReflectionMethod( Ecomkit_Vuikhoe_Shopee_Reconciliation_Service::class, 'persist' );
$batch = array( 'id' => 27, 'source_filename' => 'historical.xlsx' );
$args = array( $batch, array(), 1, array( 101 => true ), array(), array(), array(), array(), false, array(), array( 'status' => 'SUCCESS', 'windows' => array() ) );
$db->fail_order = true;
try { $method->invokeArgs( $service, $args ); throw new RuntimeException( 'ORDER_FAILURE_IGNORED' ); } catch ( Ecomkit_Vuikhoe_Shopee_Reconciliation_Exception $error ) { persist_check( 'SHOPEE_RECON_PERSIST_FAILED' === $error->getMessage(), 'Order update failure changed classification.' ); }
persist_check( in_array( 'ROLLBACK', $db->queries, true ) && 'ORDER_CONNECTION_UPDATE' === $db->metadata['shopee_reconciliation_persist_diagnostic']['operation'] && 'DUPLICATE_KEY' === $db->metadata['shopee_reconciliation_persist_diagnostic']['error_classification'] && ! str_contains( json_encode( $db->metadata ), 'SHOP-ORDER' ), 'Duplicate-key diagnostic leaked order ID or was not saved after rollback.' );
$db->fail_order = false; $db->metadata = array(); $db->queries = array(); $GLOBALS['encode_failure'] = true;
try { $method->invokeArgs( $service, $args ); throw new RuntimeException( 'ENCODE_FAILURE_IGNORED' ); } catch ( Ecomkit_Vuikhoe_Shopee_Reconciliation_Exception $error ) { persist_check( 'SHOPEE_RECON_STATE_ENCODE_FAILED' === $error->getMessage(), 'JSON encode failure not explicit.' ); }
persist_check( in_array( 'ROLLBACK', $db->queries, true ), 'JSON encode failure did not roll back.' );
$GLOBALS['encode_failure'] = false; $db->metadata = array(); $db->queries = array();
$method->invokeArgs( $service, $args );
persist_check( in_array( 'COMMIT', $db->queries, true ) && 'SUCCESS' === $db->metadata['shopee_reconciliation']['status'], 'Basic Batch state persistence failed.' );
echo "WP.6H.2T reconciliation persistence phases: PASS\n";
