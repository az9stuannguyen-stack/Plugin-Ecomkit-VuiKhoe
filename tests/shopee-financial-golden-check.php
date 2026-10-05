<?php
/** WP.6H.3: golden accounting fixture; Excel supplies the internal VAT price and discount. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' );
require __DIR__ . '/../ecomkit-vuikhoe/vendor/autoload.php';
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-shopee-payment-normalizer.php';
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-money-formatter.php';
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function golden_check( bool $ok, string $message ): void { if ( ! $ok ) { throw new RuntimeException( $message ); } }

$id = '260924TSBR7FC0';
$income = array(
	'order_selling_price' => 312000, 'commission_fee' => 51480, 'service_fee' => 20160,
	'shipping_seller_protection_fee_amount' => 2700, 'seller_transaction_fee' => 18720,
	'escrow_amount_after_adjustment' => 218940,
);
$raw = array( 'order_sn' => $id, 'order_income' => $income );
$normalized = Ecomkit_Vuikhoe_Shopee_Payment_Normalizer::normalize( $raw );
$order = array(
	'platform' => 'SHOPEE', 'matching_status' => 'MATCHED', 'marketplace_order_id' => $id,
	'product_price_vat_8' => '312000', 'affiliate_fee_vuikhoe' => '0', 'discount_vuikhoe' => '17160',
	'payment_raw_data' => json_encode( $raw ), 'payment_normalized_data' => json_encode( $normalized ),
);
$projection = ( new Ecomkit_Vuikhoe_Canonical_Result_Materializer() )->materialize( $order );
$c = $projection['columns'];
golden_check( 'v6' === $projection['version'] && 24 === count( $c ), 'Canonical version/column count changed.' );
foreach ( array( 'product_price_vat_8' => '312000', 'affiliate_fee_vuikhoe' => '0', 'discount_vuikhoe' => '17160', 'service_platform_fee' => '5700' ) as $key => $expected ) {
	golden_check( $expected === $c[ $key ], "Golden $key mismatch." );
}
golden_check( 51480 === $c['fixed_platform_fee'] && 18720 === $c['transaction_platform_fee'] && 218940 === $c['total_amount_to_collect'], 'Direct Payment/Escrow mapping mismatch.' );
golden_check( '75900' === $projection['rational']['platform_cost_percent']['numerator'] && '312000' === $projection['rational']['platform_cost_percent']['denominator'], 'Platform ratio changed.' );
golden_check( '93060' === $projection['rational']['total_cost_percent']['numerator'] && '312000' === $projection['rational']['total_cost_percent']['denominator'], 'Total-cost ratio changed.' );
golden_check( '0.055' === $c['discount_percent_vuikhoe'], 'Discount ratio changed.' );
golden_check( '218940' === Ecomkit_Vuikhoe_Exact_Financial_Math::subtract( '312000', '93060' ), 'Exact fallback/escrow parity failed.' );
golden_check( '17,160' === Ecomkit_Vuikhoe_Money_Formatter::format_exact( $c['discount_vuikhoe'] ) && '5,700' === Ecomkit_Vuikhoe_Money_Formatter::format_exact( $c['service_platform_fee'] ) && '218,940' === Ecomkit_Vuikhoe_Money_Formatter::format_exact( $c['total_amount_to_collect'] ), 'Exact money display changed.' );
golden_check( '5.5%' === Ecomkit_Vuikhoe_Exact_Financial_Math::format_percent( $projection['rational']['discount_percent_vuikhoe'] ), 'Discount percent display changed.' );
golden_check( 20160 === $normalized['serviceFee'] && 2700 === $normalized['shippingSellerProtectionFeeAmount'] && 312000 === $normalized['orderSellingPrice'], 'Provider evidence was changed.' );

// Seller vouchers and Shopee vouchers are not proven to be Vui Khỏe Voucher Xtra.
$without_internal = $order;
$without_internal['discount_vuikhoe'] = null;
$without_internal['product_price_vat_8'] = null;
$with_voucher_like_fields = $raw;
$with_voucher_like_fields['order_income']['voucher_from_shopee'] = 17160;
$with_voucher_like_fields['order_income']['voucher_from_seller'] = 17160;
$without_internal['payment_raw_data'] = json_encode( $with_voucher_like_fields );
$without_internal['payment_normalized_data'] = json_encode( Ecomkit_Vuikhoe_Shopee_Payment_Normalizer::normalize( $with_voucher_like_fields ) );
$unverified = ( new Ecomkit_Vuikhoe_Canonical_Result_Materializer() )->materialize( $without_internal )['columns'];
golden_check( null === $unverified['product_price_vat_8'] && null === $unverified['discount_vuikhoe'] && null === $unverified['service_platform_fee'], 'Unproven provider price/voucher was promoted to canonical.' );
echo "WP.6H.3 golden accounting fixture: PASS (Excel price/discount; provider Voucher Xtra unproven)\n";
