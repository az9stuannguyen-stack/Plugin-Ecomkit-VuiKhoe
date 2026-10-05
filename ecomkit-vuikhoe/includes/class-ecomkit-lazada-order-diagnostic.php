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
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $value, $timezone );
		if ( ! $date || $date->format( 'Y-m-d\TH:i' ) !== $value ) { throw new RuntimeException( 'LAZADA_ORDER_DIAGNOSTIC_WINDOW_INVALID' ); } return $date;
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
		$operation = self::value( $input, 'operation' ); $excel = self::value( $input, 'excel_order_id' );
		if ( ! in_array( $operation, array( 'orders', 'order', 'items' ), true ) ) { throw new RuntimeException( 'LAZADA_ORDER_DIAGNOSTIC_INPUT_INVALID' ); }
		$client = ( $this->client_factory )( (int) $id );
		$result = array( 'stage' => $operation, 'connection_id' => $id, 'request_count' => 1, 'request_count_scope' => 'ORDER_API_ONLY; optional lifecycle refresh excluded', 'persistence' => false, 'canonical_mapping' => false );
		if ( 'orders' === $operation ) {
			$from = self::date( self::value( $input, 'from' ), wp_timezone() ); $to = self::date( self::value( $input, 'to' ), wp_timezone() );
			$seconds = $to->getTimestamp() - $from->getTimestamp();
			if ( $seconds <= 0 || $seconds > 86400 ) { throw new RuntimeException( 'LAZADA_ORDER_DIAGNOSTIC_WINDOW_INVALID' ); }
			$offset = self::value( $input, 'offset' ); if ( 1 !== preg_match( '/^[0-9]{1,4}$/D', $offset ) || (int) $offset > 5000 || 0 !== (int) $offset % 100 ) { throw new RuntimeException( 'LAZADA_ORDER_DIAGNOSTIC_INPUT_INVALID' ); }
			$page = $client->get_orders( new Ecomkit_Vuikhoe_Lazada_Order_Query( $from, 'all', 100, (int) $offset ) );
			$result['orders'] = array_map( array( self::class, 'safe_order' ), $page['orders'] );
			$result['order_count'] = count( $result['orders'] ); $result['offsets'] = array( $offset ); $result['countTotal'] = $page['countTotal'];
			$result['window'] = array( 'from' => Ecomkit_Vuikhoe_Lazada_Order_Query::date( $from ), 'to_reference_only' => Ecomkit_Vuikhoe_Lazada_Order_Query::date( $to ), 'provider_end_bound_sent' => false );
			$result['pagination'] = 'ONE_PAGE_ONLY_NOT_FULL_WINDOW';
			$result['next_offset'] = 100 === $result['order_count'] && ( null === $page['countTotal'] || (int) $offset + 100 < $page['countTotal'] ) ? ( (int) $offset + 100 <= 5000 ? (string) ( (int) $offset + 100 ) : 'WINDOW_TOO_LARGE' ) : null;
			if ( '' !== $excel ) { $result['excel_comparison'] = array_map( static fn( array $o ): array => array( 'provider_order_id' => $o['providerOrderId'], 'excel_order_id' => $excel, 'result' => $excel === $o['providerOrderId'] ? 'MATCH' : 'NO MATCH' ), $result['orders'] ); }
		} else {
			$order_id = Ecomkit_Vuikhoe_Lazada_Order_Normalizer::id( self::value( $input, 'order_id' ) );
			$result['order_id'] = $order_id;
			if ( 'order' === $operation ) { $result['order'] = self::safe_order( $client->get_order( $order_id ) ); }
			else { $result['items'] = $client->get_order_items( $order_id ); $result['item_count'] = count( $result['items'] ); }
			if ( '' !== $excel ) {
				$provider_id = 'order' === $operation ? $result['order']['providerOrderId'] : ( $result['items'][0]['providerOrderId'] ?? null );
				$result['excel_comparison'] = array( 'excel_order_id' => $excel, 'provider_order_id' => $provider_id, 'result' => $excel === $provider_id ? 'MATCH' : 'NO MATCH' );
			}
		}
		$result['response_evidence'] = $client->last_evidence(); return $result;
	}
	public function ajax(): void {
		Ecomkit_Vuikhoe_Security::require_management_capability(); check_ajax_referer( 'ecomkit_lazada_order_diagnostic', 'nonce' ); nocache_headers();
		try { $result = $this->run( wp_unslash( $_POST ) ); }
		catch ( Throwable $e ) {
			$allowed = array( 'LAZADA_ORDER_AUTH_REQUIRED', 'LAZADA_ORDER_HTTP_ERROR', 'LAZADA_ORDER_PROVIDER_ERROR', 'LAZADA_ORDER_INVALID_RESPONSE', 'LAZADA_ORDER_NETWORK_ERROR', 'LAZADA_ORDER_NOT_FOUND', 'LAZADA_ORDER_PAGINATION_ERROR', 'LAZADA_ORDER_WINDOW_TOO_LARGE', 'LAZADA_ORDER_DIAGNOSTIC_INPUT_INVALID', 'LAZADA_ORDER_DIAGNOSTIC_WINDOW_INVALID' );
			$code = in_array( $e->getMessage(), $allowed, true ) ? $e->getMessage() : 'LAZADA_ORDER_INVALID_RESPONSE';
			wp_send_json_error( array( 'classification' => $code, 'stage' => 'ORDER_DIAGNOSTIC', 'message' => 'LAZADA_ORDER_AUTH_REQUIRED' === $code ? 'Chưa thể kiểm tra Order API. Vui lòng kết nối Lazada trước.' : 'Kiểm tra chưa thành công. Xem bằng chứng an toàn; không tự động thử lại.', 'diagnostic' => $e instanceof Ecomkit_Vuikhoe_Lazada_Provider_Exception ? $e->diagnostic : array() ), 400 );
		}
		wp_send_json_success( $result );
	}
}
