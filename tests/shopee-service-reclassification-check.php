<?php
/** WP.6H.2: persisted-source-only Shopee service reclassification. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' );
require __DIR__ . '/../ecomkit-vuikhoe/vendor/autoload.php';
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-shopee-payment-normalizer.php';
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function fee_check( bool $ok, string $message ): void { if ( ! $ok ) { throw new RuntimeException( $message ); } }
function fee_order( mixed $service, mixed $piship, mixed $discount, mixed $escrow = null, bool $normalize_piship = true ): array {
	$income = array( 'service_fee' => $service );
	if ( null !== $piship ) { $income['shipping_seller_protection_fee_amount'] = $piship; }
	$normalized = Ecomkit_Vuikhoe_Shopee_Payment_Normalizer::normalize( array( 'order_sn' => 'TEST-SHP-001', 'order_income' => $income ) );
	if ( ! $normalize_piship ) { unset( $normalized['shippingSellerProtectionFeeAmount'] ); }
	$normalized['commissionFee'] = 84810;
	$normalized['sellerTransactionFee'] = 30840;
	$normalized['escrowAmountAfterAdjustment'] = $escrow;
	return array( 'platform' => 'SHOPEE', 'matching_status' => 'MATCHED', 'marketplace_order_id' => 'TEST-SHP-001', 'eshop_order_code' => 'ĐH-PRIVATE', 'product_price_vat_8' => '514000', 'affiliate_fee_vuikhoe' => '0', 'discount_vuikhoe' => $discount, 'payment_raw_data' => json_encode( array( 'order_sn' => 'TEST-SHP-001', 'order_income' => $income ) ), 'payment_normalized_data' => json_encode( $normalized ) );
}
$materializer = new Ecomkit_Vuikhoe_Canonical_Result_Materializer();
$a = fee_order( 31270, 2700, '28270', 364380 );
$a_raw = $a['payment_raw_data']; $a_normalized = $a['payment_normalized_data'];
$result = $materializer->materialize( $a ); $columns = $result['columns'];
fee_check( 'v9' === $result['version'] && 24 === count( $columns ) && '5700' === $columns['service_platform_fee'], 'Order A fee/version/shape failed.' );
fee_check( '121350' === $result['rational']['platform_cost_percent']['numerator'] && '149620' === $result['rational']['total_cost_percent']['numerator'] && '514000' === $result['rational']['total_cost_percent']['denominator'], 'Order A exact formula numerator failed.' );
fee_check( 364380 === $columns['total_amount_to_collect'] && 'READY' === $result['formula_state']['service_platform_fee']['status'], 'Escrow precedence or service state failed.' );
fee_check( '31270' === $result['source_metadata']['serviceFeeReclassification']['providerServiceFee'] && '5700' === $result['source_metadata']['serviceFeeReclassification']['canonicalServiceFee'] && $a_raw === $a['payment_raw_data'] && $a_normalized === $a['payment_normalized_data'], 'Source evidence mutated or audit metadata lost.' );
fee_check( 31270 === json_decode( $a_normalized, true )['serviceFee'] && 2700 === json_decode( $a_normalized, true )['shippingSellerProtectionFeeAmount'], 'Provider meanings were overwritten.' );
$b = fee_order( 8940, 2700, '5940' ); $b['product_price_vat_8'] = '108000'; $b['payment_normalized_data'] = json_encode( array_merge( json_decode( $b['payment_normalized_data'], true ), array( 'commissionFee' => 17820, 'sellerTransactionFee' => 6480 ) ) );
fee_check( '5700' === $materializer->materialize( $b )['columns']['service_platform_fee'], 'Order B synthetic PiShip fixture failed.' );
$legacy = fee_order( 31270, 2700, '28270', null, false );
fee_check( '5700' === $materializer->materialize( $legacy )['columns']['service_platform_fee'], 'Old normalized snapshot did not use identity-checked raw PiShip.' );
$legacy_result = $materializer->materialize( $legacy );
fee_check( '364380' === $legacy_result['columns']['total_amount_to_collect'], 'Legacy receivable fallback did not use corrected fee.' );
$changed_raw = $legacy;
$changed_raw['payment_raw_data'] = json_encode( array( 'order_sn' => 'TEST-SHP-001', 'order_income' => array( 'shipping_seller_protection_fee_amount' => 0 ) ) );
fee_check( $materializer->fingerprint( $legacy ) !== $materializer->fingerprint( $changed_raw ), 'Raw PiShip changes did not stale canonical.' );
foreach ( array( array( 10000, 0, '2000', '3000' ), array( 10000, 2700, '0', '5700' ), array( 0, 0, '0', '0' ), array( '10000.25', '2700.50', '2000.10', '5700.5' ) ) as [$service, $piship, $discount, $expected] ) {
	fee_check( $expected === $materializer->materialize( fee_order( $service, $piship, $discount ) )['columns']['service_platform_fee'], 'Zero or exact-decimal operand failed.' );
}
foreach ( array( fee_order( 10000, null, '2000' ), fee_order( null, 2700, '2000' ) ) as $missing ) {
	$projection = $materializer->materialize( $missing );
	fee_check( null === $projection['columns']['service_platform_fee'] && null === $projection['columns']['platform_cost_percent'] && 'MISSING_OPERANDS' === $projection['formula_state']['service_platform_fee']['status'], 'Missing source became zero or raw service fallback.' );
}
$unexpected = $materializer->materialize( fee_order( 1000, 0, '2000' ) );
fee_check( null === $unexpected['columns']['service_platform_fee'] && 'SHOPEE_SERVICE_FEE_UNEXPECTED' === $unexpected['formula_state']['service_platform_fee']['status'] && 'SHOPEE_SERVICE_FEE_UNEXPECTED' === $unexpected['source_metadata']['shopeeServiceFeeDiagnostic'], 'Unexpected sub-3000 service fee was guessed.' );
$wrong_raw = $legacy; $wrong_raw['payment_raw_data'] = json_encode( array( 'order_sn' => 'OTHER', 'order_income' => array( 'shipping_seller_protection_fee_amount' => 2700 ) ) );
fee_check( null === $materializer->materialize( $wrong_raw )['columns']['service_platform_fee'], 'PiShip from another order was used.' );
$lazada = $a; $lazada['platform'] = 'LAZADA';
fee_check( null === $materializer->materialize( $lazada )['columns']['service_platform_fee'], 'Shopee formula leaked to Lazada.' );
$different_escrow = fee_order( 31270, 2700, '28270', 360000 );
fee_check( 360000 === $materializer->materialize( $different_escrow )['columns']['total_amount_to_collect'], 'Escrow lost precedence to legacy.' );
fee_check( null === $columns['difference_amount'] && null === $columns['amount_collected'] && null === $columns['receivable_status'], 'Unapproved accounting fields changed.' );
echo "WP.6H.2 Shopee service reclassification: PASS\n";
