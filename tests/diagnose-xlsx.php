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
	$result = ( new Ecomkit_Vuikhoe_Excel_Service() )->parse( $path );
	$output = array(
		'sheet'           => $result['raw']['sheet'] ?? null,
		'header_row'      => $result['raw']['header_row'] ?? null,
		'order_count'     => count( $result['orders'] ),
		'item_count'      => array_sum( array_map( static fn( array $order ): int => count( $order['items'] ), $result['orders'] ) ),
		'platform_counts' => $result['raw']['platform_counts'] ?? array(),
		'error_codes'     => array_values( array_column( $result['errors'], 'error_code' ) ),
		'failing_stage'   => $result['diagnostic']['stage'] ?? null,
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
