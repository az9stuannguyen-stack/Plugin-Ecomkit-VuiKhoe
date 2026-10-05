<?php
/** WP.6H.3F: pure TSV projection checks; no WordPress database or provider transport. */
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' );
require __DIR__ . '/../ecomkit-vuikhoe/vendor/autoload.php';

function clipboard_check( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function clipboard_row( array $columns, array $rational = array() ): array {
	return array( 'ready' => true, 'columns' => $columns, 'rational' => $rational );
}
function clipboard_cells( string $line ): array { return explode( "\t", $line ); }

$definitions = Ecomkit_Vuikhoe_Canonical_Columns::all();
clipboard_check( 24 === count( $definitions ), 'Canonical column count changed.' );
$headers = Ecomkit_Vuikhoe_Canonical_Result_Service::clipboard_tsv( array( 'rows' => array() ), true );
clipboard_check( array_column( $definitions, 'label' ) === clipboard_cells( $headers ), 'Header order is not authoritative.' );

$golden = array(
	'order_date' => '2026-09-24', 'eshop_order_code' => 'ĐH007401', 'raw_order_code' => '260924TSBR7FC0', 'sales_channel' => 'SHOPEE',
	'customer_name' => 'Đà Nẵng', 'product_price_vat_8' => 312000, 'affiliate_fee_vuikhoe' => 0,
	'discount_vuikhoe' => 17160, 'fixed_platform_fee' => 51480, 'service_platform_fee' => 5700,
	'transaction_platform_fee' => 18720, 'total_amount_to_collect' => 218940,
	'discount_percent_vuikhoe' => '0.055', 'platform_cost_percent' => '0.243269230769230769', 'total_cost_percent' => '0.298269230769230769',
);
$rational = array(
	'discount_percent_vuikhoe' => array( 'numerator' => '17160', 'denominator' => '312000' ),
	'platform_cost_percent' => array( 'numerator' => '75900', 'denominator' => '312000' ),
	'total_cost_percent' => array( 'numerator' => '93060', 'denominator' => '312000' ),
);
$result = array( 'rows' => array( clipboard_row( $golden, $rational ) ) );
$data = Ecomkit_Vuikhoe_Canonical_Result_Service::clipboard_tsv( $result );
$cells = clipboard_cells( $data );
clipboard_check( 24 === count( $cells ) && 1 === count( explode( "\n", $data ) ), 'Data-only row shape failed.' );
$by_key = array_combine( array_column( $definitions, 'key' ), $cells );
foreach ( array( 'product_price_vat_8' => '312000', 'affiliate_fee_vuikhoe' => '0', 'discount_vuikhoe' => '17160', 'fixed_platform_fee' => '51480', 'service_platform_fee' => '5700', 'transaction_platform_fee' => '18720', 'total_amount_to_collect' => '218940', 'discount_percent_vuikhoe' => '0.055', 'platform_cost_percent' => '0.243269230769230769', 'total_cost_percent' => '0.298269230769230769', 'order_date' => '2026-09-24', 'vat_issued_date' => '' ) as $key => $expected ) {
	clipboard_check( $expected === $by_key[ $key ], "Golden clipboard value wrong: $key" );
}
clipboard_check( ! str_contains( $data, '312,000' ) && ! str_contains( $data, '29.83%' ) && ! str_contains( $data, '—' ), 'Presentation format leaked.' );
$with_header = Ecomkit_Vuikhoe_Canonical_Result_Service::clipboard_tsv( $result, true );
clipboard_check( $headers . "\n" . $data === $with_header, 'Header mode failed.' );

$text = $golden;
$text['customer_name'] = '<b>=2+2</b>';
$text['phone'] = '+12345';
$text['address'] = "@SUM(1)\tline\nnext";
$text['province_city'] = '&#61;1+1';
$text['note'] = "<span style=\"color:red\">Hello</span>\tworld";
$text['eshop_order_code'] = '-1+2';
$sanitized = array_combine( array_column( $definitions, 'key' ), clipboard_cells( Ecomkit_Vuikhoe_Canonical_Result_Service::clipboard_tsv( array( 'rows' => array( clipboard_row( $text, $rational ) ) ) ) ) );
foreach ( array( 'customer_name' => "'=2+2", 'phone' => "'+12345", 'address' => "'@SUM(1) line next", 'province_city' => "'=1+1", 'eshop_order_code' => "'-1+2", 'note' => 'Hello world' ) as $key => $expected ) {
	clipboard_check( $expected === $sanitized[ $key ], "Unsafe text projection: $key" );
}
clipboard_check( ! str_contains( implode( '', $sanitized ), '<' ) && ! str_contains( implode( '', $sanitized ), 'style=' ), 'HTML/CSS leaked.' );

$filtered = array( 'rows' => array( clipboard_row( $golden, $rational ) ) );
clipboard_check( ! str_contains( Ecomkit_Vuikhoe_Canonical_Result_Service::clipboard_tsv( $filtered ), 'OTHER-ORDER' ), 'Filtered result leaked another order.' );
$many = array( 'rows' => array_fill( 0, 1000, clipboard_row( $golden, $rational ) ) );
$lines = explode( "\n", Ecomkit_Vuikhoe_Canonical_Result_Service::clipboard_tsv( $many ) );
clipboard_check( 1000 === count( $lines ), '1000-row copy truncated.' );
foreach ( $lines as $line ) { clipboard_check( 24 === count( clipboard_cells( $line ) ), 'Large-batch row shape changed.' ); }

$admin = file_get_contents( __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-admin.php' );
$view = file_get_contents( __DIR__ . '/../ecomkit-vuikhoe/admin/views/results.php' );
clipboard_check( 1 === preg_match( '/function results_page\(\): void\s*\{\s*Ecomkit_Vuikhoe_Security::require_use_capability\(\)/', $admin ), 'Result permission guard missing.' );
clipboard_check( str_contains( $admin, 'get_batch_result( $batch_id, $platform, $matching )' ), 'Result filters are not applied before clipboard projection.' );
clipboard_check( str_contains( $view, 'navigator.clipboard.writeText' ) && ! str_contains( $view, 'navigator.clipboard.write(' ), 'Plain-text clipboard API missing.' );
clipboard_check( str_contains( $view, 'clipboard_tsv( $result )' ) && ! str_contains( $view, 'innerHTML' ), 'Clipboard is not projected from canonical rows.' );
echo "clipboard-result-check: PASS\n";
