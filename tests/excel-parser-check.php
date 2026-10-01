<?php
/**
 * Synthetic PhpSpreadsheet fixtures for WP.2. Contains no business or PII data.
 */

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

define('ABSPATH', __DIR__ . '/wordpress-placeholder/');
require __DIR__ . '/../ecomkit-vuikhoe/vendor/autoload.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param callable(Spreadsheet):void $build */
function fixture(callable $build): string
{
    $spreadsheet = new Spreadsheet();
    $build($spreadsheet);
    $path = tempnam(sys_get_temp_dir(), 'ecomkit-xlsx-');
    if ($path === false) {
        throw new RuntimeException('Cannot allocate fixture.');
    }
    (new Xlsx($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();
    return $path;
}

$parser = new Ecomkit_Vuikhoe_Excel_Service();
$paths = [];

try {
    $paths[] = $valid = fixture(static function (Spreadsheet $book): void {
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Đơn nội bộ');
        $sheet->fromArray([[' Mã đơn sàn ', 'Ghi chú'], ['2609178X85CBMY', 'synthetic'], [null, null], ['order001', 'case'], ['ORDER001', 'case']]);
        $sheet->setCellValueExplicit('A6', '000012345678901', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('A7', '12345678901234567890', DataType::TYPE_STRING);
    });
    $result = $parser->parse($valid);
    assert_true($result['status'] === 'SUCCESS', 'Valid fixture must succeed.');
    assert_true($result['total_rows'] === 5 && $result['valid_rows'] === 5, 'Valid fixture counts are wrong.');
    $codes = array_column($result['orders'], 'order_code');
    assert_true(in_array('2609178X85CBMY', $codes, true), 'Alphanumeric identity changed.');
    assert_true(in_array('000012345678901', $codes, true), 'Leading zero identity changed.');
    assert_true(in_array('12345678901234567890', $codes, true), 'Long text identity changed.');
    assert_true(in_array('order001', $codes, true) && in_array('ORDER001', $codes, true), 'Case-sensitive identities were merged.');
    assert_true($result['orders'][0]['sheet'] === 'Đơn nội bộ' && $result['orders'][0]['row'] === 2, 'Source location missing.');

    $paths[] = $missing = fixture(static fn(Spreadsheet $book) => $book->getActiveSheet()->fromArray([['Mã đơn'], ['A']]));
    $missingResult = $parser->parse($missing);
    assert_true($missingResult['errors'][0]['error_code'] === 'EXCEL_REQUIRED_COLUMN_MISSING', 'Missing header was accepted.');

    $paths[] = $rows = fixture(static fn(Spreadsheet $book) => $book->getActiveSheet()->fromArray([['Mã đơn sàn', 'Ghi chú'], ['A', 'ok'], [null, 'missing'], [' A ', 'duplicate']]));
    $rowResult = $parser->parse($rows);
    assert_true($rowResult['status'] === 'WARNING' && $rowResult['valid_rows'] === 1 && count($rowResult['errors']) === 2, 'Partial row semantics are wrong.');
    assert_true($rowResult['errors'][0]['error_code'] === 'EXCEL_EMPTY_ORDER_CODE' && $rowResult['errors'][0]['row'] === 3, 'Blank ID error is wrong.');
    assert_true($rowResult['errors'][1]['error_code'] === 'EXCEL_DUPLICATE_ORDER_CODE' && $rowResult['errors'][1]['context']['first_seen_row'] === 2, 'Duplicate semantics are wrong.');

    $paths[] = $numeric = fixture(static function (Spreadsheet $book): void {
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('A1', 'Mã đơn sàn');
        $sheet->setCellValueExplicit('A2', 2609178000000000, DataType::TYPE_NUMERIC);
    });
    $numericResult = $parser->parse($numeric);
    assert_true($numericResult['errors'][0]['error_code'] === 'EXCEL_UNSAFE_NUMERIC_ORDER_CODE', 'Unsafe numeric ID was guessed.');

    $paths[] = $scientificText = fixture(static function (Spreadsheet $book): void {
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('A1', 'Mã đơn sàn');
        $sheet->setCellValueExplicit('A2', '2.609178E+13', DataType::TYPE_STRING);
    });
    assert_true($parser->parse($scientificText)['errors'][0]['error_code'] === 'EXCEL_UNSAFE_NUMERIC_ORDER_CODE', 'Scientific-notation text was accepted as identity.');

    $paths[] = $leadingNumeric = fixture(static function (Spreadsheet $book): void {
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('A1', 'Mã đơn sàn');
        $sheet->setCellValue('A2', 123);
        $sheet->getStyle('A2')->getNumberFormat()->setFormatCode('000000');
    });
    $leadingResult = $parser->parse($leadingNumeric);
    assert_true($leadingResult['orders'][0]['order_code'] === '000123', 'Recoverable numeric leading zeros were not preserved.');

    $paths[] = $multi = fixture(static function (Spreadsheet $book): void {
        $book->getActiveSheet()->setCellValue('A1', 'Mã đơn sàn');
        $book->createSheet()->setCellValue('A1', 'Mã đơn sàn');
    });
    assert_true($parser->parse($multi)['errors'][0]['error_code'] === 'EXCEL_SHEET_NOT_FOUND', 'Multiple sheets were silently merged.');

    $paths[] = $limit = fixture(static function (Spreadsheet $book): void {
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('A1', 'Mã đơn sàn');
        $sheet->setCellValue('A2002', 'TOO-MANY');
    });
    assert_true($parser->parse($limit)['errors'][0]['error_code'] === 'EXCEL_ROW_LIMIT_EXCEEDED', 'Row limit was not enforced.');

    $invalid = tempnam(sys_get_temp_dir(), 'ecomkit-invalid-');
    if ($invalid === false) {
        throw new RuntimeException('Cannot allocate invalid fixture.');
    }
    $paths[] = $invalid;
    file_put_contents($invalid, 'not an xlsx file');
    assert_true($parser->parse($invalid)['errors'][0]['error_code'] === 'EXCEL_READ_ERROR', 'Invalid workbook was not handled safely.');

    echo "WP.2 Excel parser checks passed.\n";
} finally {
    foreach ($paths as $path) {
        @unlink($path);
    }
}
