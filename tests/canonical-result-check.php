<?php
/** WP.6 pure canonical contract/materializer checks; no WordPress or provider transport. */
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' );
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function absint( mixed $value ): int { return abs( (int) $value ); }
function wp_timezone(): DateTimeZone { return new DateTimeZone( 'Asia/Bangkok' ); }
define( 'ARRAY_A', 'ARRAY_A' );
require __DIR__ . '/../ecomkit-vuikhoe/vendor/autoload.php';
function canonical_check( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }

$columns = Ecomkit_Vuikhoe_Canonical_Columns::all();
$expected = array( 'Ngày Lên Đơn', 'Mã đơn ESHOP', 'Mã đơn sàn', 'Kênh Bán Hàng', 'Trạng Thái Đơn Hàng', 'Tên Khách Hàng', 'SĐT', 'Địa Chỉ', 'Tỉnh/TP', 'Ngày Xuất VAT', 'Ghi Chú', 'Đã Thu Tiền', 'Trạng Thái Công Nợ', 'Chênh lệch', 'Giá SP (VAT 8%)', '% Tổng Chi Phí', 'Tổng Tiền Sẽ Thu', 'Phí Affiliate (Vui Khỏe)', 'Chiết Khấu (Vui Khỏe)', '% Chiết Khấu Vui Khỏe', 'Phí Cố Định (TMĐT)', 'Phí dịch vụ (TMĐT)', 'Phí Giao Dịch (TMĐT)', '% Chi Phí Sàn TMĐT' );
canonical_check( 24 === count( $columns ) && $expected === array_column( $columns, 'label' ), 'Exact 24 labels/order changed.' );
canonical_check( 24 === count( array_unique( array_column( $columns, 'key' ) ) ), 'Canonical keys are not unique.' );
$expected_sources = array( 'EXCEL_THEN_SHOPEE_FALLBACK', 'EXCEL_AVAILABLE', 'EXCEL_THEN_SHOPEE_FALLBACK', 'EXCEL_THEN_SHOPEE_FALLBACK', 'SHOPEE_ORDER_DETAIL_AVAILABLE', 'SHOPEE_ORDER_DETAIL_AVAILABLE', 'SHOPEE_ORDER_DETAIL_AVAILABLE', 'SHOPEE_ORDER_DETAIL_AVAILABLE', 'SHOPEE_ORDER_DETAIL_AVAILABLE', 'UNMAPPED_NO_SOURCE', 'UNMAPPED_NO_SOURCE', 'FUTURE_PAYMENT_ESCROW', 'FUTURE_PAYMENT_ESCROW', 'FUTURE_PAYMENT_ESCROW', 'UNMAPPED_NO_SOURCE', 'FUTURE_PAYMENT_ESCROW', 'FUTURE_PAYMENT_ESCROW', 'UNMAPPED_NO_SOURCE', 'UNMAPPED_NO_SOURCE', 'UNMAPPED_NO_SOURCE', 'FUTURE_PAYMENT_ESCROW', 'FUTURE_PAYMENT_ESCROW', 'FUTURE_PAYMENT_ESCROW', 'FUTURE_PAYMENT_ESCROW' );
canonical_check( $expected_sources === array_column( $columns, 'source' ), 'Per-column source matrix changed.' );

