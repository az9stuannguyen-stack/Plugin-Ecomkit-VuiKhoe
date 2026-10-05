<?php
/** Pure XLSX parser for the verified Vui Khỏe production structure. */

defined( 'ABSPATH' ) || exit;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class Ecomkit_Vuikhoe_Excel_Service {
	public const SOURCE_HEADER = 'Sàn & Mã Đơn';
	public const LEGACY_HEADER = 'Mã đơn sàn';
	public const MAX_ROWS = 2000;
	private const HEADER_SCAN_NON_EMPTY_ROWS = 20;
	private const PARSER_VERSION = 'wp5a-v1';
	private const PRODUCT_CODE_HEADER = 'Mã hàng hóa';
	private const PRODUCT_NAME_HEADER = 'Tên hàng hóa';
	private const QUANTITY_HEADER = 'Số lg';
	private const ORDER_DATE_HEADER = 'Ngày đặt';
	private const INTERNAL_HEADERS = array( 'Giá SP (VAT 8%)' => 'product_price_vat_8', 'Phí Affiliate (Vui Khỏe)' => 'affiliate_fee_vuikhoe', 'Chiết Khấu (Vui Khỏe)' => 'discount_vuikhoe', 'Ngày Xuất VAT' => 'vat_issued_date', 'Ghi Chú' => 'note' );
	private const DISCOUNT_ALIAS = 'Voucher Xtra';

	public function __construct( private bool $skip_runtime_check = false ) {}

	/** @return array<string,mixed> */
	public function parse( string $path ): array {
		if ( ! $this->skip_runtime_check ) {
			$runtime = new Ecomkit_Vuikhoe_Runtime_Diagnostics();
			$dependency = $runtime->dependency_check();
			if ( ! $dependency['ok'] ) {
				return $this->structural_error( $dependency['classification'], 'Môi trường Excel trên máy chủ chưa sẵn sàng.', 'Kiểm tra Excel Runtime Diagnostics trong trang Cài đặt.', null, 'WORKBOOK_LOAD', $dependency['classification'] );
			}
			$source = $runtime->source_check( $path );
			if ( ! $source['ok'] ) {
				return $this->structural_error( $source['classification'], 'Không thể truy cập file Excel tạm để xử lý.', 'Tải lại file; nếu lỗi lặp lại, kiểm tra quyền thư mục tạm.', null, 'WORKBOOK_LOAD', $source['classification'] );
			}
		}
		try {
			$reader = IOFactory::createReader( 'Xlsx' );
			$reader->setReadDataOnly( false );
			$workbook = $reader->load( $path );
		} catch ( Throwable $exception ) {
			return $this->structural_error( 'EXCEL_READ_ERROR', 'Không thể đọc file Excel.', 'Hãy lưu lại file ở định dạng .xlsx hợp lệ rồi tải lên lại.', null, 'WORKBOOK_LOAD' );
		}
		try {
			if ( 1 !== $workbook->getSheetCount() ) {
				return $this->structural_error( 'EXCEL_SHEET_NOT_FOUND', 'File Excel phải có đúng một worksheet.', 'Hãy giữ đúng một worksheet chứa dữ liệu cần nhập.', null, 'SHEET_VALIDATE' );
			}
			return $this->parse_worksheet( $workbook->getSheet( 0 ) );
		} finally {
			$workbook->disconnectWorksheets();
		}
	}

	/** @return array<string,mixed> */
	private function parse_worksheet( Worksheet $sheet ): array {
		$highest_row = $sheet->getHighestDataRow();
		$highest_column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString( $sheet->getHighestDataColumn() );
		$header = $this->discover_header( $sheet, $highest_row, $highest_column );
		if ( isset( $header['error'] ) ) {
			return $this->structural_error( $header['error'], $header['message'], $header['suggestion'], $sheet->getTitle(), 'HEADER_DISCOVERY' );
		}

		$header_row = (int) $header['row'];
		if ( $highest_row - $header_row > self::MAX_ROWS ) {
			return $this->structural_error( 'EXCEL_ROW_LIMIT_EXCEEDED', 'File Excel vượt quá giới hạn 2.000 dòng dữ liệu.', 'Hãy chia file thành các Batch nhỏ hơn, tối đa 2.000 dòng.', $sheet->getTitle(), 'ROW_CLASSIFICATION' );
		}

		$headers = $header['headers'];
		$columns = $header['columns'];
		$source_mode = $header['source_mode'];
		$identity_column = (int) $header['identity_column'];
		$orders = array();
		$errors = array();
		$rows = array();
		$seen = array();
		$current_index = null;
		$total = 0;
		$item_rows = 0;
		$platform_counts = array();
		$classifications = array_fill_keys( array( 'ORDER_ROW', 'CONTINUATION_ITEM_ROW', 'BLANK_ROW', 'FOOTER_OR_NONDATA_ROW', 'INVALID_DATA_ROW' ), 0 );

		for ( $row_number = $header_row + 1; $row_number <= $highest_row; $row_number++ ) {
			$row = $this->read_row( $sheet, $row_number, $highest_column );
			if ( ! $row['non_empty'] ) {
				$classifications['BLANK_ROW']++;
				$current_index = null;
				continue;
			}
			$identity = $this->raw_identity_value( $sheet->getCell( array( $identity_column, $row_number ) ) );
			$product_structure = $this->item_structure( $row['cells'], $columns );

			if ( isset( $identity['error'] ) ) {
				$classifications['INVALID_DATA_ROW']++;
				$errors[] = $this->row_error( $identity['error'], $identity['message'], $identity['suggestion'], $sheet->getTitle(), $row_number, $identity['raw'] ?? null );
				$current_index = null;
				continue;
			}

			$raw_identity = $identity['value'];
			if ( '' === $raw_identity ) {
				if ( 'production' === $source_mode && 'VALID_ITEM' === $product_structure ) {
					if ( null === $current_index ) {
						$classifications['INVALID_DATA_ROW']++;
						$errors[] = $this->row_error( 'EXCEL_ORPHAN_ITEM_ROW', sprintf( 'Dòng sản phẩm %d không có đơn hàng đứng trước để liên kết.', $row_number ), 'Kiểm tra cột “Sàn & Mã Đơn” ở dòng đơn hàng ngay trước dòng sản phẩm này.', $sheet->getTitle(), $row_number, null );
						continue;
					}
					$classifications['CONTINUATION_ITEM_ROW']++;
					$total++;
					$rows[] = array( 'row' => $row_number, 'classification' => 'CONTINUATION_ITEM_ROW', 'cells' => $row['cells'] );
					$orders[ $current_index ]['items'][] = $this->build_item( $row['cells'], $columns, $sheet->getTitle(), $row_number, $errors );
					$item_rows++;
					continue;
				}
				$current_index = null;
				if ( 'legacy' === $source_mode ) {
					$classifications['INVALID_DATA_ROW']++;
					$errors[] = $this->row_error( 'EXCEL_EMPTY_ORDER_CODE', sprintf( 'Dòng %d chưa có Mã đơn sàn.', $row_number ), 'Hãy điền Mã đơn sàn chính xác vào ô được chỉ ra.', $sheet->getTitle(), $row_number, null );
				} elseif ( 'PARTIAL_ITEM' === $product_structure || $this->has_business_signal( $row['cells'], $columns ) ) {
					$classifications['INVALID_DATA_ROW']++;
					$errors[] = $this->row_error( 'EXCEL_INVALID_DATA_ROW', sprintf( 'Dòng %d có dữ liệu nghiệp vụ nhưng không đủ cấu trúc Order hoặc OrderItem.', $row_number ), 'Kiểm tra mã hàng hóa, tên hàng hóa, số lượng và cột “Sàn & Mã Đơn”.', $sheet->getTitle(), $row_number, null );
				} else {
					$classifications['FOOTER_OR_NONDATA_ROW']++;
				}
				continue;
			}

			$parsed = 'production' === $source_mode ? $this->parse_combined_identity( $raw_identity ) : array( 'platform' => 'UNKNOWN', 'code' => $raw_identity, 'raw_platform' => null );
			if ( isset( $parsed['error'] ) ) {
				$classifications['INVALID_DATA_ROW']++;
				$errors[] = $this->row_error( $parsed['error'], $parsed['message'], $parsed['suggestion'], $sheet->getTitle(), $row_number, $raw_identity );
				$current_index = null;
				continue;
			}

			$unique_key = $parsed['platform'] . "\0" . $parsed['code'];
			if ( isset( $seen[ $unique_key ] ) ) {
				$classifications['INVALID_DATA_ROW']++;
				$errors[] = $this->row_error( 'EXCEL_DUPLICATE_ORDER_CODE', sprintf( 'Mã đơn sàn “%s” (%s) ở dòng %d bị trùng với dòng %d.', $parsed['code'], $parsed['platform'], $row_number, $seen[ $unique_key ] ), sprintf( 'Giữ một dòng đơn duy nhất; kiểm tra dòng %d và %d.', $seen[ $unique_key ], $row_number ), $sheet->getTitle(), $row_number, $parsed['code'], array( 'first_seen_row' => $seen[ $unique_key ], 'platform' => $parsed['platform'] ) );
				$current_index = null;
				continue;
			}

			$seen[ $unique_key ] = $row_number;
			$classifications['ORDER_ROW']++;
			$total++;
			$rows[] = array( 'row' => $row_number, 'classification' => 'ORDER_ROW', 'cells' => $row['cells'] );
			$order_date = isset( $columns[ self::ORDER_DATE_HEADER ] ) ? $this->parse_order_date_cell( $sheet->getCell( array( $columns[ self::ORDER_DATE_HEADER ], $row_number ) ) ) : array( 'value' => null, 'precision' => null, 'state' => 'blank', 'safe_raw' => null );
			if ( 'invalid' === $order_date['state'] ) {
				$errors[] = $this->row_error(
					'EXCEL_INVALID_ORDER_DATE',
					sprintf( 'Ngày đặt ở dòng %d không đúng định dạng ngày/giờ được hỗ trợ.', $row_number ),
					'Kiểm tra ô Ngày đặt; dùng định dạng ngày/tháng/năm và giờ:phút, ví dụ 17/09/2026 14:15.',
					$sheet->getTitle(),
					$row_number,
					$order_date['safe_raw'],
					array(),
					self::ORDER_DATE_HEADER
				);
			}
			$internal = array_fill_keys( array_values( self::INTERNAL_HEADERS ), null );
			foreach ( self::INTERNAL_HEADERS as $label => $field ) {
				if ( 'discount_vuikhoe' === $field ) { continue; }
				if ( ! isset( $columns[ $label ] ) ) { continue; }
				$cell = $sheet->getCell( array( $columns[ $label ], $row_number ) );
				if ( 'Ngày Xuất VAT' === $label ) {
					$parsed_internal = $this->parse_vat_date_cell( $cell );
				} elseif ( 'Ghi Chú' === $label ) {
					$text = $this->column_text( $row['cells'], $columns, $label );
					$parsed_internal = array( 'value' => '' === $text ? null : $text, 'state' => 'valid' );
				} else {
					$parsed_internal = $this->parse_internal_money( $this->safe_cell_value( $cell ) );
				}
				$internal[ $field ] = $parsed_internal['value'];
				if ( 'invalid' === $parsed_internal['state'] ) {
					$errors[] = $this->row_error( 'EXCEL_INVALID_INTERNAL_FIELD', sprintf( 'Giá trị “%s” ở dòng %d không hợp lệ.', $label, $row_number ), 'Sửa giá trị nguồn trong Excel (tiền dạng số chính xác; Ngày Xuất VAT dạng ngày hợp lệ) rồi tải lại file.', $sheet->getTitle(), $row_number, $this->safe_order_date_raw( $this->safe_cell_value( $cell ) ), array(), $label );
				}
			}
			$discount_source = null;
			$discount_values = array();
			foreach ( array( 'Chiết Khấu (Vui Khỏe)', self::DISCOUNT_ALIAS ) as $label ) {
				if ( ! isset( $columns[ $label ] ) ) { continue; }
				$parsed_discount = $this->parse_internal_money( $this->safe_cell_value( $sheet->getCell( array( $columns[ $label ], $row_number ) ) ) );
				if ( null !== $parsed_discount['value'] && Ecomkit_Vuikhoe_Exact_Financial_Math::compare( $parsed_discount['value'], '0' ) < 0 ) { $parsed_discount = array( 'value' => null, 'state' => 'invalid' ); }
				if ( 'invalid' === $parsed_discount['state'] ) {
					$errors[] = $this->row_error( 'EXCEL_INVALID_INTERNAL_FIELD', sprintf( 'Giá trị “%s” ở dòng %d không hợp lệ.', $label, $row_number ), 'Sửa giá trị tiền nguồn trong Excel rồi tải lại file.', $sheet->getTitle(), $row_number, null, array(), $label );
				}
				if ( null !== $parsed_discount['value'] ) { $discount_values[ $label ] = $parsed_discount['value']; }
			}
			if ( 2 === count( $discount_values ) && 0 !== Ecomkit_Vuikhoe_Exact_Financial_Math::compare( $discount_values['Chiết Khấu (Vui Khỏe)'], $discount_values[ self::DISCOUNT_ALIAS ] ) ) {
				$errors[] = $this->row_error( 'DISCOUNT_VUIKHOE_SOURCE_CONFLICT', sprintf( 'Hai nguồn Chiết Khấu Vui Khỏe ở dòng %d không trùng nhau.', $row_number ), 'Giữ một cột chiết khấu hoặc sửa hai giá trị cho trùng nhau.', $sheet->getTitle(), $row_number, null, array(), 'Chiết Khấu (Vui Khỏe)' );
			} elseif ( $discount_values ) {
				$discount_source = array_key_first( $discount_values );
				$internal['discount_vuikhoe'] = $discount_values[ $discount_source ];
			}
			$orders[] = array_merge( array( 'order_code' => $parsed['code'], 'platform' => $parsed['platform'], 'raw_platform' => $parsed['raw_platform'], 'raw_identity' => $raw_identity, 'order_date' => $order_date['value'], 'order_date_precision' => $order_date['precision'], 'eshop_order_code' => isset( $columns['Mã đơn hàng eShop'] ) ? $this->column_text( $row['cells'], $columns, 'Mã đơn hàng eShop' ) : null, 'sheet' => $sheet->getTitle(), 'row' => $row_number, 'raw_cells' => $row['cells'], 'discount_vuikhoe_source' => $discount_source, 'items' => array() ), $internal );
			$current_index = array_key_last( $orders );
			$platform_counts[ $parsed['platform'] ] = ( $platform_counts[ $parsed['platform'] ] ?? 0 ) + 1;
			if ( 'VALID_ITEM' === $product_structure ) {
				$orders[ $current_index ]['items'][] = $this->build_item( $row['cells'], $columns, $sheet->getTitle(), $row_number, $errors );
				$item_rows++;
			} elseif ( 'PARTIAL_ITEM' === $product_structure ) {
				$errors[] = $this->row_error( 'EXCEL_INVALID_ITEM_ROW', sprintf( 'Dòng đơn %d có thông tin sản phẩm chưa đầy đủ.', $row_number ), 'Kiểm tra mã hàng hóa và tên hàng hóa của dòng này.', $sheet->getTitle(), $row_number, null );
			}
		}

		return array(
			'status' => empty( $errors ) ? 'SUCCESS' : ( empty( $orders ) ? 'ERROR' : 'WARNING' ),
			'total_rows' => $total,
			'valid_rows' => count( $orders ),
			'orders' => $orders,
			'errors' => $errors,
			'raw' => array( 'parser_version' => self::PARSER_VERSION, 'sheet' => $sheet->getTitle(), 'header_row' => $header_row, 'headers' => array_values( $headers ), 'column_map' => $columns, 'date_column' => $columns[ self::ORDER_DATE_HEADER ] ?? null, 'source_mode' => $source_mode, 'item_rows' => $item_rows, 'platform_counts' => $platform_counts, 'classifications' => $classifications, 'rows' => $rows ),
		);
	}

	/** Exact decimal text only; Excel numeric doubles are accepted only when integral. */
	private function parse_internal_money( mixed $value ): array {
		if ( null === $value || '' === trim( (string) $value ) ) { return array( 'value' => null, 'state' => 'blank' ); }
		if ( is_float( $value ) ) {
			if ( ! is_finite( $value ) || floor( $value ) !== $value || abs( $value ) > 9007199254740991 ) { return array( 'value' => null, 'state' => 'invalid' ); }
			$value = sprintf( '%.0f', $value );
		}
		if ( ! is_int( $value ) && ! is_string( $value ) ) { return array( 'value' => null, 'state' => 'invalid' ); }
		$text = trim( (string) $value );
		if ( 1 === preg_match( '/^-?[1-9][0-9]{0,2}(?:,[0-9]{3})+(?:\.[0-9]{1,4})?$/D', $text ) ) { $text = str_replace( ',', '', $text ); }
		if ( 1 !== preg_match( '/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]{1,4})?$/D', $text ) || strlen( explode( '.', ltrim( $text, '-' ) )[0] ) > 16 ) { return array( 'value' => null, 'state' => 'invalid' ); }
		return array( 'value' => $text, 'state' => 'valid' );
	}

	/** VAT invoice date is a calendar date, never a timezone-converted instant. */
	private function parse_vat_date_cell( Cell $cell ): array {
		$value = $this->safe_cell_value( $cell );
		if ( null === $value || '' === trim( (string) $value ) ) { return array( 'value' => null, 'state' => 'blank' ); }
		if ( ( is_int( $value ) || is_float( $value ) ) && ExcelDate::isDateTime( $cell ) ) {
			try { $text = ExcelDate::excelToDateTimeObject( (float) $value )->format( 'Y-m-d' ); }
			catch ( Throwable ) { return array( 'value' => null, 'state' => 'invalid' ); }
		} elseif ( is_string( $value ) ) {
			$text = trim( $value );
		} else { return array( 'value' => null, 'state' => 'invalid' ); }
		foreach ( array( 'd/m/Y', 'Y-m-d' ) as $format ) {
			$date = DateTimeImmutable::createFromFormat( '!' . $format, $text, new DateTimeZone( 'UTC' ) );
			$errors = DateTimeImmutable::getLastErrors();
			if ( false !== $date && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $date->format( $format ) === $text ) { return array( 'value' => $date->format( 'Y-m-d 00:00:00' ), 'state' => 'valid' ); }
		}
		return array( 'value' => null, 'state' => 'invalid' );
	}

	/** @return array{value:?string,precision:?string,state:string,safe_raw:?string} */
	private function parse_order_date_cell( Cell $cell ): array {
		$value = DataType::TYPE_FORMULA === $cell->getDataType() ? $cell->getOldCalculatedValue() : $cell->getValue();
		if ( null === $value || '' === trim( (string) $value ) ) {
			return array( 'value' => null, 'precision' => null, 'state' => 'blank', 'safe_raw' => null );
		}
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		if ( ( is_int( $value ) || is_float( $value ) ) && ExcelDate::isDateTime( $cell ) ) {
			try {
				$serial = (float) $value;
				if ( ! is_finite( $serial ) || $serial <= 0 ) {
					throw new RuntimeException( 'Invalid Excel date serial.' );
				}
				$date = DateTimeImmutable::createFromInterface( ExcelDate::excelToDateTimeObject( $serial, $timezone ) );
				return array( 'value' => $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), 'precision' => 'DATETIME', 'state' => 'valid', 'safe_raw' => null );
			} catch ( Throwable $exception ) {
				return array( 'value' => null, 'precision' => null, 'state' => 'invalid', 'safe_raw' => $this->safe_order_date_raw( $value ) );
			}
		}
		if ( $value instanceof DateTimeInterface ) {
			return array( 'value' => DateTimeImmutable::createFromInterface( $value )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), 'precision' => 'DATETIME', 'state' => 'valid', 'safe_raw' => null );
		}
		$text = trim( (string) preg_replace( '/\s+/u', ' ', trim( (string) $value ) ) );
		foreach ( array( 'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d' ) as $format ) {
			$date = DateTimeImmutable::createFromFormat( '!' . $format, $text, $timezone );
			$errors = DateTimeImmutable::getLastErrors();
			if ( false !== $date && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $date->format( $format ) === $text ) {
				$precision = str_contains( $format, 'H:i' ) ? 'DATETIME' : 'DATE';
				return array( 'value' => $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), 'precision' => $precision, 'state' => 'valid', 'safe_raw' => null );
			}
		}
		return array( 'value' => null, 'precision' => null, 'state' => 'invalid', 'safe_raw' => $this->safe_order_date_raw( $value ) );
	}

	private function safe_order_date_raw( mixed $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$text = trim( (string) preg_replace( '/\s+/u', ' ', trim( (string) $value ) ) );
		return '' === $text ? null : mb_substr( $text, 0, 80 );
	}

	/** @return array<string,mixed> */
	private function discover_header( Worksheet $sheet, int $highest_row, int $highest_column ): array {
		$non_empty_rows = 0;
		for ( $row = 1; $row <= $highest_row && $non_empty_rows < self::HEADER_SCAN_NON_EMPTY_ROWS; $row++ ) {
			$headers = array();
			$columns = array();
			$has_content = false;
			for ( $column = 1; $column <= $highest_column; $column++ ) {
				$value = $this->safe_cell_value( $sheet->getCell( array( $column, $row ) ) );
				$label = $this->normalize_header( is_scalar( $value ) ? (string) $value : '' );
				$headers[ $column ] = $label;
				if ( '' !== $label ) {
					$has_content = true;
					$columns[ $label ] = $column;
				}
			}
			if ( ! $has_content ) {
				continue;
			}
			$non_empty_rows++;
			$has_production = isset( $columns[ self::SOURCE_HEADER ] );
			$has_legacy = isset( $columns[ self::LEGACY_HEADER ] );
			if ( $has_production && $has_legacy ) {
				return array( 'error' => 'EXCEL_IDENTITY_COLUMN_AMBIGUOUS', 'message' => 'Bảng dữ liệu có đồng thời cột “Sàn & Mã Đơn” và “Mã đơn sàn”, nên không thể xác định nguồn nhận diện đơn an toàn.', 'suggestion' => 'Giữ cột “Sàn & Mã Đơn” cho định dạng Vui Khỏe, hoặc chỉ giữ một cột nhận diện đơn duy nhất.' );
			}
			if ( $has_production || $has_legacy ) {
				return array( 'row' => $row, 'headers' => $headers, 'columns' => $columns, 'source_mode' => $has_production ? 'production' : 'legacy', 'identity_column' => $has_production ? $columns[ self::SOURCE_HEADER ] : $columns[ self::LEGACY_HEADER ] );
			}
		}
		return array( 'error' => 'EXCEL_REQUIRED_COLUMN_MISSING', 'message' => 'Không tìm thấy cột “Sàn & Mã Đơn” trong bảng dữ liệu Excel.', 'suggestion' => 'Kiểm tra hàng tiêu đề của bảng và đảm bảo có cột “Sàn & Mã Đơn”.' );
	}

	private function normalize_header( string $header ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', trim( $header ) ) );
	}

	/** @return array{cells:array<string,string|int|float|bool|null>,non_empty:bool} */
	private function read_row( Worksheet $sheet, int $row, int $highest_column ): array {
		$cells = array();
		$non_empty = false;
		for ( $column = 1; $column <= $highest_column; $column++ ) {
			$value = $this->safe_cell_value( $sheet->getCell( array( $column, $row ) ) );
			$cells[ (string) $column ] = $value;
			if ( null !== $value && '' !== trim( (string) $value ) ) {
				$non_empty = true;
			}
		}
		return array( 'cells' => $cells, 'non_empty' => $non_empty );
	}

	/** @param array<string,mixed> $cells @param array<string,int> $columns */
	private function item_structure( array $cells, array $columns ): string {
		$code = $this->column_text( $cells, $columns, self::PRODUCT_CODE_HEADER );
		$name = $this->column_text( $cells, $columns, self::PRODUCT_NAME_HEADER );
		$quantity = $this->column_text( $cells, $columns, self::QUANTITY_HEADER );
		if ( '' !== $code && '' !== $name ) {
			return 'VALID_ITEM';
		}
		return '' !== $code || '' !== $quantity ? 'PARTIAL_ITEM' : 'NO_ITEM';
	}

	/** @param array<string,mixed> $cells @param array<string,int> $columns */
	private function has_business_signal( array $cells, array $columns ): bool {
		foreach ( array( 'STT', 'Mã đơn hàng eShop', 'Ngày đặt', self::PRODUCT_CODE_HEADER, self::QUANTITY_HEADER ) as $header ) {
			if ( '' !== $this->column_text( $cells, $columns, $header ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param array<string,mixed> $cells @param array<string,int> $columns @param array<int,array<string,mixed>> $errors @return array<string,mixed> */
	private function build_item( array $cells, array $columns, string $sheet, int $row, array &$errors ): array {
		$product_code = $this->column_text( $cells, $columns, self::PRODUCT_CODE_HEADER );
		$product_name = $this->column_text( $cells, $columns, self::PRODUCT_NAME_HEADER );
		$raw_quantity = $this->column_text( $cells, $columns, self::QUANTITY_HEADER );
		$quantity = null;
		if ( '' !== $raw_quantity ) {
			if ( preg_match( '/^\d+$/', $raw_quantity ) ) {
				$quantity = (int) $raw_quantity;
			} else {
				$errors[] = $this->row_error( 'EXCEL_INVALID_QUANTITY', sprintf( 'Số lượng ở dòng %d không phải số nguyên an toàn.', $row ), 'Kiểm tra cột “Số lg”; để trống nếu chưa xác định được số lượng.', $sheet, $row, $raw_quantity, array(), self::QUANTITY_HEADER );
			}
		}
		return array( 'sku' => $product_code ?: null, 'product_name' => $product_name ?: null, 'quantity' => $quantity, 'raw_quantity' => $raw_quantity ?: null, 'sheet' => $sheet, 'row' => $row, 'raw_cells' => $cells );
	}

	/** @param array<string,mixed> $cells @param array<string,int> $columns */
	private function column_text( array $cells, array $columns, string $header ): string {
		return isset( $columns[ $header ] ) ? trim( (string) ( $cells[ (string) $columns[ $header ] ] ?? '' ) ) : '';
	}

	/** @return array<string,mixed> */
	private function parse_combined_identity( string $raw ): array {
		$lines = preg_split( '/\r\n|\n|\r/', $raw );
		$lines = array_values( array_filter( array_map( 'trim', is_array( $lines ) ? $lines : array() ), static fn( string $line ): bool => '' !== $line ) );
		if ( 2 !== count( $lines ) ) {
			return array( 'error' => 'EXCEL_COMBINED_ORDER_INVALID', 'message' => 'Ô “Sàn & Mã Đơn” phải chứa tên sàn và mã đơn trên hai dòng riêng biệt.', 'suggestion' => 'Nhập tên sàn ở dòng đầu và mã đơn đầy đủ ở dòng thứ hai trong cùng một ô.' );
		}
		$platforms = array( 'Shopee' => 'SHOPEE', 'Lazada' => 'LAZADA' );
		if ( ! isset( $platforms[ $lines[0] ] ) ) {
			return array( 'error' => 'EXCEL_UNKNOWN_PLATFORM', 'message' => sprintf( 'Không nhận diện được sàn “%s”.', $lines[0] ), 'suggestion' => 'Dùng đúng nhãn sàn được hỗ trợ: Shopee hoặc Lazada.' );
		}
		return array( 'platform' => $platforms[ $lines[0] ], 'code' => $lines[1], 'raw_platform' => $lines[0] );
	}

	private function safe_cell_value( Cell $cell ): string|int|float|bool|null {
		$value = DataType::TYPE_FORMULA === $cell->getDataType() ? $cell->getOldCalculatedValue() : $cell->getValue();
		if ( null === $value || is_scalar( $value ) ) {
			return $value;
		}
		return $value instanceof DateTimeInterface ? $value->format( DATE_ATOM ) : null;
	}

	/** @return array<string,mixed> */
	private function raw_identity_value( Cell $cell ): array {
		$is_formula = DataType::TYPE_FORMULA === $cell->getDataType();
		$value = $is_formula ? $cell->getOldCalculatedValue() : $cell->getValue();
		if ( $is_formula && null === $value ) {
			return array( 'error' => 'EXCEL_READ_ERROR', 'message' => 'Không thể đọc mã đơn từ công thức không có giá trị đã lưu.', 'suggestion' => 'Thay công thức bằng giá trị text cố định rồi lưu lại file.', 'raw' => null );
		}
		if ( null === $value ) {
			return array( 'value' => '' );
		}
		if ( is_string( $value ) ) {
			$trimmed = trim( $value );
			if ( preg_match( '/^[+-]?(?:\d+\.?\d*|\.\d+)[eE][+-]?\d+$/', $trimmed ) ) {
				return array( 'error' => 'EXCEL_UNSAFE_NUMERIC_ORDER_CODE', 'message' => 'Không thể đọc chính xác mã đơn vì giá trị đang ở dạng số khoa học.', 'suggestion' => 'Định dạng ô mã đơn là Text và nhập lại toàn bộ mã gốc.', 'raw' => $trimmed );
			}
			return array( 'value' => $trimmed );
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			$formatted = $is_formula ? trim( (string) $value ) : trim( (string) $cell->getFormattedValue() );
			if ( ! is_finite( (float) $value ) || floor( (float) $value ) !== (float) $value || abs( (float) $value ) > 999999999999999 || ! preg_match( '/^\d+$/', $formatted ) ) {
				return array( 'error' => 'EXCEL_UNSAFE_NUMERIC_ORDER_CODE', 'message' => 'Không thể đọc chính xác mã đơn do Excel đã định dạng giá trị thành số.', 'suggestion' => 'Định dạng ô mã đơn là Text, nhập lại giá trị gốc và tải file lên lại.', 'raw' => (string) $value );
			}
			return array( 'value' => $formatted );
		}
		return array( 'error' => 'EXCEL_ROW_PARSE_ERROR', 'message' => 'Không thể đọc mã đơn ở dòng dữ liệu.', 'suggestion' => 'Chuyển ô mã đơn thành text thuần và thử lại.', 'raw' => null );
	}

	/** @param array<string,mixed> $context @return array<string,mixed> */
	private function row_error( string $code, string $message, string $suggestion, string $sheet, int $row, ?string $raw, array $context = array(), string $column = self::SOURCE_HEADER ): array {
		$stage = in_array( $code, array( 'EXCEL_ORPHAN_ITEM_ROW', 'EXCEL_INVALID_DATA_ROW' ), true ) ? 'ROW_CLASSIFICATION' : ( str_contains( $code, 'QUANTITY' ) || str_contains( $code, 'ITEM' ) ? 'ITEM_PARSE' : 'ORDER_PARSE' );
		return array( 'error_code' => $code, 'stage' => $stage, 'message' => $message, 'suggestion' => $suggestion, 'sheet' => $sheet, 'row' => $row, 'column' => $column, 'field' => $column, 'safe_raw_value' => $raw, 'context' => $context );
	}

	/** @return array<string,mixed> */
	private function structural_error( string $code, string $message, string $suggestion, ?string $sheet = null, string $stage = 'WORKBOOK_LOAD', ?string $classification = null ): array {
		$classification = $classification ?? $code;
		return array( 'status' => 'ERROR', 'total_rows' => 0, 'valid_rows' => 0, 'orders' => array(), 'errors' => array( array( 'error_code' => $code, 'stage' => $stage, 'message' => $message, 'suggestion' => $suggestion, 'sheet' => $sheet ) ), 'raw' => array( 'parser_version' => self::PARSER_VERSION ), 'diagnostic' => array( 'stage' => $stage, 'classification' => $classification, 'exception_class' => null, 'exception_message' => null, 'sheet' => $sheet, 'row' => null ) );
	}
}
