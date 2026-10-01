<?php
/** Safe Excel runtime readiness checks for administrators and imports. */

defined( 'ABSPATH' ) || exit;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class Ecomkit_Vuikhoe_Runtime_Diagnostics {
	/** Exact runtime extension requirements declared by PhpSpreadsheet 5.8.1. */
	public const REQUIRED_EXTENSIONS = array( 'ctype', 'dom', 'fileinfo', 'filter', 'gd', 'iconv', 'libxml', 'mbstring', 'simplexml', 'xml', 'xmlreader', 'xmlwriter', 'zip', 'zlib' );

	/** @return array<string,mixed> */
	public function snapshot(): array {
		$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
		$temp_dir = get_temp_dir();
		$uploads = wp_upload_dir( null, false );
		$missing = array_values( array_filter( self::REQUIRED_EXTENSIONS, static fn( string $extension ): bool => ! extension_loaded( $extension ) ) );

		return array(
			'plugin_version'       => ECOMKIT_VUIKHOE_VERSION,
			'db_schema_version'    => ECOMKIT_VUIKHOE_DB_VERSION,
			'php_version'          => PHP_VERSION,
			'wordpress_version'    => $GLOBALS['wp_version'] ?? '',
			'composer_autoload'    => is_readable( $autoload ),
			'phpspreadsheet'       => class_exists( Spreadsheet::class ) && class_exists( IOFactory::class ),
			'phpspreadsheet_version'=> '5.8.1',
			'temp_available'       => is_string( $temp_dir ) && '' !== $temp_dir && is_dir( $temp_dir ),
			'temp_writable'        => is_string( $temp_dir ) && '' !== $temp_dir && is_dir( $temp_dir ) && is_writable( $temp_dir ),
			'upload_available'     => empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) && is_dir( $uploads['basedir'] ),
			'upload_writable'      => empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) && is_dir( $uploads['basedir'] ) && is_writable( $uploads['basedir'] ),
			'required_extensions' => self::REQUIRED_EXTENSIONS,
			'missing_extensions'  => $missing,
			'memory_limit'        => (string) ini_get( 'memory_limit' ),
			'upload_max_filesize' => (string) ini_get( 'upload_max_filesize' ),
			'post_max_size'       => (string) ini_get( 'post_max_size' ),
		);
	}

	/** @return array{ok:bool,stage:string,classification:string,message:string} */
	public function dependency_check( ?array $snapshot = null ): array {
		$snapshot = $snapshot ?? array(
			'composer_autoload'   => is_readable( dirname( __DIR__ ) . '/vendor/autoload.php' ),
			'phpspreadsheet'      => class_exists( Spreadsheet::class ) && class_exists( IOFactory::class ),
			'missing_extensions' => array_values( array_filter( self::REQUIRED_EXTENSIONS, static fn( string $extension ): bool => ! extension_loaded( $extension ) ) ),
		);
		if ( ! $snapshot['composer_autoload'] || ! $snapshot['phpspreadsheet'] ) {
			return $this->failure( 'WORKBOOK_LOAD', 'EXCEL_RUNTIME_DEPENDENCY_MISSING', 'Composer autoload hoặc PhpSpreadsheet chưa sẵn sàng.' );
		}
		if ( ! empty( $snapshot['missing_extensions'] ) ) {
			return $this->failure( 'WORKBOOK_LOAD', 'EXCEL_RUNTIME_EXTENSION_MISSING', 'Thiếu PHP extension bắt buộc: ' . implode( ', ', $snapshot['missing_extensions'] ) . '.' );
		}
		return array( 'ok' => true, 'stage' => 'WORKBOOK_LOAD', 'classification' => 'READY', 'message' => 'Excel runtime sẵn sàng.' );
	}

	/**
	 * @param array{exists:bool,readable:bool,size:int}|null $probe Test-only probe.
	 * @return array{ok:bool,stage:string,classification:string,message:string}
	 */
	public function source_check( string $path, ?array $probe = null ): array {
		$state = $probe ?? array( 'exists' => is_file( $path ), 'readable' => is_readable( $path ), 'size' => is_file( $path ) ? (int) filesize( $path ) : 0 );
		if ( ! $state['exists'] ) {
			return $this->failure( 'WORKBOOK_LOAD', 'EXCEL_WORKBOOK_SOURCE_MISSING', 'File Excel tạm không còn tồn tại trước khi đọc.' );
		}
		if ( ! $state['readable'] ) {
			return $this->failure( 'WORKBOOK_LOAD', 'EXCEL_WORKBOOK_SOURCE_UNREADABLE', 'File Excel tạm không thể đọc.' );
		}
		if ( $state['size'] <= 0 ) {
			return $this->failure( 'WORKBOOK_LOAD', 'EXCEL_WORKBOOK_SOURCE_EMPTY', 'File Excel tạm đang trống.' );
		}
		return array( 'ok' => true, 'stage' => 'WORKBOOK_LOAD', 'classification' => 'READY', 'message' => 'Nguồn workbook sẵn sàng.' );
	}

	/** @return array{ok:bool,stage:string,classification:string,message:string} */
	public function self_test( ?callable $temp_factory = null, ?array $snapshot = null ): array {
		$dependency = $this->dependency_check( $snapshot );
		if ( ! $dependency['ok'] ) {
			return $dependency;
		}
		$snapshot = $snapshot ?? $this->snapshot();
		if ( ! $snapshot['temp_available'] || ! $snapshot['temp_writable'] ) {
			return $this->failure( 'WORKBOOK_LOAD', 'EXCEL_RUNTIME_TEMP_UNWRITABLE', 'Thư mục tạm PHP/WordPress không khả dụng hoặc không thể ghi.' );
		}

		$temp_path = null;
		try {
			$temp_path = $temp_factory ? $temp_factory() : wp_tempnam( 'ecomkit-runtime-test.xlsx' );
			if ( ! is_string( $temp_path ) || '' === $temp_path ) {
				return $this->failure( 'WORKBOOK_LOAD', 'EXCEL_RUNTIME_TEMP_UNWRITABLE', 'Không thể tạo file tạm cho Excel runtime test.' );
			}
			$book = new Spreadsheet();
			$book->getActiveSheet()->setCellValue( 'A1', 'ECOMKIT-RUNTIME-OK' );
			( new Xlsx( $book ) )->save( $temp_path );
			$book->disconnectWorksheets();
			$source = $this->source_check( $temp_path );
			if ( ! $source['ok'] ) {
				return $source;
			}
			$loaded = IOFactory::load( $temp_path );
			$value = (string) $loaded->getActiveSheet()->getCell( 'A1' )->getValue();
			$loaded->disconnectWorksheets();
			if ( 'ECOMKIT-RUNTIME-OK' !== $value ) {
				return $this->failure( 'WORKBOOK_LOAD', 'EXCEL_RUNTIME_ROUNDTRIP_FAILED', 'Excel runtime test không đọc lại đúng dữ liệu tổng hợp.' );
			}
			return array( 'ok' => true, 'stage' => 'WORKBOOK_LOAD', 'classification' => 'EXCEL_RUNTIME_READY', 'message' => 'Ghi và đọc XLSX tổng hợp thành công.' );
		} catch ( Throwable $exception ) {
			return $this->failure( 'WORKBOOK_LOAD', 'EXCEL_RUNTIME_SELF_TEST_FAILED', $this->sanitize_message( $exception->getMessage() ) );
		} finally {
			if ( is_string( $temp_path ) && is_file( $temp_path ) ) {
				wp_delete_file( $temp_path );
			}
		}
	}

	/** @return array{ok:false,stage:string,classification:string,message:string} */
	private function failure( string $stage, string $classification, string $message ): array {
		return array( 'ok' => false, 'stage' => $stage, 'classification' => $classification, 'message' => $message );
	}

	private function sanitize_message( string $message ): string {
		$message = preg_replace( '~(?:[A-Za-z]:)?[\\/](?:[^\s\\/]+[\\/])+[^\s]+~', '[path]', $message );
		$message = preg_replace( '/[\r\n\t]+/', ' ', (string) $message );
		return mb_substr( trim( (string) $message ), 0, 240 );
	}
}