$provider = array( 'providerCreatedAt' => '2026-09-19T03:30:00Z', 'providerStatus' => 'CANCELLED', 'recipientName' => 'Nguyen A', 'recipientPhone' => '0900', 'recipientFullAddress' => 'Source address', 'recipientState' => 'State', 'recipientCity' => 'City', 'recipientRegion' => 'Region', 'totalAmount' => 1000, 'escrowAmount' => 800 );
$base = array( 'id' => 1, 'platform' => 'SHOPEE', 'matching_status' => 'MATCHED', 'raw_order_code' => 'SHP-1', 'eshop_order_code' => 'ESHOP-1', 'provider_normalized_data' => json_encode( $provider ) );
$items = array( array( 'id' => 1, 'sku' => 'A' ), array( 'id' => 2, 'sku' => 'B' ), array( 'id' => 3, 'sku' => 'C' ) );
$materializer = new Ecomkit_Vuikhoe_Canonical_Result_Materializer( new DateTimeZone( 'Asia/Bangkok' ) );
$excel = $materializer->materialize( $base + array( 'order_date' => '2026-09-17 23:00:00' ), $items );
canonical_check( '2026-09-18' === $excel['columns']['order_date'], 'Excel date did not win or convert from stored UTC.' );
canonical_check( 'CANCELLED' === $excel['columns']['order_status'] && 'State' === $excel['columns']['province_city'], 'Cancelled/recipient/state mapping failed.' );
canonical_check( 'Nguyen A' === $excel['columns']['customer_name'] && '0900' === $excel['columns']['phone'] && 'Source address' === $excel['columns']['address'], 'Recipient mapping failed.' );
canonical_check( 'ESHOP-1' === $excel['columns']['eshop_order_code'], 'Excel eShop code did not materialize.' );
$raw_eshop = $materializer->materialize( array_merge( $base, array( 'eshop_order_code' => null, 'raw_source_metadata' => json_encode( array( 'column_map' => array( 'Mã đơn hàng eShop' => 4 ), 'cells' => array( '4' => 'RAW-ESHOP' ) ) ) ) ) );
canonical_check( 'RAW-ESHOP' === $raw_eshop['columns']['eshop_order_code'], 'Verified raw Excel header fallback failed.' );
$structured_priority = $materializer->materialize( array_merge( $base, array( 'eshop_order_code' => 'ESHOP-STRUCTURED', 'raw_source_metadata' => json_encode( array( 'column_map' => array( 'Mã đơn hàng eShop' => 4 ), 'cells' => array( '4' => 'ESHOP-RAW' ) ) ) ) ) );
canonical_check( 'ESHOP-STRUCTURED' === $structured_priority['columns']['eshop_order_code'], 'Structured Excel value did not take precedence.' );
$trimmed = $materializer->materialize( array_merge( $base, array( 'eshop_order_code' => '  ĐH-SYNTH-002  ' ) ) );
canonical_check( 'ĐH-SYNTH-002' === $trimmed['columns']['eshop_order_code'], 'eShop identity was not preserved with outer whitespace trimmed.' );
$legacy_batch = array( 'parser_version' => 'wp5a-v1', 'header_row' => 3, 'date_column' => 3, 'valid_rows' => 8, 'item_rows' => 18 );
$legacy_cells = array( '1' => 1, '2' => "Shopee\nSHP-LEGACY", '3' => '17/09/2026 10:00', '4' => '  ĐH-SYNTH-001  ', '5' => 'SKU-A', '6' => 'Product A', '7' => 1 );
$legacy_order = array_merge( $base, array( 'raw_order_code' => 'SHP-LEGACY', 'marketplace_order_id' => 'SHP-LEGACY', 'eshop_order_code' => null, 'source_refs' => json_encode( array( 'source' => 'EXCEL', 'sheet' => 'DANH SÁCH ĐƠN HÀNG', 'row' => 4 ) ), 'raw_source_metadata' => json_encode( array( 'source' => 'EXCEL', 'combined_identity' => "Shopee\nSHP-LEGACY", 'cells' => $legacy_cells ) ) ) );
$legacy_result = $materializer->materialize( $legacy_order, $items, $legacy_batch );
canonical_check( 'ĐH-SYNTH-001' === $legacy_result['columns']['eshop_order_code'] && 'SHP-LEGACY' === $legacy_result['columns']['raw_order_code'], 'Verified legacy D-cell recovery or identity separation failed.' );
$ambiguous_batch = array_merge( $legacy_batch, array( 'date_column' => 2 ) );
canonical_check( null === $materializer->materialize( $legacy_order, $items, $ambiguous_batch )['columns']['eshop_order_code'], 'Ambiguous legacy workbook used D-cell.' );
$ambiguous_order = array_merge( $legacy_order, array( 'source_refs' => json_encode( array( 'source' => 'EXCEL', 'sheet' => 'OTHER SHEET', 'row' => 4 ) ) ) );
canonical_check( null === $materializer->materialize( $ambiguous_order, $items, $legacy_batch )['columns']['eshop_order_code'], 'Unrelated worksheet used D-cell.' );
$continuation_items = array( array( 'id' => 1, 'sku' => 'A', 'product_name' => 'One' ), array( 'id' => 2, 'sku' => 'B', 'product_name' => 'Two', 'raw_product_metadata' => json_encode( array( 'cells' => array( '4' => '' ) ) ) ) );
canonical_check( 'ĐH-SYNTH-001' === $materializer->materialize( $legacy_order, $continuation_items, $legacy_batch )['columns']['eshop_order_code'], 'Continuation item cleared parent eShop code.' );
$unverified_eshop = $materializer->materialize( array_merge( $base, array( 'eshop_order_code' => null, 'raw_source_metadata' => json_encode( array( 'cells' => array( '4' => 'UNVERIFIED' ) ) ) ) ) );
canonical_check( null === $unverified_eshop['columns']['eshop_order_code'], 'Old raw column position was guessed.' );
$fallback = $materializer->materialize( $base + array( 'order_date' => null ), $items );
canonical_check( '2026-09-19' === $fallback['columns']['order_date'], 'Provider date fallback failed.' );
$city_provider = $provider; $city_provider['recipientState'] = null;
$city = $materializer->materialize( array_merge( $base, array( 'order_date' => null, 'provider_normalized_data' => json_encode( $city_provider ) ) ) );
canonical_check( 'City' === $city['columns']['province_city'], 'Province city fallback failed.' );
$missing_provider = $provider; $missing_provider['recipientPhone'] = null;
$missing = $materializer->materialize( array_merge( $base, array( 'order_date' => null, 'provider_normalized_data' => json_encode( $missing_provider ) ) ) );
canonical_check( null === $missing['columns']['phone'], 'Missing phone was fabricated.' );
foreach ( array( 'NOT_FOUND_IN_SHOPEE', 'DETAIL_MISSING' ) as $status ) {
	$row = $materializer->materialize( array_merge( $base, array( 'matching_status' => $status, 'order_date' => null ) ) );
	canonical_check( null === $row['columns']['customer_name'] && 'SHP-1' === $row['columns']['raw_order_code'], "$status row policy failed." );
}
$lazada = $materializer->materialize( array( 'id' => 2, 'platform' => 'LAZADA', 'matching_status' => null, 'raw_order_code' => '0001', 'order_date' => '2026-09-17 00:00:00', 'provider_normalized_data' => json_encode( $provider ) ) );
canonical_check( '0001' === $lazada['columns']['raw_order_code'] && null === $lazada['columns']['customer_name'], 'Lazada isolation failed.' );
$future = array_column( array_filter( $columns, static fn( array $column ): bool => 'FUTURE_PAYMENT_ESCROW' === $column['source'] ), 'key' );
foreach ( $future as $key ) { canonical_check( null === $excel['columns'][ $key ], "Future escrow $key is not NULL." ); }
canonical_check( null === $excel['columns']['vat_issued_date'] && null === $excel['columns']['product_price_vat_8'] && null === $excel['columns']['discount_vuikhoe'], 'Unapproved date, VAT price or internal discount was inferred.' );
$completed = $materializer->materialize( array_merge( $base, array( 'provider_normalized_data' => json_encode( array_merge( $provider, array( 'providerStatus' => 'COMPLETED' ) ) ) ) ) );
canonical_check( null === $completed['columns']['amount_collected'] && null === $completed['columns']['total_amount_to_collect'], 'COMPLETED or gross total was reinterpreted as collected/settlement.' );
canonical_check( $excel === $materializer->materialize( $base + array( 'order_date' => '2026-09-17 23:00:00' ), $items ), 'Materializer is not deterministic.' );
canonical_check( 64 === strlen( $materializer->fingerprint( $base, $items ) ) && $materializer->fingerprint( $base, $items ) !== $materializer->fingerprint( array_merge( $base, array( 'provider_normalized_data' => json_encode( array_merge( $provider, array( 'recipientState' => 'Changed' ) ) ) ) ), $items ), 'Source fingerprint stale detection failed.' );
canonical_check( 24 === count( $excel['columns'] ) && 'v2' === $excel['version'], 'Canonical row shape/version failed.' );
foreach ( $columns as $column ) {
	$key = $column['key']; $source = $column['source'];
	if ( in_array( $source, array( 'FUTURE_PAYMENT_ESCROW', 'UNMAPPED_NO_SOURCE' ), true ) ) { canonical_check( null === $excel['columns'][ $key ], "Unauthorized source populated $key." ); }
}
$fixture_items = array_fill( 0, 18, array( 'sku' => 'SKU', 'product_name' => 'Product', 'quantity' => 1 ) );
$fixture_orders = array_fill( 0, 8, array( 'platform' => 'LAZADA', 'matching_status' => null, 'raw_order_code' => 'LZ', 'order_date' => null ) );
$fixture_rows = array_map( static fn( array $order ): array => $materializer->materialize( $order, $fixture_items ), $fixture_orders );
canonical_check( 8 === count( $fixture_rows ), '8 Orders / 18 Items did not remain 8 canonical rows.' );

