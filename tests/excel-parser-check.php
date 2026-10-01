<?php
/** Synthetic WP.2A Excel fixtures. Contains no customer or production data. */
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' );
require __DIR__ . '/../ecomkit-vuikhoe/vendor/autoload.php';

function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function fixture( callable $build ): string {
	$book = new Spreadsheet(); $build( $book ); $path = tempnam( sys_get_temp_dir(), 'ecomkit-xlsx-' );
	if ( false === $path ) { throw new RuntimeException( 'Cannot allocate fixture.' ); }
	( new Xlsx( $book ) )->save( $path ); $book->disconnectWorksheets(); return $path;
}
function codes( array $result ): array { return array_column( $result['errors'], 'error_code' ); }

$parser = new Ecomkit_Vuikhoe_Excel_Service();
$paths = array();
try {
	$paths[] = $production = fixture( static function ( Spreadsheet $book ): void {
		$sheet = $book->getActiveSheet(); $sheet->setTitle( 'Đơn thử nghiệm' );
		$sheet->mergeCells( 'A1:G1' ); $sheet->setCellValue( 'A1', 'DANH SÁCH LẤY HÀNG TEST' );
		$sheet->fromArray( array(
			array( 'STT', ' Sàn   & Mã Đơn ', 'Ngày đặt', 'Mã đơn hàng eShop', 'Mã hàng hóa', 'Tên hàng hóa', 'Số lg' ),
			array( 1, "Shopee\r\nTEST-SHP-001", '01/01/2026 10:00', 'TEST001', 'SKU-A', 'Sản phẩm A', 1 ),
			array( 2, "Shopee\nTEST-SHP-002", '01/01/2026 10:05', 'TEST002', 'SKU-B', 'Sản phẩm B', 1 ),
			array( null, null, null, null, 'SKU-C', 'Sản phẩm C', 2 ),
			array( 3, "Lazada\r000123456789", '01/01/2026 10:10', 'TEST003', 'SKU-D', 'Sản phẩm D', 1 ),
		), null, 'A3' );
	} );
	$result = $parser->parse( $production );
	assert_true( 'SUCCESS' === $result['status'], 'Production fixture must succeed.' );
	assert_true( 3 === $result['raw']['header_row'], 'Header after title rows was not discovered.' );
	assert_true( 3 === count( $result['orders'] ) && 4 === $result['raw']['item_rows'], 'Order/item counts are wrong.' );
	assert_true( 'SHOPEE' === $result['orders'][0]['platform'] && 'TEST-SHP-001' === $result['orders'][0]['order_code'], 'Shopee combined cell failed.' );
	assert_true( 'TEST-SHP-001' !== 'TEST001' && 'TEST-SHP-001' === $result['orders'][0]['order_code'], 'eShop code was used as identity.' );
	assert_true( 2 === count( $result['orders'][1]['items'] ), 'Continuation row did not attach to one order.' );
	assert_true( 'LAZADA' === $result['orders'][2]['platform'] && '000123456789' === $result['orders'][2]['order_code'], 'Lazada leading-zero ID changed.' );
	assert_true( array() === codes( $result ), 'Valid continuation generated an error.' );

	$paths[] = $orphan = fixture( static function ( Spreadsheet $book ): void {
		$book->getActiveSheet()->fromArray( array( array( 'Sàn & Mã Đơn', 'Mã hàng hóa', 'Tên hàng hóa', 'Số lg' ), array( null, 'SKU-X', 'Sản phẩm X', 1 ) ) );
	} );
	assert_true( 'EXCEL_ORPHAN_ITEM_ROW' === $parser->parse( $orphan )['errors'][0]['error_code'], 'Orphan item was not rejected.' );

	$paths[] = $duplicates = fixture( static function ( Spreadsheet $book ): void {
		$book->getActiveSheet()->fromArray( array( array( 'Sàn & Mã Đơn' ), array( "Shopee\nSAME" ), array( "Lazada\nSAME" ), array( "Shopee\nSAME" ) ) );
	} );
	$duplicate_result = $parser->parse( $duplicates );
	assert_true( 2 === count( $duplicate_result['orders'] ) && 'EXCEL_DUPLICATE_ORDER_CODE' === $duplicate_result['errors'][0]['error_code'], 'Platform-scoped duplicate semantics failed.' );

	$paths[] = $unknown = fixture( static fn( Spreadsheet $book ) => $book->getActiveSheet()->fromArray( array( array( 'Sàn & Mã Đơn' ), array( "Unknown\nABC" ) ) ) );
	assert_true( 'EXCEL_UNKNOWN_PLATFORM' === $parser->parse( $unknown )['errors'][0]['error_code'], 'Unknown platform was guessed.' );

	$paths[] = $missing = fixture( static fn( Spreadsheet $book ) => $book->getActiveSheet()->fromArray( array( array( 'DANH SÁCH TEST' ), array( 'Mã đơn hàng eShop' ), array( 'TEST001' ) ) ) );
	$missing_result = $parser->parse( $missing );
	assert_true( 'EXCEL_REQUIRED_COLUMN_MISSING' === $missing_result['errors'][0]['error_code'] && str_contains( $missing_result['errors'][0]['message'], 'Sàn & Mã Đơn' ), 'Missing source-header message is wrong.' );

	$paths[] = $ambiguous = fixture( static fn( Spreadsheet $book ) => $book->getActiveSheet()->fromArray( array( array( 'Sàn & Mã Đơn', 'Mã đơn sàn' ) ) ) );
	assert_true( 'EXCEL_IDENTITY_COLUMN_AMBIGUOUS' === $parser->parse( $ambiguous )['errors'][0]['error_code'], 'Ambiguous identity columns were silently selected.' );

	$paths[] = $legacy = fixture( static fn( Spreadsheet $book ) => $book->getActiveSheet()->fromArray( array( array( 'Mã đơn sàn' ), array( 'LEGACY-001' ) ) ) );
	assert_true( 'LEGACY-001' === $parser->parse( $legacy )['orders'][0]['order_code'], 'Safe legacy source support regressed.' );

	echo "WP.2A Excel parser checks passed.\n";
} finally { foreach ( $paths as $path ) { @unlink( $path ); } }
