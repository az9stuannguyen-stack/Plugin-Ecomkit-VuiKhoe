<?php
/** WP.6H.3D: source-backed business-rule fixtures only; no HTTP transport. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' );
require __DIR__ . '/../ecomkit-vuikhoe/vendor/autoload.php';
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function voucher_check( bool $ok, string $message ): void { if ( ! $ok ) { throw new RuntimeException( $message ); } }
$id = '260924TSBR7FC0';
$base_payment = array( 'marketplaceOrderId' => $id, 'orderSellingPrice' => 312000, 'commissionFee' => 51480, 'serviceFee' => 20160, 'shippingSellerProtectionFeeAmount' => 2700, 'sellerTransactionFee' => 18720, 'escrowAmountAfterAdjustment' => 218940, 'voucherFromShopee' => 50000 );
$base = array( 'platform' => 'SHOPEE', 'matching_status' => 'MATCHED', 'marketplace_order_id' => $id, 'product_price_vat_8' => null, 'affiliate_fee_vuikhoe' => null, 'discount_vuikhoe' => null, 'payment_normalized_data' => json_encode( $base_payment ) );
$materializer = new Ecomkit_Vuikhoe_Canonical_Result_Materializer();
$golden = $materializer->materialize( $base ); $c = $golden['columns']; $m = $golden['source_metadata'];
voucher_check( 'v9' === $golden['version'] && 24 === count( $c ) && '312000' === $c['product_price_vat_8'], 'Version/shape/product fallback failed.' );
voucher_check( '3000' === $m['serviceFeeReclassification']['infrastructureFee'] && '17160' === $m['serviceFeeReclassification']['derivedVoucherXtra'] && '17160' === $c['discount_vuikhoe'] && '5700' === $c['service_platform_fee'], 'Golden derivation failed.' );
voucher_check( 51480 === $c['fixed_platform_fee'] && 18720 === $c['transaction_platform_fee'] && 218940 === $c['total_amount_to_collect'] && null === $c['affiliate_fee_vuikhoe'], 'Payment/affiliate source isolation failed.' );
voucher_check( 'SHOPEE_OPENAPI_PAYMENT_DERIVED/serviceFee-minus-infrastructure' === $m['discountVuikhoe']['source'] && 'SHOPEE_OPENAPI_PAYMENT_DERIVED/infrastructure-plus-piship' === $m['serviceFeeReclassification']['source'], 'Derived provenance failed.' );
voucher_check( null === $c['discount_percent_vuikhoe'] && null === $c['total_cost_percent'] && '75900' === $golden['rational']['platform_cost_percent']['numerator'], 'Missing affiliate was treated as zero.' );
$with_affiliate = $base; $with_affiliate['affiliate_fee_vuikhoe'] = '0'; $ready = $materializer->materialize( $with_affiliate );
voucher_check( '0.055' === $ready['columns']['discount_percent_vuikhoe'] && '93060' === $ready['rational']['total_cost_percent']['numerator'] && '5.50%' === Ecomkit_Vuikhoe_Exact_Financial_Math::format_percent_two_decimals( $ready['rational']['discount_percent_vuikhoe'] ) && '24.33%' === Ecomkit_Vuikhoe_Exact_Financial_Math::format_percent_two_decimals( $ready['rational']['platform_cost_percent'] ) && '29.83%' === Ecomkit_Vuikhoe_Exact_Financial_Math::format_percent_two_decimals( $ready['rational']['total_cost_percent'] ), 'Golden ratio/display regression.' );
foreach ( array( array( 17025, 2700, '14025', '3000', '5700' ), array( 3000, 0, '0', '3000', '3000' ), array( 0, 2700, '0', '0', '2700' ), array( 20160, 0, '17160', '3000', '3000' ) ) as [$service, $piship, $voucher, $infrastructure, $fee] ) {
	$payment = $base_payment; $payment['serviceFee'] = $service; $payment['shippingSellerProtectionFeeAmount'] = $piship;
	$order = $base; $order['payment_normalized_data'] = json_encode( $payment ); $result = $materializer->materialize( $order );
	voucher_check( $voucher === $result['columns']['discount_vuikhoe'] && $infrastructure === $result['source_metadata']['serviceFeeReclassification']['infrastructureFee'] && $fee === $result['columns']['service_platform_fee'], 'Variable service/PiShip derivation failed.' );
}
$payment = $base_payment; $payment['serviceFee'] = 1500; $order = $base; $order['payment_normalized_data'] = json_encode( $payment ); $unexpected = $materializer->materialize( $order );
voucher_check( null === $unexpected['columns']['discount_vuikhoe'] && null === $unexpected['columns']['service_platform_fee'] && 'SHOPEE_SERVICE_FEE_UNEXPECTED' === $unexpected['source_metadata']['shopeeServiceFeeDiagnostic'], 'Unexpected service fee was guessed.' );
$payment['serviceFee'] = null; $order['payment_normalized_data'] = json_encode( $payment ); $missing = $materializer->materialize( $order );
voucher_check( null === $missing['columns']['discount_vuikhoe'] && null === $missing['columns']['service_platform_fee'], 'Missing service became zero.' );
$payment = $base_payment; unset( $payment['shippingSellerProtectionFeeAmount'] ); $order['payment_normalized_data'] = json_encode( $payment ); $no_piship = $materializer->materialize( $order );
voucher_check( '17160' === $no_piship['columns']['discount_vuikhoe'] && null === $no_piship['columns']['service_platform_fee'], 'Missing PiShip was treated as zero.' );
$excel = $base; $excel['discount_vuikhoe'] = '18000'; $excel['raw_source_metadata'] = json_encode( array( 'discount_vuikhoe_source' => 'Voucher Xtra' ) ); $override = $materializer->materialize( $excel );
voucher_check( '18000' === $override['columns']['discount_vuikhoe'] && '5700' === $override['columns']['service_platform_fee'] && 'EXCEL/Voucher Xtra' === $override['source_metadata']['discountVuikhoe']['source'] && array( 'excel_discount' => '18000', 'derived_voucher_xtra' => '17160' ) === $override['source_metadata']['discountVuikhoe']['discrepancy'], 'Excel alias precedence/discrepancy failed.' );
$conflict = $base; $conflict['raw_source_metadata'] = json_encode( array( 'discount_vuikhoe_conflict' => true ) ); $blocked = $materializer->materialize( $conflict );
voucher_check( null === $blocked['columns']['discount_vuikhoe'] && 'EXCEL_SOURCE_CONFLICT' === $blocked['source_metadata']['discountVuikhoe']['source'], 'Excel conflict was concealed by provider fallback.' );
$old_conflict = $base; $old_conflict['raw_source_metadata'] = json_encode( array( 'column_map' => array( 'Chiết Khấu (Vui Khỏe)' => 3, 'Voucher Xtra' => 4 ), 'cells' => array( '3' => '17160', '4' => '18000' ) ) );
voucher_check( null === $materializer->materialize( $old_conflict )['columns']['discount_vuikhoe'], 'Pre-v8 Excel conflict was concealed on rematerialization.' );
$wrong = $base; $wrong['payment_normalized_data'] = json_encode( array_merge( $base_payment, array( 'marketplaceOrderId' => 'OTHER' ) ) );
voucher_check( null === $materializer->materialize( $wrong )['columns']['discount_vuikhoe'], 'Other order Payment leaked into discount.' );
echo "WP.6H.3D Voucher Xtra derivation: PASS\n";
