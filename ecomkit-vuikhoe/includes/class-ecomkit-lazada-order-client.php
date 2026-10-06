<?php
defined( 'ABSPATH' ) || exit;
/** Official seller Order endpoints. No UI hooks, persistence or automatic pipeline wiring. */
final class Ecomkit_Vuikhoe_Lazada_Order_Client {
	public const GET_ORDERS = '/orders/get';
	public const GET_ORDER = '/order/get';
	public const GET_ITEMS = '/order/items/get';
	// Official route known; request/response shape intentionally deferred.
	public const GET_MULTIPLE_ITEMS = '/orders/items/get';
	private mixed $transport;
	private array $evidence = array();
	/** Safe last-response evidence in memory only; no raw payload/value capture. */
	public function last_evidence(): array { return $this->evidence; }
	private static function field_paths( array $data, string $prefix = '', int $depth = 0 ): array {
		if ( $depth > 6 ) { return array(); }
		$paths = array();
		foreach ( $data as $key => $value ) {
			if ( is_int( $key ) ) { $key = '[]'; } elseif ( 1 !== preg_match( '/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/D', $key ) ) { continue; }
			if ( preg_match( '/token|secret|sign|authorization|code$/i', $key ) ) { continue; }
			$path = '' === $prefix ? $key : $prefix . ( '[]' === $key ? '[]' : '.' . $key ); $paths[] = $path;
			if ( is_array( $value ) ) { $paths = array_merge( $paths, self::field_paths( $value, $path, $depth + 1 ) ); }
			$paths = array_values( array_unique( $paths ) ); if ( count( $paths ) >= 128 ) { break; }
			if ( '[]' === $key ) { break; } // Representative structure, never thousands of paths.
		}
		return array_slice( $paths, 0, 128 );
	}
	public function __construct( private int $connection_id, private ?Ecomkit_Vuikhoe_Lazada_Token_Service $tokens = null, private ?Ecomkit_Vuikhoe_Lazada_Config $config = null, mixed $transport = null ) {
		if ( $connection_id < 1 ) { throw new InvalidArgumentException( 'LAZADA_ORDER_CONNECTION_INVALID' ); }
		$this->tokens ??= new Ecomkit_Vuikhoe_Lazada_Token_Service(); $this->config ??= new Ecomkit_Vuikhoe_Lazada_Config(); $this->transport = $transport ?? 'wp_remote_get';
	}
	private function fail( string $code, array $diag ): never {
		if ( ! empty( $this->evidence['field_paths'] ) ) { $diag['field_paths'] = $this->evidence['field_paths']; }
		throw new Ecomkit_Vuikhoe_Lazada_Provider_Exception( $code, $diag );
	}
	/** Preserve every JSON number lexeme before decode, including decimals and large IDs. */
	private static function decode( string $body ): array {
		$lossless = preg_replace_callback( '~"(?:[^"\\\\]|\\\\.)*"|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?(?=\s*[,}\]])~s', static fn( array $m ): string => '"' === $m[0][0] ? $m[0] : '"' . $m[0] . '"', $body );
		$data = json_decode( (string) $lossless, true, 40, JSON_THROW_ON_ERROR );
		if ( ! is_array( $data ) || array_is_list( $data ) ) { throw new RuntimeException(); }
		return $data;
	}
	private function request( string $path, array $business ): array {
		$this->evidence = array();
		$diag = array_merge( array( 'api_path' => $path, 'http_method' => 'GET', 'connection_id' => $this->connection_id, 'http_status' => null, 'provider_code' => '', 'safe_provider_message' => '', 'request_id' => '' ), array_intersect_key( $business, array_flip( array( 'offset', 'limit', 'order_id' ) ) ) );
		try { $token = $this->tokens->ensure_usable_access_token( $this->connection_id ); $app = $this->config->credentials(); }
		catch ( Throwable ) { $this->fail( 'LAZADA_ORDER_AUTH_REQUIRED', $diag ); }
		$params = array_merge( array( 'app_key' => $app['app_key'], 'access_token' => $token, 'timestamp' => (string) ( time() * 1000 ), 'sign_method' => 'sha256' ), $business );
		$params['sign'] = Ecomkit_Vuikhoe_Lazada_Signer::sign( $path, $params, $app['app_secret'] );
		// Sign original values before RFC3986 encoding. Never log this credential-bearing URL.
		try { $response = ( $this->transport )( Ecomkit_Vuikhoe_Lazada_Config::API_BASE . $path . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ), array( 'method' => 'GET', 'timeout' => 20, 'sslverify' => true, 'redirection' => 0, 'limit_response_size' => 2097152 ) ); }
		catch ( Throwable ) { $this->fail( 'LAZADA_ORDER_NETWORK_ERROR', $diag ); }
		if ( is_wp_error( $response ) ) { $this->fail( 'LAZADA_ORDER_NETWORK_ERROR', $diag ); }
		$diag['http_status'] = (int) wp_remote_retrieve_response_code( $response );
		// HTTP failure is not guessed to be a particular provider business error.
		if ( $diag['http_status'] < 200 || $diag['http_status'] >= 300 ) { $this->fail( 'LAZADA_ORDER_HTTP_ERROR', $diag ); }
		try { $body = wp_remote_retrieve_body( $response ); if ( ! is_string( $body ) || strlen( $body ) >= 2097152 ) { throw new RuntimeException(); } $data = self::decode( $body ); }
		catch ( Throwable ) { $this->fail( 'LAZADA_ORDER_INVALID_RESPONSE', $diag ); }
		$secrets = array( $token, $app['app_secret'], $params['sign'] );
		foreach ( array( 'provider_code' => 'code', 'safe_provider_message' => 'message', 'request_id' => 'request_id' ) as $target => $source ) { $diag[$target] = Ecomkit_Vuikhoe_Lazada_HTTP_Client::safe_text( $data[$source] ?? '', $secrets ); }
		$this->evidence = array( 'diagnostic' => $diag, 'field_paths' => self::field_paths( $data ) );
		if ( ! is_string( $data['code'] ?? null ) ) { $this->fail( 'LAZADA_ORDER_INVALID_RESPONSE', $diag ); }
		if ( '0' !== $data['code'] ) { $this->fail( 'LAZADA_ORDER_PROVIDER_ERROR', $diag ); }
		if ( ! is_array( $data['data'] ?? null ) ) { $this->fail( 'LAZADA_ORDER_INVALID_RESPONSE', $diag ); }
		return array( 'data' => $data['data'], 'diagnostic' => $diag );
	}
	private static function count_value( mixed $value ): int {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[0-9]{1,9}$/D', $value ) ) { throw new RuntimeException( 'LAZADA_ORDER_PAGINATION_ERROR' ); }
		return (int) $value;
	}
	public function get_orders( Ecomkit_Vuikhoe_Lazada_Order_Query $query ): array {
		$result = $this->request( self::GET_ORDERS, $query->parameters() ); $data = $result['data']; $diag = $result['diagnostic'];
		try {
			if ( ! is_array( $data['orders'] ?? null ) || ! array_is_list( $data['orders'] ) ) { throw new RuntimeException( 'LAZADA_ORDER_INVALID_RESPONSE' ); }
			$orders = array(); foreach ( $data['orders'] as $raw ) { if ( ! is_array( $raw ) ) { throw new RuntimeException( 'LAZADA_ORDER_INVALID_RESPONSE' ); } $orders[] = Ecomkit_Vuikhoe_Lazada_Order_Normalizer::order( $raw, $this->connection_id ); }
			$total = array_key_exists( 'countTotal', $data ) ? self::count_value( $data['countTotal'] ) : null;
			if ( count( $orders ) > $query->limit || ( isset( $data['count'] ) && self::count_value( $data['count'] ) !== count( $orders ) ) || ( null !== $total && count( $orders ) > max( 0, $total - $query->offset ) ) ) { throw new RuntimeException( 'LAZADA_ORDER_PAGINATION_ERROR' ); }
		} catch ( Throwable $e ) { $this->fail( 'LAZADA_ORDER_PAGINATION_ERROR' === $e->getMessage() ? $e->getMessage() : 'LAZADA_ORDER_INVALID_RESPONSE', $diag ); }
		return array( 'orders' => $orders, 'countTotal' => $total, 'offset' => $query->offset, 'limit' => $query->limit, 'diagnostic' => $diag );
	}
	/** Complete listing or exception, never return a partial set as complete. */
	public function paginate( Ecomkit_Vuikhoe_Lazada_Order_Query $query, int $max_pages = 51 ): array {
		if ( $max_pages < 1 || $max_pages > 5001 ) { throw new InvalidArgumentException( 'LAZADA_ORDER_QUERY_INVALID' ); }
		$all = array(); $fingerprints = array(); $ids = array(); $total = null; $offset = $query->offset;
		for ( $page = 0; $page < $max_pages; ++$page ) {
			if ( $offset > Ecomkit_Vuikhoe_Lazada_Order_Query::MAX_OFFSET ) { $this->fail( 'LAZADA_ORDER_WINDOW_TOO_LARGE', array( 'api_path' => self::GET_ORDERS, 'connection_id' => $this->connection_id, 'offset' => $offset, 'limit' => $query->limit ) ); }
			$current = $this->get_orders( $query->at_offset( $offset ) ); $orders = $current['orders']; $diag = $current['diagnostic'];
			if ( null !== $current['countTotal'] ) { if ( null !== $total && $total !== $current['countTotal'] ) { $this->fail( 'LAZADA_ORDER_PAGINATION_ERROR', $diag ); } $total = $current['countTotal']; }
			$fingerprint = hash( 'sha256', wp_json_encode( array_column( $orders, 'providerOrderId' ) ) );
			if ( $orders && isset( $fingerprints[$fingerprint] ) ) { $this->fail( 'LAZADA_ORDER_PAGINATION_ERROR', $diag ); } $fingerprints[$fingerprint] = true;
			foreach ( $orders as $order ) { $key = 'id:' . $order['providerOrderId']; if ( isset( $ids[$key] ) ) { $this->fail( 'LAZADA_ORDER_PAGINATION_ERROR', $diag ); } $ids[$key] = true; $all[] = $order; }
			$offset += $query->limit;
			if ( count( $orders ) < $query->limit ) { if ( null !== $total && $query->offset + count( $all ) < $total ) { $this->fail( 'LAZADA_ORDER_PAGINATION_ERROR', $diag ); } return array( 'orders' => $all, 'paginationComplete' => true, 'pageCount' => $page + 1, 'countTotal' => $total ); }
			if ( null !== $total && $query->offset + count( $all ) === $total ) { return array( 'orders' => $all, 'paginationComplete' => true, 'pageCount' => $page + 1, 'countTotal' => $total ); }
		}
		$this->fail( $offset > Ecomkit_Vuikhoe_Lazada_Order_Query::MAX_OFFSET ? 'LAZADA_ORDER_WINDOW_TOO_LARGE' : 'LAZADA_ORDER_PAGINATION_ERROR', $diag );
	}
	public function get_order( string $order_id ): array {
		$order_id = Ecomkit_Vuikhoe_Lazada_Order_Normalizer::id( $order_id ); $result = $this->request( self::GET_ORDER, array( 'order_id' => $order_id ) );
		if ( empty( $result['data'] ) ) { $this->fail( 'LAZADA_ORDER_NOT_FOUND', $result['diagnostic'] ); }
		try { $order = Ecomkit_Vuikhoe_Lazada_Order_Normalizer::order( $result['data'], $this->connection_id ); if ( $order_id !== $order['providerOrderId'] ) { throw new RuntimeException(); } return $order; }
		catch ( Throwable ) { $this->fail( 'LAZADA_ORDER_INVALID_RESPONSE', $result['diagnostic'] ); }
	}
	public function get_order_items( string $order_id ): array {
		$order_id = Ecomkit_Vuikhoe_Lazada_Order_Normalizer::id( $order_id ); $result = $this->request( self::GET_ITEMS, array( 'order_id' => $order_id ) );
		try { if ( ! array_is_list( $result['data'] ) ) { throw new RuntimeException(); } $items = array(); $ids = array(); foreach ( $result['data'] as $raw ) { if ( ! is_array( $raw ) ) { throw new RuntimeException(); } $item = Ecomkit_Vuikhoe_Lazada_Order_Normalizer::item( $raw, $this->connection_id, $order_id ); if ( isset( $ids['id:' . $item['orderItemId']] ) ) { throw new RuntimeException(); } $ids['id:' . $item['orderItemId']] = true; $items[] = $item; } return $items; }
		catch ( Throwable ) { $this->fail( 'LAZADA_ORDER_INVALID_RESPONSE', $result['diagnostic'] ); }
	}
}
