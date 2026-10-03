<?php
/** Single-purpose Shopee OAuth HTTP client; no business endpoints. */
defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Shopee_HTTP_Client {
	private const TOKEN_PATH = '/api/v2/auth/token/get';
	/** @var array<string,mixed> */
	public array $last_diagnostic = array();
	/** @return array<string,mixed> */
	public function exchange( array $config, string $partner_key, string $code, string $shop_id ): array {
		$timestamp = time();
		$partner_id = (string) $config['partner_id'];
		$url = add_query_arg( array( 'partner_id' => $partner_id, 'timestamp' => $timestamp, 'sign' => Ecomkit_Vuikhoe_Shopee_Signer::sign( $partner_id, self::TOKEN_PATH, $timestamp, $partner_key ) ), Ecomkit_Vuikhoe_Shopee_Environment::api_url( (string) $config['environment'], self::TOKEN_PATH ) );
		$started = microtime( true );
		$response = wp_remote_post( $url, array( 'timeout' => 15, 'sslverify' => true, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( array( 'partner_id' => (int) $partner_id, 'code' => $code, 'shop_id' => (int) $shop_id ) ) ) );
		$this->last_diagnostic = array( 'operation' => 'TOKEN_EXCHANGE', 'api_path' => self::TOKEN_PATH, 'http_status' => null, 'provider_error' => null, 'request_id' => '', 'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ) );
		if ( is_wp_error( $response ) ) { throw new RuntimeException( method_exists( $response, 'get_error_code' ) && str_contains( strtolower( (string) $response->get_error_code() ), 'timeout' ) ? 'SHOPEE_TIMEOUT' : 'SHOPEE_NETWORK_ERROR' ); }
		$status = wp_remote_retrieve_response_code( $response );
		$this->last_diagnostic['http_status'] = $status;
		try { $data = json_decode( wp_remote_retrieve_body( $response ), true, 16, JSON_THROW_ON_ERROR ); } catch ( Throwable $exception ) { throw new RuntimeException( 'SHOPEE_RESPONSE_INVALID' ); }
		if ( ! is_array( $data ) ) { throw new RuntimeException( 'SHOPEE_RESPONSE_INVALID' ); }
		$error = trim( (string) ( $data['error'] ?? '' ) );
		$this->last_diagnostic['provider_error'] = sanitize_key( $error );
		$this->last_diagnostic['request_id'] = sanitize_text_field( (string) ( $data['request_id'] ?? '' ) );
		if ( '' !== $error || $status < 200 || $status >= 300 ) { throw new Ecomkit_Vuikhoe_Shopee_Provider_Exception( self::map_error( $error, $status ), sanitize_text_field( (string) ( $data['request_id'] ?? '' ) ) ); }
		foreach ( array( 'access_token', 'refresh_token' ) as $field ) { if ( ! isset( $data[ $field ] ) || ! is_string( $data[ $field ] ) || '' === $data[ $field ] ) { throw new RuntimeException( 'SHOPEE_TOKEN_RESPONSE_INCOMPLETE' ); } }
		$expire_in = filter_var( $data['expire_in'] ?? null, FILTER_VALIDATE_INT );
		if ( false === $expire_in || $expire_in <= 0 ) { throw new RuntimeException( 'SHOPEE_TOKEN_RESPONSE_INCOMPLETE' ); }
		if ( isset( $data['shop_id_list'] ) ) {
			$list = array_map( 'strval', is_array( $data['shop_id_list'] ) ? $data['shop_id_list'] : array() );
			if ( ! in_array( $shop_id, $list, true ) ) { throw new RuntimeException( 'SHOPEE_OAUTH_SHOP_MISMATCH' ); }
		}
		$data['expire_in'] = (int) $expire_in;
		return $data;
	}

	private static function map_error( string $error, int $status ): string {
		return match ( strtolower( $error ) ) { 'invalid_partner_id' => 'SHOPEE_INVALID_PARTNER_ID', 'error_sign' => 'SHOPEE_SIGNATURE_INVALID', 'invalid_code' => 'SHOPEE_AUTH_CODE_INVALID', 'no_permission', 'permission_denied' => 'SHOPEE_PERMISSION_DENIED', default => $status >= 500 ? 'SHOPEE_PROVIDER_ERROR' : 'SHOPEE_OAUTH_FAILED' };
	}
}

final class Ecomkit_Vuikhoe_Shopee_Provider_Exception extends RuntimeException {
	public function __construct( string $code, public readonly string $request_id = '' ) { parent::__construct( $code ); }
}
