<?php
defined( 'ABSPATH' ) || exit;

/** Explicit admin reconciliation only. Existing Order rows own batch-scoped provider evidence. */
final class Ecomkit_Vuikhoe_Lazada_Reconciliation_Service {
	public function __construct( private ?Ecomkit_Vuikhoe_Lazada_Token_Service $tokens = null, private mixed $client_factory = null ) {
		$this->tokens ??= new Ecomkit_Vuikhoe_Lazada_Token_Service();
		$this->client_factory ??= static fn( int $id ) => new Ecomkit_Vuikhoe_Lazada_Order_Client( $id );
	}
	public function active_connections(): array {
		return array_values( array_filter( $this->tokens->safe_connections(), static fn( array $c ): bool => 'ACTIVE' === $c['status'] ) );
	}
	/** Safe admin evidence preview; separate from the canonical 24-column table. */
	public function batch_evidence( int $batch_id ): array {
		Ecomkit_Vuikhoe_Security::require_management_capability(); global $wpdb; $t = Ecomkit_Vuikhoe_DB::table_names();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, batch_id, platform, marketplace_order_id, connection_id, matching_status, provider_normalized_data FROM {$t['orders']} WHERE batch_id = %d AND platform = %s ORDER BY id ASC LIMIT 100", $batch_id, 'LAZADA' ), ARRAY_A );
		$result = array();
		foreach ( $rows ?? array() as $row ) {
			$e = json_decode( (string) ( $row['provider_normalized_data'] ?? '' ), true ); $e = is_array( $e ) ? $e : array();
			$result[] = array( 'excel_id' => $row['marketplace_order_id'], 'state' => $row['matching_status'], 'provider_id' => $e['providerOrderId'] ?? null, 'exact_match' => $e['reconciliation']['exact_match'] ?? false, 'get_order_success' => $e['reconciliation']['get_order_success'] ?? null, 'get_items_success' => $e['reconciliation']['get_items_success'] ?? null, 'item_count' => is_array( $e['items'] ?? null ) ? count( $e['items'] ) : null, 'raw_statuses' => $e['order']['rawStatuses'] ?? array(), 'pii' => $e['order']['piiAvailability'] ?? null, 'error_code' => $e['reconciliation']['error_code'] ?? null );
		}
		return $result;
	}
	/** Persistent aggregate across the entire Batch, never the 100-row preview or provider. */
	public function batch_summary( int $batch_id ): array {
		Ecomkit_Vuikhoe_Security::require_management_capability(); global $wpdb; $t = Ecomkit_Vuikhoe_DB::table_names();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, platform, marketplace_order_id, matching_status, provider_normalized_data FROM {$t['orders']} WHERE batch_id = %d AND platform = %s ORDER BY id ASC", $batch_id, 'LAZADA' ), ARRAY_A );
		if ( ! is_array( $rows ) ) { throw new RuntimeException( 'LAZADA_RECON_READ_FAILED' ); }
		return self::aggregate( $rows );
	}
	private static function terminal( array $row ): bool {
		return in_array( $row['matching_status'] ?? '', array( 'MATCHED', 'NOT_FOUND_IN_LAZADA', 'UNMATCHED', 'ERROR' ), true );
	}
	private static function aggregate( array $rows ): array {
		$s = array( 'total_lazada' => count( $rows ), 'eligible_count' => 0, 'skipped_blank' => 0, 'processed' => 0, 'matched' => 0, 'unmatched' => 0, 'errors' => 0, 'get_order_success' => 0, 'get_items_success' => 0, 'last_order_id' => 0 );
		foreach ( $rows as $row ) {
			if ( ! is_string( $row['marketplace_order_id'] ) || '' === $row['marketplace_order_id'] ) { ++$s['skipped_blank']; continue; }
			++$s['eligible_count'];
			if ( ! self::terminal( $row ) ) { continue; }
			++$s['processed']; ++$s[ 'MATCHED' === $row['matching_status'] ? 'matched' : ( 'ERROR' === $row['matching_status'] ? 'errors' : 'unmatched' ) ];
			$e = json_decode( (string) ( $row['provider_normalized_data'] ?? '' ), true );
			$s['get_order_success'] += (int) ( $e['reconciliation']['get_order_success'] ?? false );
			$s['get_items_success'] += (int) ( $e['reconciliation']['get_items_success'] ?? false );
			$s['last_order_id'] = max( $s['last_order_id'], (int) $row['id'] );
		}
		$s['pending'] = $s['eligible_count'] - $s['processed'];
		$s['status'] = $s['pending'] > 0 ? 'PROCESSING' : ( $s['errors'] ? 'INCOMPLETE' : ( $s['unmatched'] ? 'WARNING' : 'SUCCESS' ) );
		return $s;
	}
	/** One order by default (at most two 20s reads); next explicit call resumes a persisted checkpoint.
	 * Test/internal callers may choose up to five, never unbounded parallel work. */
	public function reconcile_batch( int $batch_id, ?int $connection_id = null, int $max_orders = 1 ): array {
		Ecomkit_Vuikhoe_Security::require_management_capability();
		return ( new Ecomkit_Vuikhoe_Lazada_Lock() )->synchronized( 'reconciliation:batch:' . $batch_id, fn() => $this->process( $batch_id, $connection_id, max( 1, min( 5, $max_orders ) ) ) );
	}
	private function process( int $batch_id, ?int $connection_id, int $limit ): array {
		global $wpdb;
		$t = Ecomkit_Vuikhoe_DB::table_names();
		$batch = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['batches']} WHERE id = %d", $batch_id ), ARRAY_A );
		if ( ! is_array( $batch ) || 'EXCEL' !== $batch['source_type'] || ! in_array( $batch['status'], array( 'SUCCESS', 'WARNING' ), true ) ) { throw new RuntimeException( 'LAZADA_RECON_BATCH_NOT_ELIGIBLE' ); }
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, batch_id, platform, marketplace_order_id, connection_id, source_refs, matching_status, provider_normalized_data FROM {$t['orders']} WHERE batch_id = %d AND platform = %s ORDER BY id ASC", $batch_id, 'LAZADA' ), ARRAY_A );
		if ( ! is_array( $rows ) ) { throw new RuntimeException( 'LAZADA_RECON_READ_FAILED' ); }
		$eligible = array_values( array_filter( $rows, static fn( array $o ): bool => 'LAZADA' === $o['platform'] && is_string( $o['marketplace_order_id'] ) && '' !== $o['marketplace_order_id'] ) );
		$metadata = json_decode( (string) ( $batch['source_metadata'] ?? '' ), true ); $metadata = is_array( $metadata ) ? $metadata : array();
		if ( in_array( $metadata['auto_pipeline']['status'] ?? '', array( 'QUEUED', 'PROCESSING' ), true ) ) { throw new RuntimeException( 'LAZADA_RECON_BATCH_BUSY' ); }
		if ( ! $eligible ) {
			$summary = array( 'connection_id' => null, 'shop_id' => '', 'total_lazada' => count( $rows ), 'eligible_count' => 0, 'skipped_blank' => count( $rows ), 'matched' => 0, 'unmatched' => 0, 'errors' => 0, 'get_order_success' => 0, 'get_items_success' => 0, 'processed' => 0, 'pending' => 0, 'last_order_id' => 0, 'status' => 'SUCCESS', 'completed_at' => current_time( 'mysql', true ) );
			$metadata['lazada_reconciliation'] = $summary;
			if ( false === $wpdb->update( $t['batches'], array( 'source_metadata' => self::json( $metadata ) ), array( 'id' => $batch_id ) ) ) { throw new RuntimeException( 'LAZADA_RECON_PERSIST_FAILED' ); }
			return $summary;
		}
		$previous = $metadata['lazada_reconciliation'] ?? array();
		$persisted = self::aggregate( $rows );
		$summary = array_merge( $previous, $persisted );
		if ( 0 === $persisted['pending'] ) {
			$summary['completed_at'] ??= current_time( 'mysql', true );
			$metadata['lazada_reconciliation'] = $summary;
			if ( false === $wpdb->update( $t['batches'], array( 'source_metadata' => self::json( $metadata ) ), array( 'id' => $batch_id ) ) ) { throw new RuntimeException( 'LAZADA_RECON_PERSIST_FAILED' ); }
			return $summary;
		}
		$resume = 'PROCESSING' === ( $previous['status'] ?? '' );
		if ( $resume ) {
			if ( null !== $connection_id && $connection_id !== (int) $previous['connection_id'] ) { throw new RuntimeException( 'LAZADA_RECON_CONNECTION_CONFLICT' ); }
			$connection_id = (int) $previous['connection_id'];
		}
		$connections = $this->active_connections();
		if ( null === $connection_id ) {
			if ( count( $connections ) !== 1 ) { throw new RuntimeException( count( $connections ) ? 'LAZADA_RECON_CONNECTION_AMBIGUOUS' : 'LAZADA_ORDER_AUTH_REQUIRED' ); }
			$connection_id = (int) $connections[0]['id'];
		}
		$connection = null;
		foreach ( $connections as $c ) { if ( (int) $c['id'] === $connection_id ) { $connection = $c; break; } }
		// An explicitly selected but no-longer-active shop is an auth error per eligible row, never unmatched.
		$summary = $resume ? $previous : array( 'connection_id' => $connection_id, 'shop_id' => $connection['shop'] ?? '', 'total_lazada' => count( $rows ), 'eligible_count' => count( $eligible ), 'skipped_blank' => count( $rows ) - count( $eligible ), 'matched' => 0, 'unmatched' => 0, 'errors' => 0, 'get_order_success' => 0, 'get_items_success' => 0, 'processed' => 0, 'last_order_id' => 0, 'started_at' => current_time( 'mysql', true ) );
		$summary = array_merge( $summary, $persisted );
		$pending = array_values( array_filter( $eligible, static fn( array $o ): bool => ! self::terminal( $o ) ) );

