<?php
/** Synthetic WP.2A Excel fixtures. Contains no customer or production data. */
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' );
function wp_timezone(): DateTimeZone { return new DateTimeZone( 'Asia/Bangkok' ); }
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

/** @param int[] $order_rows @param array<int,string> $platforms */
function production_shape( int $last_data_row, array $order_rows, array $platforms ): callable {
	return static function ( Spreadsheet $book ) use ( $last_data_row, $order_rows, $platforms ): void {
		$sheet = $book->getActiveSheet();
		$sheet->setTitle( 'DANH SÁCH ĐƠN HÀNG' );
		$sheet->mergeCells( 'A1:G1' );
		$sheet->setCellValue( 'A1', 'DANH SÁCH LẤY HÀNG TEST' );
		$sheet->fromArray( array( 'STT', 'Sàn & Mã Đơn', 'Ngày đặt', 'Mã đơn hàng eShop', 'Mã hàng hóa', 'Tên hàng hóa', 'Số lg' ), null, 'A3' );
		$order_number = 0;
		for ( $row = 4; $row <= $last_data_row; $row++ ) {
			$is_order = in_array( $row, $order_rows, true );
			if ( $is_order ) {
				$order_number++;
				$platform = $platforms[ $order_number ] ?? 'SHOPEE';
				$label = 'LAZADA' === $platform ? 'Lazada' : 'Shopee';
				$sheet->setCellValue( "A{$row}", $order_number );
				$sheet->setCellValue( "B{$row}", $label . "\nTEST-" . $platform . '-' . str_pad( (string) $order_number, 3, '0', STR_PAD_LEFT ) );
				$sheet->setCellValue( "C{$row}", '01/01/2026 10:00' );
				$sheet->setCellValue( "D{$row}", 'ESHOP-' . $order_number );
			}
			$sheet->setCellValue( "E{$row}", 'SKU-' . $row );
			$sheet->setCellValue( "F{$row}", 'Sản phẩm thử nghiệm ' . $row );
			$sheet->setCellValue( "G{$row}", 1 );
		}
		$footer_row = $last_data_row + 2;
		$sheet->setCellValue( "F{$footer_row}", 'Thủ Kho' );
	};
}

