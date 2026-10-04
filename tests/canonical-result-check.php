<?php
/** WP.6 pure canonical contract/materializer checks; no WordPress or provider transport. */
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' );
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function absint( mixed $value ): int { return abs( (int) $value ); }
require __DIR__ . '/../ecomkit-vuikhoe/vendor/autoload.php';
function canonical_check( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }

$columns = Ecomkit_Vuikhoe_Canonical_Columns::all();
$expected = array( 'Ngày Lên Đơn', 'Mã đơn ESHOP', 'Mã đơn sàn', 'Kênh Bán Hàng', 'Trạng Thái Đơn Hàng', 'Tên Khách Hàng', 'SĐT', 'Địa Chỉ', 'Tỉnh/TP', 'Ngày Xuất VAT', 'Ghi Chú', 'Đã Thu Tiền', 'Trạng Thái Công Nợ', 'Chênh lệch', 'Giá SP (VAT 8%)', '% Tổng Chi Phí', 'Tổng Tiền Sẽ Thu', 'Phí Affiliate (Vui Khỏe)', 'Chiết Khấu (Vui Khỏe)', '% Chiết Khấu Vui Khỏe', 'Phí Cố Định (TMĐT)', 'Phí dịch vụ (TMĐT)', 'Phí Giao Dịch (TMĐT)', '% Chi Phí Sàn TMĐT' );
canonical_check( 24 === count( $columns ) && $expected === array_column( $columns, 'label' ), 'Exact 24 labels/order changed.' );
canonical_check( 24 === count( array_unique( array_column( $columns, 'key' ) ) ), 'Canonical keys are not unique.' );

$provider = array( 'providerCreatedAt' => '2026-09-18T03:30:00Z', 'providerStatus' => 'CANCELLED', 'recipientName' => 'Nguyen A', 'recipientPhone' => '0900', 'recipientFullAddress' => 'Source address', 'recipientState' => 'State', 'recipientCity' => 'City', 'recipientRegion' => 'Region' );
$base = array( 'id' => 1, 'platform' => 'SHOPEE', 'matching_status' => 'MATCHED', 'raw_order_code' => 'SHP-1', 'provider_normalized_data' => json_encode( $provider ) );
$items = array( array( 'id' => 1, 'sku' => 'A' ), array( 'id' => 2, 'sku' => 'B' ), array( 'id' => 3, 'sku' => 'C' ) );
$materializer = new Ecomkit_Vuikhoe_Canonical_Result_Materializer( new DateTimeZone( 'Asia/Bangkok' ) );
$excel = $materializer->materialize( $base + array( 'order_date' => '2026-09-17 23:00:00' ), $items );
canonical_check( '2026-09-18' === $excel['columns']['order_date'], 'Excel date did not win or convert from stored UTC.' );
canonical_check( 'CANCELLED' === $excel['columns']['order_status'] && 'State' === $excel['columns']['province_city'], 'Cancelled/recipient/state mapping failed.' );
canonical_check( 'Nguyen A' === $excel['columns']['customer_name'] && '0900' === $excel['columns']['phone'] && 'Source address' === $excel['columns']['address'], 'Recipient mapping failed.' );
$fallback = $materializer->materialize( $base + array( 'order_date' => null ), $items );
canonical_check( '2026-09-18' === $fallback['columns']['order_date'], 'Provider date fallback failed.' );
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
canonical_check( $excel === $materializer->materialize( $base + array( 'order_date' => '2026-09-17 23:00:00' ), $items ), 'Materializer is not deterministic.' );
canonical_check( 64 === strlen( $materializer->fingerprint( $base, $items ) ) && $materializer->fingerprint( $base, $items ) !== $materializer->fingerprint( array_merge( $base, array( 'provider_normalized_data' => json_encode( array_merge( $provider, array( 'recipientState' => 'Changed' ) ) ) ) ), $items ), 'Source fingerprint stale detection failed.' );
canonical_check( 24 === count( $excel['columns'] ) && 'v1' === $excel['version'], 'Canonical row shape/version failed.' );
$fixture_items = array_fill( 0, 18, array( 'sku' => 'SKU', 'product_name' => 'Product', 'quantity' => 1 ) );
$fixture_orders = array_fill( 0, 8, array( 'platform' => 'LAZADA', 'matching_status' => null, 'raw_order_code' => 'LZ', 'order_date' => null ) );
$fixture_rows = array_map( static fn( array $order ): array => $materializer->materialize( $order, $fixture_items ), $fixture_orders );
canonical_check( 8 === count( $fixture_rows ), '8 Orders / 18 Items did not remain 8 canonical rows.' );
echo "WP.6 canonical result checks passed.\n";
