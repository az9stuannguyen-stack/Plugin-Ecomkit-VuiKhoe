<?php
/** WP.6F: approved formula provenance and strict no-source activation gate. */
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' );
require __DIR__ . '/../ecomkit-vuikhoe/vendor/autoload.php';
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function contract_check( bool $ok, string $message ): void { if ( ! $ok ) { throw new RuntimeException( $message ); } }

$columns = Ecomkit_Vuikhoe_Canonical_Columns::all();
contract_check( 24 === count( $columns ) && 'v3' === Ecomkit_Vuikhoe_Canonical_Columns::VERSION, 'Canonical shape/version changed.' );
$contract = Ecomkit_Vuikhoe_Canonical_Columns::legacy_formula_contract();
$fees = array( 'fixed_platform_fee', 'service_platform_fee', 'transaction_platform_fee' );
$all = array_merge( $fees, array( 'affiliate_fee_vuikhoe', 'discount_vuikhoe' ) );
contract_check( $all === $contract['total_cost_percent']['numerator'] && 'product_price_vat_8' === $contract['total_cost_percent']['denominator'], 'Total cost formula operands changed.' );
contract_check( $all === $contract['total_amount_to_collect']['numerator'] && 'product_price_vat_8' === $contract['total_amount_to_collect']['subtract_from'], 'Legacy receivable formula operands changed.' );
contract_check( array( 'affiliate_fee_vuikhoe', 'discount_vuikhoe' ) === $contract['discount_percent_vuikhoe']['numerator'], 'Vui Khỏe discount formula lost affiliate operand.' );
contract_check( $fees === $contract['platform_cost_percent']['numerator'], 'Platform cost formula operands changed.' );
contract_check( ! isset( $contract['difference_amount'] ), 'Broken #REF! gained a formula.' );

foreach ( array(
	array( 308000, 0, 12108, 50265, 3000, 18481, 83854, 71746, 12108, 224146 ),
	array( 312000, 0, 12265, 50918, 3000, 18722, 84905, 72640, 12265, 227095 ),
) as $row ) {
	[ $price, $affiliate, $discount, $fixed, $service, $transaction, $cost_expected, $platform_expected, $discount_expected, $receivable_expected ] = $row;
	$values = array( 'product_price_vat_8' => $price, 'affiliate_fee_vuikhoe' => $affiliate, 'discount_vuikhoe' => $discount, 'fixed_platform_fee' => $fixed, 'service_platform_fee' => $service, 'transaction_platform_fee' => $transaction );
	foreach ( array_keys( $contract ) as $key ) { contract_check( Ecomkit_Vuikhoe_Canonical_Columns::legacy_operands_ready( $values, $key ), "Complete legacy operands rejected for $key." ); }
	$total_cost = array_sum( array_map( static fn( string $key ): int => $values[ $key ], $all ) );
	$platform_cost = array_sum( array_map( static fn( string $key ): int => $values[ $key ], $fees ) );
	$discount_cost = $affiliate + $discount;
	contract_check( $cost_expected === $total_cost && $receivable_expected === $price - $total_cost, 'Legacy row money formula changed.' );
	// All three ratios are exact fractions with denominator $price, no float or ×100.
	contract_check( $cost_expected === $total_cost && $platform_expected === $platform_cost && $discount_expected === $discount_cost, 'Legacy row ratio numerators changed.' );
	$missing = $values; $missing['affiliate_fee_vuikhoe'] = null;
	foreach ( array( 'total_cost_percent', 'total_amount_to_collect', 'discount_percent_vuikhoe' ) as $key ) { contract_check( ! Ecomkit_Vuikhoe_Canonical_Columns::legacy_operands_ready( $missing, $key ), "Missing affiliate became zero for $key." ); }
	$missing['product_price_vat_8'] = null;
	foreach ( array_keys( $contract ) as $key ) { contract_check( ! Ecomkit_Vuikhoe_Canonical_Columns::legacy_operands_ready( $missing, $key ), "Missing price passed $key." ); }
	$zero_price = $values; $zero_price['product_price_vat_8'] = 0;
	foreach ( array( 'total_cost_percent', 'discount_percent_vuikhoe', 'platform_cost_percent' ) as $key ) { contract_check( ! Ecomkit_Vuikhoe_Canonical_Columns::legacy_operands_ready( $zero_price, $key ), "Zero denominator passed $key." ); }
	contract_check( Ecomkit_Vuikhoe_Canonical_Columns::legacy_operands_ready( $zero_price, 'total_amount_to_collect' ), 'Subtraction incorrectly rejected zero price.' );
}
$decimal_values = array( 'product_price_vat_8' => '308000.50', 'affiliate_fee_vuikhoe' => '0', 'discount_vuikhoe' => '12108.25', 'fixed_platform_fee' => '50265', 'service_platform_fee' => '3000', 'transaction_platform_fee' => '18481' );
contract_check( Ecomkit_Vuikhoe_Canonical_Columns::legacy_operands_ready( $decimal_values, 'total_cost_percent' ), 'Exact decimal strings were rejected.' );
$decimal_values['discount_vuikhoe'] = 12108.25;
contract_check( ! Ecomkit_Vuikhoe_Canonical_Columns::legacy_operands_ready( $decimal_values, 'total_cost_percent' ), 'Binary float was accepted as an exact operand.' );

$materializer = new Ecomkit_Vuikhoe_Canonical_Result_Materializer();
$order = array( 'platform' => 'SHOPEE', 'matching_status' => 'MATCHED', 'marketplace_order_id' => 'TEST-SHP-001', 'product_price_vat_8' => 308000, 'affiliate_fee_vuikhoe' => 0, 'discount_vuikhoe' => 12108,
	'payment_normalized_data' => json_encode( array( 'marketplaceOrderId' => 'TEST-SHP-001', 'escrowAmountAfterAdjustment' => 218940, 'escrowAmount' => 220000, 'commissionFee' => 50265, 'serviceFee' => 3000, 'sellerTransactionFee' => 18481, 'affiliateCommissionFee' => 5000 ) ) );
$result = $materializer->materialize( $order );
contract_check( 218940 === $result['columns']['total_amount_to_collect'], 'Shopee Escrow lost precedence.' );
foreach ( array( 'product_price_vat_8', 'affiliate_fee_vuikhoe', 'discount_vuikhoe', 'total_cost_percent', 'platform_cost_percent', 'discount_percent_vuikhoe', 'difference_amount' ) as $key ) { contract_check( null === $result['columns'][ $key ], "Unverified source/formula populated $key." ); }
unset( $order['payment_normalized_data'] );
$without_payment = $materializer->materialize( $order );
contract_check( null === $without_payment['columns']['total_amount_to_collect'], 'Legacy fallback was activated without verified persisted operands.' );
contract_check( 24 === count( $result['columns'] ) && 'v3' === $result['version'], 'Result contract changed.' );
echo "WP.6F legacy contract and source gates: PASS\n";
