<?php
defined( 'ABSPATH' ) || exit;

/** Explicit admin source inspection. Response memory only; lifecycle may rotate credentials. */
final class Ecomkit_Vuikhoe_Lazada_Finance_Diagnostic {
	public function __construct( private ?Ecomkit_Vuikhoe_Lazada_Token_Service $tokens = null, private mixed $factory = null, private mixed $order_factory = null ) {
		$this->tokens ??= new Ecomkit_Vuikhoe_Lazada_Token_Service();
		$this->factory ??= static fn( int $id ) => new Ecomkit_Vuikhoe_Lazada_Finance_Client( $id );
		$this->order_factory ??= static fn( int $id ) => new Ecomkit_Vuikhoe_Lazada_Order_Client( $id );
	}
	public function register(): void { add_action( 'wp_ajax_ecomkit_lazada_finance_diagnostic', array( $this, 'ajax' ) ); }
	private static function value( array $input, string $key ): string {
		$v = $input[$key] ?? ''; if ( ! is_string( $v ) || strlen( $v ) > 128 ) { throw new RuntimeException( 'LAZADA_FINANCE_INPUT_INVALID' ); } return $v;
	}
	public function run( array $input ): array {
		Ecomkit_Vuikhoe_Security::require_management_capability();
		$id = self::value( $input, 'connection_id' ); $ready = false;
		foreach ( $this->tokens->safe_connections() as $c ) { if ( (string) $c['id'] === $id && 'ACTIVE' === $c['status'] && in_array( $c['lifecycle'], array( 'READY', 'REFRESH_NEEDED' ), true ) ) { $ready = true; break; } }
		if ( ! $ready ) { throw new RuntimeException( 'LAZADA_FINANCE_AUTH_REQUIRED' ); }
		$order_id = Ecomkit_Vuikhoe_Lazada_Order_Normalizer::id( self::value( $input, 'order_id' ) );
		$start = self::value( $input, 'start_date' ); $end = self::value( $input, 'end_date' );
		$from = Ecomkit_Vuikhoe_Lazada_Finance_Client::date( $start ); $to = Ecomkit_Vuikhoe_Lazada_Finance_Client::date( $end );
		$offset = self::value( $input, 'offset' ); $offset = '' === $offset ? '0' : $offset;
		if ( $to < $from || (int) $from->diff( $to )->days >= 180 || ! preg_match( '/^[0-9]{1,7}$/D', $offset ) || (int) $offset > 1000000 ) { throw new RuntimeException( 'LAZADA_FINANCE_INPUT_INVALID' ); }
		$endpoint = self::value( $input, 'endpoint' );
		if ( ! in_array( $endpoint, array( 'detail', 'details' ), true ) ) { throw new RuntimeException( 'LAZADA_FINANCE_INPUT_INVALID' ); }
		$client = ( $this->factory )( (int) $id ); $checks = array();
		$check = static function( string $key, callable $read ) use ( &$checks ): void {
			try { $checks[$key] = array( 'success' => true, 'data' => $read() ); }
			catch ( Throwable $e ) { $checks[$key] = array( 'success' => false, 'classification' => self::error_code( $e ), 'evidence' => $e instanceof Ecomkit_Vuikhoe_Lazada_Provider_Exception ? $e->diagnostic : array() ); }
		};
		$check( 'transactions', fn() => $client->transactions( $order_id, $start, $end, (int) $offset, 100, 'details' === $endpoint ) );
		if ( '1' === self::value( $input, 'check_payout' ) ) { $check( 'payout', fn() => $client->payouts( $start ) ); }
		if ( '1' === self::value( $input, 'check_order' ) ) {
			$order_client = ( $this->order_factory )( (int) $id );
			$check( 'order_candidates', static function() use ( $order_client, $order_id ): array {
				$o = $order_client->get_order( $order_id );
				return array( 'order_id' => $o['providerOrderId'], 'raw_statuses' => $o['rawStatuses'], 'price' => $o['price'], 'voucher' => $o['voucher'], 'shipping_fee' => $o['shippingFee'], 'confidence' => 'UNKNOWN_FOR_CANONICAL', 'evidence' => $order_client->last_evidence() );
			} );
			$check( 'item_candidates', static function() use ( $order_client, $order_id ): array {
				$items = $order_client->get_order_items( $order_id );
				return array_map( static fn( array $i ): array => array( 'order_id' => $i['providerOrderId'], 'order_item_id' => $i['orderItemId'], 'raw_status' => $i['status'], 'item_price' => $i['itemPrice'], 'paid_price' => $i['paidPrice'], 'confidence' => 'UNKNOWN_FOR_CANONICAL' ), $items );
			} );
		}
		$names = array();
		foreach ( $checks['transactions']['data']['records'] ?? array() as $r ) {
			$key = wp_json_encode( array( $r['fee_type'], $r['fee_name'], $r['transaction_type'] ) );
			$names[$key] ??= array( 'fee_type' => $r['fee_type'], 'fee_name' => $r['fee_name'], 'transaction_type' => $r['transaction_type'], 'frequency' => 0, 'positive' => 0, 'negative' => 0, 'zero' => 0, 'missing_amount' => 0, 'scopes' => array(), 'meaning' => 'UNKNOWN', 'confidence' => 'UNKNOWN' );
			++$names[$key]['frequency']; $amount = $r['amount'];
			$sign = null === $amount ? 'missing_amount' : ( ! preg_match( '/[1-9]/', $amount ) ? 'zero' : ( str_starts_with( $amount, '-' ) ? 'negative' : 'positive' ) );
			++$names[$key][$sign]; $names[$key]['scopes'][$r['linkage_scope']] = true;
		}
		foreach ( $names as &$n ) { $n['scopes'] = array_keys( $n['scopes'] ); } unset( $n );
		return array( 'order_id' => $order_id, 'start_date' => $start, 'end_date' => $end, 'checks' => $checks, 'distinct_names' => array_values( $names ), 'canonical_confidence' => 'UNKNOWN', 'canonical_write' => false, 'finance_persistence' => false, 'note' => 'ONE_PAGE_ONLY; empty page is not zero fees, not absence of settlement; no fee aggregation or statement/order allocation' );
	}
	private static function error_code( Throwable $e ): string {
		$code = $e->getMessage(); return preg_match( '/^LAZADA_(?:FINANCE_(?:AUTH_REQUIRED|INPUT_INVALID|PROVIDER_ERROR|NETWORK_ERROR|HTTP_ERROR|INVALID_RESPONSE)|ORDER_(?:AUTH_REQUIRED|INVALID_RESPONSE|NOT_FOUND|NETWORK_ERROR|HTTP_ERROR|PROVIDER_ERROR))$/D', $code ) ? $code : 'LAZADA_FINANCE_INVALID_RESPONSE';
	}
	public function ajax(): void {
		nocache_headers();
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'classification' => 'WORDPRESS_PERMISSION_DENIED', 'message' => 'Bạn không có quyền kiểm tra tài chính Lazada.' ), 403 ); }
		if ( false === check_ajax_referer( 'ecomkit_lazada_finance_diagnostic', 'nonce', false ) ) { wp_send_json_error( array( 'classification' => 'WORDPRESS_NONCE_INVALID', 'message' => 'Phiên kiểm tra đã hết hạn. Tải lại trang.' ), 403 ); }
		try { $result = $this->run( wp_unslash( $_POST ) ); }
		catch ( Throwable $e ) { wp_send_json_error( array( 'classification' => self::error_code( $e ), 'message' => 'Không thể kiểm tra. Kiểm tra shop, token và khoảng ngày giao dịch.' ), 400 ); }
		wp_send_json_success( $result );
	}
}
