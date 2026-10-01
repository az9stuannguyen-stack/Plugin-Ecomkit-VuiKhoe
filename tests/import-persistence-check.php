<?php
/** WP.2A persistence checks with an in-memory wpdb double. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' );
define( 'ECOMKIT_VUIKHOE_VERSION', '0.2.1' );
define( 'ECOMKIT_VUIKHOE_DB_VERSION', 2 );
define( 'ARRAY_A', 'ARRAY_A' );
function wp_max_upload_size(): int { return 20 * 1024 * 1024; }
function size_format( int $bytes ): string { return (string) $bytes; }
function current_time( string $type, bool $gmt = false ): string { return '2026-10-01 00:00:00'; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }

final class FakeWpdb {
	public string $prefix = 'tenant_2_'; public int $insert_id = 0; public array $inserts = array(); public array $updates = array(); public array $queries = array();
	public function insert( string $table, array $data ): int { $this->inserts[] = compact( 'table', 'data' ); $this->insert_id++; return 1; }
	public function update( string $table, array $data, array $where ): int { $this->updates[] = compact( 'table', 'data', 'where' ); return 1; }
	public function query( string $sql ): int { $this->queries[] = $sql; return 1; }
}
function check_import( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }

$GLOBALS['wpdb'] = new FakeWpdb();
require __DIR__ . '/../ecomkit-vuikhoe/vendor/autoload.php';
$service = new Ecomkit_Vuikhoe_Import_Service();
$persist = new ReflectionMethod( $service, 'persist_result' );
$persist->invoke( $service, 42, 'synthetic.xlsx', 1000, array(
	'status' => 'SUCCESS', 'total_rows' => 2, 'valid_rows' => 1,
	'orders' => array( array(
		'order_code' => '0000A-01', 'platform' => 'SHOPEE', 'raw_platform' => 'Shopee', 'raw_identity' => "Shopee\n0000A-01", 'sheet' => 'Orders', 'row' => 4, 'raw_cells' => array( '2' => "Shopee\n0000A-01" ),
		'items' => array(
			array( 'sku' => 'SKU-A', 'product_name' => 'Sản phẩm A', 'quantity' => 1, 'raw_quantity' => '1', 'sheet' => 'Orders', 'row' => 4, 'raw_cells' => array( '5' => 'SKU-A' ) ),
			array( 'sku' => 'SKU-B', 'product_name' => 'Sản phẩm B', 'quantity' => 2, 'raw_quantity' => '2', 'sheet' => 'Orders', 'row' => 5, 'raw_cells' => array( '5' => 'SKU-B' ) ),
		),
	) ),
	'errors' => array(),
	'raw' => array( 'parser_version' => 'wp2a-v1', 'header_row' => 3, 'item_rows' => 2, 'platform_counts' => array( 'SHOPEE' => 1 ) ),
) );
$wpdb = $GLOBALS['wpdb'];
check_import( array( 'START TRANSACTION', 'COMMIT' ) === $wpdb->queries, 'Persistence transaction failed.' );
check_import( 3 === count( $wpdb->inserts ), 'Expected one Order and two OrderItems.' );
check_import( 'tenant_2_ecomkit_orders' === $wpdb->inserts[0]['table'] && 'SHOPEE' === $wpdb->inserts[0]['data']['platform'], 'Order platform was not persisted.' );
check_import( '0000A-01' === $wpdb->inserts[0]['data']['marketplace_order_id'], 'Marketplace identity changed.' );
check_import( 'tenant_2_ecomkit_order_items' === $wpdb->inserts[1]['table'] && 'SKU-A' === $wpdb->inserts[1]['data']['sku'], 'First item was not persisted.' );
check_import( 'tenant_2_ecomkit_order_items' === $wpdb->inserts[2]['table'] && 2 === $wpdb->inserts[2]['data']['quantity'], 'Continuation item was not persisted.' );
check_import( 1 === count( $wpdb->updates ) && 1 === $wpdb->updates[0]['data']['order_count'], 'Batch order count is wrong.' );
$metadata = json_decode( (string) $wpdb->updates[0]['data']['source_metadata'], true );
check_import( 3 === $metadata['header_row'] && 2 === $metadata['item_rows'] && 1 === $metadata['platform_counts']['SHOPEE'], 'WP.2A metadata was not persisted.' );
echo "WP.2A import persistence checks passed.\n";
