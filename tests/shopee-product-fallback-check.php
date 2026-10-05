<?php
/** WP.6H.3B: persisted OpenAPI price fallback; no provider transport. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' );
require __DIR__ . '/../ecomkit-vuikhoe/vendor/autoload.php';
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function price_check( bool $ok, string $message ): void { if ( ! $ok ) { throw new RuntimeException( $message ); } }
$id = '260924TSBR7FC0';
$payment = array( 'marketplaceOrderId' => $id, 'orderSellingPrice' => 312000, 'voucherFromShopee' => 50000, 'commissionFee' => 51480, 'serviceFee' => 20160, 'shippingSellerProtectionFeeAmount' => 2700, 'sellerTransactionFee' => 18720, 'escrowAmountAfterAdjustment' => 218940 );
$base = array( 'platform' => 'SHOPEE', 'matching_status' => 'MATCHED', 'marketplace_order_id' => $id, 'product_price_vat_8' => null, 'affiliate_fee_vuikhoe' => null, 'discount_vuikhoe' => null, 'payment_normalized_data' => json_encode( $payment ) );
$m = new Ecomkit_Vuikhoe_Canonical_Result_Materializer();
$fallback = $m->materialize( $base );
price_check( 'v8' === $fallback['version'] && 24 === count( $fallback['columns'] ), 'Canonical shape/version failed.' );
price_check( '312000' === $fallback['columns']['product_price_vat_8'] && 'SHOPEE_OPENAPI_PAYMENT/order_selling_price' === $fallback['source_metadata']['productPrice']['source'], 'Provider fallback/provenance failed.' );
price_check( '17160' === $fallback['columns']['discount_vuikhoe'] && '5700' === $fallback['columns']['service_platform_fee'] && null === $fallback['columns']['discount_percent_vuikhoe'] && '75900' === $fallback['rational']['platform_cost_percent']['numerator'], 'Service-derived voucher or missing Affiliate gate failed.' );
price_check( 51480 === $fallback['columns']['fixed_platform_fee'] && 18720 === $fallback['columns']['transaction_platform_fee'] && 218940 === $fallback['columns']['total_amount_to_collect'], 'Payment direct mapping/escrow failed.' );
$excel = $base; $excel['product_price_vat_8'] = '310000';
$priority = $m->materialize( $excel );
price_check( '310000' === $priority['columns']['product_price_vat_8'] && 'EXCEL' === $priority['source_metadata']['productPrice']['source'] && array( 'excel' => '310000', 'provider' => '312000' ) === $priority['source_metadata']['productPrice']['discrepancy'], 'Excel precedence/discrepancy failed.' );
$zero = $base; $zero['product_price_vat_8'] = '0';
price_check( '0' === $m->materialize( $zero )['columns']['product_price_vat_8'], 'Explicit Excel zero overwritten.' );
$none = $base; $none['payment_normalized_data'] = json_encode( array( 'marketplaceOrderId' => $id ) );
price_check( null === $m->materialize( $none )['columns']['product_price_vat_8'], 'Missing price became zero.' );
$wrong_id = $base; $wrong_id['payment_normalized_data'] = json_encode( array_merge( $payment, array( 'marketplaceOrderId' => 'OTHER' ) ) );
price_check( null === $m->materialize( $wrong_id )['columns']['product_price_vat_8'], 'Price from another order accepted.' );
$trusted = $base; $trusted['affiliate_fee_vuikhoe'] = '0'; $trusted['discount_vuikhoe'] = '17160';
$trusted['raw_source_metadata'] = json_encode( array( 'source' => 'EXCEL', 'discount_vuikhoe_source' => 'Voucher Xtra' ) );
$golden = $m->materialize( $trusted );
price_check( '5700' === $golden['columns']['service_platform_fee'] && '0.055' === $golden['columns']['discount_percent_vuikhoe'] && '75900' === $golden['rational']['platform_cost_percent']['numerator'] && '93060' === $golden['rational']['total_cost_percent']['numerator'], 'Trusted discount golden formula failed.' );
price_check( 'EXCEL/Voucher Xtra' === $golden['source_metadata']['discountVuikhoe']['source'] && 'SHOPEE_OPENAPI_PAYMENT_DERIVED/serviceFee-minus-infrastructure' === $fallback['source_metadata']['discountVuikhoe']['source'], 'Discount provenance failed.' );
echo "WP.6H.3B Shopee product fallback: PASS\n";
