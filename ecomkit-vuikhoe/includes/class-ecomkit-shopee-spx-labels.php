<?php
/** Request-only Shopee SPX label text extraction. Never persists the PDF or customer data. */

defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Shopee_SPX_Labels {
	public const MAX_BYTES = 5242880; // 5 MiB; the PHP/WordPress limit may be lower.
	public const MAX_PAGES = 50;

	/**
	 * The PHP upload temporary file is the only server-side copy. It is removed
	 * even when validation or parsing fails. No WordPress upload/media API is used.
	 *
	 * @param array<string,mixed> $upload
	 * @param array<string,mixed> $result The already authorized and filtered Result.
	 * @return array<string,mixed> Safe summary and matched name/address map.
	 */
	public static function process_temporary_upload( array $upload, array $result, bool $require_http_upload = true ): array {
		$path = is_string( $upload['tmp_name'] ?? null ) ? $upload['tmp_name'] : '';
		try {
			self::validate( $upload, $require_http_upload );
			$parsed = self::parse_file( $path );
			$wanted = array();
			foreach ( (array) ( $result['rows'] ?? array() ) as $row ) {
				if ( 'SHOPEE' !== (string) ( $row['platform'] ?? '' ) ) { continue; }
				$id = $row['columns']['raw_order_code'] ?? null;
				if ( is_string( $id ) && '' !== $id ) { $wanted[ $id ] = true; }
			}
			$matched = array(); $unmatched = 0;
			foreach ( $parsed['labels'] as $id => $recipient ) {
				if ( ! isset( $wanted[ $id ] ) ) { $unmatched++; continue; }
				$matched[ $id ] = array(
					'name' => Ecomkit_Vuikhoe_Canonical_Result_Service::clipboard_text( $recipient['name'] ),
					'address' => Ecomkit_Vuikhoe_Canonical_Result_Service::clipboard_text( $recipient['address'] ),
				);
			}
			return array(
				'pages' => $parsed['pages'], 'parsed' => $parsed['parsed'], 'matched' => count( $matched ),
				'names_enriched' => count( $matched ), 'addresses_enriched' => count( $matched ),
				'unmatched' => $unmatched, 'duplicate_conflicts' => $parsed['duplicate_conflicts'],
				'page_status_counts' => $parsed['page_status_counts'], 'enrichment' => $matched,
			);
		} finally {
			if ( '' !== $path && is_file( $path ) && ! @unlink( $path ) ) { throw new RuntimeException( 'SHOPEE_PDF_TEMP_CLEANUP_FAILED' ); }
		}
	}

	/** @param array<string,mixed> $upload */
	private static function validate( array $upload, bool $require_http_upload ): void {
		$path = is_string( $upload['tmp_name'] ?? null ) ? $upload['tmp_name'] : '';
		if ( in_array( (int) ( $upload['error'] ?? UPLOAD_ERR_NO_FILE ), array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) { throw new RuntimeException( 'SHOPEE_PDF_TOO_LARGE' ); }
		if ( UPLOAD_ERR_OK !== (int) ( $upload['error'] ?? UPLOAD_ERR_NO_FILE ) || '' === $path || ! is_file( $path ) || ( $require_http_upload && ! is_uploaded_file( $path ) ) ) {
			throw new RuntimeException( 'SHOPEE_PDF_INVALID' );
		}
		$name = is_string( $upload['name'] ?? null ) ? $upload['name'] : '';
		if ( 'pdf' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) { throw new RuntimeException( 'SHOPEE_PDF_INVALID' ); }
		$limit = self::MAX_BYTES;
		if ( function_exists( 'wp_max_upload_size' ) ) { $limit = min( $limit, (int) wp_max_upload_size() ); }
		$size = filesize( $path );
		if ( false === $size || $size < 5 || $size > $limit ) { throw new RuntimeException( 'SHOPEE_PDF_TOO_LARGE' ); }
		$handle = fopen( $path, 'rb' );
		$header = false === $handle ? '' : fread( $handle, 5 );
		if ( false !== $handle ) { fclose( $handle ); }
		if ( '%PDF-' !== $header ) { throw new RuntimeException( 'SHOPEE_PDF_INVALID' ); }
		$mime = function_exists( 'finfo_open' ) ? ( new finfo( FILEINFO_MIME_TYPE ) )->file( $path ) : false;
		if ( false !== $mime && 'application/pdf' !== $mime ) { throw new RuntimeException( 'SHOPEE_PDF_INVALID' ); }
	}

	/** @return array<string,mixed> */
	public static function parse_file( string $path ): array {
		try { $document = ( new \Smalot\PdfParser\Parser() )->parseFile( $path ); }
		catch ( Throwable $exception ) { throw new RuntimeException( 'SHOPEE_PDF_INVALID', 0, $exception ); }
		$pages = $document->getPages();
		if ( count( $pages ) > self::MAX_PAGES ) { throw new RuntimeException( 'SHOPEE_PDF_TOO_MANY_PAGES' ); }
		$labels = array(); $conflicted = array(); $counts = array(); $parsed_count = 0; $text_pages = 0;
		foreach ( $pages as $page ) {
			try { $text = $page->getText(); $positions = $page->getDataTm(); }
			catch ( Throwable ) { $counts['UNSUPPORTED_PAGE'] = ( $counts['UNSUPPORTED_PAGE'] ?? 0 ) + 1; continue; }
			if ( '' !== trim( $text ) ) { $text_pages++; }
			$entry = self::parse_page_evidence( $text, $positions );
			$status = $entry['status']; $counts[ $status ] = ( $counts[ $status ] ?? 0 ) + 1;
			if ( 'PARSED' !== $status ) { continue; }
			$parsed_count++; $id = $entry['id'];
			if ( isset( $conflicted[ $id ] ) ) { continue; }
			$recipient = array( 'name' => $entry['name'], 'address' => $entry['address'] );
			if ( isset( $labels[ $id ] ) && $labels[ $id ] !== $recipient ) { unset( $labels[ $id ] ); $conflicted[ $id ] = true; }
			else { $labels[ $id ] = $recipient; }
		}
		if ( 0 === $text_pages ) { throw new RuntimeException( 'SHOPEE_PDF_TEXT_LAYER_REQUIRED' ); }
		if ( 0 === $parsed_count ) { throw new RuntimeException( 'SHOPEE_PDF_NO_VALID_LABELS' ); }
		return array( 'pages' => count( $pages ), 'parsed' => $parsed_count, 'labels' => $labels, 'duplicate_conflicts' => count( $conflicted ), 'page_status_counts' => $counts );
	}

	/**
	 * SPX A6 label: right-hand recipient pane, beneath the “Đến:” heading.
	 * Coordinate and label gates deliberately reject unknown PDF layouts.
	 * @param array<int,mixed> $positions
	 * @return array<string,string>
	 */
	public static function parse_page_evidence( string $text, array $positions ): array {
		if ( '' === trim( $text ) ) { return array( 'status' => 'UNSUPPORTED_PAGE' ); }
		if ( 1 !== preg_match( '/SPXVN[A-Z0-9]+/', $text ) ) { return array( 'status' => 'UNSUPPORTED_PAGE' ); }
		$lines = preg_split( '/\R/u', $text ) ?: array(); $ids = array();
		foreach ( $lines as $index => $line ) {
			$label = preg_replace( '/\s+/u', '', $line );
			if ( ! is_string( $label ) || ! str_contains( $label, 'Mãđơnhàng:' ) ) { continue; }
			foreach ( array_slice( $lines, $index, 4 ) as $candidate ) {
				$candidate = trim( $candidate );
				if ( 1 === preg_match( '/\A[0-9]{6}[A-Z0-9]{8}\z/D', $candidate ) ) { $ids[] = $candidate; }
			}
		}
		$ids = array_values( array_unique( $ids ) );
		if ( ! $ids ) { return array( 'status' => 'NO_ORDER_ID' ); }
		if ( 1 !== count( $ids ) ) { return array( 'status' => 'AMBIGUOUS_LAYOUT' ); }
		$groups = array();
		foreach ( $positions as $entry ) {
			$matrix = $entry[0] ?? null;
			if ( ! is_array( $matrix ) || ! isset( $matrix[4], $matrix[5], $entry[1] ) ) { continue; }
			$x = (float) $matrix[4]; $y = (float) $matrix[5];
			if ( $x < 140 || $x > 300 ) { continue; }
			$groups[ (string) round( $y, 1 ) ][] = array( 'x' => $x, 'text' => (string) $entry[1] );
		}
		$recipient_y = null; $recipient_x = null;
		foreach ( $groups as $y => $chunks ) {
			usort( $chunks, static fn( array $a, array $b ): int => $a['x'] <=> $b['x'] );
			$joined = implode( '', array_column( $chunks, 'text' ) );
			if ( str_starts_with( preg_replace( '/\s+/u', '', $joined ), 'Đến:' ) ) {
				if ( null !== $recipient_y ) { return array( 'status' => 'AMBIGUOUS_LAYOUT' ); }
				$recipient_y = (float) $y; $recipient_x = $chunks[0]['x'];
			}
		}
		if ( null === $recipient_y ) { return array( 'status' => 'NO_RECIPIENT' ); }
		$recipient_lines = array();
		foreach ( $groups as $y => $chunks ) {
			$distance = $recipient_y - (float) $y;
			if ( $distance < 3 || $distance > 55 ) { continue; }
			$chunks = array_values( array_filter( $chunks, static fn( array $chunk ): bool => $chunk['x'] >= $recipient_x - 2 ) );
			usort( $chunks, static fn( array $a, array $b ): int => $a['x'] <=> $b['x'] );
			$line = self::normalize_text( implode( '', array_column( $chunks, 'text' ) ) );
			if ( '' !== $line ) { $recipient_lines[ (string) $y ] = $line; }
		}
		krsort( $recipient_lines, SORT_NUMERIC ); $recipient_lines = array_values( $recipient_lines );
		if ( count( $recipient_lines ) < 2 ) { return array( 'status' => 'NO_RECIPIENT' ); }
		$name = array_shift( $recipient_lines ); $address = self::normalize_text( implode( ' ', $recipient_lines ) );
		if ( '' === $name || '' === $address || mb_strlen( $name ) > 120 || mb_strlen( $address ) > 700 ) { return array( 'status' => 'AMBIGUOUS_LAYOUT' ); }
		return array( 'status' => 'PARSED', 'id' => $ids[0], 'name' => $name, 'address' => $address );
	}

	private static function normalize_text( string $text ): string { return trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) ); }
}
