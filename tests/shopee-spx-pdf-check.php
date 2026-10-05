<?php
/** WP.6H.3H synthetic SPX PDF, temporary-file, status and clipboard gates. */
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' );
require __DIR__ . '/../ecomkit-vuikhoe/vendor/autoload.php';
function spx_check( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function wp_max_upload_size(): int { return 10 * 1024 * 1024; }
function current_user_can( string $capability ): bool { return (bool) ( $GLOBALS['spx_test_admin'] ?? false ); }
function esc_html__( string $value, string $domain ): string { return $value; }
function wp_die( string $message, string $title, array $args ): never { throw new RuntimeException( 'DENIED_' . (string) $args['response'] ); }
function check_ajax_referer( string $action, string $query_arg, bool $die ): bool { return false; }
function wp_send_json_error( array $payload, int $status ): never { throw new RuntimeException( 'JSON_ERROR_' . $status ); }
function spx_blank_pdf( int $pages ): string {
	$objects = array( 1 => '<< /Type /Catalog /Pages 2 0 R >>' );
	$kids = array(); $content_id = $pages + 3;
	for ( $number = 0; $number < $pages; $number++ ) {
		$id = $number + 3; $kids[] = "$id 0 R";
		$objects[ $id ] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 297 419] /Contents $content_id 0 R >>";
	}
	$objects[2] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . "] /Count $pages >>";
	$objects[ $content_id ] = "<< /Length 0 >>\nstream\n\nendstream";
	ksort( $objects ); $pdf = "%PDF-1.4\n"; $offsets = array( 0 );
	foreach ( $objects as $id => $body ) { $offsets[ $id ] = strlen( $pdf ); $pdf .= "$id 0 obj\n$body\nendobj\n"; }
	$xref = strlen( $pdf ); $pdf .= 'xref' . "\n0 " . ( count( $objects ) + 1 ) . "\n0000000000 65535 f \n";
	for ( $id = 1; $id <= count( $objects ); $id++ ) { $pdf .= sprintf( '%010d 00000 n ', $offsets[ $id ] ) . "\n"; }
	return $pdf . "trailer\n<< /Size " . ( count( $objects ) + 1 ) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
}

$fixture = __DIR__ . '/fixtures/spx-synthetic-10-pages.pdf';
spx_check( is_file( $fixture ), 'Synthetic fixture missing.' );
$parsed = Ecomkit_Vuikhoe_Shopee_SPX_Labels::parse_file( $fixture );
spx_check( 10 === $parsed['pages'] && 7 === $parsed['parsed'], 'Ten-page SPX extraction failed.' );
spx_check( 1 === $parsed['duplicate_conflicts'] && ! isset( $parsed['labels']['261005SFFHEMBC'] ), 'Conflicting duplicate enriched.' );
spx_check( 1 === $parsed['page_status_counts']['NO_RECIPIENT'] && 1 === $parsed['page_status_counts']['NO_ORDER_ID'] && 1 === $parsed['page_status_counts']['UNSUPPORTED_PAGE'], 'Bad page classifications failed.' );
spx_check( 4 === count( $parsed['labels'] ) && 'Mai Example' === $parsed['labels']['261005S50BGX90']['name'], 'Same-value duplicate did not deduplicate.' );
spx_check( 'Nguyễn Thị Ví Dụ' === $parsed['labels']['261005TEST0001']['name'], 'Vietnamese Unicode name changed.' );
spx_check( str_contains( $parsed['labels']['261005S50BGX90']['address'], 'Phường Thử Nghiệm, Đà Nẵng' ) && ! str_contains( $parsed['labels']['261005S50BGX90']['address'], "\n" ), 'Wrapped recipient address failed.' );
spx_check( ! isset( $parsed['labels']['SPXVN00000000000A'] ), 'Tracking number used as order identity.' );

