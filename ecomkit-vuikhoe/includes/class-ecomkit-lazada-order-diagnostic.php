<?php
defined( 'ABSPATH' ) || exit;
/** Admin-only explicit live reads. AJAX response only, no payload persistence/transients. */
final class Ecomkit_Vuikhoe_Lazada_Order_Diagnostic {
	public function __construct( private ?Ecomkit_Vuikhoe_Lazada_Token_Service $tokens = null, private mixed $client_factory = null ) {
		$this->tokens ??= new Ecomkit_Vuikhoe_Lazada_Token_Service();
		$this->client_factory ??= static fn( int $id ): Ecomkit_Vuikhoe_Lazada_Order_Client => new Ecomkit_Vuikhoe_Lazada_Order_Client( $id );
	}
	public function register(): void { add_action( 'wp_ajax_ecomkit_lazada_order_diagnostic', array( $this, 'ajax' ) ); }
	private static function value( array $input, string $key ): string {
		$value = $input[$key] ?? ''; if ( ! is_string( $value ) || strlen( $value ) > 128 ) { throw new RuntimeException( 'LAZADA_ORDER_DIAGNOSTIC_INPUT_INVALID' ); } return $value;
	}
	private static function date( string $value, DateTimeZone $timezone ): DateTimeImmutable {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $timezone );
		if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) { throw new RuntimeException( 'LAZADA_ORDER_DIAGNOSTIC_WINDOW_INVALID' ); } return $date;
	}
	private static function pii( ?array $address ): array {
		$fields = array(); $present = false; $masked = false;
		foreach ( $address ?? array() as $key => $value ) {
			$state = null === $value || '' === $value ? 'MISSING' : ( str_contains( $value, '*' ) ? 'MASKED' : 'AVAILABLE' );
			$fields[$key] = $state; if ( 'country' !== $key ) { $present = $present || 'MISSING' !== $state; $masked = $masked || 'MASKED' === $state; }
		}
		return array( 'classification' => $masked ? 'MASKED' : ( $present ? 'AVAILABLE' : 'MISSING' ), 'fields' => $fields, 'note' => 'Chỉ xác nhận field có giá trị/masked; không khẳng định đã có đầy đủ quyền PII.' );
	}
	private static function safe_order( array $order ): array {
		$order['piiAvailability'] = self::pii( $order['addressShipping'] ); unset( $order['addressShipping'] ); return $order;
	}
	/** Raw payload NEVER returned. This method performs no business writes. Lifecycle may rotate tokens. */
	public function run( array $input ): array {
		Ecomkit_Vuikhoe_Security::require_management_capability();
		$id = self::value( $input, 'connection_id' );
		if ( 1 !== preg_match( '/^[1-9][0-9]{0,9}$/D', $id ) ) { throw new RuntimeException( 'LAZADA_ORDER_AUTH_REQUIRED' ); }
		$ready = false;
		foreach ( $this->tokens->safe_connections() as $connection ) { if ( (string) $connection['id'] === $id && 'ACTIVE' === $connection['status'] && in_array( $connection['lifecycle'], array( 'READY', 'REFRESH_NEEDED' ), true ) ) { $ready = true; break; } }
		if ( ! $ready ) { throw new RuntimeException( 'LAZADA_ORDER_AUTH_REQUIRED' ); }
		$order_id = self::value( $input, 'order_id' );
		if ( '' !== $order_id ) { $order_id = Ecomkit_Vuikhoe_Lazada_Order_Normalizer::id( $order_id ); }
		$list = '1' === self::value( $input, 'check_list' );
		if ( '' === $order_id && ! $list ) { throw new RuntimeException( 'LAZADA_ORDER_DIAGNOSTIC_INPUT_INVALID' ); }
		$from = $list ? self::date( self::value( $input, 'order_date' ), new DateTimeZone( 'Asia/Ho_Chi_Minh' ) ) : null;
		$offset = self::value( $input, 'offset' ); $offset = '' === $offset ? '0' : $offset;
		if ( $list && ( 1 !== preg_match( '/^[0-9]{1,4}$/D', $offset ) || (int) $offset > 5000 || 0 !== (int) $offset % 100 ) ) { throw new RuntimeException( 'LAZADA_ORDER_DIAGNOSTIC_INPUT_INVALID' ); }
		$client = ( $this->client_factory )( (int) $id );
		$result = array( 'connection' => 'READY', 'order_id' => $order_id, 'request_count' => 0, 'request_count_scope' => 'ORDER_API_ONLY; optional lifecycle refresh excluded', 'persistence' => false, 'canonical_mapping' => false, 'checks' => array() );
		// Each endpoint has independent evidence: list failure cannot erase direct success.
		$check = static function( string $stage, callable $read ) use ( &$result, $client ): void {
			++$result['request_count'];
			try { $result['checks'][$stage] = array( 'success' => true, 'data' => $read(), 'evidence' => $client->last_evidence() ); }
			catch ( Throwable $e ) { $result['checks'][$stage] = array( 'success' => false, 'classification' => self::error_code( $e ), 'message' => self::message( self::error_code( $e ), $stage ), 'evidence' => $e instanceof Ecomkit_Vuikhoe_Lazada_Provider_Exception ? $e->diagnostic : array() ); }
		};
		if ( '' !== $order_id ) {
			$check( 'order', static function() use ( $client, $order_id ): array {
				$order = self::safe_order( $client->get_order( $order_id ) );
				return array( 'provider_order_id' => $order['providerOrderId'], 'comparison' => $order_id === $order['providerOrderId'] ? 'MATCH' : 'NO MATCH', 'raw_statuses' => $order['rawStatuses'], 'pii' => $order['piiAvailability'] );
			} );
			$check( 'items', static function() use ( $client, $order_id ): array {
				$items = $client->get_order_items( $order_id );
				return array( 'item_count' => count( $items ), 'comparison' => $items ? ( count( array_filter( $items, static fn( array $item ): bool => $order_id !== $item['providerOrderId'] ) ) ? 'NO MATCH' : 'MATCH' ) : 'UNVERIFIED' );
			} );
		}
		if ( $list ) {
			$check( 'orders', static function() use ( $client, $from, $offset, $order_id ): array {
				$page = $client->get_orders( new Ecomkit_Vuikhoe_Lazada_Order_Query( $from, 'all', 100, (int) $offset ) );
				$ids = array_column( $page['orders'], 'providerOrderId' );
				return array( 'comparison' => '' === $order_id ? 'UNVERIFIED' : ( in_array( $order_id, $ids, true ) ? 'MATCH' : 'NO MATCH' ), 'order_count' => count( $ids ), 'countTotal' => $page['countTotal'], 'offset' => $offset, 'limit' => 100, 'created_after' => Ecomkit_Vuikhoe_Lazada_Order_Query::date( $from ), 'provider_end_bound_sent' => false, 'pagination' => 'ONE_PAGE_ONLY_NOT_FULL_DAY' );
			} );
		}
		return $result;
	}
	private static function error_code( Throwable $e ): string {
		$allowed = array( 'LAZADA_ORDER_AUTH_REQUIRED', 'LAZADA_ORDER_HTTP_ERROR', 'LAZADA_ORDER_PROVIDER_ERROR', 'LAZADA_ORDER_INVALID_RESPONSE', 'LAZADA_ORDER_NETWORK_ERROR', 'LAZADA_ORDER_NOT_FOUND', 'LAZADA_ORDER_PAGINATION_ERROR', 'LAZADA_ORDER_WINDOW_TOO_LARGE', 'LAZADA_ORDER_DIAGNOSTIC_INPUT_INVALID', 'LAZADA_ORDER_DIAGNOSTIC_WINDOW_INVALID' );
		return in_array( $e->getMessage(), $allowed, true ) ? $e->getMessage() : 'LAZADA_ORDER_INVALID_RESPONSE';
	}
	private static function message( string $code, string $stage = '' ): string {
		if ( 'LAZADA_ORDER_AUTH_REQUIRED' === $code ) { return 'Token Lazada cần được ủy quyền lại.'; }
		if ( 'LAZADA_ORDER_NOT_FOUND' === $code ) { return 'Không tìm thấy đơn Lazada này.'; }
		if ( 'LAZADA_ORDER_DIAGNOSTIC_WINDOW_INVALID' === $code ) { return 'Chọn ngày đơn hợp lệ để kiểm tra danh sách.'; }
		if ( 'LAZADA_ORDER_DIAGNOSTIC_INPUT_INVALID' === $code ) { return 'Nhập mã đơn Lazada hoặc chọn kiểm tra danh sách theo ngày.'; }
		return 'items' === $stage ? 'Không thể lấy sản phẩm của đơn.' : ( 'orders' === $stage ? 'Không thể kiểm tra danh sách đơn.' : 'Không thể lấy chi tiết đơn.' );
	}

	public function ajax(): void {
		nocache_headers();
		header( 'X-Ecomkit-Lazada-Diagnostic: handler' );
		$route = array( 'layer' => 'WORDPRESS_DIAGNOSTIC', 'handler_reached' => true, 'permission_passed' => false, 'nonce_passed' => false, 'client_invoked' => false, 'plugin_version' => ECOMKIT_VUIKHOE_VERSION );
		if ( ! current_user_can( Ecomkit_Vuikhoe_Security::MANAGEMENT_CAPABILITY ) ) {
			wp_send_json_error( array( 'classification' => 'WORDPRESS_PERMISSION_DENIED', 'message' => 'Bạn không có quyền kiểm tra đơn Lazada.', 'transport' => $route ), 403 );
		}
		$route['permission_passed'] = true;
		if ( false === check_ajax_referer( 'ecomkit_lazada_order_diagnostic', 'nonce', false ) ) {
			wp_send_json_error( array( 'classification' => 'WORDPRESS_NONCE_INVALID', 'message' => 'Phiên kiểm tra đã hết hạn. Tải lại trang và thử lại.', 'transport' => $route ), 403 );
		}
		$route['nonce_passed'] = true;
		try { $result = $this->run( wp_unslash( $_POST ) ); }
		catch ( Throwable $e ) {
			$code = self::error_code( $e );
			wp_send_json_error( array( 'classification' => $code, 'message' => self::message( $code ), 'transport' => $route, 'diagnostic' => $e instanceof Ecomkit_Vuikhoe_Lazada_Provider_Exception ? $e->diagnostic : array() ), 400 );
		}
		$route['client_invoked'] = $result['request_count'] > 0;
		$result['transport'] = $route;
		wp_send_json_success( $result );
	}
}
