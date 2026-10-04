<?php
/** Local-only canonical snapshot persistence and Result queries. */

defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Canonical_Result_Service {
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
		$updates = array();
		foreach ( $orders as $order ) {
			try {
				$order_items = $items[ (int) $order['id'] ] ?? array();
				$snapshot = $materializer->materialize( $order, $order_items, $batch_source );
				$json = wp_json_encode( $snapshot, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
				if ( ! is_string( $json ) || 24 !== count( $snapshot['columns'] ) ) { throw new RuntimeException( 'CANONICAL_ROW_BUILD_FAILED' ); }
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
		return array( 'orders' => count( $orders ), 'rows' => count( $updates ) );
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
			$rows[] = array( 'id' => (int) $order['id'], 'platform' => $p, 'matching_status' => $m, 'stale' => $stale, 'ready' => $ready, 'columns' => $ready ? $snapshot['columns'] : array() );
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