$page_text = "SPXVN00000000000A\nMã đơn hàng:\n261005S50BGX90";
$positions = array(
	array( array( 1, 0, 0, 1, 151, 340 ), 'Đến:' ),
	array( array( 1, 0, 0, 1, 151, 326 ), 'Test Recipient' ),
	array( array( 1, 0, 0, 1, 151, 315 ), 'Số 1 Đường Mẫu,' ),
	array( array( 1, 0, 0, 1, 151, 304 ), 'Hà Nội' ),
	array( array( 1, 0, 0, 1, 10, 326 ), 'Sender Must Not Be Recipient' ),
);
$one = Ecomkit_Vuikhoe_Shopee_SPX_Labels::parse_page_evidence( $page_text, $positions );
spx_check( 'PARSED' === $one['status'] && 'Test Recipient' === $one['name'] && 'Số 1 Đường Mẫu, Hà Nội' === $one['address'], 'One-page recipient pane failed.' );
spx_check( 'NO_ORDER_ID' === Ecomkit_Vuikhoe_Shopee_SPX_Labels::parse_page_evidence( "SPXVN00000000000A\nMã đơn hàng:", $positions )['status'], 'Missing order ID guessed.' );
spx_check( 'NO_RECIPIENT' === Ecomkit_Vuikhoe_Shopee_SPX_Labels::parse_page_evidence( $page_text, array() )['status'], 'Missing recipient guessed.' );
spx_check( 'UNSUPPORTED_PAGE' === Ecomkit_Vuikhoe_Shopee_SPX_Labels::parse_page_evidence( 'Another PDF', $positions )['status'], 'Non-SPX page accepted.' );

$columns = array( 'raw_order_code' => '261005S50BGX90' );
$result = array( 'rows' => array(
	array( 'platform' => 'SHOPEE', 'columns' => $columns ),
	array( 'platform' => 'SHOPEE', 'columns' => array( 'raw_order_code' => '261005TEST0001' ) ),
	array( 'platform' => 'LAZADA', 'columns' => array( 'raw_order_code' => '261005EXTRA001' ) ),
) );
$temp = tempnam( sys_get_temp_dir(), 'spx-test-' ); copy( $fixture, $temp );
$upload = array( 'name' => 'labels.pdf', 'tmp_name' => $temp, 'error' => UPLOAD_ERR_OK );
$summary = Ecomkit_Vuikhoe_Shopee_SPX_Labels::process_temporary_upload( $upload, $result, false );
spx_check( ! is_file( $temp ), 'Successful parse retained temporary PDF.' );
spx_check( 2 === $summary['matched'] && 2 === $summary['unmatched'] && 2 === $summary['names_enriched'] && 2 === $summary['addresses_enriched'], 'Exact Result matching/partial coverage failed.' );
spx_check( ! isset( $summary['enrichment']['261005SFFHEMBC'] ) && ! isset( $summary['enrichment']['261005EXTRA001'] ), 'Conflict/extra order enriched.' );
spx_check( ! isset( $summary['enrichment']['261005S50BGX90']['phone'] ), 'PDF invented a phone number.' );