final class CanonicalWpdb {
	public string $prefix = 'test_';
	public array $orders;
	public array $batch_source = array();
	public function __construct( array $orders ) { $this->orders = $orders; }
	public function prepare( string $query, mixed ...$args ): string { return $query; }
	public function get_row( string $query, string $mode ): array { return array( 'id' => 7, 'source_type' => 'EXCEL', 'source_metadata' => json_encode( $this->batch_source ), 'status' => 'SUCCESS' ); }
	public function get_results( string $query, string $mode ): array { return str_contains( $query, 'ecomkit_orders' ) ? $this->orders : array(); }
	public function query( string $query ): int { return 1; }
	public function update( string $table, array $data, array $where ): int { foreach ( $this->orders as &$order ) { if ( (int) $order['id'] === (int) $where['id'] ) { $order = array_merge( $order, $data ); return 1; } } return 0; }
}
$old_snapshot = array( 'version' => 'v1', 'columns' => $excel['columns'] );
$GLOBALS['wpdb'] = new CanonicalWpdb( array( array_merge( $base, array( 'canonical_data' => json_encode( $old_snapshot ), 'canonical_result_version' => 'v1', 'canonical_source_fingerprint' => 'old' ) ) ) );
$old_result = ( new Ecomkit_Vuikhoe_Canonical_Result_Service() )->get_batch_result( 7 );
canonical_check( 1 === count( $old_result['rows'] ) && $old_result['rows'][0]['stale'] && ! $old_result['rows'][0]['ready'], 'v1 snapshot was presented as current v2.' );
$GLOBALS['wpdb'] = new CanonicalWpdb( array( $legacy_order ) );
$GLOBALS['wpdb']->batch_source = $legacy_batch;
function current_time( string $type, bool $gmt = false ): string { return '2026-10-04 00:00:00'; }
$service = new Ecomkit_Vuikhoe_Canonical_Result_Service();
$persisted = $service->materialize_batch( 7 );
$rematerialized = $service->get_batch_result( 7 );
canonical_check( 1 === $persisted['rows'] && 'ĐH-SYNTH-001' === $rematerialized['rows'][0]['columns']['eshop_order_code'] && ! $rematerialized['rows'][0]['stale'], 'Legacy Batch did not rematerialize to a current v2 snapshot.' );
echo "WP.6 canonical result checks passed.\n";
