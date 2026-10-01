<?php
/**
 * Synchronous WP.2 upload orchestration and read models.
 */

defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Import_Service {
	public const MAX_UPLOAD_BYTES = 10485760;
	private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

	public function max_upload_bytes(): int {
		return min( self::MAX_UPLOAD_BYTES, (int) wp_max_upload_size() );
	}

	/**
	 * Creates one Batch for every authorized upload attempt.
	 *
	 * @param array<string,mixed> $file Normalized $_FILES entry.
	 */
	public function import_upload( array $file, int $user_id ): int {
		global $wpdb;

		$tables            = Ecomkit_Vuikhoe_DB::table_names();
		$original_filename = isset( $file['name'] ) && is_string( $file['name'] ) ? sanitize_text_field( wp_basename( str_replace( '\\', '/', wp_unslash( $file['name'] ) ) ) ) : '';
		$now               = current_time( 'mysql', true );
		$batch_data        = array(
			'source_type'     => 'EXCEL',
			'status'          => 'PROCESSING',
			'source_filename' => $original_filename ?: null,
			'source_metadata' => wp_json_encode( array( 'plugin_version' => ECOMKIT_VUIKHOE_VERSION ) ),
			'file_count'      => empty( $file ) ? 0 : 1,
			'created_by'      => $user_id,
			'started_at'      => $now,
			'created_at'      => $now,
			'updated_at'      => $now,
		);

		if ( false === $wpdb->insert( $tables['batches'], $batch_data ) ) {
			throw new RuntimeException( 'ECOMKIT_BATCH_CREATE_FAILED' );
		}
		$batch_id = (int) $wpdb->insert_id;

		$validation_error = $this->validate_upload( $file, $original_filename );
		if ( null !== $validation_error ) {
			$this->persist_failure( $batch_id, $original_filename, $validation_error );
			return $batch_id;
		}

		$temp_path = wp_tempnam( 'ecomkit-' . wp_generate_uuid4() . '.xlsx' );
		if ( ! is_string( $temp_path ) || '' === $temp_path ) {
			$this->persist_failure( $batch_id, $original_filename, $this->upload_error( 'EXCEL_TEMP_STORAGE_FAILED', 'Không thể tạo file xử lý tạm thời.', 'Kiểm tra quyền ghi thư mục tạm của máy chủ rồi thử lại.' ) );
			return $batch_id;
		}

		try {
			$tmp_name = (string) $file['tmp_name'];
			if ( ! is_uploaded_file( $tmp_name ) || ! move_uploaded_file( $tmp_name, $temp_path ) ) {
				$this->persist_failure( $batch_id, $original_filename, $this->upload_error( 'EXCEL_UPLOAD_INVALID', 'Không thể nhận file Excel đã tải lên.', 'Chọn lại file .xlsx và thử tải lên lần nữa.' ) );
				return $batch_id;
			}

			$result = ( new Ecomkit_Vuikhoe_Excel_Service() )->parse( $temp_path );
			$this->persist_result( $batch_id, $original_filename, (int) $file['size'], $result );
		} catch ( Throwable $exception ) {
			$this->persist_failure( $batch_id, $original_filename, $this->upload_error( 'EXCEL_IMPORT_FAILED', 'Quá trình nhập Excel không thể hoàn tất an toàn.', 'Vui lòng kiểm tra file và thử lại; nếu lỗi lặp lại, liên hệ quản trị viên.' ) );
		} finally {
			if ( is_file( $temp_path ) ) {
				wp_delete_file( $temp_path );
			}
		}

		return $batch_id;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function validate_upload( array $file, string $filename ): ?array {
		if (
			empty( $file )
			|| ! isset( $file['error'], $file['tmp_name'], $file['size'] )
			|| ! is_scalar( $file['error'] )
			|| ! is_string( $file['tmp_name'] )
			|| ! is_scalar( $file['size'] )
		) {
			return $this->upload_error( 'EXCEL_UPLOAD_INVALID', 'Chưa nhận được file Excel.', 'Chọn một file .xlsx rồi thử lại.' );
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			$code = in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ? 'EXCEL_FILE_TOO_LARGE' : 'EXCEL_UPLOAD_INVALID';
			return $this->upload_error( $code, 'WordPress không thể nhận file Excel hoàn chỉnh.', 'Kiểm tra kích thước file và giới hạn upload của máy chủ rồi thử lại.' );
		}
		if ( (int) $file['size'] <= 0 ) {
			return $this->upload_error( 'EXCEL_UPLOAD_INVALID', 'File Excel đang trống.', 'Chọn một file .xlsx có dữ liệu.' );
		}
		if ( (int) $file['size'] > $this->max_upload_bytes() ) {
			return $this->upload_error( 'EXCEL_FILE_TOO_LARGE', 'File Excel vượt quá giới hạn upload an toàn.', sprintf( 'Giảm kích thước file xuống dưới %s rồi thử lại.', size_format( $this->max_upload_bytes() ) ) );
		}
		if ( 'xlsx' !== strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
			return $this->upload_error( 'EXCEL_UNSUPPORTED_FILE_TYPE', 'WP.2 chỉ chấp nhận file .xlsx.', 'Lưu workbook dưới định dạng .xlsx; không dùng CSV, XLS, PDF, ZIP, HTML hoặc XML.' );
		}

		$checked = wp_check_filetype_and_ext( (string) $file['tmp_name'], $filename, array( 'xlsx' => self::XLSX_MIME ) );
		if ( 'xlsx' !== ( $checked['ext'] ?? null ) || self::XLSX_MIME !== ( $checked['type'] ?? null ) ) {
			return $this->upload_error( 'EXCEL_UNSUPPORTED_FILE_TYPE', 'Nội dung file không khớp định dạng .xlsx được hỗ trợ.', 'Mở file trong Excel hoặc LibreOffice, lưu lại thành .xlsx rồi thử lại.' );
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $result Parser result.
	 */
	private function persist_result( int $batch_id, string $filename, int $file_size, array $result ): void {
		global $wpdb;

		$tables = Ecomkit_Vuikhoe_DB::table_names();
		$now    = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );

		try {
			foreach ( $result['orders'] as $order ) {
				$code = (string) $order['order_code'];
				$data = array(
					'batch_id'                => $batch_id,
					'connection_id'           => null,
					'platform'                => (string) $order['platform'],
					'marketplace_order_id'    => $code,
					'raw_order_code'          => $code,
					'normalized_order_code'   => $code,
					'matching_status'         => null,
					'raw_source_metadata'     => wp_json_encode( array( 'source' => 'EXCEL', 'combined_identity' => $order['raw_identity'], 'platform_label' => $order['raw_platform'], 'cells' => $order['raw_cells'] ), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ),
					'source_refs'             => wp_json_encode( array( 'source' => 'EXCEL', 'sheet' => $order['sheet'], 'row' => $order['row'] ), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ),
					'created_at'              => $now,
					'updated_at'              => $now,
				);
				if ( false === $wpdb->insert( $tables['orders'], $data ) ) {
					throw new RuntimeException( 'ECOMKIT_ORDER_INSERT_FAILED' );
				}

				$order_id = (int) $wpdb->insert_id;
				foreach ( $order['items'] as $item ) {
					$item_data = array(
						'order_id'             => $order_id,
						'product_name'          => $item['product_name'],
						'sku'                   => $item['sku'],
						'quantity'              => $item['quantity'],
						'raw_product_metadata'  => wp_json_encode( array( 'source' => 'EXCEL', 'sheet' => $item['sheet'], 'row' => $item['row'], 'raw_quantity' => $item['raw_quantity'], 'cells' => $item['raw_cells'] ), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ),
						'created_at'            => $now,
						'updated_at'            => $now,
					);
					if ( false === $wpdb->insert( $tables['order_items'], $item_data ) ) {
						throw new RuntimeException( 'ECOMKIT_ORDER_ITEM_INSERT_FAILED' );
					}
				}
			}

			foreach ( $result['errors'] as $error ) {
				$this->insert_error( $batch_id, $filename, $error, $now );
			}

			$metadata = array(
				'plugin_version' => ECOMKIT_VUIKHOE_VERSION,
				'parser_version' => $result['raw']['parser_version'] ?? 'wp2-v1',
				'file_size'      => $file_size,
				'total_rows'     => (int) $result['total_rows'],
				'valid_rows'     => (int) $result['valid_rows'],
				'header_rule'    => 'first-20-non-empty-rows-exact-normalized',
				'header_row'     => (int) ( $result['raw']['header_row'] ?? 0 ),
				'item_rows'      => (int) ( $result['raw']['item_rows'] ?? 0 ),
				'platform_counts'=> $result['raw']['platform_counts'] ?? array(),
			);
			$updated  = $wpdb->update(
				$tables['batches'],
				array(
					'status'          => $result['status'],
					'source_metadata' => wp_json_encode( $metadata ),
					'order_count'     => count( $result['orders'] ),
					'error_count'     => count( $result['errors'] ),
					'warning_count'   => 'WARNING' === $result['status'] ? count( $result['errors'] ) : 0,
					'finished_at'     => $now,
					'updated_at'      => $now,
				),
				array( 'id' => $batch_id )
			);
			if ( false === $updated ) {
				throw new RuntimeException( 'ECOMKIT_BATCH_UPDATE_FAILED' );
			}

			$wpdb->query( 'COMMIT' );
		} catch ( Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' );
			throw $exception;
		}
	}

	/**
	 * @param array<string,mixed> $error Safe error data.
	 */
	private function persist_failure( int $batch_id, string $filename, array $error ): void {
		global $wpdb;
		$tables = Ecomkit_Vuikhoe_DB::table_names();
		$now    = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		try {
			$this->insert_error( $batch_id, $filename, $error, $now );
			if ( false === $wpdb->update( $tables['batches'], array( 'status' => 'ERROR', 'error_count' => 1, 'finished_at' => $now, 'updated_at' => $now ), array( 'id' => $batch_id ) ) ) {
				throw new RuntimeException( 'ECOMKIT_BATCH_FAILURE_UPDATE_FAILED' );
			}
			$wpdb->query( 'COMMIT' );
		} catch ( Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' );
			throw $exception;
		}
	}

	/**
	 * @param array<string,mixed> $error Safe error data.
	 */
	private function insert_error( int $batch_id, string $filename, array $error, string $now ): void {
		global $wpdb;
		$tables = Ecomkit_Vuikhoe_DB::table_names();
		$data   = array(
			'batch_id'         => $batch_id,
			'source'           => 'EXCEL',
			'stage'            => 'IMPORT',
			'marketplace'      => null,
			'filename'         => $filename ?: null,
			'sheet_name'       => $error['sheet'] ?? null,
			'row_number'       => $error['row'] ?? null,
			'column_name'      => $error['column'] ?? null,
			'field_name'       => $error['field'] ?? null,
			'order_code'       => $error['safe_raw_value'] ?? null,
			'error_code'       => $error['error_code'],
			'severity'         => 'ERROR',
			'friendly_message' => $error['message'],
			'safe_raw_value'   => isset( $error['safe_raw_value'] ) ? mb_substr( (string) $error['safe_raw_value'], 0, 500 ) : null,
			'suggestion'       => $error['suggestion'],
			'created_at'       => $now,
		);
		if ( false === $wpdb->insert( $tables['errors'], $data ) ) {
			throw new RuntimeException( 'ECOMKIT_ERROR_INSERT_FAILED' );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function upload_error( string $code, string $message, string $suggestion ): array {
		return array( 'error_code' => $code, 'message' => $message, 'suggestion' => $suggestion );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get_batch_summary( int $batch_id ): ?array {
		global $wpdb;
		$tables = Ecomkit_Vuikhoe_DB::table_names();
		$batch  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['batches']} WHERE id = %d", $batch_id ), ARRAY_A );
		if ( ! is_array( $batch ) || 'EXCEL' !== $batch['source_type'] ) {
			return null;
		}
		$batch['metadata'] = json_decode( (string) $batch['source_metadata'], true ) ?: array();
		$batch['orders']   = $wpdb->get_results( $wpdb->prepare( "SELECT platform, raw_order_code, source_refs FROM {$tables['orders']} WHERE batch_id = %d ORDER BY id ASC LIMIT 100", $batch_id ), ARRAY_A );
		$batch['errors']   = $wpdb->get_results( $wpdb->prepare( "SELECT sheet_name, row_number, column_name, error_code, friendly_message, suggestion FROM {$tables['errors']} WHERE batch_id = %d ORDER BY id ASC LIMIT 100", $batch_id ), ARRAY_A );
		return $batch;
	}

	/** @return array<int,array<string,mixed>> */
	public function list_batches(): array {
		global $wpdb;
		$table = Ecomkit_Vuikhoe_DB::table_names()['batches'];
		return $wpdb->get_results( $wpdb->prepare( "SELECT id, created_at, source_filename, source_type, status, order_count, error_count FROM $table WHERE source_type = %s ORDER BY created_at DESC, id DESC LIMIT 100", 'EXCEL' ), ARRAY_A ) ?: array();
	}

	/** @return array<int,array<string,mixed>> */
	public function list_errors(): array {
		global $wpdb;
		$table = Ecomkit_Vuikhoe_DB::table_names()['errors'];
		return $wpdb->get_results( $wpdb->prepare( "SELECT batch_id, filename, sheet_name, row_number, column_name, error_code, friendly_message, suggestion, created_at FROM $table WHERE source = %s ORDER BY created_at DESC, id DESC LIMIT 100", 'EXCEL' ), ARRAY_A ) ?: array();
	}
}

