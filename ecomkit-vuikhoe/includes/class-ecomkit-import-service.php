<?php
/**
 * Synchronous WP.2 upload orchestration and read models.
 */

defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Import_Exception extends RuntimeException {
	/** @param array<string,mixed> $diagnostic */
	public function __construct( private array $diagnostic, ?Throwable $previous = null ) {
		parent::__construct( 'ECOMKIT_IMPORT_STAGE_FAILED', 0, $previous );
	}

	/** @return array<string,mixed> */
	public function diagnostic(): array {
		return $this->diagnostic;
	}
}

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
			$diagnostic = $this->failure_diagnostic( 'BATCH_PERSIST', new RuntimeException( 'ECOMKIT_BATCH_CREATE_FAILED' ), array( 'entity' => 'batches', 'operation' => 'insert' ), (string) $wpdb->last_error );
			throw new Ecomkit_Vuikhoe_Import_Exception( $diagnostic );
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
			$diagnostic = $exception instanceof Ecomkit_Vuikhoe_Import_Exception
				? $exception->diagnostic()
				: $this->failure_diagnostic( 'WORKBOOK_LOAD', $exception );
			$error = $this->upload_error( 'EXCEL_IMPORT_FAILED', 'Không thể hoàn tất quá trình nhập Excel.', sprintf( 'Vui lòng thử lại hoặc liên hệ quản trị viên kèm Batch ID #%d.', $batch_id ) );
			$error['stage'] = $diagnostic['stage'];
			$error['diagnostic'] = $diagnostic;
			$this->persist_failure( $batch_id, $original_filename, $error );
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
		if ( ! empty( $checked['ext'] ) && 'xlsx' !== $checked['ext'] ) {
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
		$stage = 'BATCH_PERSIST';
		$context = array( 'entity' => 'transaction', 'operation' => 'begin', 'row' => null, 'sheet' => $result['raw']['sheet'] ?? null );
		if ( ! Ecomkit_Vuikhoe_DB::supports_import_transactions() ) {
			throw new Ecomkit_Vuikhoe_Import_Exception( $this->failure_diagnostic( $stage, new RuntimeException( 'ECOMKIT_NON_TRANSACTIONAL_TABLE' ), $context ) );
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new Ecomkit_Vuikhoe_Import_Exception( $this->failure_diagnostic( $stage, new RuntimeException( 'ECOMKIT_TRANSACTION_START_FAILED' ), $context, (string) $wpdb->last_error ) );
		}

		try {
			foreach ( $result['orders'] as $order ) {
				$stage = 'ORDER_PERSIST';
				$context = array( 'entity' => 'orders', 'operation' => 'insert', 'row' => $order['row'], 'sheet' => $order['sheet'] );
				$code = (string) $order['order_code'];
				$data = array(
					'batch_id'                => $batch_id,
					'connection_id'           => null,
					'platform'                => (string) $order['platform'],
					'marketplace_order_id'    => $code,
					'raw_order_code'          => $code,
					'normalized_order_code'   => $code,
					'matching_status'         => null,
					'order_date'              => $order['order_date'] ?? null,
					'raw_source_metadata'     => wp_json_encode( array( 'source' => 'EXCEL', 'combined_identity' => $order['raw_identity'], 'platform_label' => $order['raw_platform'], 'cells' => $order['raw_cells'] ), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ),
					'source_refs'             => wp_json_encode( array( 'source' => 'EXCEL', 'sheet' => $order['sheet'], 'row' => $order['row'] ), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ),
					'created_at'              => $now,
					'updated_at'              => $now,
				);
				$invalid_field = $this->invalid_order_contract_field( $data );
				if ( null !== $invalid_field ) {
					$context['field'] = $invalid_field;
					throw new RuntimeException( 'ECOMKIT_ORDER_CONTRACT_INVALID' );
				}
				if ( false === $wpdb->insert( $tables['orders'], $data ) ) {
					throw new RuntimeException( 'ECOMKIT_ORDER_INSERT_FAILED:' . $this->sanitized_db_error( (string) $wpdb->last_error ) );
				}

				$order_id = (int) $wpdb->insert_id;
				foreach ( $order['items'] as $item ) {
					$stage = 'ITEM_PERSIST';
					$context = array( 'entity' => 'order_items', 'operation' => 'insert', 'row' => $item['row'], 'sheet' => $item['sheet'] );
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
						throw new RuntimeException( 'ECOMKIT_ORDER_ITEM_INSERT_FAILED:' . $this->sanitized_db_error( (string) $wpdb->last_error ) );
					}
				}
			}

			foreach ( $result['errors'] as $error ) {
				$stage = 'ERROR_PERSIST';
				$context = array( 'entity' => 'errors', 'operation' => 'insert', 'row' => $error['row'] ?? null, 'sheet' => $error['sheet'] ?? null );
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
				'date_column'    => isset( $result['raw']['date_column'] ) ? (int) $result['raw']['date_column'] : null,
				'item_rows'      => (int) ( $result['raw']['item_rows'] ?? 0 ),
				'platform_counts'=> $result['raw']['platform_counts'] ?? array(),
				'failure_diagnostic' => $result['diagnostic'] ?? null,
			);
			$stage = 'BATCH_FINALIZE';
			$context = array( 'entity' => 'batches', 'operation' => 'update', 'row' => null, 'sheet' => $result['raw']['sheet'] ?? null );
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
				throw new RuntimeException( 'ECOMKIT_BATCH_UPDATE_FAILED:' . $this->sanitized_db_error( (string) $wpdb->last_error ) );
			}

			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'ECOMKIT_TRANSACTION_COMMIT_FAILED:' . $this->sanitized_db_error( (string) $wpdb->last_error ) );
			}
		} catch ( Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' );
			throw new Ecomkit_Vuikhoe_Import_Exception( $this->failure_diagnostic( $stage, $exception, $context, (string) $wpdb->last_error ), $exception );
		}
	}

	/**
	 * @param array<string,mixed> $error Safe error data.
	 */
	private function persist_failure( int $batch_id, string $filename, array $error ): void {
		global $wpdb;
		$tables = Ecomkit_Vuikhoe_DB::table_names();
		$now    = current_time( 'mysql', true );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new RuntimeException( 'ECOMKIT_FAILURE_TRANSACTION_START_FAILED' );
		}
		try {
			$this->insert_error( $batch_id, $filename, $error, $now );
			$metadata = array( 'plugin_version' => ECOMKIT_VUIKHOE_VERSION, 'failure_diagnostic' => $error['diagnostic'] ?? array( 'stage' => 'ERROR_PERSIST' ) );
			if ( false === $wpdb->update( $tables['batches'], array( 'status' => 'ERROR', 'source_metadata' => wp_json_encode( $metadata ), 'error_count' => 1, 'finished_at' => $now, 'updated_at' => $now ), array( 'id' => $batch_id ) ) ) {
				throw new RuntimeException( 'ECOMKIT_BATCH_FAILURE_UPDATE_FAILED' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'ECOMKIT_FAILURE_TRANSACTION_COMMIT_FAILED' );
			}
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
			'stage'            => $error['stage'] ?? ( $error['diagnostic']['stage'] ?? 'IMPORT' ),
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
			throw new RuntimeException( 'ECOMKIT_ERROR_INSERT_FAILED:' . $this->sanitized_db_error( (string) $wpdb->last_error ) );
		}
	}

	/** @param array<string,mixed> $context @return array<string,mixed> */
	private function failure_diagnostic( string $stage, Throwable $exception, array $context = array(), string $db_error = '' ): array {
		$message = $exception->getMessage();
		$classification = str_contains( $message, ':' ) ? strstr( $message, ':', true ) : $message;
		return array(
			'stage'             => $stage,
			'classification'    => mb_substr( preg_replace( '/[^A-Z0-9_]/', '', strtoupper( (string) $classification ) ), 0, 100 ) ?: 'EXCEL_IMPORT_FAILED',
			'exception_class'   => get_class( $exception ),
			'exception_message' => $this->sanitize_diagnostic_message( $message ),
			'php_version'       => PHP_VERSION,
			'phpspreadsheet'    => class_exists( \PhpOffice\PhpSpreadsheet\IOFactory::class ) ? 'Loaded' : 'Not loaded',
			'db_error'          => $this->sanitized_db_error( $db_error ),
			'sheet'             => $context['sheet'] ?? null,
			'row'               => $context['row'] ?? null,
			'entity'            => $context['entity'] ?? null,
			'operation'         => $context['operation'] ?? null,
			'field'             => $context['field'] ?? null,
			'db_column'         => $this->db_error_column( $db_error ),
			'rollback'          => in_array( $stage, array( 'ORDER_PERSIST', 'ITEM_PERSIST', 'ERROR_PERSIST', 'BATCH_FINALIZE' ), true ),
		);
	}

	/** @param array<string,mixed> $data */
	private function invalid_order_contract_field( array $data ): ?string {
		foreach ( array( 'batch_id', 'platform', 'marketplace_order_id', 'raw_order_code', 'normalized_order_code', 'source_refs', 'created_at', 'updated_at' ) as $field ) {
			if ( ! array_key_exists( $field, $data ) || null === $data[ $field ] || ( is_string( $data[ $field ] ) && '' === trim( $data[ $field ] ) ) ) {
				return $field;
			}
		}
		return null;
	}

	private function db_error_column( string $message ): ?string {
		if ( preg_match( "/Column ['`]([A-Za-z0-9_]+)['`] cannot be null/i", $message, $match ) ) {
			return $match[1];
		}
		return null;
	}

	private function sanitize_diagnostic_message( string $message ): string {
		$message = preg_replace( '~(?:[A-Za-z]:)?[\\/](?:[^\s\\/]+[\\/])+[^\s]+~', '[path]', $message );
		$message = preg_replace( '/[\r\n\t]+/', ' ', $message );
		$message = preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $message );
		return mb_substr( trim( (string) $message ), 0, 240 );
	}

	private function sanitized_db_error( string $message ): string {
		if ( '' === trim( $message ) ) {
			return '';
		}
		$message = preg_replace( "/(['\"]).*?\\1/u", '$1…$1', $message );
		return $this->sanitize_diagnostic_message( (string) $message );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function upload_error( string $code, string $message, string $suggestion ): array {
		return array( 'error_code' => $code, 'stage' => 'UPLOAD_VALIDATE', 'message' => $message, 'suggestion' => $suggestion );
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
		return $wpdb->get_results( "SELECT batch_id, source, stage, marketplace, filename, sheet_name, row_number, column_name, order_code, error_code, friendly_message, suggestion, created_at FROM $table ORDER BY created_at DESC, id DESC LIMIT 100", ARRAY_A ) ?: array();
	}
}