		$start = microtime( true ); $done = 0;
		foreach ( $pending as $row ) {
			if ( $done >= $limit || ( $done > 0 && microtime( true ) - $start >= 10 ) ) { break; }
			$outcome = $this->read( $row, $connection_id, $connection );
			$next = $summary;
			++$next['processed']; ++$next[ 'MATCHED' === $outcome['state'] ? 'matched' : ( 'UNMATCHED' === $outcome['state'] ? 'unmatched' : 'errors' ) ];
			$next['get_order_success'] += (int) $outcome['get_order_success'];
			$next['get_items_success'] += (int) $outcome['get_items_success'];
			$next['last_order_id'] = max( (int) $summary['last_order_id'], (int) $row['id'] );
			$next['pending'] = count( $eligible ) - $next['processed'];
			$next['status'] = $next['processed'] < count( $eligible ) ? 'PROCESSING' : ( $next['errors'] ? 'INCOMPLETE' : ( $next['unmatched'] ? 'WARNING' : 'SUCCESS' ) );
			$next['completed_at'] = 'PROCESSING' === $next['status'] ? null : current_time( 'mysql', true );
			$this->persist( $batch, $metadata, $row, $connection_id, $outcome, $next );
			$metadata['lazada_reconciliation'] = $next; $summary = $next; ++$done;
		}
		return $summary;
	}
	private function read( array $row, int $connection_id, ?array $connection ): array {
		$id = $row['marketplace_order_id'];
		$r = array( 'state' => 'ERROR', 'connection_resolved' => null !== $connection, 'exact_match' => false, 'get_order_success' => false, 'get_items_success' => false, 'order' => null, 'items' => null, 'evidence' => array(), 'code' => null, 'stage' => 'RECON_ORDER' );
		try {
			if ( ! empty( $row['connection_id'] ) && (int) $row['connection_id'] !== $connection_id ) { throw new RuntimeException( 'LAZADA_RECON_CONNECTION_CONFLICT' ); }
			if ( ! $connection || ! in_array( $connection['lifecycle'], array( 'READY', 'REFRESH_NEEDED' ), true ) ) { throw new RuntimeException( 'LAZADA_ORDER_AUTH_REQUIRED' ); }
			if ( 1 !== preg_match( '/^[0-9]{1,40}$/D', $id ) || ! preg_match( '/[1-9]/', $id ) ) { throw new RuntimeException( 'LAZADA_RECON_ID_INVALID' ); }
			$client = ( $this->client_factory )( $connection_id );
			$order = $client->get_order( $id ); $r['evidence']['order'] = $client->last_evidence();
			$r['get_order_success'] = true;
			if ( $id !== ( $order['providerOrderId'] ?? null ) || $connection_id !== ( $order['connectionId'] ?? null ) ) { throw new RuntimeException( 'LAZADA_RECON_IDENTITY_MISMATCH' ); }
			$r['exact_match'] = true;
			$address = $order['addressShipping'] ?? null; $pii = 'MISSING';
			foreach ( $address ?? array() as $value ) { if ( is_string( $value ) && '' !== $value ) { if ( str_contains( $value, '*' ) ) { $pii = 'MASKED'; break; } $pii = 'AVAILABLE'; } }
			unset( $order['addressShipping'] ); $order['piiAvailability'] = $pii; $r['order'] = $order;
			$r['stage'] = 'RECON_ITEMS';
			$items = $client->get_order_items( $id ); $r['evidence']['items'] = $client->last_evidence();
			$seen = array();
			foreach ( $items as $item ) {
				if ( $id !== ( $item['providerOrderId'] ?? null ) || $connection_id !== ( $item['connectionId'] ?? null ) || ! is_string( $item['orderItemId'] ?? null ) || isset( $seen['id:' . $item['orderItemId']] ) ) { throw new RuntimeException( 'LAZADA_RECON_IDENTITY_MISMATCH' ); }
				$seen['id:' . $item['orderItemId']] = true;
			}
			$r['items'] = $items; $r['get_items_success'] = true; $r['state'] = 'MATCHED';
		} catch ( Throwable $e ) {
			$allowed = array( 'LAZADA_ORDER_NOT_FOUND', 'LAZADA_ORDER_AUTH_REQUIRED', 'LAZADA_ORDER_NETWORK_ERROR', 'LAZADA_ORDER_HTTP_ERROR', 'LAZADA_ORDER_PROVIDER_ERROR', 'LAZADA_ORDER_INVALID_RESPONSE', 'LAZADA_RECON_ID_INVALID', 'LAZADA_RECON_IDENTITY_MISMATCH', 'LAZADA_RECON_CONNECTION_CONFLICT' );
			$r['code'] = in_array( $e->getMessage(), $allowed, true ) ? $e->getMessage() : 'LAZADA_ORDER_INVALID_RESPONSE';
			if ( 'RECON_ORDER' === $r['stage'] && 'LAZADA_ORDER_NOT_FOUND' === $r['code'] ) { $r['state'] = 'UNMATCHED'; }
			if ( $e instanceof Ecomkit_Vuikhoe_Lazada_Provider_Exception ) { $r['evidence'][ 'RECON_ITEMS' === $r['stage'] ? 'items' : 'order' ] = $e->diagnostic; }
		}
		return $r;
	}
	private static function json( array $v ): string {
		$json = wp_json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( ! is_string( $json ) ) { throw new RuntimeException( 'LAZADA_RECON_PERSIST_FAILED' ); } return $json;
	}
	/** Atomic per Order and checkpoint; a later failure never rolls back earlier successful Orders. */
	private function persist( array $batch, array $metadata, array $row, int $connection, array $r, array $summary ): void {
		global $wpdb; $t = Ecomkit_Vuikhoe_DB::table_names(); $now = current_time( 'mysql', true );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { throw new RuntimeException( 'LAZADA_RECON_PERSIST_FAILED' ); }
		try {
			$current = $wpdb->get_row( $wpdb->prepare( "SELECT id, batch_id, platform, marketplace_order_id, connection_id FROM {$t['orders']} WHERE id = %d FOR UPDATE", (int) $row['id'] ), ARRAY_A );
			if ( ! is_array( $current ) || (int) $current['batch_id'] !== (int) $batch['id'] || 'LAZADA' !== $current['platform'] || $current['marketplace_order_id'] !== $row['marketplace_order_id'] || (int) $current['connection_id'] !== (int) $row['connection_id'] ) { throw new RuntimeException( 'LAZADA_RECON_PERSIST_FAILED' ); }
			$normalized = array( 'platform' => 'LAZADA', 'connectionId' => $connection, 'providerOrderId' => $r['order']['providerOrderId'] ?? null, 'order' => $r['order'], 'items' => $r['items'], 'reconciliation' => array( 'state' => $r['state'], 'exact_match' => $r['exact_match'], 'get_order_success' => $r['get_order_success'], 'get_items_success' => $r['get_items_success'], 'error_code' => $r['code'], 'evidence' => $r['evidence'] ) );
			$data = array( 'matching_status' => 'UNMATCHED' === $r['state'] ? 'NOT_FOUND_IN_LAZADA' : $r['state'], 'provider_normalized_data' => self::json( $normalized ), 'provider_raw_data' => null, 'matched_at' => $r['exact_match'] ? $now : null, 'updated_at' => $now );
			// Never rebind an existing Order to another shop, even on a failed attempt.
			if ( 'LAZADA_RECON_CONNECTION_CONFLICT' === $r['code'] ) { unset( $data['provider_normalized_data'], $data['provider_raw_data'], $data['matched_at'] ); }
			elseif ( $r['connection_resolved'] ) { $data['connection_id'] = $connection; }
			if ( false === $wpdb->update( $t['orders'], $data, array( 'id' => (int) $row['id'], 'batch_id' => (int) $batch['id'], 'platform' => 'LAZADA', 'marketplace_order_id' => $row['marketplace_order_id'] ) ) ) { throw new RuntimeException( 'LAZADA_RECON_PERSIST_FAILED' ); }
			if ( false === $wpdb->delete( $t['errors'], array( 'batch_id' => (int) $batch['id'], 'order_id' => (int) $row['id'], 'source' => 'LAZADA_RECON' ) ) ) { throw new RuntimeException( 'LAZADA_RECON_PERSIST_FAILED' ); }
			if ( null !== $r['code'] ) {
				$source = json_decode( (string) ( $row['source_refs'] ?? '' ), true ) ?: array();
				$message = 'UNMATCHED' === $r['state'] ? 'Không tìm thấy đơn Lazada này trong shop đã chọn.' : ( 'LAZADA_ORDER_AUTH_REQUIRED' === $r['code'] ? 'Token Lazada cần được ủy quyền lại.' : ( 'RECON_ITEMS' === $r['stage'] ? 'Mã đơn đã khớp nhưng không thể lấy đầy đủ sản phẩm.' : 'Không thể xác nhận đơn Lazada; chưa kết luận không tìm thấy.' ) );
				$error = array( 'batch_id' => (int) $batch['id'], 'order_id' => (int) $row['id'], 'source' => 'LAZADA_RECON', 'stage' => $r['stage'], 'marketplace' => 'LAZADA', 'filename' => $batch['source_filename'] ?? null, 'sheet_name' => $source['sheet'] ?? null, 'row_number' => $source['row'] ?? null, 'order_code' => $row['marketplace_order_id'], 'error_code' => $r['code'], 'severity' => 'ERROR', 'friendly_message' => $message, 'safe_raw_value' => self::json( $r['evidence'] ), 'suggestion' => 'Kiểm tra shop, quyền API và Chi tiết kỹ thuật; sửa kết nối nếu cần rồi chạy lại đối chiếu.', 'created_at' => $now );
				if ( false === $wpdb->insert( $t['errors'], $error ) ) { throw new RuntimeException( 'LAZADA_RECON_PERSIST_FAILED' ); }
			}
			$metadata['lazada_reconciliation'] = $summary;
			if ( false === $wpdb->update( $t['batches'], array( 'source_metadata' => self::json( $metadata ), 'updated_at' => $now ), array( 'id' => (int) $batch['id'] ) ) || false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException( 'LAZADA_RECON_PERSIST_FAILED' ); }
		} catch ( Throwable $e ) { $wpdb->query( 'ROLLBACK' ); throw new RuntimeException( 'LAZADA_RECON_PERSIST_FAILED' ); }
	}
}
