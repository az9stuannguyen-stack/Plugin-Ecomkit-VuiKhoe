<?php
/**
 * Pure XLSX parser for the WP.2 import contract.
 */

defined( 'ABSPATH' ) || exit;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class Ecomkit_Vuikhoe_Excel_Service {
	public const REQUIRED_HEADER = 'Mã đơn sàn';
	public const HEADER_ROW = 1;
	public const MAX_ROWS = 2000;
	private const PARSER_VERSION = 'wp2-v1';

	/**
	 * @return array{status:string,total_rows:int,valid_rows:int,orders:array<int,array<string,mixed>>,errors:array<int,array<string,mixed>>,raw:array<string,mixed>}
	 */
	public function parse( string $path ): array {
		try {
			$reader = IOFactory::createReader( 'Xlsx' );
			$reader->setReadDataOnly( false );
			$workbook = $reader->load( $path );
		} catch ( Throwable $exception ) {
			return $this->structural_error( 'EXCEL_READ_ERROR', 'Không thể đọc file Excel.', 'Hãy lưu lại file ở định dạng .xlsx hợp lệ rồi tải lên lại.' );
		}

		try {
			if ( 1 !== $workbook->getSheetCount() ) {
				return $this->structural_error( 'EXCEL_SHEET_NOT_FOUND', 'File Excel phải có đúng một worksheet.', 'Hãy giữ đúng một worksheet chứa dữ liệu cần nhập.' );
			}

			return $this->parse_worksheet( $workbook->getSheet( 0 ) );
		} finally {
			$workbook->disconnectWorksheets();
		}
	}

	/**
	 * @return array{status:string,total_rows:int,valid_rows:int,orders:array<int,array<string,mixed>>,errors:array<int,array<string,mixed>>,raw:array<string,mixed>}
	 */
	private function parse_worksheet( Worksheet $sheet ): array {
		$highest_row = $sheet->getHighestDataRow();
		if ( $highest_row > self::MAX_ROWS + self::HEADER_ROW ) {
			return $this->structural_error( 'EXCEL_ROW_LIMIT_EXCEEDED', 'File Excel vượt quá giới hạn 2.000 dòng dữ liệu của WP.2.', 'Hãy chia file thành các Batch nhỏ hơn, tối đa 2.000 dòng.', $sheet->getTitle() );
		}

		$highest_column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString( $sheet->getHighestDataColumn() );
		$headers        = array();
		for ( $column = 1; $column <= $highest_column; $column++ ) {
			$headers[ $column ] = trim( (string) $sheet->getCell( array( $column, self::HEADER_ROW ) )->getValue() );
		}

		if ( array() === array_filter( $headers, static fn( string $header ): bool => '' !== $header ) ) {
			return $this->structural_error( 'EXCEL_HEADER_MISSING', 'Dòng tiêu đề đầu tiên đang trống.', 'Hãy đặt tiêu đề ở dòng 1 và thêm cột “Mã đơn sàn”.', $sheet->getTitle() );
		}

		$required_column = array_search( self::REQUIRED_HEADER, $headers, true );
		if ( false === $required_column ) {
			return $this->structural_error( 'EXCEL_REQUIRED_COLUMN_MISSING', 'Không tìm thấy cột “Mã đơn sàn” trong file Excel.', 'Hãy thêm đúng tiêu đề “Mã đơn sàn” ở dòng 1; không dùng tên thay thế.', $sheet->getTitle() );
		}

		$orders = array();
		$errors = array();
		$rows   = array();
		$seen   = array();
		$total  = 0;

		for ( $row_number = self::HEADER_ROW + 1; $row_number <= $highest_row; $row_number++ ) {
			$raw_cells = array();
			$non_empty = false;
			for ( $column = 1; $column <= $highest_column; $column++ ) {
				$cell  = $sheet->getCell( array( $column, $row_number ) );
				$value = $this->safe_cell_value( $cell );
				$raw_cells[ (string) $column ] = $value;
				if ( ( null !== $value && '' !== $value ) || null !== $cell->getValue() ) {
					$non_empty = true;
				}
			}

			if ( ! $non_empty ) {
				continue;
			}

			$total++;
			$identity = $this->identity_value( $sheet->getCell( array( (int) $required_column, $row_number ) ) );
			$rows[]   = array( 'row' => $row_number, 'cells' => $raw_cells );

			if ( isset( $identity['error'] ) ) {
				$errors[] = $this->row_error( $identity['error'], $identity['message'], $identity['suggestion'], $sheet->getTitle(), $row_number, $identity['raw'] ?? null );
				continue;
			}

			$code = $identity['value'];
			if ( '' === $code ) {
				$errors[] = $this->row_error( 'EXCEL_EMPTY_ORDER_CODE', sprintf( 'Dòng %d chưa có Mã đơn sàn.', $row_number ), 'Hãy điền Mã đơn sàn chính xác vào ô được chỉ ra.', $sheet->getTitle(), $row_number, null );
				continue;
			}

			if ( isset( $seen[ $code ] ) ) {
				$errors[] = $this->row_error( 'EXCEL_DUPLICATE_ORDER_CODE', sprintf( 'Mã đơn sàn “%s” ở dòng %d bị trùng với dòng %d.', $code, $row_number, $seen[ $code ] ), sprintf( 'Giữ một dòng duy nhất cho mã này; kiểm tra dòng %d và %d.', $seen[ $code ], $row_number ), $sheet->getTitle(), $row_number, $code, array( 'first_seen_row' => $seen[ $code ] ) );
				continue;
			}

			$seen[ $code ] = $row_number;
			$orders[]      = array(
				'order_code' => $code,
				'sheet'      => $sheet->getTitle(),
				'row'        => $row_number,
				'raw_cells'  => $raw_cells,
			);
		}

		return array(
			'status'     => empty( $errors ) ? 'SUCCESS' : ( empty( $orders ) ? 'ERROR' : 'WARNING' ),
			'total_rows' => $total,
			'valid_rows' => count( $orders ),
			'orders'     => $orders,
			'errors'     => $errors,
			'raw'        => array(
				'parser_version' => self::PARSER_VERSION,
				'sheet'          => $sheet->getTitle(),
				'header_row'     => self::HEADER_ROW,
				'headers'        => array_values( $headers ),
				'rows'           => $rows,
			),
		);
	}

	/**
	 * Returns safe scalar display data and never evaluates a formula.
	 */
	private function safe_cell_value( Cell $cell ): string|int|float|bool|null {
		$value = DataType::TYPE_FORMULA === $cell->getDataType() ? $cell->getOldCalculatedValue() : $cell->getValue();
		if ( null === $value || is_scalar( $value ) ) {
			return $value;
		}
		if ( $value instanceof DateTimeInterface ) {
			return $value->format( DATE_ATOM );
		}

		return null;
	}

	/**
	 * @return array{value?:string,error?:string,message?:string,suggestion?:string,raw?:string|null}
	 */
	private function identity_value( Cell $cell ): array {
		$is_formula = DataType::TYPE_FORMULA === $cell->getDataType();
		$value      = $is_formula ? $cell->getOldCalculatedValue() : $cell->getValue();

		if ( $is_formula && null === $value ) {
			return array(
				'error'      => 'EXCEL_READ_ERROR',
				'message'    => 'Không thể đọc Mã đơn sàn từ công thức không có giá trị đã lưu.',
				'suggestion' => 'Hãy thay công thức bằng giá trị text cố định rồi lưu lại file.',
				'raw'        => null,
			);
		}

		if ( null === $value ) {
			return array( 'value' => '' );
		}

		if ( is_string( $value ) ) {
			$trimmed = trim( $value );
			if ( preg_match( '/^[+-]?(?:\d+\.?\d*|\.\d+)[eE][+-]?\d+$/', $trimmed ) ) {
				return array(
					'error'      => 'EXCEL_UNSAFE_NUMERIC_ORDER_CODE',
					'message'    => 'Không thể đọc chính xác Mã đơn sàn vì giá trị đang ở dạng số khoa học.',
					'suggestion' => 'Định dạng cột Mã đơn sàn là Text, nhập lại toàn bộ chữ số gốc và tải file lên lại.',
					'raw'        => $trimmed,
				);
			}
			return array( 'value' => $trimmed );
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			$formatted = $is_formula ? trim( (string) $value ) : trim( (string) $cell->getFormattedValue() );
			if ( ! is_finite( (float) $value ) || floor( (float) $value ) !== (float) $value || abs( (float) $value ) > 999999999999999 || ! preg_match( '/^\d+$/', $formatted ) ) {
				return array(
					'error'      => 'EXCEL_UNSAFE_NUMERIC_ORDER_CODE',
					'message'    => 'Không thể đọc chính xác Mã đơn sàn do Excel đã định dạng giá trị thành số.',
					'suggestion' => 'Định dạng cột Mã đơn sàn là Text, nhập lại giá trị gốc và tải file lên lại.',
					'raw'        => (string) $value,
				);
			}

			return array( 'value' => $formatted );
		}

		return array(
			'error'      => 'EXCEL_ROW_PARSE_ERROR',
			'message'    => 'Không thể đọc Mã đơn sàn ở dòng dữ liệu.',
			'suggestion' => 'Hãy chuyển ô Mã đơn sàn thành text thuần và thử lại.',
			'raw'        => null,
		);
	}

	/**
	 * @param array<string,mixed> $context Extra safe context.
	 * @return array<string,mixed>
	 */
	private function row_error( string $code, string $message, string $suggestion, string $sheet, int $row, ?string $raw, array $context = array() ): array {
		return array(
			'error_code'    => $code,
			'message'       => $message,
			'suggestion'    => $suggestion,
			'sheet'         => $sheet,
			'row'           => $row,
			'column'        => self::REQUIRED_HEADER,
			'field'         => self::REQUIRED_HEADER,
			'safe_raw_value'=> $raw,
			'context'       => $context,
		);
	}

	/**
	 * @return array{status:string,total_rows:int,valid_rows:int,orders:array<int,mixed>,errors:array<int,array<string,mixed>>,raw:array<string,mixed>}
	 */
	private function structural_error( string $code, string $message, string $suggestion, ?string $sheet = null ): array {
		return array(
			'status'     => 'ERROR',
			'total_rows' => 0,
			'valid_rows' => 0,
			'orders'     => array(),
			'errors'     => array(
				array(
					'error_code' => $code,
					'message'    => $message,
					'suggestion' => $suggestion,
					'sheet'      => $sheet,
				),
			),
			'raw'        => array( 'parser_version' => self::PARSER_VERSION ),
		);
	}
}

