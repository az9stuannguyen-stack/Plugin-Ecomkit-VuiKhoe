<?php
/** Admin-initiated, bounded read-only Shopee Income lookup. */
defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Shopee_Income_Service {
	public const PATH = '/api/v2/payment/get_income_detail';
	public const PAGE_SIZE = 30; // Provider schema request example; no larger bound is assumed.
	public const MAX_PAGES = 10;
	public array $last_diagnostic = array();
	public function __construct( private ?Ecomkit_Vuikhoe_Shopee_Token_Service $tokens = null ) { $this->tokens ??= new Ecomkit_Vuikhoe_Shopee_Token_Service(); }

	public static function query_dates( string $bucket, string $from = '', string $to = '' ): array {
		if ( 'PENDING' === $bucket ) {
			$today = new DateTimeImmutable( 'today', wp_timezone() );
			return array( $today->modify( '-1 day' )->format( 'Y-m-d' ), $today->format( 'Y-m-d' ) );
		}
		if ( 'RELEASED' !== $bucket ) { throw new InvalidArgumentException( 'SHOPEE_INCOME_INVALID_STATUS' ); }
		foreach ( array( $from, $to ) as $date ) {
			$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
			if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $date ) { throw new InvalidArgumentException( 'SHOPEE_INCOME_INVALID_DATE' ); }
		}
		$start = new DateTimeImmutable( $from, wp_timezone() ); $end = new DateTimeImmutable( $to, wp_timezone() );
		$days = (int) $start->diff( $end )->format( '%r%a' );
		if ( $days < 1 || $days > 14 ) { throw new InvalidArgumentException( 'SHOPEE_INCOME_INVALID_DATE_RANGE' ); }
		return array( $from, $to );
	}

	public function inspect_matched_order( int $batch_id, int $order_id, string $bucket, string $from = '', string $to = '' ): array {
		global $wpdb;
		$this->last_diagnostic = array( 'stage' => 'WP.6D', 'api_path' => self::PATH, 'method' => 'POST', 'http_status' => null, 'request_id' => '', 'pages' => 0 );
		try { list( $from, $to ) = self::query_dates( $bucket, $from, $to ); } catch ( InvalidArgumentException $exception ) { $this->fail( $exception->getMessage() ); }
		if ( $batch_id < 1 || $order_id < 1 || ! Ecomkit_Vuikhoe_DB::income_schema_ready() ) { $this->fail( 'SHOPEE_INCOME_SOURCE_NOT_READY' ); }
		$tables = Ecomkit_Vuikhoe_DB::table_names();
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT id, batch_id, platform, matching_status, marketplace_order_id, eshop_order_code, connection_id FROM {$tables['orders']} WHERE id = %d AND batch_id = %d", $order_id, $batch_id ), ARRAY_A );
		if ( ! is_array( $order ) || 'SHOPEE' !== (string) ( $order['platform'] ?? '' ) || 'MATCHED' !== (string) ( $order['matching_status'] ?? '' ) || (int) ( $order['connection_id'] ?? 0 ) < 1 ) { $this->fail( 'SHOPEE_INCOME_ORDER_NOT_ELIGIBLE' ); }
		$sn = (string) ( $order['marketplace_order_id'] ?? '' );
		if ( '' === $sn || strlen( $sn ) > 191 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $sn ) ) { $this->fail( 'SHOPEE_INCOME_ORDER_NOT_ELIGIBLE' ); }
		$connection_id = (int) $order['connection_id'];
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, platform, external_shop_id, status, credential_source, metadata FROM {$tables['marketplace_connections']} WHERE id = %d", $connection_id ), ARRAY_A );
		$meta = is_array( $row ) ? json_decode( (string) ( $row['metadata'] ?? '' ), true ) : null;
		$config_service = new Ecomkit_Vuikhoe_Shopee_Config(); $config = $config_service->get();
		if ( ! is_array( $row ) || ! is_array( $meta ) || ! $config_service->readiness()['ready'] || 'SHOPEE' !== (string) ( $row['platform'] ?? '' ) || 'ACTIVE' !== (string) ( $row['status'] ?? '' ) || 'OAUTH' !== (string) ( $row['credential_source'] ?? '' ) || 'ECOMKIT' !== (string) ( $meta['refresh_ownership'] ?? '' ) || 'READY' !== (string) ( $meta['credential_lifecycle'] ?? '' ) || ! hash_equals( (string) ( $config['fingerprint'] ?? '' ), (string) ( $meta['provider_config_fingerprint'] ?? '' ) ) || ! Ecomkit_Vuikhoe_Shopee_Config::valid_partner_id( (string) ( $row['external_shop_id'] ?? '' ) ) ) { $this->fail( 'SHOPEE_INCOME_CONNECTION_NOT_READY' ); }
		$token = $this->tokens->ensure_usable_access_token( $connection_id );
		$partner_id = (string) $config['partner_id']; $shop_id = (string) $row['external_shop_id'];
		$cursor = ''; $seen = array();
		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$timestamp = time();
			$signature = Ecomkit_Vuikhoe_Shopee_Signer::sign_shop( $partner_id, self::PATH, $timestamp, $token, $shop_id, $config_service->partner_key( $config ) );
			$url = add_query_arg( array( 'partner_id' => (int) $partner_id, 'timestamp' => $timestamp, 'access_token' => $token, 'shop_id' => (int) $shop_id, 'sign' => $signature ), Ecomkit_Vuikhoe_Shopee_Environment::api_url( (string) $config['environment'], self::PATH ) );
			$body = wp_json_encode( array( 'cursor' => $cursor, 'date_from' => $from, 'date_to' => $to, 'income_status' => 'RELEASED' === $bucket ? 1 : 2, 'page_size' => self::PAGE_SIZE ) );
			if ( ! is_string( $body ) ) { $this->fail( 'SHOPEE_INCOME_REQUEST_INVALID' ); }
			$http = wp_remote_post( $url, array( 'timeout' => 15, 'sslverify' => true, 'redirection' => 0, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => $body ) );
			$this->last_diagnostic['pages'] = $page;
			if ( is_wp_error( $http ) ) { $this->fail( 'SHOPEE_INCOME_NETWORK_ERROR' ); }
			$status = (int) wp_remote_retrieve_response_code( $http ); $this->last_diagnostic['http_status'] = $status;
			try { $data = json_decode( wp_remote_retrieve_body( $http ), true, 64, JSON_THROW_ON_ERROR ); } catch ( Throwable ) { $this->fail( 'SHOPEE_INCOME_RESPONSE_INVALID' ); }
			if ( ! is_array( $data ) ) { $this->fail( 'SHOPEE_INCOME_RESPONSE_INVALID' ); }
			$income = $data['income_detail_list'] ?? null;
			$this->last_diagnostic['top_level_keys'] = array_keys( $data );
			$this->last_diagnostic['income_response_type'] = get_debug_type( $income );
			$this->last_diagnostic['income_response_keys'] = is_array( $income ) ? array_keys( $income ) : array();
			$this->last_diagnostic['income_list_type'] = is_array( $income ) ? get_debug_type( $income['list'] ?? null ) : 'null';
			$this->last_diagnostic['next_page_type'] = is_array( $income ) ? get_debug_type( $income['next_page'] ?? null ) : 'null';
			$error = is_scalar( $data['error'] ?? null ) ? sanitize_key( (string) $data['error'] ) : '';
			$this->last_diagnostic['provider_error'] = $error;
			$this->last_diagnostic['request_id'] = sanitize_text_field( (string) ( $data['request_id'] ?? '' ) );
			$this->last_diagnostic['provider_message'] = self::safe_message( (string) ( $data['message'] ?? '' ), $token, $signature );
			if ( '' !== $error ) { $this->fail( self::provider_classification( $error ) ); }
			if ( $status < 200 || $status >= 300 ) { $this->fail( 'SHOPEE_INCOME_HTTP_ERROR' ); }
			if ( ! array_key_exists( 'error', $data ) || ! is_array( $income ) || ! is_array( $income['list'] ?? null ) || ! array_is_list( $income['list'] ) || ! is_array( $income['next_page'] ?? null ) || ! is_string( $income['next_page']['cursor'] ?? null ) ) { $this->fail( 'SHOPEE_INCOME_RESPONSE_INVALID' ); }
			foreach ( $income['list'] as $item ) {
				if ( ! is_array( $item ) || ! is_string( $item['order_sn'] ?? null ) || '' === $item['order_sn'] ) { $this->fail( 'SHOPEE_INCOME_RESPONSE_INVALID' ); }
				if ( ! hash_equals( $sn, $item['order_sn'] ) ) { continue; }
				$normalized = Ecomkit_Vuikhoe_Shopee_Income_Normalizer::normalize( $item, $bucket );
				$raw_json = wp_json_encode( Ecomkit_Vuikhoe_Shopee_Income_Normalizer::minimized_raw( $item ), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
				$normalized_json = wp_json_encode( $normalized, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
				if ( ! is_string( $raw_json ) || ! is_string( $normalized_json ) ) { $this->fail( 'SHOPEE_INCOME_RESPONSE_INVALID' ); }
				$updated = $wpdb->update( $tables['orders'], array( 'income_raw_data' => $raw_json, 'income_normalized_data' => $normalized_json, 'income_fetched_at' => current_time( 'mysql', true ), 'income_request_id' => $this->last_diagnostic['request_id'] ), array( 'id' => $order_id, 'batch_id' => $batch_id, 'connection_id' => $connection_id, 'platform' => 'SHOPEE', 'marketplace_order_id' => $sn, 'matching_status' => 'MATCHED' ) );
				if ( false === $updated ) { $this->fail( 'SHOPEE_INCOME_PERSIST_FAILED' ); }
				return array( 'classification' => 'FOUND', 'order_sn' => $sn, 'normalized' => $normalized, 'request_id' => $this->last_diagnostic['request_id'], 'pages' => $page );
			}
			$next = $income['next_page']['cursor'];
			if ( '' === $next ) { return array( 'classification' => 'NOT_FOUND_IN_THIS_INCOME_QUERY', 'order_sn' => $sn, 'incomeBucket' => $bucket, 'pages' => $page, 'request_id' => $this->last_diagnostic['request_id'] ); }
			if ( $next === $cursor || isset( $seen[ $next ] ) ) { $this->fail( 'SHOPEE_INCOME_PAGINATION_INCOMPLETE' ); }
			$seen[ $next ] = true; $cursor = $next;
		}
		$this->fail( 'SHOPEE_INCOME_PAGINATION_INCOMPLETE' );
	}

	private static function provider_classification( string $error ): string { return match ( $error ) { 'error_api_permission', 'error_api_call_restricted' => 'MANUAL_BLOCKED_EXTERNAL_PERMISSION', 'error_auth', 'error_sign', 'source_ip_undeclared', 'invalid_partner_id', 'error_partner_key_expired' => 'SHOPEE_INCOME_AUTH_OR_CONFIG_ERROR', 'error_rate_limit', 'error_limit' => 'SHOPEE_INCOME_RATE_LIMITED', default => 'SHOPEE_INCOME_PROVIDER_ERROR' }; }
	private static function safe_message( string $message, string $token, string $signature ): string { $message = str_replace( array( $token, $signature ), '[redacted]', $message ); $message = preg_replace( '/[A-Za-z0-9_\-]{32,}/', '[redacted]', $message ); return mb_substr( trim( preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $message ) ), 0, 240 ); }
	private function fail( string $classification ): never { $this->last_diagnostic['classification'] = $classification; throw new Ecomkit_Vuikhoe_Shopee_Income_Exception( $classification, $this->last_diagnostic ); }
}

final class Ecomkit_Vuikhoe_Shopee_Income_Exception extends RuntimeException { public function __construct( string $code, public readonly array $diagnostic = array() ) { parent::__construct( $code ); } }
