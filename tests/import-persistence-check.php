<?php
/** WP.2A persistence checks with an in-memory wpdb double. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' );
define( 'ECOMKIT_VUIKHOE_VERSION', '0.7.4' );
define( 'ECOMKIT_VUIKHOE_DB_VERSION', 9 );
define( 'ARRAY_A', 'ARRAY_A' );
function wp_max_upload_size(): int { return 20 * 1024 * 1024; }
function size_format( int $bytes ): string { return (string) $bytes; }
function current_time( string $type, bool $gmt = false ): string { return '2026-10-01 00:00:00'; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }

final class FakeWpdb {
	public string $prefix = 'tenant_2_'; public int $insert_id = 0; public string $last_error = ''; public string $fail_error = "Synthetic insert failure containing 'private-value'"; public ?string $fail_table = null; public array $inserts = array(); public array $updates = array(); public array $queries = array();
	public function insert( string $table, array $data ): int|false { if ( $table === $this->fail_table ) { $this->last_error = $this->fail_error; return false; } $this->inserts[] = compact( 'table', 'data' ); $this->insert_id++; return 1; }
	public function update( string $table, array $data, array $where ): int { $this->updates[] = compact( 'table', 'data', 'where' ); return 1; }
	public function query( string $sql ): int { $this->queries[] = $sql; return 1; }
	public function esc_like( string $value ): string { return $value; }
	public function prepare( string $query, mixed ...$args ): string { return vsprintf( str_replace( '%s', "'%s'", $query ), $args ); }
	public function get_row( string $query, string $output ): array { return array( 'Engine' => 'InnoDB' ); }
	public function get_results( string $query, string $output ): array { return str_contains( $query, 'SHOW ENGINES' ) ? array( array( 'Engine' => 'InnoDB', 'Support' => 'DEFAULT' ) ) : array(); }
	public function db_server_info(): string { return 'MySQL 8.0'; }
}

function check_import( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }

$GLOBALS['wpdb'] = new FakeWpdb();
require __DIR__ . '/../ecomkit-vuikhoe/vendor/autoload.php';
$service = new Ecomkit_Vuikhoe_Import_Service();
$persist = new ReflectionMethod( $service, 'persist_result' );
$persist->invoke( $service, 42, 'synthetic.xlsx', 1000, array(
	'status' => 'SUCCESS', 'total_rows' => 2, 'valid_rows' => 1,
	'orders' => array( array(
		'order_code' => '0000A-01', 'platform' => 'SHOPEE', 'raw_platform' => 'Shopee', 'raw_identity' => "Shopee\n0000A-01", 'order_date' => '2026-09-17 03:30:00', 'order_date_precision' => 'DATETIME', 'eshop_order_code' => 'ĐH-SYNTH-001', 'product_price_vat_8' => '308000.00', 'affiliate_fee_vuikhoe' => '0', 'discount_vuikhoe' => '12108', 'vat_issued_date' => '2026-10-01 00:00:00', 'note' => '<script>unsafe</script>', 'sheet' => 'Orders', 'row' => 4, 'raw_cells' => array( '2' => "Shopee\n0000A-01", '4' => 'ĐH-SYNTH-001' ),
		'items' => array(
			array( 'sku' => 'SKU-A', 'product_name' => 'Sản phẩm A', 'quantity' => 1, 'raw_quantity' => '1', 'sheet' => 'Orders', 'row' => 4, 'raw_cells' => array( '5' => 'SKU-A' ) ),
			array( 'sku' => 'SKU-B', 'product_name' => 'Sản phẩm B', 'quantity' => 2, 'raw_quantity' => '2', 'sheet' => 'Orders', 'row' => 5, 'raw_cells' => array( '5' => 'SKU-B' ) ),
		),
	) ),
	'errors' => array(),
	'raw' => array( 'parser_version' => 'wp2a-v1', 'header_row' => 3, 'column_map' => array( 'Sàn & Mã Đơn' => 2, 'Ngày đặt' => 3, 'Mã đơn hàng eShop' => 4, 'Mã hàng hóa' => 5, 'Tên hàng hóa' => 6, 'Số lg' => 7 ), 'item_rows' => 2, 'platform_counts' => array( 'SHOPEE' => 1 ) ),
) );
$wpdb = $GLOBALS['wpdb'];
check_import( array( 'START TRANSACTION', 'COMMIT' ) === $wpdb->queries, 'Persistence transaction failed.' );
check_import( 3 === count( $wpdb->inserts ), 'Expected one Order and two OrderItems.' );
check_import( 'tenant_2_ecomkit_orders' === $wpdb->inserts[0]['table'] && 'SHOPEE' === $wpdb->inserts[0]['data']['platform'], 'Order platform was not persisted.' );
check_import( '0000A-01' === $wpdb->inserts[0]['data']['marketplace_order_id'], 'Marketplace identity changed.' );
check_import( 'ĐH-SYNTH-001' === $wpdb->inserts[0]['data']['eshop_order_code'], 'Structured eShop identity was not persisted separately.' );
foreach ( array( 'product_price_vat_8' => '308000.00', 'affiliate_fee_vuikhoe' => '0', 'discount_vuikhoe' => '12108', 'vat_issued_date' => '2026-10-01 00:00:00', 'note' => '<script>unsafe</script>' ) as $field => $expected ) { check_import( $expected === $wpdb->inserts[0]['data'][ $field ], "Structured internal $field was not persisted." ); }
check_import( 4 === json_decode( (string) $wpdb->inserts[0]['data']['raw_source_metadata'], true )['column_map']['Mã đơn hàng eShop'], 'Verified eShop header position was not persisted.' );
check_import( null === $wpdb->inserts[0]['data']['connection_id'] && null === $wpdb->inserts[0]['data']['matching_status'], 'Pre-reconciliation fields must persist as NULL.' );
check_import( '2026-09-17 03:30:00' === $wpdb->inserts[0]['data']['order_date'], 'Parsed UTC Excel order date was not persisted.' );
check_import( 'DATETIME' === json_decode( (string) $wpdb->inserts[0]['data']['raw_source_metadata'], true )['order_date_precision'], 'Order-date precision metadata was dropped.' );
check_import( 'UTC' === json_decode( (string) $wpdb->inserts[0]['data']['raw_source_metadata'], true )['order_date_storage'], 'UTC order-date storage marker was dropped.' );
check_import( 'tenant_2_ecomkit_order_items' === $wpdb->inserts[1]['table'] && 'SKU-A' === $wpdb->inserts[1]['data']['sku'], 'First item was not persisted.' );
check_import( 'tenant_2_ecomkit_order_items' === $wpdb->inserts[2]['table'] && 2 === $wpdb->inserts[2]['data']['quantity'], 'Continuation item was not persisted.' );
check_import( 1 === count( $wpdb->updates ) && 1 === $wpdb->updates[0]['data']['order_count'], 'Batch order count is wrong.' );
$metadata = json_decode( (string) $wpdb->updates[0]['data']['source_metadata'], true );
check_import( 3 === $metadata['header_row'] && 2 === $metadata['item_rows'] && 1 === $metadata['platform_counts']['SHOPEE'], 'WP.2A metadata was not persisted.' );
check_import( 1 === $metadata['internal_source_counts']['product_price_vat_8'] && 1 === $metadata['internal_source_counts']['affiliate_fee_vuikhoe'], 'Internal source counts were not persisted.' );

$failing_db = new FakeWpdb();
$failing_db->fail_table = 'tenant_2_ecomkit_order_items';
$GLOBALS['wpdb'] = $failing_db;
try {
	$persist->invoke( $service, 43, 'synthetic.xlsx', 1000, array(
		'status' => 'SUCCESS', 'total_rows' => 1, 'valid_rows' => 1,
		'orders' => array( array( 'order_code' => 'SAFE-ID', 'platform' => 'SHOPEE', 'raw_platform' => 'Shopee', 'raw_identity' => "Shopee\nSAFE-ID", 'sheet' => 'Orders', 'row' => 4, 'raw_cells' => array(), 'items' => array( array( 'sku' => 'SKU', 'product_name' => 'Product', 'quantity' => 1, 'raw_quantity' => '1', 'sheet' => 'Orders', 'row' => 4, 'raw_cells' => array() ) ) ) ),
		'errors' => array(), 'raw' => array( 'sheet' => 'Orders' ),
	) );
	throw new RuntimeException( 'Expected item persistence failure.' );
} catch ( Ecomkit_Vuikhoe_Import_Exception $exception ) {
	$diagnostic = $exception->diagnostic();
	check_import( 'ITEM_PERSIST' === $diagnostic['stage'] && 'order_items' === $diagnostic['entity'] && 4 === $diagnostic['row'], 'Failure stage/entity/row was not retained.' );
	check_import( true === $diagnostic['rollback'] && array( 'START TRANSACTION', 'ROLLBACK' ) === $failing_db->queries, 'Failed persistence was not rolled back.' );
	check_import( ! str_contains( $diagnostic['exception_message'], 'private-value' ), 'Sensitive quoted DB value was not redacted.' );
}

$schema_failure = new FakeWpdb();
$schema_failure->fail_table = 'tenant_2_ecomkit_orders';
$schema_failure->fail_error = "Column 'matching_status' cannot be null";
$GLOBALS['wpdb'] = $schema_failure;
try {
	$persist->invoke( $service, 44, 'synthetic.xlsx', 1000, array(
		'status' => 'SUCCESS', 'total_rows' => 1, 'valid_rows' => 1,
		'orders' => array( array( 'order_code' => 'SAFE-ID', 'platform' => 'SHOPEE', 'raw_platform' => 'Shopee', 'raw_identity' => 'redacted', 'sheet' => 'Orders', 'row' => 4, 'raw_cells' => array(), 'items' => array() ) ),
		'errors' => array(), 'raw' => array( 'sheet' => 'Orders' ),
	) );
	throw new RuntimeException( 'Expected legacy schema failure.' );
} catch ( Ecomkit_Vuikhoe_Import_Exception $exception ) {
	$diagnostic = $exception->diagnostic();
	check_import( 'ECOMKIT_ORDER_INSERT_FAILED' === $diagnostic['classification'] && 'matching_status' === $diagnostic['db_column'], 'Failing DB column was not safely classified.' );
	check_import( ! str_contains( $diagnostic['db_error'], 'SAFE-ID' ), 'Order value leaked into DB diagnostic.' );
}

$invalid_contract = new FakeWpdb();
$GLOBALS['wpdb'] = $invalid_contract;
try {
	$persist->invoke( $service, 45, 'synthetic.xlsx', 1000, array(
		'status' => 'SUCCESS', 'total_rows' => 1, 'valid_rows' => 1,
		'orders' => array( array( 'order_code' => '', 'platform' => 'SHOPEE', 'raw_platform' => 'Shopee', 'raw_identity' => 'redacted', 'sheet' => 'Orders', 'row' => 4, 'raw_cells' => array(), 'items' => array() ) ),
		'errors' => array(), 'raw' => array( 'sheet' => 'Orders' ),
	) );
	throw new RuntimeException( 'Expected application contract failure.' );
} catch ( Ecomkit_Vuikhoe_Import_Exception $exception ) {
	$diagnostic = $exception->diagnostic();
	check_import( 'ECOMKIT_ORDER_CONTRACT_INVALID' === $diagnostic['classification'] && 'marketplace_order_id' === $diagnostic['field'], 'Missing required Order identity was not classified before insert.' );
	check_import( array() === $invalid_contract->inserts && array( 'START TRANSACTION', 'ROLLBACK' ) === $invalid_contract->queries, 'Invalid Order reached database insert.' );
}

$validate = new ReflectionMethod( $service, 'validate_upload' );
$unsupported = $validate->invoke( $service, array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => 'x', 'size' => 100 ), 'fixture.csv' );
check_import( 'EXCEL_UNSUPPORTED_FILE_TYPE' === $unsupported['error_code'], 'Unsupported upload type was not blocked.' );

$GLOBALS['wpdb'] = $large_db = new FakeWpdb();
$persist->invoke( $service, 99, 'synthetic-lazada.xlsx', 1000, array(
	'status' => 'SUCCESS', 'total_rows' => 1, 'valid_rows' => 1,
	'orders' => array( array( 'order_code' => '987654321098765432109876543210', 'platform' => 'LAZADA', 'raw_platform' => 'Lazada', 'raw_identity' => "Lazada\n987654321098765432109876543210", 'sheet' => 'Orders', 'row' => 2, 'raw_cells' => array(), 'items' => array() ) ),
	'errors' => array(), 'raw' => array( 'platform_counts' => array( 'LAZADA' => 1 ) ),
) );
check_import( $large_db->inserts[0]['data']['marketplace_order_id'] === '987654321098765432109876543210' && $large_db->inserts[0]['data']['platform'] === 'LAZADA', 'Large Lazada ID changed on import DB write.' );
echo "WP.2B import persistence checks passed.\n";
