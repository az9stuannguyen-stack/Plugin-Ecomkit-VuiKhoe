<?php
/** One explicitly requested, authenticated Shopee Payment POST; no retries or canonical writes. */
defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Shopee_Payment_Service {
	public const PATH = '/api/v2/payment/get_escrow_detail';
	public array $last_diagnostic = array();
	public function __construct( private ?Ecomkit_Vuikhoe_Shopee_Token_Service $tokens = null ) { $this->tokens ??= new Ecomkit_Vuikhoe_Shopee_Token_Service(); }
	/** @return array<int,array<string,mixed>> */
	public function eligible_orders( int $batch_id ): array {
		if ( $batch_id < 1 ) { return array(); }
		global $wpdb; $table = Ecomkit_Vuikhoe_DB::table_names()['orders'];
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, marketplace_order_id FROM $table WHERE batch_id = %d AND platform = %s AND matching_status = %s AND connection_id IS NOT NULL ORDER BY id ASC", $batch_id, 'SHOPEE', 'MATCHED' ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<string,mixed> Safe one-order accounting summary. */
	public function inspect_matched_order( int $batch_id, int $order_id ): array {
		global $wpdb;
		$this->last_diagnostic = array( 'api_path' => self::PATH, 'method' => 'POST', 'http_status' => null, 'request_id' => '' );
		if ( $batch_id < 1 || $order_id < 1 || ! Ecomkit_Vuikhoe_DB::payment_schema_ready() ) { $this->fail( 'SHOPEE_PAYMENT_SOURCE_NOT_READY' ); }
		$tables = Ecomkit_Vuikhoe_DB::table_names();
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT id, batch_id, platform, matching_status, marketplace_order_id, eshop_order_code, connection_id FROM {$tables['orders']} WHERE id = %d AND batch_id = %d", $order_id, $batch_id ), ARRAY_A );
		if ( ! is_array( $order ) || 'SHOPEE' !== (string) ( $order['platform'] ?? '' ) || 'MATCHED' !== (string) ( $order['matching_status'] ?? '' ) || (int) ( $order['connection_id'] ?? 0 ) < 1 ) { $this->fail( 'SHOPEE_PAYMENT_ORDER_NOT_ELIGIBLE' ); }
		$sn = (string) ( $order['marketplace_order_id'] ?? '' );
		if ( '' === $sn || strlen( $sn ) > 191 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $sn ) ) { $this->fail( 'SHOPEE_PAYMENT_ORDER_NOT_ELIGIBLE' ); }
		$connection_id = (int) $order['connection_id'];
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, platform, external_shop_id, status, credential_source, metadata FROM {$tables['marketplace_connections']} WHERE id = %d", $connection_id ), ARRAY_A );
		$meta = is_array( $row ) ? json_decode( (string) ( $row['metadata'] ?? '' ), true ) : null;
		$config_service = new Ecomkit_Vuikhoe_Shopee_Config(); $config = $config_service->get();
		if ( ! is_array( $row ) || ! is_array( $meta ) || ! $config_service->readiness()['ready']
			|| 'SHOPEE' !== (string) ( $row['platform'] ?? '' ) || 'ACTIVE' !== (string) ( $row['status'] ?? '' )
			|| 'OAUTH' !== (string) ( $row['credential_source'] ?? '' ) || 'ECOMKIT' !== (string) ( $meta['refresh_ownership'] ?? '' )
			|| 'READY' !== (string) ( $meta['credential_lifecycle'] ?? '' )
			|| ! hash_equals( (string) ( $config['fingerprint'] ?? '' ), (string) ( $meta['provider_config_fingerprint'] ?? '' ) )
			|| ! Ecomkit_Vuikhoe_Shopee_Config::valid_partner_id( (string) ( $row['external_shop_id'] ?? '' ) ) ) { $this->fail( 'SHOPEE_PAYMENT_CONNECTION_NOT_READY' ); }
		$token = $this->tokens->ensure_usable_access_token( $connection_id );
		$partner_id = (string) $config['partner_id']; $shop_id = (string) $row['external_shop_id']; $timestamp = time();
		$signature = Ecomkit_Vuikhoe_Shopee_Signer::sign_shop( $partner_id, self::PATH, $timestamp, $token, $shop_id, $config_service->partner_key( $config ) );
		$url = add_query_arg( array( 'partner_id' => (int) $partner_id, 'timestamp' => $timestamp, 'access_token' => $token, 'shop_id' => (int) $shop_id, 'sign' => $signature ), Ecomkit_Vuikhoe_Shopee_Environment::api_url( (string) $config['environment'], self::PATH ) );
		$body = wp_json_encode( array( 'order_sn' => $sn ) );
		if ( ! is_string( $body ) ) { $this->fail( 'SHOPEE_PAYMENT_REQUEST_INVALID' ); }
		$http = wp_remote_post( $url, array( 'timeout' => 15, 'sslverify' => true, 'redirection' => 0, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => $body ) );
		if ( is_wp_error( $http ) ) { $this->fail( 'SHOPEE_PAYMENT_NETWORK_ERROR' ); }
		$status = (int) wp_remote_retrieve_response_code( $http ); $this->last_diagnostic['http_status'] = $status;
		try { $data = json_decode( wp_remote_retrieve_body( $http ), true, 64, JSON_THROW_ON_ERROR ); } catch ( Throwable ) { $this->fail( 'SHOPEE_PAYMENT_RESPONSE_INVALID' ); }
		if ( ! is_array( $data ) ) { $this->fail( 'SHOPEE_PAYMENT_RESPONSE_INVALID' ); }
		$this->capture_shape( $data );
		if ( ! array_key_exists( 'error', $data ) || ! is_scalar( $data['error'] ) ) { $this->fail( 'SHOPEE_PAYMENT_RESPONSE_INVALID' ); }
		$error = sanitize_key( (string) $data['error'] ); $message = (string) ( $data['message'] ?? '' );
		$this->last_diagnostic['provider_error'] = $error;
		$this->last_diagnostic['provider_message'] = self::safe_message( $message, $token, $signature );
		$this->last_diagnostic['request_id'] = sanitize_text_field( (string) ( $data['request_id'] ?? '' ) );
		if ( self::method_rejected( $status, $error, $message ) ) { $this->fail( 'SHOPEE_PAYMENT_METHOD_CONTRACT_REJECTED' ); }
		if ( '' !== $error ) { $this->fail( self::provider_classification( $error ) ); }
		if ( $status < 200 || $status >= 300 ) { $this->fail( 'SHOPEE_PAYMENT_HTTP_ERROR' ); }
		$response = $data['response'] ?? null;
		if ( ! is_array( $response ) || ! is_string( $response['order_sn'] ?? null ) || '' === $response['order_sn'] ) { $this->fail( 'SHOPEE_PAYMENT_RESPONSE_INVALID' ); }
		if ( ! hash_equals( $sn, $response['order_sn'] ) ) { $this->fail( 'SHOPEE_PAYMENT_IDENTITY_MISMATCH' ); }
		if ( ! is_array( $response['order_income'] ?? null ) ) { $this->fail( 'SHOPEE_PAYMENT_INCOME_UNAVAILABLE' ); }
		// Persist only the verified accounting subtree; buyer metadata and transport credentials are excluded.
		$raw = array( 'order_sn' => $sn, 'order_income' => $response['order_income'] );
		$normalized = Ecomkit_Vuikhoe_Shopee_Payment_Normalizer::normalize( $response );
		$raw_json = wp_json_encode( $raw, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		$normalized_json = wp_json_encode( $normalized, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( ! is_string( $raw_json ) || ! is_string( $normalized_json ) ) { $this->fail( 'SHOPEE_PAYMENT_RESPONSE_INVALID' ); }
		$updated = $wpdb->update( $tables['orders'], array( 'payment_raw_data' => $raw_json, 'payment_normalized_data' => $normalized_json, 'payment_fetched_at' => current_time( 'mysql', true ), 'payment_request_id' => $this->last_diagnostic['request_id'] ), array( 'id' => $order_id, 'batch_id' => $batch_id, 'connection_id' => $connection_id, 'marketplace_order_id' => $sn, 'matching_status' => 'MATCHED' ) );
		if ( false === $updated ) { $this->fail( 'SHOPEE_PAYMENT_PERSIST_FAILED' ); }
		if ( 0 === $updated ) {
			$stored = $wpdb->get_row( $wpdb->prepare( "SELECT marketplace_order_id, matching_status, connection_id, payment_raw_data, payment_normalized_data FROM {$tables['orders']} WHERE id = %d AND batch_id = %d", $order_id, $batch_id ), ARRAY_A );
			if ( ! is_array( $stored ) || $sn !== (string) ( $stored['marketplace_order_id'] ?? '' ) || 'MATCHED' !== (string) ( $stored['matching_status'] ?? '' ) || $connection_id !== (int) ( $stored['connection_id'] ?? 0 ) || $raw_json !== (string) ( $stored['payment_raw_data'] ?? '' ) || $normalized_json !== (string) ( $stored['payment_normalized_data'] ?? '' ) ) { $this->fail( 'SHOPEE_PAYMENT_PERSIST_FAILED' ); }
		}
		return array( 'order_sn' => $sn, 'normalized' => $normalized, 'request_id' => $this->last_diagnostic['request_id'], 'diagnostic' => $this->last_diagnostic );
	}
	private function capture_shape( array $data ): void {
		$response = $data['response'] ?? null; $income = is_array( $response ) ? ( $response['order_income'] ?? null ) : null;
		$this->last_diagnostic['top_level_keys'] = array_keys( $data );
		$this->last_diagnostic['response_type'] = get_debug_type( $response );
		$this->last_diagnostic['response_keys'] = is_array( $response ) ? array_keys( $response ) : array();
		$this->last_diagnostic['order_income_type'] = get_debug_type( $income );
		$this->last_diagnostic['order_income_keys'] = is_array( $income ) ? array_keys( $income ) : array();
		$this->last_diagnostic['order_income_field_types'] = is_array( $income ) ? array_map( static fn( mixed $key ): string => (string) $key . ':' . get_debug_type( $income[ $key ] ), array_keys( $income ) ) : array();
	}
	private static function method_rejected( int $status, string $error, string $message ): bool { return 405 === $status || ( in_array( $error, array( 'error_param', 'error_method' ), true ) && 1 === preg_match( '/(?:http\s*method|method\s*not\s*allowed|unsupported\s*method|request\s*body\s*is\s*not\s*valid\s*json)/i', $message ) ); }
	private static function provider_classification( string $error ): string { return match ( $error ) { 'error_api_permission', 'error_api_call_restricted' => 'MANUAL_BLOCKED_EXTERNAL_PERMISSION', 'error_auth', 'error_sign', 'invalid_partner_id', 'source_ip_undeclared', 'error_partner_key_expired', 'shop_no_linked', 'shop_banned', 'error_kyc_auth' => 'SHOPEE_PAYMENT_AUTH_OR_CONFIG_ERROR', 'error_rate_limit', 'error_limit' => 'SHOPEE_PAYMENT_RATE_LIMITED', 'order_not_found' => 'SHOPEE_PAYMENT_INCOME_UNAVAILABLE', default => 'SHOPEE_PAYMENT_PROVIDER_ERROR' }; }
	private static function safe_message( string $message, string $token, string $signature ): string { $message = str_replace( array( $token, $signature ), '[redacted]', $message ); $message = preg_replace( '/[A-Za-z0-9_\-]{32,}/', '[redacted]', $message ); return mb_substr( trim( preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $message ) ), 0, 240 ); }
	private function fail( string $classification ): never { $this->last_diagnostic['classification'] = $classification; throw new Ecomkit_Vuikhoe_Shopee_Payment_Exception( $classification, $this->last_diagnostic ); }
}

final class Ecomkit_Vuikhoe_Shopee_Payment_Exception extends RuntimeException { public function __construct( string $code, public readonly array $diagnostic = array() ) { parent::__construct( $code ); } }
