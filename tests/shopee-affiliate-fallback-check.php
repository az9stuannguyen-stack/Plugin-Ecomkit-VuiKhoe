<?php
/** WP.6H.3E: exact Affiliate evidence and ratio gates; no provider transport. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' );
require __DIR__ . '/../ecomkit-vuikhoe/vendor/autoload.php';
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-shopee-payment-normalizer.php';
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function affiliate_check( bool $ok, string $message ): void { if ( ! $ok ) { throw new RuntimeException( $message ); } }
$id = '260924TSBR7FC0';
$income = array( 'order_selling_price' => 312000, 'commission_fee' => 51480, 'service_fee' => 20160, 'shipping_seller_protection_fee_amount' => 2700, 'seller_transaction_fee' => 18720, 'escrow_amount_after_adjustment' => 218940, 'order_ams_commission_fee' => 0, 'voucher_from_shopee' => 50000 );
$payment = Ecomkit_Vuikhoe_Shopee_Payment_Normalizer::normalize( array( 'order_sn' => $id, 'order_income' => $income ) );
affiliate_check( 0 === $payment['affiliateCommissionFee'] && 50000 === $payment['voucherFromShopee'], 'Raw affiliate/voucher normalization source changed.' );
$order = array( 'platform' => 'SHOPEE', 'matching_status' => 'MATCHED', 'marketplace_order_id' => $id, 'affiliate_fee_vuikhoe' => null, 'discount_vuikhoe' => null, 'product_price_vat_8' => null, 'payment_normalized_data' => json_encode( $payment ) );
$m = new Ecomkit_Vuikhoe_Canonical_Result_Materializer();
$golden = $m->materialize( $order ); $c = $golden['columns'];
affiliate_check( 'v9' === $golden['version'] && 24 === count( $c ) && '312000' === $c['product_price_vat_8'] && '0' === $c['affiliate_fee_vuikhoe'] && '17160' === $c['discount_vuikhoe'], 'Golden source fallback/shape failed.' );
affiliate_check( 51480 === $c['fixed_platform_fee'] && '5700' === $c['service_platform_fee'] && 18720 === $c['transaction_platform_fee'] && 218940 === $c['total_amount_to_collect'], 'Golden Payment components failed.' );
affiliate_check( 'SHOPEE_OPENAPI_PAYMENT/affiliateCommissionFee' === $golden['source_metadata']['affiliateVuikhoe']['source'], 'Provider zero lost provenance.' );
affiliate_check( '17160' === $golden['rational']['discount_percent_vuikhoe']['numerator'] && '0.055' === $c['discount_percent_vuikhoe'] && '75900' === $golden['rational']['platform_cost_percent']['numerator'] && '93060' === $golden['rational']['total_cost_percent']['numerator'], 'Golden exact numerators/ratio failed.' );
affiliate_check( '5.50%' === Ecomkit_Vuikhoe_Exact_Financial_Math::format_percent_two_decimals( $golden['rational']['discount_percent_vuikhoe'] ) && '24.33%' === Ecomkit_Vuikhoe_Exact_Financial_Math::format_percent_two_decimals( $golden['rational']['platform_cost_percent'] ) && '29.83%' === Ecomkit_Vuikhoe_Exact_Financial_Math::format_percent_two_decimals( $golden['rational']['total_cost_percent'] ), 'Golden display failed.' );
affiliate_check( '218940' === Ecomkit_Vuikhoe_Exact_Financial_Math::subtract( '312000', '93060' ), 'Accounting identity failed.' );
$income['order_ams_commission_fee'] = 12000; $payment = Ecomkit_Vuikhoe_Shopee_Payment_Normalizer::normalize( array( 'order_sn' => $id, 'order_income' => $income ) ); $order['payment_normalized_data'] = json_encode( $payment );
$with_affiliate = $m->materialize( $order );
affiliate_check( '12000' === $with_affiliate['columns']['affiliate_fee_vuikhoe'] && '29160' === $with_affiliate['rational']['discount_percent_vuikhoe']['numerator'] && '105060' === $with_affiliate['rational']['total_cost_percent']['numerator'], 'Nonzero affiliate did not participate in exact ratios.' );
$excel = $order; $excel['affiliate_fee_vuikhoe'] = '15000'; $override = $m->materialize( $excel );
affiliate_check( '15000' === $override['columns']['affiliate_fee_vuikhoe'] && 'EXCEL/Phí Affiliate (Vui Khỏe)' === $override['source_metadata']['affiliateVuikhoe']['source'] && array( 'excel_affiliate' => '15000', 'provider_affiliate' => '12000' ) === $override['source_metadata']['affiliateVuikhoe']['discrepancy'], 'Excel precedence/discrepancy failed.' );
$excel['affiliate_fee_vuikhoe'] = '0'; affiliate_check( '0' === $m->materialize( $excel )['columns']['affiliate_fee_vuikhoe'], 'Explicit Excel zero lost.' );
$missing = $order; unset( $missing['payment_normalized_data'] ); $no_payment = $m->materialize( $missing );
affiliate_check( null === $no_payment['columns']['affiliate_fee_vuikhoe'] && 'NULL_NO_VERIFIED_SOURCE' === $no_payment['source_metadata']['affiliateVuikhoe']['source'] && null === $no_payment['columns']['discount_percent_vuikhoe'], 'Missing Payment became zero.' );
$wrong = $order; $wrong['payment_normalized_data'] = json_encode( array_merge( $payment, array( 'marketplaceOrderId' => 'OTHER' ) ) ); affiliate_check( null === $m->materialize( $wrong )['columns']['affiliate_fee_vuikhoe'], 'Other order affiliate leaked.' );
$zero_price = $order; $zero_price['product_price_vat_8'] = '0'; affiliate_check( null === $m->materialize( $zero_price )['columns']['discount_percent_vuikhoe'], 'Zero product divided.' );
$missing_service = $order; $p = $payment; $p['serviceFee'] = null; $missing_service['payment_normalized_data'] = json_encode( $p ); affiliate_check( null === $m->materialize( $missing_service )['columns']['total_cost_percent'], 'Missing service did not gate total cost.' );
$returned = $order; $p = $payment; $p['serviceFee'] = 0; $p['affiliateCommissionFee'] = 0; $returned['payment_normalized_data'] = json_encode( $p ); $cancelled = $m->materialize( $returned );
affiliate_check( '0' === $cancelled['columns']['affiliate_fee_vuikhoe'] && '0' === $cancelled['columns']['discount_vuikhoe'] && '2700' === $cancelled['columns']['service_platform_fee'], 'Return/cancel zero rule failed.' );
echo "WP.6H.3E affiliate and cost ratios: PASS\n";
