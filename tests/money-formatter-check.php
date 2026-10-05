<?php
/** WP.6C.1 exact money presentation; no DB, WordPress or provider transport. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' );
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-money-formatter.php';
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-canonical-columns.php';
$cases = array(
	array( 218940, '218,940' ), array( 51480, '51,480' ), array( 20160, '20,160' ), array( 18720, '18,720' ),
	array( 828060, '828,060' ), array( 191070, '191,070' ), array( 66690, '66,690' ), array( 69480, '69,480' ),
	array( 24589, '24,589' ), array( 300000, '300,000' ), array( 0, '0' ), array( '0', '0' ),
	array( null, null ), array( '-12500', '-12,500' ), array( '24589.50', '24,589.50' ),
	array( '24589.5', '24,589.5' ), array( '24589.987', '24,589.987' ),
	array( '999999999999999999999999.0001', '999,999,999,999,999,999,999,999.0001' ),
);
foreach ( $cases as [ $input, $expected ] ) {
	if ( $expected !== Ecomkit_Vuikhoe_Money_Formatter::format_exact( $input ) ) { throw new RuntimeException( 'Exact money display failed for ' . var_export( $input, true ) ); }
}
$columns = Ecomkit_Vuikhoe_Canonical_Columns::all();
$money = array_values( array_filter( $columns, static fn( array $column ): bool => Ecomkit_Vuikhoe_Canonical_Columns::is_money( $column['key'] ) ) );
$expected_keys = array( 'amount_collected', 'difference_amount', 'product_price_vat_8', 'total_amount_to_collect', 'affiliate_fee_vuikhoe', 'discount_vuikhoe', 'fixed_platform_fee', 'service_platform_fee', 'transaction_platform_fee' );
if ( 24 !== count( $columns ) || $expected_keys !== array_column( $money, 'key' ) || 'v8' !== Ecomkit_Vuikhoe_Canonical_Columns::VERSION ) { throw new RuntimeException( 'Money column classification changed.' ); }
foreach ( array( 'total_cost_percent', 'discount_percent_vuikhoe', 'platform_cost_percent', 'eshop_order_code', 'raw_order_code' ) as $key ) { if ( Ecomkit_Vuikhoe_Canonical_Columns::is_money( $key ) ) { throw new RuntimeException( 'Non-money column classified as money.' ); } }
$view = file_get_contents( __DIR__ . '/../ecomkit-vuikhoe/admin/views/results.php' );
if ( ! str_contains( $view, 'Ecomkit_Vuikhoe_Money_Formatter::format_exact( $value )' ) || ! str_contains( $view, 'esc_html( (string) $display )' ) ) { throw new RuntimeException( 'Result UI exact formatting or output escaping missing.' ); }
echo "WP.6C.1 exact money formatter checks passed.\n";
