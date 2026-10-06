<?php
defined( 'ABSPATH' ) || exit;

/** Source audit only. No finance aggregation, canonical mapping, logging or persistence. */
final class Ecomkit_Vuikhoe_Lazada_Finance_Client {
	public const TRANSACTIONS = '/finance/transaction/detail/get';
	public const QUERY_TRANSACTIONS = '/finance/transaction/details/get';
	public const ACCOUNT = '/finance/transaction/accountTransactions/query';
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
	private static function decode( string $body, bool $account = false ): array {
		$original = $account ? json_decode( $body, false, 40, JSON_THROW_ON_ERROR ) : null; // Type inventory only; values come from lossless lexemes below.
		$body = preg_replace_callback( '~"(?:[^"\\\\]|\\\\.)*"|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?(?=\s*[,}\]])~s', static fn( array $m ): string => '"' === $m[0][0] ? $m[0] : '"' . $m[0] . '"', $body );
		$shape = json_decode( (string) $body, false, 40, JSON_THROW_ON_ERROR );
		if ( ! is_object( $shape ) || ( ! $account && isset( $shape->data ) && ! is_array( $shape->data ) ) ) { throw new RuntimeException(); }
		$d = json_decode( (string) $body, true, 40, JSON_THROW_ON_ERROR );
		if ( ! is_array( $d ) || array_is_list( $d ) ) { throw new RuntimeException(); }
		if ( $account ) { $d['_transactions_array'] = isset( $shape->data->transactions ) && is_array( $shape->data->transactions ); $d['_account_shapes'] = $original->data->transactions ?? array(); $d['_page_shape'] = $original->data->page_info ?? null; }
		return $d;
	}
	private function request( string $path, array $business ): array {
		$account = self::ACCOUNT === $path; $method = $account ? 'POST' : 'GET';
		$diag = array( 'api_path' => $path, 'http_method' => $method, 'http_status' => null, 'provider_code' => '', 'safe_provider_message' => '', 'request_id' => '' );
		$fail = static function( string $code ) use ( &$diag ): never { throw new Ecomkit_Vuikhoe_Lazada_Provider_Exception( $code, $diag ); };
		try { $token = $this->tokens->ensure_usable_access_token( $this->connection_id ); $app = $this->config->credentials(); } catch ( Throwable ) { $fail( 'LAZADA_FINANCE_AUTH_REQUIRED' ); }
		$params = array_merge( array( 'app_key' => $app['app_key'], 'access_token' => $token, 'timestamp' => (string) ( time() * 1000 ), 'sign_method' => 'sha256' ), $business );
		$params['sign'] = Ecomkit_Vuikhoe_Lazada_Signer::sign( $path, $params, $app['app_secret'] );
		$this->secrets = array( $token, $app['app_secret'], $params['sign'] );
		// Never log or return this credential-bearing URL. No automatic retry or redirects.
		try {
			$args = array( 'method' => $method, 'timeout' => 20, 'sslverify' => true, 'redirection' => 0, 'limit_response_size' => 2097152 );
			$url = Ecomkit_Vuikhoe_Lazada_Config::API_BASE . $path;
			$transport = $this->transport;
			if ( $account ) { $args['body'] = $params; if ( 'wp_remote_get' === $transport ) { $transport = 'wp_remote_post'; } }
			else { $url .= '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ); }
			$r = $transport( $url, $args );
		} catch ( Throwable ) { $fail( 'LAZADA_FINANCE_NETWORK_ERROR' ); }
		if ( is_wp_error( $r ) ) { $fail( 'LAZADA_FINANCE_NETWORK_ERROR' ); }
		$diag['http_status'] = (int) wp_remote_retrieve_response_code( $r );
		$http_error = $diag['http_status'] < 200 || $diag['http_status'] >= 300;
		try { $body = wp_remote_retrieve_body( $r ); if ( ! is_string( $body ) || strlen( $body ) >= 2097152 ) { throw new RuntimeException(); } $d = self::decode( $body, $account ); }
		catch ( Throwable ) { $fail( $http_error ? 'LAZADA_FINANCE_HTTP_ERROR' : 'LAZADA_FINANCE_INVALID_RESPONSE' ); }
		$this->secrets = array_merge( $this->secrets, array_filter( array( $d['access_token'] ?? null, $d['refresh_token'] ?? null, $d['app_secret'] ?? null, $d['sign'] ?? null ), 'is_string' ) );
		foreach ( array( 'provider_code' => 'code', 'safe_provider_message' => 'message', 'request_id' => 'request_id' ) as $to => $from ) { $diag[$to] = $this->text( $d[$from] ?? ( $account && 'provider_code' === $to ? ( $d['error_code'] ?? '' ) : ( $account && 'safe_provider_message' === $to ? ( $d['msg'] ?? '' ) : '' ) ) ); }
		if ( $http_error ) { $fail( 'LAZADA_FINANCE_HTTP_ERROR' ); }
		if ( $account ) {
			if ( false === ( $d['success'] ?? null ) ) { $fail( 'LAZADA_FINANCE_PROVIDER_ERROR' ); }
			if ( true !== ( $d['success'] ?? null ) || ! is_array( $d['data'] ?? null ) || array_is_list( $d['data'] ) ) { $fail( 'LAZADA_FINANCE_INVALID_RESPONSE' ); }
			if ( ! $d['_transactions_array'] ) { $fail( 'LAZADA_FINANCE_INVALID_RESPONSE' ); }
			$diag['response_container'] = 'data.transactions[]';
			return array( 'data' => $d['data'], 'diagnostic' => $diag, 'shapes' => $d['_account_shapes'], 'page_shape' => $d['_page_shape'] );
		}
		if ( ! is_string( $d['code'] ?? null ) ) { $fail( 'LAZADA_FINANCE_INVALID_RESPONSE' ); }
		if ( '0' !== $d['code'] ) { $fail( 'LAZADA_FINANCE_PROVIDER_ERROR' ); }
		if ( ! is_array( $d['data'] ?? null ) || ! array_is_list( $d['data'] ) ) { $fail( 'LAZADA_FINANCE_INVALID_RESPONSE' ); }
		return array( 'data' => $d['data'], 'diagnostic' => $diag );
	}
	/** One bounded page; filter locally with strict order_no equality. Singular endpoint has no order filter. */
	public function transactions( string $order_id, string $start, string $end, int $offset = 0, int $limit = 100, bool $query = false ): array {
		$order_id = Ecomkit_Vuikhoe_Lazada_Order_Normalizer::id( $order_id );
		$from = self::date( $start ); $to = self::date( $end );
		if ( $to < $from || (int) $from->diff( $to )->days >= 180 || $offset < 0 || $offset > 1000000 || $limit < 1 || $limit > 500 ) { throw new RuntimeException( 'LAZADA_FINANCE_INPUT_INVALID' ); }
		$p = array( 'start_time' => $start, 'end_time' => $end, 'trans_type' => '-1', 'offset' => (string) $offset, 'limit' => (string) $limit );
		if ( $query ) { $p['trade_order_id'] = $order_id; }
		$r = $this->request( $query ? self::QUERY_TRANSACTIONS : self::TRANSACTIONS, $p ); $rows = array();
		if ( count( $r['data'] ) > $limit ) { throw new Ecomkit_Vuikhoe_Lazada_Provider_Exception( 'LAZADA_FINANCE_INVALID_RESPONSE', $r['diagnostic'] ); }
		foreach ( $r['data'] as $raw ) {
			if ( ! is_array( $raw ) ) { throw new Ecomkit_Vuikhoe_Lazada_Provider_Exception( 'LAZADA_FINANCE_INVALID_RESPONSE', $r['diagnostic'] ); }
			if ( $order_id !== ( $raw['order_no'] ?? null ) ) { continue; }
			try {
			$provider_id = $raw['order_no'] ?? null;
			$safe = array( 'order_no' => self::identifier( $provider_id ) );
			foreach ( array( 'orderItem_no', 'reference' ) as $key ) { $safe[$key] = self::identifier( $raw[$key] ?? null ); }
			foreach ( array( 'amount', 'VAT_in_amount', 'WHT_amount' ) as $key ) { $safe[$key] = self::decimal( $raw[$key] ?? null ); }
			foreach ( array( 'fee_type', 'fee_name', 'transaction_type', 'transaction_number', 'transaction_date', 'statement', 'paid_status', 'orderItem_status', 'WHT_included_in_amount' ) as $key ) { $safe[$key] = $this->text( $raw[$key] ?? null ); }
			// Currency is not documented in transaction response; retain only a valid explicit observed code, never infer VND.
			$safe['currency'] = is_string( $raw['currency'] ?? null ) && preg_match( '/^[A-Z]{3}$/D', $raw['currency'] ) ? $raw['currency'] : null;
			$safe['linkage_scope'] = $safe['orderItem_no'] || $safe['reference'] ? 'ITEM_REFERENCE_PRESENT_NOT_AGGREGATED' : 'ORDER_REFERENCE_ONLY';
			$safe['canonical_candidate'] = 'UNKNOWN'; $rows[] = $safe;
			} catch ( Throwable ) { throw new Ecomkit_Vuikhoe_Lazada_Provider_Exception( 'LAZADA_FINANCE_INVALID_RESPONSE', $r['diagnostic'] ); }
		}
		return array( 'records' => $rows, 'page_count' => count( $r['data'] ), 'matched_count' => count( $rows ), 'offset' => $offset, 'limit' => $limit, 'next_offset' => count( $r['data'] ) === $limit ? $offset + $limit : null, 'coverage' => 0 === $offset && count( $r['data'] ) < $limit ? 'REQUESTED_WINDOW_ONLY' : 'PAGE_ONLY_NOT_COMPLETE', 'diagnostic' => $r['diagnostic'] );
	}
	private static function json_type( mixed $value ): string {
		return match ( true ) { null === $value => 'null', is_string( $value ) => 'string', is_bool( $value ) => 'bool', is_array( $value ) => 'array', is_object( $value ) => 'object', default => 'number' };
	}
	/** Exact expansion of JSON exponent lexemes; never calculate through binary floating point. */
	private static function account_decimal( mixed $value ): ?string {
		if ( null === $value || '' === $value ) { return null; }
		if ( ! is_string( $value ) ) { throw new RuntimeException(); }
		if ( preg_match( '/^(-?)([0-9]+)(?:\.([0-9]+))?[eE]([+-]?[0-9]{1,3})$/D', $value, $m ) ) {
			$digits = $m[2] . ( $m[3] ?? '' ); $point = strlen( $m[2] ) + (int) $m[4];
			if ( abs( $point ) > 80 || strlen( $digits ) > 80 ) { throw new RuntimeException(); }
			$value = $m[1] . ( $point <= 0 ? '0.' . str_repeat( '0', -$point ) . $digits : ( $point >= strlen( $digits ) ? $digits . str_repeat( '0', $point - strlen( $digits ) ) : substr( $digits, 0, $point ) . '.' . substr( $digits, $point ) ) );
		}
		return self::decimal( $value );
	}
	/** Official account scope: POST, yyyyMMdd, page_num/page_size. No documented order key. */
	public function account_transactions( string $start, string $end, int $page = 1, int $size = 100 ): array {
		$from = self::date( $start ); $to = self::date( $end );
		if ( $to < $from || $page < 1 || $page > 1000000 || $size < 1 || $size > 100 ) { throw new RuntimeException( 'LAZADA_FINANCE_INPUT_INVALID' ); }
		$r = $this->request( self::ACCOUNT, array( 'start_time' => $from->format( 'Ymd' ), 'end_time' => $to->format( 'Ymd' ), 'page_num' => (string) $page, 'page_size' => (string) $size ) );
		$data = $r['data']; $raws = $data['transactions']; $rows = array(); $errors = array(); $inventories = array(); $warnings = array();
		$pagination = array_fill_keys( array( 'page_num', 'page_size', 'total_page', 'total_count' ), null );
		$info = $data['page_info'] ?? array();
		if ( null !== ( $data['page_info'] ?? null ) && ! is_object( $r['page_shape'] ) ) { $warnings[] = array( 'field' => 'page_info', 'observed_type' => self::json_type( $r['page_shape'] ), 'expected_type' => 'object / null', 'code' => 'ACCOUNT_PAGINATION_INVALID' ); }
		foreach ( $pagination as $key => $_ ) {
			$value = is_array( $info ) ? ( $info[$key] ?? null ) : null;
			if ( null !== $value && ( ! is_string( $value ) || ! preg_match( '/^[0-9]{1,9}$/D', $value ) || ( 'page_num' === $key && (string) $page !== $value ) ) ) {
				$warnings[] = array( 'field' => 'page_info.' . $key, 'observed_type' => self::json_type( is_object( $r['page_shape'] ) ? ( $r['page_shape']->$key ?? null ) : null ), 'expected_type' => 'integer string / JSON integer; requested page_num', 'code' => 'ACCOUNT_PAGINATION_INVALID' ); continue;
			}
			$pagination[$key] = $value;
		}
		foreach ( $raws as $index => $raw ) {
			$shape = $r['shapes'][$index] ?? null; $inventory = array();
			if ( is_object( $shape ) ) {
				foreach ( get_object_vars( $shape ) as $key => $value ) {
					// Names only, no unknown values. Bound/sanitize hostile key strings too.
					$name = $this->text( $key );
					$inventory[] = array( 'field' => $name, 'observed_type' => self::json_type( $value ), 'classification' => in_array( $key, array( 'type', 'sub_type', 'amount', 'currency', 'pmt_reference', 'transaction_number', 'transaction_time' ), true ) ? 'OPTIONAL_DOCUMENTED' : 'UNKNOWN_NOT_RENDERED' );
				}
			}
			$inventories[] = array( 'transaction_index' => $index, 'fields' => $inventory );
			$failure = function( string $field, string $expected, string $code ) use ( &$errors, $index, $shape, $inventory ): void {
				$value = '$record' === $field ? $shape : ( is_object( $shape ) ? ( $shape->$field ?? null ) : null );
				$errors[] = array( 'transaction_index' => $index, 'field' => $field, 'observed_type' => self::json_type( $value ), 'expected_type' => $expected, 'code' => $code, 'classification' => 'LAZADA_FINANCE_NORMALIZATION_ERROR', 'observed_fields' => array_column( $inventory, 'field' ) );
			};
			if ( ! is_object( $shape ) || ! is_array( $raw ) ) { $failure( '$record', 'object', 'ACCOUNT_RECORD_NOT_OBJECT' ); continue; }
			try { $amount = self::account_decimal( $raw['amount'] ?? null ); }
			catch ( Throwable ) { $failure( 'amount', 'decimal string / JSON number / null', 'ACCOUNT_AMOUNT_NOT_EXACT_DECIMAL' ); continue; }
			$safe = array( 'order_no' => null, 'orderItem_no' => null, 'fee_type' => null, 'fee_name' => null, 'amount' => $amount, 'linkage_scope' => 'ORDER LINKAGE NOT AVAILABLE FROM ACCOUNT TRANSACTION API', 'canonical_candidate' => 'UNKNOWN', 'transaction_index' => $index );
			foreach ( array( 'type', 'sub_type', 'pmt_reference', 'transaction_number', 'transaction_time', 'currency' ) as $key ) {
				$value = $raw[$key] ?? null;
				if ( null !== $value && ! is_string( $value ) ) { $warnings[] = array( 'transaction_index' => $index, 'field' => $key, 'observed_type' => self::json_type( $shape->$key ?? null ), 'expected_type' => 'string / number / null', 'code' => 'ACCOUNT_OPTIONAL_FIELD_UNSUPPORTED' ); $value = null; }
				// Exact numeric references bypass generic long-token redaction, but never known secrets.
				$safe[$key] = null === $value ? null : ( in_array( $key, array( 'pmt_reference', 'transaction_number' ), true ) && preg_match( '/^[0-9]{1,80}$/D', $value ) && ! in_array( $value, $this->secrets, true ) ? $value : $this->text( $value ) );
			}
			$safe['transaction_type'] = $safe['type']; $rows[] = $safe;
		}
		return array( 'provider_success' => true, 'records' => $rows, 'page_count' => count( $raws ), 'normalized_count' => count( $rows ), 'normalization_error_count' => count( $errors ), 'normalization_errors' => $errors, 'field_inventory' => $inventories, 'normalization_warnings' => $warnings, 'matched_count' => count( $rows ), 'page_num' => $page, 'page_size' => $size, 'page_info' => $pagination, 'next_page' => null !== $pagination['total_page'] && null !== $pagination['page_num'] && $page < (int) $pagination['total_page'] ? $page + 1 : null, 'coverage' => 'ACCOUNT_PAGE_ONLY_NOT_ORDER_SETTLEMENT', 'diagnostic' => $r['diagnostic'] );

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
