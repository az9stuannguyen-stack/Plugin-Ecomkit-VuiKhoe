<?php
defined( 'ABSPATH' ) || exit;

/** Source audit only. No finance aggregation, canonical mapping, logging or persistence. */
final class Ecomkit_Vuikhoe_Lazada_Finance_Client {
	public const TRANSACTIONS = '/finance/transaction/detail/get';
	public const QUERY_TRANSACTIONS = '/finance/transaction/details/get';
	public const PAYOUT = '/finance/payout/status/get';
	private array $secrets = array();
	public function __construct( private int $connection_id, private ?Ecomkit_Vuikhoe_Lazada_Token_Service $tokens = null, private ?Ecomkit_Vuikhoe_Lazada_Config $config = null, private mixed $transport = null ) {
		if ( $connection_id < 1 ) { throw new InvalidArgumentException( 'LAZADA_FINANCE_INPUT_INVALID' ); }
		$this->tokens ??= new Ecomkit_Vuikhoe_Lazada_Token_Service(); $this->config ??= new Ecomkit_Vuikhoe_Lazada_Config(); $this->transport ??= 'wp_remote_get';
	}
	public static function date( string $date ): DateTimeImmutable {
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new DateTimeZone( 'Asia/Ho_Chi_Minh' ) );
		if ( ! $d || $d->format( 'Y-m-d' ) !== $date ) { throw new RuntimeException( 'LAZADA_FINANCE_INPUT_INVALID' ); } return $d;
	}
	public static function decimal( mixed $value ): ?string {
		if ( null === $value || '' === $value ) { return null; }
		if ( ! is_string( $value ) || 1 !== preg_match( '/^-?[0-9]{1,40}(?:\.[0-9]{1,20})?$/D', $value ) ) { throw new RuntimeException( 'LAZADA_FINANCE_INVALID_RESPONSE' ); }
		return $value;
	}
	private function text( mixed $value ): ?string {
		return null === $value ? null : Ecomkit_Vuikhoe_Lazada_HTTP_Client::safe_text( $value, $this->secrets );
	}
	private static function identifier( mixed $value ): ?string {
		if ( null === $value || '' === $value ) { return null; }
		// Finance may use zero for an absent order/item reference. Keep the exact lexeme; do not infer a match.
		if ( ! is_string( $value ) || ! preg_match( '/^[0-9]{1,40}$/D', $value ) ) { throw new RuntimeException( 'LAZADA_FINANCE_INVALID_RESPONSE' ); }
		return $value;
	}
	/** Lossless JSON numeric lexemes, including unquoted decimals/large identifiers. */
	private static function decode( string $body ): array {
		$body = preg_replace_callback( '~"(?:[^"\\\\]|\\\\.)*"|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?(?=\s*[,}\]])~s', static fn( array $m ): string => '"' === $m[0][0] ? $m[0] : '"' . $m[0] . '"', $body );
		$shape = json_decode( (string) $body, false, 40, JSON_THROW_ON_ERROR );
		if ( ! is_object( $shape ) || ( isset( $shape->data ) && ! is_array( $shape->data ) ) ) { throw new RuntimeException(); }
		$d = json_decode( (string) $body, true, 40, JSON_THROW_ON_ERROR );
		if ( ! is_array( $d ) || array_is_list( $d ) ) { throw new RuntimeException(); } return $d;
	}
	private function request( string $path, array $business ): array {
		$diag = array( 'api_path' => $path, 'http_method' => 'GET', 'http_status' => null, 'provider_code' => '', 'safe_provider_message' => '', 'request_id' => '' );
		$fail = static function( string $code ) use ( &$diag ): never { throw new Ecomkit_Vuikhoe_Lazada_Provider_Exception( $code, $diag ); };
		try { $token = $this->tokens->ensure_usable_access_token( $this->connection_id ); $app = $this->config->credentials(); } catch ( Throwable ) { $fail( 'LAZADA_FINANCE_AUTH_REQUIRED' ); }
		$params = array_merge( array( 'app_key' => $app['app_key'], 'access_token' => $token, 'timestamp' => (string) ( time() * 1000 ), 'sign_method' => 'sha256' ), $business );
		$params['sign'] = Ecomkit_Vuikhoe_Lazada_Signer::sign( $path, $params, $app['app_secret'] );
		$this->secrets = array( $token, $app['app_secret'], $params['sign'] );
		// Never log or return this credential-bearing URL. No automatic retry or redirects.
		try { $r = ( $this->transport )( Ecomkit_Vuikhoe_Lazada_Config::API_BASE . $path . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ), array( 'method' => 'GET', 'timeout' => 20, 'sslverify' => true, 'redirection' => 0, 'limit_response_size' => 2097152 ) ); } catch ( Throwable ) { $fail( 'LAZADA_FINANCE_NETWORK_ERROR' ); }
		if ( is_wp_error( $r ) ) { $fail( 'LAZADA_FINANCE_NETWORK_ERROR' ); }
		$diag['http_status'] = (int) wp_remote_retrieve_response_code( $r );
		$http_error = $diag['http_status'] < 200 || $diag['http_status'] >= 300;
		try { $body = wp_remote_retrieve_body( $r ); if ( ! is_string( $body ) || strlen( $body ) >= 2097152 ) { throw new RuntimeException(); } $d = self::decode( $body ); }
		catch ( Throwable ) { $fail( $http_error ? 'LAZADA_FINANCE_HTTP_ERROR' : 'LAZADA_FINANCE_INVALID_RESPONSE' ); }
		$this->secrets = array_merge( $this->secrets, array_filter( array( $d['access_token'] ?? null, $d['refresh_token'] ?? null, $d['app_secret'] ?? null, $d['sign'] ?? null ), 'is_string' ) );
		foreach ( array( 'provider_code' => 'code', 'safe_provider_message' => 'message', 'request_id' => 'request_id' ) as $to => $from ) { $diag[$to] = $this->text( $d[$from] ?? '' ); }
		if ( $http_error ) { $fail( 'LAZADA_FINANCE_HTTP_ERROR' ); }
		if ( ! is_string( $d['code'] ?? null ) ) { $fail( 'LAZADA_FINANCE_INVALID_RESPONSE' ); }
		if ( '0' !== $d['code'] ) { $fail( 'LAZADA_FINANCE_PROVIDER_ERROR' ); }
		if ( ! is_array( $d['data'] ?? null ) || ! array_is_list( $d['data'] ) ) { $fail( 'LAZADA_FINANCE_INVALID_RESPONSE' ); }
		return array( 'data' => $d['data'], 'diagnostic' => $diag );
	}
	/** One bounded page; filter locally with strict order_no equality. Singular endpoint has no order filter. */
	public function transactions( string $order_id, string $start, string $end, int $offset = 0, int $limit = 100, bool $query = false, bool $scan = false ): array {
		$order_id = $scan ? '' : Ecomkit_Vuikhoe_Lazada_Order_Normalizer::id( $order_id );
		if ( $scan ) { $query = true; }
		$from = self::date( $start ); $to = self::date( $end );
		if ( $to < $from || (int) $from->diff( $to )->days >= 180 || $offset < 0 || $offset > 1000000 || $limit < 1 || $limit > 500 ) { throw new RuntimeException( 'LAZADA_FINANCE_INPUT_INVALID' ); }
		$p = array( 'start_time' => $start, 'end_time' => $end, 'trans_type' => '-1', 'offset' => (string) $offset, 'limit' => (string) $limit );
		if ( $query && ! $scan ) { $p['trade_order_id'] = $order_id; }
		$r = $this->request( $query ? self::QUERY_TRANSACTIONS : self::TRANSACTIONS, $p ); $rows = array();
		if ( count( $r['data'] ) > $limit ) { throw new Ecomkit_Vuikhoe_Lazada_Provider_Exception( 'LAZADA_FINANCE_INVALID_RESPONSE', $r['diagnostic'] ); }
		foreach ( $r['data'] as $raw ) {
			if ( ! is_array( $raw ) ) { throw new Ecomkit_Vuikhoe_Lazada_Provider_Exception( 'LAZADA_FINANCE_INVALID_RESPONSE', $r['diagnostic'] ); }
			if ( ! $scan && $order_id !== ( $raw['order_no'] ?? null ) ) { continue; }
			$provider_id = $raw['order_no'] ?? null;
			$safe = array( 'order_no' => self::identifier( $provider_id ) );
			foreach ( array( 'orderItem_no', 'reference' ) as $key ) { $safe[$key] = self::identifier( $raw[$key] ?? null ); }
			foreach ( array( 'amount', 'VAT_in_amount', 'WHT_amount' ) as $key ) { $safe[$key] = self::decimal( $raw[$key] ?? null ); }
			foreach ( array( 'fee_type', 'fee_name', 'transaction_type', 'transaction_number', 'transaction_date', 'statement', 'paid_status', 'orderItem_status', 'WHT_included_in_amount' ) as $key ) { $safe[$key] = $this->text( $raw[$key] ?? null ); }
			// Currency is not documented in transaction response; retain only a valid explicit observed code, never infer VND.
			$safe['currency'] = is_string( $raw['currency'] ?? null ) && preg_match( '/^[A-Z]{3}$/D', $raw['currency'] ) ? $raw['currency'] : null;
			$safe['linkage_scope'] = $safe['orderItem_no'] || $safe['reference'] ? 'ITEM_REFERENCE_PRESENT_NOT_AGGREGATED' : 'ORDER_REFERENCE_ONLY';
			$safe['canonical_candidate'] = 'UNKNOWN'; $rows[] = $safe;
		}
		return array( 'records' => $rows, 'page_count' => count( $r['data'] ), 'matched_count' => count( $rows ), 'offset' => $offset, 'limit' => $limit, 'next_offset' => count( $r['data'] ) === $limit ? $offset + $limit : null, 'coverage' => 0 === $offset && count( $r['data'] ) < $limit ? 'REQUESTED_WINDOW_ONLY' : 'PAGE_ONLY_NOT_COMPLETE', 'diagnostic' => $r['diagnostic'] );
	}
	/** Statement-level evidence, NOT order payout; no undocumented pagination parameters. */
	public function payouts( string $created_after ): array {
		self::date( $created_after ); $r = $this->request( self::PAYOUT, array( 'created_after' => $created_after ) ); $rows = array();
		foreach ( array_slice( $r['data'], 0, 100 ) as $raw ) {
			if ( ! is_array( $raw ) ) { throw new RuntimeException( 'LAZADA_FINANCE_INVALID_RESPONSE' ); }
			$safe = array();
			foreach ( array( 'statement_number', 'paid', 'created_at', 'updated_at' ) as $key ) { $safe[$key] = $this->text( $raw[$key] ?? null ); }
			foreach ( array( 'closing_balance', 'opening_balance', 'guarantee_deposit', 'item_revenue', 'shipment_fee', 'shipment_fee_credit', 'other_revenue_total', 'fees_total', 'subtotal1', 'refunds', 'fees_on_refunds_total', 'subtotal2' ) as $key ) { $safe[$key] = self::decimal( $raw[$key] ?? null ); }
			$payout = $raw['payout'] ?? null; $safe['payout_amount'] = null; $safe['payout_currency'] = null;
			if ( null !== $payout && '' !== $payout ) {
				if ( ! is_string( $payout ) || ! preg_match( '/^(-?[0-9]{1,40}(?:\.[0-9]{1,20})?)(?: ([A-Z]{3}))?$/D', $payout, $m ) ) { throw new RuntimeException( 'LAZADA_FINANCE_INVALID_RESPONSE' ); }
				$safe['payout_amount'] = self::decimal( $m[1] ); $safe['payout_currency'] = $m[2] ?? null;
			}
			$safe['scope'] = 'SELLER_STATEMENT_NOT_ORDER'; $rows[] = $safe;
		}
		return array( 'statements' => $rows, 'provider_count' => count( $r['data'] ), 'preview_truncated' => count( $r['data'] ) > 100, 'order_payout_link' => 'UNKNOWN', 'diagnostic' => $r['diagnostic'] );
	}
}
