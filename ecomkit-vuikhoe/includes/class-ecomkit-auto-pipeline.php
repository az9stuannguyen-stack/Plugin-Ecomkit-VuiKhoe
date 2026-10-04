<?php
/** WP-Cron, one-upload pipeline. State lives in existing Batch source_metadata. */
defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Auto_Pipeline {
	public const HOOK = 'ecomkit_vuikhoe_auto_pipeline_step';
	private const META_KEY = 'auto_pipeline';
	private const INCOME_WINDOWS = 6;
	private const LOCK_SECONDS = 180;
	public function __construct( private array $operations = array() ) {}

	public function register(): void { add_action( self::HOOK, array( $this, 'run_one' ), 10, 1 ); }

	public function start( int $batch_id, bool $resume = false ): array {
		$batch = $this->batch( $batch_id ); $metadata = $this->metadata( $batch );
		if ( ! in_array( (string) ( $batch['status'] ?? '' ), array( 'SUCCESS', 'WARNING' ), true ) ) { throw new RuntimeException( 'PIPELINE_IMPORT_NOT_READY' ); }
		$existing = $metadata[ self::META_KEY ] ?? null;
		if ( is_array( $existing ) && ! $resume ) { if ( ! in_array( (string) ( $existing['status'] ?? '' ), array( 'SUCCESS', 'WARNING', 'ERROR' ), true ) ) { $this->schedule( $batch_id ); } return $existing; }
		$counts = (array) ( $metadata['platform_counts'] ?? array() );
		$shopee = (int) ( $counts['SHOPEE'] ?? 0 );
		$state = array( 'status' => 'QUEUED', 'stage' => $shopee > 0 ? 'RECONCILE' : 'MATERIALIZE', 'import_status' => (string) $batch['status'], 'reconciliation_status' => $shopee > 0 ? 'QUEUED' : 'SKIPPED', 'payment_status' => $shopee > 0 ? 'QUEUED' : 'SKIPPED', 'income_status' => $shopee > 0 ? 'QUEUED' : 'SKIPPED', 'canonical_status' => 'QUEUED', 'started_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ), 'completed_at' => null, 'counts' => array( 'excel_orders' => (int) ( $batch['order_count'] ?? 0 ), 'shopee_orders' => $shopee, 'lazada_orders' => (int) ( $counts['LAZADA'] ?? 0 ), 'shopee_matched' => 0, 'payment_fetched' => 0, 'payment_reused' => 0, 'payment_failed' => 0, 'income_matched' => 0, 'income_empty' => 0, 'canonical_rows' => 0 ), 'warnings' => array(), 'errors' => array(), 'payment_index' => 0, 'income_index' => 0, 'income_cursor' => '', 'income_pages' => 0 );
		if ( $resume && is_array( $existing ) && is_array( $metadata['shopee_reconciliation'] ?? null ) ) {
			$state['stage'] = 'PAYMENT'; $state['reconciliation_status'] = (string) ( $metadata['shopee_reconciliation']['status'] ?? 'WARNING' ); $state['counts']['shopee_matched'] = (int) ( $metadata['shopee_reconciliation']['matched_count'] ?? 0 );
		}
		$this->save( $batch_id, $state );
		try { $this->schedule( $batch_id ); }
		catch ( Throwable ) { $state['warnings'][] = 'PIPELINE_SCHEDULE_FAILED'; $state['stage'] = 'MATERIALIZE'; try { $state = $this->materialize( $batch_id, $state ); } catch ( Throwable $exception ) { $state['errors'][] = self::safe_code( $exception ); $state['status'] = 'ERROR'; } $this->save( $batch_id, $state ); }
		return $state;
	}

	/** One bounded business step per cron event. */
	public function run_one( int $batch_id ): void {
		if ( $batch_id < 1 ) { return; }
		$lock_key = 'ecomkit_pipeline_lock_' . $batch_id;
		if ( ! add_option( $lock_key, time() + self::LOCK_SECONDS, '', false ) ) {
			if ( (int) get_option( $lock_key, 0 ) >= time() ) { $this->schedule( $batch_id, 30 ); return; }
			delete_option( $lock_key );
			if ( ! add_option( $lock_key, time() + self::LOCK_SECONDS, '', false ) ) { $this->schedule( $batch_id, 30 ); return; }
		}
		try {
			$state = $this->state( $batch_id );
			if ( ! is_array( $state ) || in_array( (string) ( $state['status'] ?? '' ), array( 'SUCCESS', 'WARNING', 'ERROR' ), true ) ) { return; }
			$stage = (string) ( $state['stage'] ?? '' );
			$state['status'] = 'PROCESSING'; $state['updated_at'] = current_time( 'mysql', true ); $this->save( $batch_id, $state );
			try {
				switch ( $stage ) {
					case 'RECONCILE': $state = $this->reconcile( $batch_id, $state ); break;
					case 'PAYMENT': $state = $this->payment( $batch_id, $state ); break;
					case 'INCOME_INIT': $state = $this->income_init( $batch_id, $state ); break;
					case 'INCOME': $state = $this->income( $batch_id, $state ); break;
					case 'MATERIALIZE': $state = $this->materialize( $batch_id, $state ); break;
					default: throw new RuntimeException( 'PIPELINE_STAGE_INVALID' );
				}
			} catch ( Throwable $exception ) {
				$state['errors'][] = self::safe_code( $exception ); $state['status'] = 'ERROR'; $state['completed_at'] = current_time( 'mysql', true );
			}
			$state['updated_at'] = current_time( 'mysql', true ); $this->save( $batch_id, $state );
			if ( ! in_array( $state['status'], array( 'SUCCESS', 'WARNING', 'ERROR' ), true ) ) {
				try { $this->schedule( $batch_id ); }
				catch ( Throwable ) { $state['warnings'][] = 'PIPELINE_SCHEDULE_FAILED'; try { $state = $this->materialize( $batch_id, $state ); } catch ( Throwable $exception ) { $state['errors'][] = self::safe_code( $exception ); $state['status'] = 'ERROR'; } $this->save( $batch_id, $state ); }
			}
		} finally { delete_option( $lock_key ); }
	}

	public function state( int $batch_id ): ?array { $metadata = $this->metadata( $this->batch( $batch_id ) ); return is_array( $metadata[ self::META_KEY ] ?? null ) ? $metadata[ self::META_KEY ] : null; }
	public static function operator_message( string $code ): string { return match ( $code ) {
		'SHOPEE_CONNECTION_NOT_READY' => 'Đã nhập Excel nhưng chưa thể bổ sung dữ liệu Shopee. Kiểm tra kết nối Marketplace rồi tiếp tục pipeline.',
		'SHOP_SELECTION_REQUIRED' => 'Có nhiều shop Shopee sẵn sàng. Chọn đúng shop trong Chẩn đoán nâng cao trước khi tiếp tục.',
		'SHOPEE_INCOME_EMPTY' => 'Chưa có Income record; Result vẫn sử dụng Excel, Order Detail và Payment đã xác thực.',
		'SHOPEE_INCOME_PAGINATION_INCOMPLETE', 'INCOME_HISTORY_SEARCH_BOUNDED' => 'Tìm kiếm Income chưa bao phủ toàn bộ lịch sử; không kết luận đơn không tồn tại.',
		'PAYMENT_INTERRUPTED_NO_AUTO_RETRY', 'INCOME_INTERRUPTED_NO_AUTO_RETRY', 'SHOPEE_RECON_INTERRUPTED_NO_AUTO_RETRY' => 'Bước provider bị gián đoạn; hệ thống không tự gọi lại để tránh lặp yêu cầu không rõ kết quả.',
		default => 'Result vẫn hiển thị dữ liệu có nguồn xác thực; mở Chẩn đoán nâng cao để kiểm tra nếu cần.',
	}; }

	private function reconcile( int $batch_id, array $state ): array {
		if ( ! empty( $state['reconcile_inflight'] ) ) { $state['warnings'][] = 'SHOPEE_RECON_INTERRUPTED_NO_AUTO_RETRY'; $state['reconciliation_status'] = 'WARNING'; $state['payment_status'] = 'SKIPPED'; $state['income_status'] = 'SKIPPED'; $state['stage'] = 'MATERIALIZE'; return $state; }
		$ready = isset( $this->operations['ready_connections'] ) ? ( $this->operations['ready_connections'] )() : ( new Ecomkit_Vuikhoe_Shopee_Reconciliation_Service() )->ready_connections();
		if ( 1 !== count( $ready ) ) { $state['warnings'][] = $ready ? 'SHOP_SELECTION_REQUIRED' : 'SHOPEE_CONNECTION_NOT_READY'; $state['reconciliation_status'] = 'WARNING'; $state['payment_status'] = 'SKIPPED'; $state['income_status'] = 'SKIPPED'; $state['stage'] = 'MATERIALIZE'; return $state; }
		$state['connection_id'] = (int) $ready[0]['id']; $state['reconcile_inflight'] = true; $this->save( $batch_id, $state );
		try {
			$limits = array( 'window_offset' => (int) ( $state['reconcile_window_offset'] ?? 0 ), 'max_windows' => 1, 'max_pages' => 2, 'max_detail_batches' => 1 );
			$summary = isset( $this->operations['reconcile'] ) ? ( $this->operations['reconcile'] )( $batch_id, $state['connection_id'], $limits ) : ( new Ecomkit_Vuikhoe_Shopee_Reconciliation_Service() )->reconcile_batch( $batch_id, $state['connection_id'], $limits );
			$state['counts']['shopee_matched'] = (int) ( $summary['matched_count'] ?? 0 );
			$state['reconciliation_status'] = (string) ( $summary['status'] ?? 'WARNING' );
			if ( 'SUCCESS' !== $state['reconciliation_status'] ) { $state['warnings'][] = 'SHOPEE_RECON_PARTIAL'; }
			$state['reconcile_window_offset'] = (int) ( $summary['next_window_offset'] ?? 1 );
			$state['stage'] = $state['reconcile_window_offset'] < (int) ( $summary['total_windows'] ?? 1 ) ? 'RECONCILE' : 'PAYMENT';
		} catch ( Throwable $exception ) { $code = self::safe_code( $exception ); if ( preg_match( '/(?:CREDENTIAL|DECRYPT|PARTNER_KEY|TOKEN_CORRUPT|CONFIG_FINGERPRINT)/', $code ) ) { throw $exception; } $state['warnings'][] = $code; $state['reconciliation_status'] = 'WARNING'; $state['payment_status'] = 'SKIPPED'; $state['income_status'] = 'SKIPPED'; $state['stage'] = 'MATERIALIZE'; }
		unset( $state['reconcile_inflight'] ); return $state;
	}

	private function payment( int $batch_id, array $state ): array {
		$orders = $this->matched_orders( $batch_id ); $index = (int) ( $state['payment_index'] ?? 0 );
		if ( ! empty( $state['payment_inflight'] ) ) { $state['counts']['payment_failed']++; $state['warnings'][] = 'PAYMENT_INTERRUPTED_NO_AUTO_RETRY'; unset( $state['payment_inflight'] ); }
		if ( $index >= count( $orders ) ) { $state['payment_status'] = $state['counts']['payment_failed'] ? 'WARNING' : 'SUCCESS'; $state['stage'] = 'INCOME_INIT'; return $state; }
		$order = $orders[ $index ]; $state['payment_index'] = $index + 1;
		$snapshot = json_decode( (string) ( $order['payment_normalized_data'] ?? '' ), true );
		if ( is_array( $snapshot ) && hash_equals( (string) $order['marketplace_order_id'], (string) ( $snapshot['marketplaceOrderId'] ?? '' ) ) ) { $state['counts']['payment_reused']++; return $state; }
		$state['payment_inflight'] = (int) $order['id']; $this->save( $batch_id, $state );
		try { if ( isset( $this->operations['payment'] ) ) { ( $this->operations['payment'] )( $batch_id, (int) $order['id'] ); } else { ( new Ecomkit_Vuikhoe_Shopee_Payment_Service() )->inspect_matched_order( $batch_id, (int) $order['id'] ); } $state['counts']['payment_fetched']++; }
		catch ( Throwable $exception ) { $code = self::safe_code( $exception ); if ( preg_match( '/(?:CREDENTIAL_DECRYPT|CREDENTIAL_INCOMPLETE|PARTNER_KEY)/', $code ) ) { throw $exception; } $state['counts']['payment_failed']++; $state['warnings'][] = $code; }
		unset( $state['payment_inflight'] ); return $state;
	}

	private function income_init( int $batch_id, array $state ): array {
		$orders = $this->matched_orders( $batch_id );
		if ( ! $orders ) { $state['income_status'] = 'EMPTY'; $state['stage'] = 'MATERIALIZE'; return $state; }
		$all_valid = true;
		foreach ( $orders as $order ) { $snapshot = json_decode( (string) ( $order['income_normalized_data'] ?? '' ), true ); if ( ! is_array( $snapshot ) || ! hash_equals( (string) $order['marketplace_order_id'], (string) ( $snapshot['marketplaceOrderId'] ?? '' ) ) || ! in_array( (string) ( $snapshot['incomeBucket'] ?? '' ), array( 'PENDING', 'RELEASED' ), true ) ) { $all_valid = false; break; } }
		if ( $all_valid ) { $state['income_status'] = 'REUSED'; $state['stage'] = 'MATERIALIZE'; return $state; }
		$today = new DateTimeImmutable( 'today', wp_timezone() ); $yesterday = $today->modify( '-1 day' );
		$plan = array( array( 'bucket' => 'PENDING', 'from' => $yesterday->format( 'Y-m-d' ), 'to' => $today->format( 'Y-m-d' ) ) );
		$oldest = null;
		foreach ( $orders as $order ) { $date = substr( (string) ( $order['order_date'] ?? '' ), 0, 10 ); if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && ( null === $oldest || $date < $oldest ) ) { $oldest = $date; } }
		for ( $i = 0; $i < self::INCOME_WINDOWS; $i++ ) {
			$end = $today->modify( '-' . ( $i * 14 ) . ' days' ); $start = $end->modify( '-13 days' );
			if ( null !== $oldest && $end->format( 'Y-m-d' ) < $oldest ) { break; }
			$plan[] = array( 'bucket' => 'RELEASED', 'from' => $start->format( 'Y-m-d' ), 'to' => $end->format( 'Y-m-d' ) );
		}
		if ( null !== $oldest && $oldest < $today->modify( '-83 days' )->format( 'Y-m-d' ) ) { $state['warnings'][] = 'INCOME_HISTORY_SEARCH_BOUNDED'; $state['income_warning'] = true; }
		$state['income_plan'] = $plan; $state['income_index'] = 0; $state['income_cursor'] = ''; $state['income_pages'] = 0; $state['stage'] = 'INCOME'; return $state;
	}

	private function income( int $batch_id, array $state ): array {
		$plan = (array) ( $state['income_plan'] ?? array() ); $index = (int) ( $state['income_index'] ?? 0 );
		if ( $index >= count( $plan ) ) { $state['income_status'] = ! empty( $state['income_warning'] ) ? 'WARNING' : ( $state['counts']['income_matched'] ? 'SUCCESS' : 'EMPTY' ); $state['stage'] = 'MATERIALIZE'; return $state; }
		if ( ! empty( $state['income_inflight'] ) ) { $state['warnings'][] = 'INCOME_INTERRUPTED_NO_AUTO_RETRY'; $state['income_warning'] = true; return $this->next_income_query( $state ); }
		$query = $plan[ $index ]; $cursor = (string) ( $state['income_cursor'] ?? '' ); $state['income_inflight'] = true; $this->save( $batch_id, $state );
		try {
			$result = isset( $this->operations['income_page'] ) ? ( $this->operations['income_page'] )( (int) $state['connection_id'], $query, $cursor ) : ( new Ecomkit_Vuikhoe_Shopee_Income_Service() )->scan_page( (int) $state['connection_id'], (string) $query['bucket'], (string) $query['from'], (string) $query['to'], $cursor );
			if ( 'SHOPEE_INCOME_EMPTY' === $result['classification'] ) { $state['counts']['income_empty']++; return $this->next_income_query( $state ); }
			$by_sn = array(); foreach ( $this->matched_orders( $batch_id ) as $order ) { $by_sn[ (string) $order['marketplace_order_id'] ] = $order; }
			foreach ( $result['records'] as $item ) {
				$sn = (string) $item['order_sn']; if ( ! isset( $by_sn[ $sn ] ) ) { continue; }
				$order = $by_sn[ $sn ]; $existing = json_decode( (string) ( $order['income_normalized_data'] ?? '' ), true ); if ( is_array( $existing ) && hash_equals( $sn, (string) ( $existing['marketplaceOrderId'] ?? '' ) ) && in_array( (string) ( $existing['incomeBucket'] ?? '' ), array( 'PENDING', 'RELEASED' ), true ) ) { continue; }
				if ( isset( $this->operations['income_store'] ) ) { ( $this->operations['income_store'] )( $batch_id, $order, $item, $query, $result['request_id'] ); } else { ( new Ecomkit_Vuikhoe_Shopee_Income_Service() )->store_matched_record( $batch_id, (int) $order['id'], $sn, (int) $state['connection_id'], $item, (string) $query['bucket'], (string) $result['request_id'] ); }
				$state['counts']['income_matched']++;
			}
			$state['income_pages'] = (int) ( $state['income_pages'] ?? 0 ) + 1;
			$next = (string) $result['next_cursor'];
			if ( '' === $next ) { return $this->next_income_query( $state ); }
			if ( $next === $cursor || in_array( $next, (array) ( $state['income_seen'] ?? array() ), true ) || (int) $state['income_pages'] >= Ecomkit_Vuikhoe_Shopee_Income_Service::MAX_PAGES ) { $state['warnings'][] = 'SHOPEE_INCOME_PAGINATION_INCOMPLETE'; $state['income_warning'] = true; return $this->next_income_query( $state ); }
			$state['income_seen'][] = $next; $state['income_cursor'] = $next; unset( $state['income_inflight'] ); return $state;
		} catch ( Throwable $exception ) { $state['warnings'][] = self::safe_code( $exception ); $state['income_warning'] = true; return $this->next_income_query( $state ); }
	}

	private function next_income_query( array $state ): array { $state['income_index'] = (int) $state['income_index'] + 1; $state['income_cursor'] = ''; $state['income_pages'] = 0; $state['income_seen'] = array(); unset( $state['income_inflight'] ); return $state; }

	private function materialize( int $batch_id, array $state ): array {
		$result = isset( $this->operations['materialize'] ) ? ( $this->operations['materialize'] )( $batch_id ) : ( new Ecomkit_Vuikhoe_Canonical_Result_Service() )->materialize_batch( $batch_id );
		$state['counts']['canonical_rows'] = (int) ( $result['rows'] ?? 0 ); $state['canonical_status'] = 'READY'; $state['stage'] = 'RESULT_READY'; $state['status'] = $state['warnings'] ? 'WARNING' : 'SUCCESS'; $state['completed_at'] = current_time( 'mysql', true ); return $state;
	}

	private function matched_orders( int $batch_id ): array {
		if ( isset( $this->operations['matched_orders'] ) ) { return ( $this->operations['matched_orders'] )( $batch_id ); }
		global $wpdb; $tables = Ecomkit_Vuikhoe_DB::table_names();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, marketplace_order_id, order_date, payment_normalized_data, income_normalized_data FROM {$tables['orders']} WHERE batch_id = %d AND platform = %s AND matching_status = %s AND connection_id IS NOT NULL ORDER BY id ASC", $batch_id, 'SHOPEE', 'MATCHED' ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	private function batch( int $batch_id ): array { global $wpdb; $tables = Ecomkit_Vuikhoe_DB::table_names(); $row = $wpdb->get_row( $wpdb->prepare( "SELECT id, source_type, status, order_count, source_metadata FROM {$tables['batches']} WHERE id = %d", $batch_id ), ARRAY_A ); if ( ! is_array( $row ) || 'EXCEL' !== (string) ( $row['source_type'] ?? '' ) ) { throw new RuntimeException( 'PIPELINE_BATCH_NOT_FOUND' ); } return $row; }
	private function metadata( array $batch ): array { $data = json_decode( (string) ( $batch['source_metadata'] ?? '' ), true ); return is_array( $data ) ? $data : array(); }
	private function save( int $batch_id, array $state ): void { global $wpdb; $tables = Ecomkit_Vuikhoe_DB::table_names(); $metadata = $this->metadata( $this->batch( $batch_id ) ); $metadata[ self::META_KEY ] = $state; $json = wp_json_encode( $metadata, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ); if ( ! is_string( $json ) || false === $wpdb->update( $tables['batches'], array( 'source_metadata' => $json, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $batch_id ) ) ) { throw new RuntimeException( 'PIPELINE_STATE_PERSIST_FAILED' ); } }
	private function schedule( int $batch_id, int $delay = 1 ): void { if ( ! wp_next_scheduled( self::HOOK, array( $batch_id ) ) && ! wp_schedule_single_event( time() + $delay, self::HOOK, array( $batch_id ) ) ) { throw new RuntimeException( 'PIPELINE_SCHEDULE_FAILED' ); } }
	private static function safe_code( Throwable $exception ): string { $code = strtoupper( sanitize_key( $exception->getMessage() ) ); return preg_match( '/^[A-Z][A-Z0-9_]{2,90}$/', $code ) ? $code : 'PIPELINE_STAGE_FAILED'; }
}