$wrong = tempnam( sys_get_temp_dir(), 'spx-test-' ); file_put_contents( $wrong, '%PDF-actually not a readable PDF' );
try { Ecomkit_Vuikhoe_Shopee_SPX_Labels::process_temporary_upload( array( 'name' => 'labels.pdf', 'tmp_name' => $wrong, 'error' => 0 ), $result, false ); throw new RuntimeException( 'Malformed PDF accepted.' ); }
catch ( RuntimeException $exception ) { spx_check( 'SHOPEE_PDF_INVALID' === $exception->getMessage(), 'Malformed PDF error changed.' ); }
spx_check( ! is_file( $wrong ), 'Parser failure retained temporary PDF.' );
$disguised = tempnam( sys_get_temp_dir(), 'spx-test-' ); file_put_contents( $disguised, 'not a PDF' );
try { Ecomkit_Vuikhoe_Shopee_SPX_Labels::process_temporary_upload( array( 'name' => 'labels.pdf', 'tmp_name' => $disguised, 'error' => 0 ), $result, false ); throw new RuntimeException( 'Disguised PDF accepted.' ); }
catch ( RuntimeException $exception ) { spx_check( 'SHOPEE_PDF_INVALID' === $exception->getMessage(), 'Disguised file error changed.' ); }
spx_check( ! is_file( $disguised ), 'Disguised PDF temp remained.' );
$oversized = tempnam( sys_get_temp_dir(), 'spx-test-' ); $stream = fopen( $oversized, 'wb' ); ftruncate( $stream, Ecomkit_Vuikhoe_Shopee_SPX_Labels::MAX_BYTES + 1 ); fclose( $stream );
try { Ecomkit_Vuikhoe_Shopee_SPX_Labels::process_temporary_upload( array( 'name' => 'labels.pdf', 'tmp_name' => $oversized, 'error' => 0 ), $result, false ); throw new RuntimeException( 'Oversized PDF accepted.' ); }
catch ( RuntimeException $exception ) { spx_check( 'SHOPEE_PDF_TOO_LARGE' === $exception->getMessage(), 'Oversize error changed.' ); }
spx_check( ! is_file( $oversized ), 'Oversized temporary PDF retained.' );
$blank = tempnam( sys_get_temp_dir(), 'spx-test-' ); file_put_contents( $blank, spx_blank_pdf( 1 ) );
try { Ecomkit_Vuikhoe_Shopee_SPX_Labels::process_temporary_upload( array( 'name' => 'blank.pdf', 'tmp_name' => $blank, 'error' => 0 ), $result, false ); throw new RuntimeException( 'Blank PDF accepted.' ); }
catch ( RuntimeException $exception ) { spx_check( 'SHOPEE_PDF_TEXT_LAYER_REQUIRED' === $exception->getMessage(), 'Missing text layer error changed.' ); }
spx_check( ! is_file( $blank ), 'Blank PDF temporary file retained.' );
$too_many = tempnam( sys_get_temp_dir(), 'spx-test-' ); file_put_contents( $too_many, spx_blank_pdf( 51 ) );
try { Ecomkit_Vuikhoe_Shopee_SPX_Labels::process_temporary_upload( array( 'name' => 'many.pdf', 'tmp_name' => $too_many, 'error' => 0 ), $result, false ); throw new RuntimeException( '51-page PDF accepted.' ); }
catch ( RuntimeException $exception ) { spx_check( 'SHOPEE_PDF_TOO_MANY_PAGES' === $exception->getMessage(), 'Page limit error changed.' ); }
spx_check( ! is_file( $too_many ), 'Over-page temporary PDF retained.' );

spx_check( 'Giao Hàng Thành Công' === Ecomkit_Vuikhoe_Shopee_Business_Status::resolve( 'SHOPEE', 'COMPLETED' )['label'], 'COMPLETED label wrong.' );
spx_check( 'Đang Giao Hàng' === Ecomkit_Vuikhoe_Shopee_Business_Status::resolve( 'SHOPEE', 'SHIPPED' )['label'], 'SHIPPED label wrong.' );
foreach ( array( 'CANCELLED', 'PROCESSED', 'IN_CANCEL', 'RETURNED' ) as $unknown ) {
	$resolved = Ecomkit_Vuikhoe_Shopee_Business_Status::resolve( 'SHOPEE', $unknown );
	spx_check( $unknown === $resolved['label'] && 'UNMAPPED_SHOPEE_BUSINESS_STATUS' === $resolved['diagnostic'], 'Unsupported business state guessed.' );
}
spx_check( 'COMPLETED' === Ecomkit_Vuikhoe_Shopee_Business_Status::resolve( 'LAZADA', 'COMPLETED' )['label'], 'Shopee label leaked to Lazada.' );

