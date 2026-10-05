<?php
/** Local-only canonical snapshot persistence and Result queries. */

defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Canonical_Result_Service {
	/** Plain-text clipboard projection of the already authorized, filtered Result rows. */
	public static function clipboard_tsv( array $result, bool $include_headers = false ): string {
		$definitions = Ecomkit_Vuikhoe_Canonical_Columns::all();
		if ( 24 !== count( $definitions ) ) { throw new RuntimeException( 'CANONICAL_MAPPING_CONTRACT_INVALID' ); }
		$contracts = Ecomkit_Vuikhoe_Canonical_Columns::legacy_formula_contract();
		$lines = array();
		if ( $include_headers ) { $lines[] = implode( "\t", array_map( static fn( array $column ): string => self::clipboard_text( $column['label'] ), $definitions ) ); }
		foreach ( (array) ( $result['rows'] ?? array() ) as $row ) {
			$cells = array();
			foreach ( $definitions as $column ) {
				$key = $column['key']; $value = $row['columns'][ $key ] ?? null;
				if ( null === $value ) { $cells[] = ''; continue; }
				if ( isset( $contracts[ $key ]['denominator'] ) ) {
					$rational = $row['rational'][ $key ] ?? null;
					if ( ! is_array( $rational ) || ! isset( $rational['numerator'], $rational['denominator'] ) ) { $cells[] = ''; continue; }
					try { $cells[] = Ecomkit_Vuikhoe_Exact_Financial_Math::ratio( $rational['numerator'], $rational['denominator'], 18 )['ratio']; }
					catch ( InvalidArgumentException ) { $cells[] = ''; }
				} elseif ( Ecomkit_Vuikhoe_Canonical_Columns::is_money( $key ) ) {
					$cells[] = ( is_int( $value ) || is_string( $value ) ) && 1 === preg_match( '/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/D', (string) $value ) ? (string) $value : '';
				} elseif ( in_array( $key, array( 'order_date', 'vat_issued_date' ), true ) ) {
					$cells[] = is_string( $value ) && 1 === preg_match( '/\A\d{4}-\d{2}-\d{2}\z/D', $value ) ? $value : '';
				} else { $cells[] = self::clipboard_text( (string) $value ); }
			}
			$lines[] = implode( "\t", $cells );
		}
		return implode( "\n", $lines );
	}

	private static function clipboard_text( string $value ): string {
		$value = strip_tags( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$value = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $value ) );
		$check = (string) preg_replace( '/\A[\p{Z}\s]+/u', '', $value );
		return 1 === preg_match( '/\A[=+\-@]/u', $check ) ? "'" . $value : $value;
	}

	/** @return array<string,int> */
	public function materialize_batch( int $batch_id ): array {
		global $wpdb;
		if ( $batch_id < 1 ) { throw new InvalidArgumentException( 'CANONICAL_SOURCE_INVALID' ); }
		$tables = Ecomkit_Vuikhoe_DB::table_names();
		$batch = $wpdb->get_row( $wpdb->prepare( "SELECT id, source_metadata FROM {$tables['batches']} WHERE id = %d AND source_type = %s", $batch_id, 'EXCEL' ), ARRAY_A );
		if ( ! is_array( $batch ) ) { throw new RuntimeException( 'CANONICAL_SOURCE_INVALID' ); }
		$orders = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['orders']} WHERE batch_id = %d ORDER BY id ASC", $batch_id ), ARRAY_A );
		$orders = is_array( $orders ) ? $orders : array();
		$batch_source = json_decode( (string) ( $batch['source_metadata'] ?? '' ), true );
		$batch_source = is_array( $batch_source ) ? $batch_source : array();
		$items = $this->items_by_order( array_column( $orders, 'id' ) );
		$materializer = new Ecomkit_Vuikhoe_Canonical_Result_Materializer( wp_timezone() );
		$now = current_time( 'mysql', true );
		$updates = array(); $service_fee_source_gaps = 0; $service_fee_negative_reviews = 0;
		foreach ( $orders as $order ) {
			try {
				$order_items = $items[ (int) $order['id'] ] ?? array();
				$snapshot = $materializer->materialize( $order, $order_items, $batch_source );
				$json = wp_json_encode( $snapshot, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
				if ( ! is_string( $json ) || 24 !== count( $snapshot['columns'] ) ) { throw new RuntimeException( 'CANONICAL_ROW_BUILD_FAILED' ); }
				$fee_state = $snapshot['formula_state']['service_platform_fee']['status'] ?? '';
				if ( 'MISSING_OPERANDS' === $fee_state ) { $service_fee_source_gaps++; }
				if ( 'SERVICE_FEE_NEGATIVE_REVIEW' === $fee_state ) { $service_fee_negative_reviews++; }
				$updates[] = array( 'id' => (int) $order['id'], 'json' => $json, 'fingerprint' => $materializer->fingerprint( $order, $order_items, $batch_source ) );
			} catch ( Throwable $exception ) {
				if ( in_array( $exception->getMessage(), array( 'CANONICAL_MAPPING_CONTRACT_INVALID', 'CANONICAL_SOURCE_INVALID' ), true ) ) { throw $exception; }
				throw new RuntimeException( 'CANONICAL_ROW_BUILD_FAILED', 0, $exception );
			}
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { throw new RuntimeException( 'CANONICAL_RESULT_PERSIST_FAILED' ); }
		try {
			foreach ( $updates as $update ) {
				$ok = $wpdb->update( $tables['orders'], array( 'canonical_data' => $update['json'], 'canonical_result_version' => Ecomkit_Vuikhoe_Canonical_Columns::VERSION, 'canonical_materialized_at' => $now, 'canonical_source_fingerprint' => $update['fingerprint'] ), array( 'id' => $update['id'] ) );
				if ( false === $ok ) { throw new RuntimeException( 'CANONICAL_RESULT_PERSIST_FAILED' ); }
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException( 'CANONICAL_RESULT_PERSIST_FAILED' ); }
		} catch ( Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' );
			throw new RuntimeException( 'CANONICAL_RESULT_PERSIST_FAILED', 0, $exception );
		}
		return array( 'orders' => count( $orders ), 'rows' => count( $updates ), 'service_fee_source_gaps' => $service_fee_source_gaps, 'service_fee_negative_reviews' => $service_fee_negative_reviews );
	}

	/** @return array<string,mixed>|null */
	public function get_batch_result( int $batch_id, string $platform = '', string $matching = '' ): ?array {
		global $wpdb;
		$tables = Ecomkit_Vuikhoe_DB::table_names();
		$batch = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['batches']} WHERE id = %d AND source_type = %s", $batch_id, 'EXCEL' ), ARRAY_A );
		if ( ! is_array( $batch ) ) { return null; }
		$all = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['orders']} WHERE batch_id = %d ORDER BY id ASC", $batch_id ), ARRAY_A );
		$all = is_array( $all ) ? $all : array();
		$batch_source = json_decode( (string) ( $batch['source_metadata'] ?? '' ), true );
		$batch_source = is_array( $batch_source ) ? $batch_source : array();
		$items = $this->items_by_order( array_column( $all, 'id' ) );
		$materializer = new Ecomkit_Vuikhoe_Canonical_Result_Materializer( wp_timezone() );
		$rows = array(); $counts = array( 'total' => count( $all ), 'SHOPEE' => 0, 'LAZADA' => 0, 'MATCHED' => 0, 'NOT_FOUND_IN_SHOPEE' => 0, 'DETAIL_MISSING' => 0, 'ready' => 0, 'warnings' => 0 );
		foreach ( $all as $order ) {
			$p = (string) ( $order['platform'] ?? '' ); $m = (string) ( $order['matching_status'] ?? '' );
			if ( isset( $counts[ $p ] ) ) { $counts[ $p ]++; } if ( isset( $counts[ $m ] ) ) { $counts[ $m ]++; }
			$snapshot = json_decode( (string) ( $order['canonical_data'] ?? '' ), true );
			$version_current = Ecomkit_Vuikhoe_Canonical_Columns::VERSION === (string) ( $order['canonical_result_version'] ?? '' ) && Ecomkit_Vuikhoe_Canonical_Columns::VERSION === (string) ( $snapshot['version'] ?? '' );
			$ready = is_array( $snapshot ) && $version_current && is_array( $snapshot['columns'] ?? null ) && 24 === count( $snapshot['columns'] );
			$stale = is_array( $snapshot ) && ( ! $version_current || ( $ready && ! hash_equals( (string) ( $order['canonical_source_fingerprint'] ?? '' ), $materializer->fingerprint( $order, $items[ (int) $order['id'] ] ?? array(), $batch_source ) ) ) );
			if ( $ready ) { $counts['ready']++; } if ( $stale || in_array( $m, array( 'NOT_FOUND_IN_SHOPEE', 'DETAIL_MISSING' ), true ) ) { $counts['warnings']++; }
			if ( ( '' !== $platform && $platform !== $p ) || ( '__BLANK__' === $matching ? '' !== $m : ( '' !== $matching && $matching !== $m ) ) ) { continue; }
			$rows[] = array( 'id' => (int) $order['id'], 'platform' => $p, 'matching_status' => $m, 'stale' => $stale, 'ready' => $ready, 'columns' => $ready ? $snapshot['columns'] : array(), 'rational' => $ready ? (array) ( $snapshot['rational'] ?? array() ) : array(), 'formula_state' => $ready ? (array) ( $snapshot['formula_state'] ?? array() ) : array() );
		}
		$metadata = json_decode( (string) ( $batch['source_metadata'] ?? '' ), true );
		return array( 'batch' => $batch, 'reconciliation' => is_array( $metadata ) ? ( $metadata['shopee_reconciliation'] ?? null ) : null, 'counts' => $counts, 'columns' => Ecomkit_Vuikhoe_Canonical_Columns::all(), 'rows' => $rows );
	}

	/** @return array<int,array<string,mixed>> */
	public function list_batches(): array {
		global $wpdb; $table = Ecomkit_Vuikhoe_DB::table_names()['batches'];
		return $wpdb->get_results( $wpdb->prepare( "SELECT id, source_filename, status, order_count, created_at FROM $table WHERE source_type = %s ORDER BY created_at DESC, id DESC LIMIT 100", 'EXCEL' ), ARRAY_A ) ?: array();
	}

	/** @param array<int,mixed> $order_ids @return array<int,array<int,array<string,mixed>>> */
	private function items_by_order( array $order_ids ): array {
		global $wpdb; $grouped = array(); $ids = array_values( array_filter( array_map( 'absint', $order_ids ) ) );
		if ( ! $ids ) { return $grouped; }
		$table = Ecomkit_Vuikhoe_DB::table_names()['order_items'];
		$rows = $wpdb->get_results( "SELECT id, order_id, sku, product_name, quantity, price, variant FROM $table WHERE order_id IN (" . implode( ',', $ids ) . ') ORDER BY order_id ASC, id ASC', ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) { $grouped[ (int) $row['order_id'] ][] = $row; }
		return $grouped;
	}
}