$parser = new Ecomkit_Vuikhoe_Excel_Service( true );
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
	assert_true( 'SUCCESS' === $result['status'], 'Production fixture must succeed: ' . json_encode( $result['errors'] ) );
	assert_true( 3 === $result['raw']['header_row'], 'Header after title rows was not discovered.' );
	assert_true( 3 === count( $result['orders'] ) && 4 === $result['raw']['item_rows'], 'Order/item counts are wrong.' );
	assert_true( 'SHOPEE' === $result['orders'][0]['platform'] && 'TEST-SHP-001' === $result['orders'][0]['order_code'], 'Shopee combined cell failed.' );
	assert_true( '2026-01-01 03:00:00' === $result['orders'][0]['order_date'] && 3 === $result['raw']['date_column'], 'Named Excel Ngày đặt was not parsed to UTC deterministically.' );
	assert_true( 'DATETIME' === $result['orders'][0]['order_date_precision'], 'Datetime precision was not retained.' );
	assert_true( 'TEST-SHP-001' !== 'TEST001' && 'TEST-SHP-001' === $result['orders'][0]['order_code'], 'eShop code was used as identity.' );
	assert_true( 2 === count( $result['orders'][1]['items'] ), 'Continuation row did not attach to one order.' );
	assert_true( 'LAZADA' === $result['orders'][2]['platform'] && '000123456789' === $result['orders'][2]['order_code'], 'Lazada leading-zero ID changed.' );
	assert_true( array() === codes( $result ), 'Valid continuation generated an error.' );

	$paths[] = $date_matrix = fixture( static function ( Spreadsheet $book ): void {
		$sheet = $book->getActiveSheet();
		$sheet->fromArray( array( 'Sàn & Mã Đơn', 'Ngày đặt', 'Mã hàng hóa', 'Tên hàng hóa', 'Số lg' ), null, 'A1' );
		$serial = ExcelDate::PHPToExcel( new DateTimeImmutable( '2026-09-17 14:15:00', wp_timezone() ) );
		$sheet->setCellValue( 'A2', "Shopee\nDATE-NUMERIC" );
		$sheet->setCellValue( 'B2', $serial );
		$sheet->getStyle( 'B2' )->getNumberFormat()->setFormatCode( NumberFormat::FORMAT_DATE_DATETIME );
		$values = array(
			3 => '17/09/2026 14:15',
			4 => "17/09/2026\n14:15",
			5 => "17/09/2026\r\n14:15",
			6 => '17/09/2026    14:15',
			7 => '17/09/2026',
			8 => '31/02/2026 14:15',
			9 => '',
		);
		foreach ( $values as $row => $value ) {
			$sheet->setCellValue( "A{$row}", "Shopee\nDATE-{$row}" );
			$sheet->setCellValue( "B{$row}", $value );
			$sheet->setCellValue( "C{$row}", 'SKU-' . $row );
			$sheet->setCellValue( "D{$row}", 'Item ' . $row );
			$sheet->setCellValue( "E{$row}", 1 );
		}
		$sheet->setCellValue( 'C10', 'SKU-CONTINUATION' );
		$sheet->setCellValue( 'D10', 'Continuation item' );
		$sheet->setCellValue( 'E10', 1 );
		$sheet->setCellValue( 'D12', 'Thủ Kho' );
	} );
	$date_result = $parser->parse( $date_matrix );
	assert_true( 8 === count( $date_result['orders'] ), 'Date matrix order count changed.' );
	foreach ( array( 0, 1, 2, 3, 4 ) as $index ) {
		assert_true( '2026-09-17 07:15:00' === $date_result['orders'][ $index ]['order_date'], 'Numeric/string/newline date was not normalized to the same UTC instant.' );
	}
	assert_true( '2026-09-16 17:00:00' === $date_result['orders'][5]['order_date'] && 'DATE' === $date_result['orders'][5]['order_date_precision'], 'Date-only precision or UTC storage failed.' );
	assert_true( null === $date_result['orders'][6]['order_date'] && in_array( 'EXCEL_INVALID_ORDER_DATE', codes( $date_result ), true ), 'Impossible date was normalized or not reported.' );
	assert_true( "17/09/2026\n14:15" === $date_result['orders'][2]['raw_cells']['2'], 'Date normalization overwrote the raw Excel cell.' );
	assert_true( null === $date_result['orders'][7]['order_date'] && 2 === count( $date_result['orders'][7]['items'] ), 'Blank date or continuation-row inheritance policy failed.' );
	assert_true( 1 === $date_result['raw']['classifications']['CONTINUATION_ITEM_ROW'] && 1 === $date_result['raw']['classifications']['FOOTER_OR_NONDATA_ROW'], 'Continuation/footer classification regressed in date matrix.' );

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

	$shapes = array(
		'A' => array( 6, array( 4, 5, 6 ), array( 1 => 'SHOPEE', 2 => 'SHOPEE', 3 => 'SHOPEE' ), 3, 3, 3, 0 ),
		'B' => array( 26, array( 4, 5, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 24, 25, 26 ), array_combine( range( 1, 16 ), array_merge( array_fill( 0, 13, 'SHOPEE' ), array_fill( 0, 3, 'LAZADA' ) ) ), 16, 23, 13, 3 ),
		'C' => array( 21, array( 4, 5, 10, 13, 15, 18, 19, 21 ), array( 1 => 'SHOPEE', 2 => 'SHOPEE', 3 => 'LAZADA', 4 => 'LAZADA', 5 => 'LAZADA', 6 => 'LAZADA', 7 => 'SHOPEE', 8 => 'SHOPEE' ), 8, 18, 4, 4 ),
	);
	foreach ( $shapes as $name => $shape_definition ) {
		list( $last_row, $order_rows, $platforms, $expected_orders, $expected_items, $expected_shopee, $expected_lazada ) = $shape_definition;
		$paths[] = $path = fixture( production_shape( $last_row, $order_rows, $platforms ) );
		$shape = $parser->parse( $path );
		assert_true( 'SUCCESS' === $shape['status'], "Fixture {$name} must succeed." );
		assert_true( $expected_orders === count( $shape['orders'] ), "Fixture {$name} order count failed." );
		assert_true( $expected_items === $shape['raw']['item_rows'] && $expected_items === $shape['total_rows'], "Fixture {$name} item/business-row count failed." );
		assert_true( $expected_shopee === ( $shape['raw']['platform_counts']['SHOPEE'] ?? 0 ) && $expected_lazada === ( $shape['raw']['platform_counts']['LAZADA'] ?? 0 ), "Fixture {$name} platform counts failed." );
		assert_true( count( $shape['orders'] ) === count( array_filter( array_column( $shape['orders'], 'order_date' ) ) ), "Fixture {$name} order dates were not extracted." );
		assert_true( 1 === $shape['raw']['classifications']['BLANK_ROW'] && 1 === $shape['raw']['classifications']['FOOTER_OR_NONDATA_ROW'], "Fixture {$name} footer structure failed." );
	}
	assert_true( 5 === count( $parser->parse( $paths[ array_key_last( $paths ) ] )['orders'][1]['items'] ), 'Fixture C row 5 must have five items.' );
	$fixture_b = $parser->parse( $paths[ count( $paths ) - 2 ] );
	assert_true( 7 === count( $fixture_b['orders'][12]['items'] ), 'Fixture B row 17 must have seven items.' );

	$paths[] = $limit = fixture( static function ( Spreadsheet $book ): void {
		$sheet = $book->getActiveSheet();
		$sheet->setCellValue( 'A1', 'Sàn & Mã Đơn' );
		$sheet->setCellValue( 'A2002', "Shopee\nTOO-MANY" );
	} );
	assert_true( 'EXCEL_ROW_LIMIT_EXCEEDED' === $parser->parse( $limit )['errors'][0]['error_code'], 'Row limit was not enforced.' );

	$invalid = tempnam( sys_get_temp_dir(), 'ecomkit-invalid-' );
	if ( false === $invalid ) { throw new RuntimeException( 'Cannot allocate invalid fixture.' ); }
	$paths[] = $invalid;
	file_put_contents( $invalid, 'not an xlsx file' );
	assert_true( 'EXCEL_READ_ERROR' === $parser->parse( $invalid )['errors'][0]['error_code'], 'Invalid workbook was not handled safely.' );

	echo "WP.2B Excel parser checks passed.\n";
} finally { foreach ( $paths as $path ) { @unlink( $path ); } }