$fields = array( 'raw_order_code' => '261005S50BGX90', 'sales_channel' => 'SHOPEE', 'order_status' => 'COMPLETED', 'customer_name' => 'Existing Name', 'phone' => '09***', 'address' => 'Existing Address', 'product_price_vat_8' => 312000, 'affiliate_fee_vuikhoe' => 0, 'discount_vuikhoe' => 17160, 'fixed_platform_fee' => 51480, 'service_platform_fee' => 5700, 'transaction_platform_fee' => 18720, 'total_amount_to_collect' => 218940, 'discount_percent_vuikhoe' => '0.055' );
$tsv = Ecomkit_Vuikhoe_Canonical_Result_Service::clipboard_tsv( array( 'rows' => array( array( 'platform' => 'SHOPEE', 'columns' => $fields, 'rational' => array( 'discount_percent_vuikhoe' => array( 'numerator' => '17160', 'denominator' => '312000' ) ) ) ) ) );
$cells = explode( "\t", $tsv );
spx_check( 24 === count( $cells ) && 'Giao Hàng Thành Công' === $cells[4] && '09***' === $cells[6] && '312000' === $cells[14] && '0' === $cells[17] && '0.055' === $cells[19], '24-column clipboard/status/financial regression failed.' );
spx_check( "'=HYPERLINK(1)" === Ecomkit_Vuikhoe_Canonical_Result_Service::clipboard_text( "=HYPERLINK(1)\n" ) && "'@SUM(1) line" === Ecomkit_Vuikhoe_Canonical_Result_Service::clipboard_text( "@SUM(1)\tline" ), 'PDF formula text not neutralized.' );

$admin = file_get_contents( __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-admin.php' );
$view = file_get_contents( __DIR__ . '/../ecomkit-vuikhoe/admin/views/results.php' );
$parser = file_get_contents( __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-shopee-spx-labels.php' );
spx_check( str_contains( $admin, "wp_ajax_ecomkit_shopee_spx_pdf" ) && str_contains( $admin, "check_ajax_referer( 'ecomkit_shopee_spx_pdf'" ), 'PDF endpoint nonce missing.' );
spx_check( 1 === preg_match( '/function handle_shopee_spx_pdf\(\): void\s*\{\s*Ecomkit_Vuikhoe_Security::require_management_capability\(\)/', $admin ), 'PDF endpoint capability missing.' );
try { ( new Ecomkit_Vuikhoe_Admin() )->handle_shopee_spx_pdf(); throw new RuntimeException( 'Unauthorized PDF request accepted.' ); }
catch ( RuntimeException $exception ) { spx_check( 'DENIED_403' === $exception->getMessage(), 'Unauthorized PDF request did not return 403.' ); }
$GLOBALS['spx_test_admin'] = true;
try { ( new Ecomkit_Vuikhoe_Admin() )->handle_shopee_spx_pdf(); throw new RuntimeException( 'Invalid nonce accepted.' ); }
catch ( RuntimeException $exception ) { spx_check( 'JSON_ERROR_403' === $exception->getMessage(), 'Invalid nonce did not return 403.' ); }
preg_match( '/public function handle_shopee_spx_pdf\(\): void.*?(?=\n\tpublic function|\z)/s', $admin, $handler );
spx_check( ! preg_match( '/wp_insert_attachment|media_handle_upload|wp_upload_bits|localStorage|sessionStorage|indexedDB|set_transient|update_option|\$wpdb|wp_remote_/', ( $handler[0] ?? '' ) . $parser . $view ), 'PDF flow may persist PII or call provider.' );
spx_check( str_contains( $view, "enrichment.clear()" ) && str_contains( $view, "cells[5] = recipient.name; cells[7] = recipient.address" ) && ! str_contains( $view, 'cells[6] = recipient' ), 'Clipboard merge/clear/phone isolation missing.' );

echo "shopee-spx-pdf-check: PASS\n";
