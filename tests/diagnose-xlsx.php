<?php
/**
 * Development-only, PII-free XLSX parser diagnostic.
 * Usage: php tests/diagnose-xlsx.php /path/to/workbook.xlsx
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' );
require __DIR__ . '/../ecomkit-vuikhoe/vendor/autoload.php';

$path = $argv[1] ?? '';
if ( '' === $path || ! is_file( $path ) ) {
	fwrite( STDERR, "Usage: php tests/diagnose-xlsx.php /path/to/workbook.xlsx\n" );
	exit( 2 );
}

try {
	$result = ( new Ecomkit_Vuikhoe_Excel_Service( true ) )->parse( $path );
	$date_shapes = array();
	$date_column = (int) ( $result['raw']['date_column'] ?? 0 );
	if ( $date_column > 0 && ! empty( $result['raw']['rows'] ) ) {
		$reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader( 'Xlsx' );
		$reader->setReadDataOnly( false );
		$book = $reader->load( $path );
		$sheet = $book->getSheet( 0 );
		foreach ( $result['raw']['rows'] as $row ) {
			if ( 'ORDER_ROW' !== ( $row['classification'] ?? '' ) || count( $date_shapes ) >= 20 ) {
				continue;
			}
			$cell = $sheet->getCell( array( $date_column, (int) $row['row'] ) );
			$value = $cell->getValue();
			$safe_value = is_scalar( $value ) ? mb_substr( (string) preg_replace( '/\r\n|\r|\n/', '\\n', (string) $value ), 0, 80 ) : null;
			$date_shapes[] = array( 'row' => (int) $row['row'], 'php_type' => get_debug_type( $value ), 'excel_datetime_style' => \PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime( $cell ), 'safe_date_value' => $safe_value );
		}
		$book->disconnectWorksheets();
	}
	$output = array(
		'sheet'           => $result['raw']['sheet'] ?? null,
		'header_row'      => $result['raw']['header_row'] ?? null,
		'order_count'     => count( $result['orders'] ),
		'orders_with_date'=> count( array_filter( array_column( $result['orders'], 'order_date' ), static fn( mixed $date ): bool => is_string( $date ) && '' !== $date ) ),
		'item_count'      => array_sum( array_map( static fn( array $order ): int => count( $order['items'] ), $result['orders'] ) ),
		'platform_counts' => $result['raw']['platform_counts'] ?? array(),
		'error_codes'     => array_values( array_column( $result['errors'], 'error_code' ) ),
		'failing_stage'   => $result['diagnostic']['stage'] ?? null,
		'date_shapes'     => $date_shapes,
	);
	echo json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . PHP_EOL;
} catch ( Throwable $exception ) {
	echo json_encode(
		array(
			'sheet'           => null,
			'header_row'      => null,
			'order_count'     => 0,
			'item_count'      => 0,
			'platform_counts' => array(),
			'error_codes'     => array( 'UNEXPECTED_DIAGNOSTIC_FAILURE' ),
			'failing_stage'   => 'WORKBOOK_LOAD',
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
	) . PHP_EOL;
	exit( 1 );
}
