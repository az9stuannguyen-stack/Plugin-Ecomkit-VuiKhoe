<?php
/** Single-purpose Shopee OAuth HTTP client; no business endpoints. */
defined( 'ABSPATH' ) || exit;
final class Ecomkit_Vuikhoe_Shopee_HTTP_Client {
	private const TOKEN_PATH = '/api/v2/auth/token/get';
	private const REFRESH_PATH = '/api/v2/auth/access_token/get';
	/** @var array<string,mixed> */ public array $last_diagnostic = array();
	/** @return array<string,mixed> */
	public function exchange( array $config, string $partner_key, string $code, string $callback_shop_id ): array {
		$partner_id = self::strict_positive_int( (string) ( $config['partner_id'] ?? '' ), 'SHOPEE_PARTNER_ID_INVALID' );
		$timestamp = time();
		$url = add_query_arg( array( 'partner_id' => $partner_id, 'timestamp' => $timestamp, 'sign' => Ecomkit_Vuikhoe_Shopee_Signer::sign( (string) $partner_id, self::TOKEN_PATH, $timestamp, $partner_key ) ), Ecomkit_Vuikhoe_Shopee_Environment::api_url( (string) $config['environment'], self::TOKEN_PATH ) );
		$body = wp_json_encode( array( 'code' => $code, 'partner_id' => $partner_id ) );
		if ( ! is_string( $body ) ) { $this->fail( 'SHOPEE_TOKEN_RESPONSE_INVALID', 'TOKEN_REQUEST_BUILD' ); }
		$started = microtime( true );
		$response = wp_remote_post( $url, array( 'timeout' => 15, 'redirection' => 0, 'sslverify' => true, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => $body ) );
		$this->last_diagnostic = $this->diagnostic( 'TOKEN_HTTP_REQUEST', null, '', '', '', (int) round( ( microtime( true ) - $started ) * 1000 ) );
		if ( is_wp_error( $response ) ) { $this->fail( 'SHOPEE_TOKEN_NETWORK_ERROR', 'TOKEN_HTTP_REQUEST' ); }
		$status = (int) wp_remote_retrieve_response_code( $response ); $this->last_diagnostic['http_status'] = $status;
		try { $data = json_decode( wp_remote_retrieve_body( $response ), true, 16, JSON_THROW_ON_ERROR ); } catch ( Throwable $exception ) { $this->fail( 'SHOPEE_TOKEN_INVALID_JSON', 'TOKEN_PROVIDER_RESPONSE' ); }
		if ( ! is_array( $data ) ) { $this->fail( 'SHOPEE_TOKEN_INVALID_JSON', 'TOKEN_PROVIDER_RESPONSE' ); }
		$error = sanitize_key( trim( (string) ( $data['error'] ?? '' ) ) ); $message = $this->safe_message( (string) ( $data['message'] ?? '' ), $code ); $request_id = sanitize_text_field( (string) ( $data['request_id'] ?? '' ) );
		$this->last_diagnostic = $this->diagnostic( 'TOKEN_PROVIDER_RESPONSE', $status, $error, $message, $request_id, (int) $this->last_diagnostic['duration_ms'] );
		if ( '' !== $error ) { $this->fail( self::map_error( $error ), 'TOKEN_PROVIDER_RESPONSE' ); }
		if ( $status < 200 || $status >= 300 ) { $this->fail( 'SHOPEE_TOKEN_HTTP_ERROR', 'TOKEN_PROVIDER_RESPONSE' ); }
		foreach ( array( 'access_token', 'refresh_token' ) as $field ) { if ( ! isset( $data[ $field ] ) || ! is_string( $data[ $field ] ) || '' === trim( $data[ $field ] ) ) { $this->fail( 'SHOPEE_TOKEN_RESPONSE_INVALID', 'TOKEN_RESPONSE_VALIDATE' ); } }
		$expire_in = filter_var( $data['expire_in'] ?? null, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ); if ( false === $expire_in ) { $this->fail( 'SHOPEE_TOKEN_RESPONSE_INVALID', 'TOKEN_RESPONSE_VALIDATE' ); }
		if ( isset( $data['shop_id_list'] ) ) {
			if ( ! is_array( $data['shop_id_list'] ) ) { $this->fail( 'SHOPEE_TOKEN_RESPONSE_INVALID', 'SHOP_ID_VALIDATE' ); }
			$list = array(); foreach ( $data['shop_id_list'] as $shop_id ) { if ( ! is_int( $shop_id ) && ! ( is_string( $shop_id ) && ctype_digit( $shop_id ) ) ) { $this->fail( 'SHOPEE_TOKEN_RESPONSE_INVALID', 'SHOP_ID_VALIDATE' ); } $list[] = ltrim( (string) $shop_id, '0' ) ?: '0'; }
			if ( ! in_array( ltrim( $callback_shop_id, '0' ) ?: '0', $list, true ) ) { $this->fail( 'SHOPEE_OAUTH_SHOP_MISMATCH', 'SHOP_ID_VALIDATE' ); }
		}
		$data['expire_in'] = (int) $expire_in; return $data;
	}
	/** @return array<string,mixed> */
	public function refresh( array $config, string $partner_key, string $refresh_token, string $shop_id ): array {
		$partner_id = self::strict_positive_int( (string) ( $config['partner_id'] ?? '' ), 'SHOPEE_INVALID_PARTNER_ID', 'REFRESH_REQUEST_BUILD' );
		$shop_id_int = self::strict_positive_int( $shop_id, 'SHOPEE_REFRESH_SHOP_INVALID', 'REFRESH_REQUEST_BUILD' );
		$timestamp = time(); $url = add_query_arg( array( 'partner_id' => $partner_id, 'timestamp' => $timestamp, 'sign' => Ecomkit_Vuikhoe_Shopee_Signer::sign( (string) $partner_id, self::REFRESH_PATH, $timestamp, $partner_key ) ), Ecomkit_Vuikhoe_Shopee_Environment::api_url( (string) $config['environment'], self::REFRESH_PATH ) );
		$body = wp_json_encode( array( 'refresh_token' => $refresh_token, 'partner_id' => $partner_id, 'shop_id' => $shop_id_int ) ); if ( ! is_string( $body ) ) { $this->refresh_fail( 'SHOPEE_REFRESH_REQUEST_INVALID', 'REFRESH_REQUEST_BUILD' ); }
		$started = microtime( true ); $response = wp_remote_post( $url, array( 'timeout' => 15, 'redirection' => 0, 'sslverify' => true, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => $body ) );
		$this->last_diagnostic = $this->refresh_diagnostic( 'REFRESH_HTTP_REQUEST', null, '', '', '', (int) round( ( microtime( true ) - $started ) * 1000 ) );
		if ( is_wp_error( $response ) ) { $this->refresh_fail( 'SHOPEE_REFRESH_NETWORK_ERROR', 'REFRESH_HTTP_REQUEST' ); }
		$status = (int) wp_remote_retrieve_response_code( $response );
		try { $data = json_decode( wp_remote_retrieve_body( $response ), true, 16, JSON_THROW_ON_ERROR ); } catch ( Throwable $exception ) { $this->last_diagnostic['http_status'] = $status; $this->refresh_fail( 'SHOPEE_REFRESH_INVALID_JSON', 'REFRESH_PROVIDER_RESPONSE' ); }
		if ( ! is_array( $data ) ) { $this->refresh_fail( 'SHOPEE_REFRESH_INVALID_JSON', 'REFRESH_PROVIDER_RESPONSE' ); }
		$error = sanitize_key( trim( (string) ( $data['error'] ?? '' ) ) ); $message = $this->safe_message( (string) ( $data['message'] ?? '' ), $refresh_token ); $request_id = sanitize_text_field( (string) ( $data['request_id'] ?? '' ) );
		$this->last_diagnostic = $this->refresh_diagnostic( 'REFRESH_PROVIDER_RESPONSE', $status, $error, $message, $request_id, (int) $this->last_diagnostic['duration_ms'] );
		if ( '' !== $error ) { $this->refresh_fail( self::map_refresh_error( $error, $message ), 'REFRESH_PROVIDER_RESPONSE' ); }
		if ( $status < 200 || $status >= 300 ) { $this->refresh_fail( 'SHOPEE_REFRESH_HTTP_ERROR', 'REFRESH_PROVIDER_RESPONSE' ); }
		foreach ( array( 'access_token', 'refresh_token' ) as $field ) { if ( ! isset( $data[ $field ] ) || ! is_string( $data[ $field ] ) || '' === trim( $data[ $field ] ) ) { $this->refresh_fail( 'SHOPEE_REFRESH_RESPONSE_INVALID', 'REFRESH_RESPONSE_VALIDATE' ); } }
		$expire_in = filter_var( $data['expire_in'] ?? null, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ); if ( false === $expire_in ) { $this->refresh_fail( 'SHOPEE_REFRESH_RESPONSE_INVALID', 'REFRESH_RESPONSE_VALIDATE' ); }
		if ( isset( $data['partner_id'] ) && (string) $partner_id !== (string) $data['partner_id'] ) { $this->refresh_fail( 'SHOPEE_REFRESH_IDENTITY_MISMATCH', 'REFRESH_IDENTITY_VALIDATE' ); }
		if ( isset( $data['shop_id'] ) && (string) $shop_id_int !== (string) $data['shop_id'] ) { $this->refresh_fail( 'SHOPEE_REFRESH_IDENTITY_MISMATCH', 'REFRESH_IDENTITY_VALIDATE' ); }
		$data['expire_in'] = (int) $expire_in; return $data;
	}
	private static function strict_positive_int( string $value, string $error, string $stage = 'TOKEN_REQUEST_BUILD' ): int { if ( PHP_INT_SIZE < 8 || 1 !== preg_match( '/^[1-9][0-9]*$/', $value ) || false === filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1, 'max_range' => PHP_INT_MAX ) ) ) ) { throw new Ecomkit_Vuikhoe_Shopee_Provider_Exception( $error, array( 'stage' => $stage ) ); } return (int) $value; }
	private static function map_error( string $error ): string { return match ( $error ) { 'invalid_code', 'error_auth' => 'SHOPEE_AUTH_CODE_INVALID', 'invalid_shop_id' => 'SHOPEE_AUTH_SHOP_INVALID', 'error_param' => 'SHOPEE_TOKEN_PARAMETER_INVALID', 'error_sign' => 'SHOPEE_SIGNATURE_INVALID', 'invalid_partner_id' => 'SHOPEE_INVALID_PARTNER_ID', 'error_server', 'error_network' => 'SHOPEE_PROVIDER_ERROR', default => 'SHOPEE_TOKEN_PROVIDER_ERROR' }; }
	private static function map_refresh_error( string $error, string $message ): string { if ( in_array( $error, array( 'refresh_token_expired', 'error_shop_refresh_token', 'shop_access_expired', 'shop_no_linked', 'shop_banned', 'error_merchant_refresh_token', 'merchant_access_expired', 'merchant_no_linked', 'merchant_banned' ), true ) || ( 'error_auth' === $error && false !== stripos( $message, 'refresh' ) ) ) { return 'SHOPEE_REFRESH_REAUTH_REQUIRED'; } return match ( $error ) { 'source_ip_undeclared' => 'SHOPEE_SOURCE_IP_UNDECLARED', 'error_partner_key_expired' => 'SHOPEE_PROVIDER_CONFIG_REQUIRES_UPDATE', 'error_sign' => 'SHOPEE_SIGNATURE_INVALID', 'invalid_partner_id' => 'SHOPEE_INVALID_PARTNER_ID', 'error_api_permission', 'error_api_call_restricted' => 'SHOPEE_API_PERMISSION_DENIED', 'error_rate_limit', 'error_limit' => 'SHOPEE_RATE_LIMITED', 'error_param' => 'SHOPEE_REFRESH_PARAMETER_INVALID', 'error_server', 'error_network' => 'SHOPEE_PROVIDER_ERROR', default => 'SHOPEE_REFRESH_TOKEN_INVALID' }; }
	private function safe_message( string $message, string $authorization_code ): string { $message = str_replace( $authorization_code, '[redacted]', $message ); $message = preg_replace( '/[A-Za-z0-9_\-]{32,}/', '[redacted]', $message ); return mb_substr( trim( preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $message ) ), 0, 240 ); }
	/** @return array<string,mixed> */ private function diagnostic( string $stage, ?int $status = null, string $error = '', string $message = '', string $request_id = '', int $duration = 0 ): array { return array( 'stage' => $stage, 'api_path' => self::TOKEN_PATH, 'http_status' => $status, 'provider_error' => $error, 'provider_message' => $message, 'request_id' => $request_id, 'duration_ms' => $duration ); }
	/** @return array<string,mixed> */ private function refresh_diagnostic( string $stage, ?int $status = null, string $error = '', string $message = '', string $request_id = '', int $duration = 0 ): array { return array( 'stage' => $stage, 'api_path' => self::REFRESH_PATH, 'http_status' => $status, 'provider_error' => $error, 'provider_message' => $message, 'request_id' => $request_id, 'duration_ms' => $duration ); }
	private function fail( string $classification, string $stage ): never { $this->last_diagnostic['stage'] = $stage; throw new Ecomkit_Vuikhoe_Shopee_Provider_Exception( $classification, $this->last_diagnostic ); }
	private function refresh_fail( string $classification, string $stage ): never { $this->last_diagnostic['stage'] = $stage; throw new Ecomkit_Vuikhoe_Shopee_Provider_Exception( $classification, $this->last_diagnostic ); }
}
final class Ecomkit_Vuikhoe_Shopee_Provider_Exception extends RuntimeException {
	/** @param array<string,mixed> $diagnostic */ public function __construct( string $code, public readonly array $diagnostic = array() ) { parent::__construct( $code ); }
	public function request_id(): string { return (string) ( $this->diagnostic['request_id'] ?? '' ); }
}
