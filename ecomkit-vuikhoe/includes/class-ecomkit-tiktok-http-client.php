<?php
/** Fixed allowlisted seller authorization endpoints only; no Order API. */
defined( 'ABSPATH' ) || exit;
final class Ecomkit_Vuikhoe_Tiktok_Http_Client {
	public const MAX_BYTES = 1048576;
	public array $last_diagnostic = array();
	public function __construct( private mixed $transport = null ) {}
	public function exchange( array $app, string $code ): array {
		return $this->get( 'https://auth.tiktok-shops.com', '/api/v2/token/get', array( 'app_key' => $app['app_key'], 'app_secret' => $app['app_secret'], 'auth_code' => $code, 'grant_type' => 'authorized_code' ) );
	}
	public function refresh( array $app, string $refresh ): array {
		return $this->get( 'https://auth.tiktok-shops.com', '/api/v2/token/refresh', array( 'app_key' => $app['app_key'], 'app_secret' => $app['app_secret'], 'refresh_token' => $refresh, 'grant_type' => 'refresh_token' ) );
	}
	public function shops( array $app, string $access ): array {
		$path = '/authorization/202309/shops'; $query = array( 'app_key' => $app['app_key'], 'timestamp' => (string) time() );
		$query['sign'] = Ecomkit_Vuikhoe_Tiktok_Signer::sign( $path, $query, $app['app_secret'] );
		return $this->get( Ecomkit_Vuikhoe_Tiktok_Config::API_BASE, $path, $query, array( 'x-tts-access-token' => $access ) );
	}
	private function get( string $base, string $path, array $query, array $headers = array() ): array {
		$this->last_diagnostic = array( 'api_path' => $path, 'http_method' => 'GET' );
		$url = $base . $path . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		$args = array( 'timeout' => 20, 'sslverify' => true, 'redirection' => 0, 'limit_response_size' => self::MAX_BYTES + 1, 'headers' => array_merge( array( 'content-type' => 'application/json' ), $headers ) );
		try { $result = $this->transport ? ( $this->transport )( $url, $args ) : wp_remote_get( $url, $args ); } catch ( Throwable ) { throw new RuntimeException( 'TIKTOK_NETWORK_ERROR' ); }
		if ( is_wp_error( $result ) ) { throw new RuntimeException( 'TIKTOK_NETWORK_ERROR' ); }
		$status = wp_remote_retrieve_response_code( $result ); $body = wp_remote_retrieve_body( $result ); $this->last_diagnostic['http_status'] = $status;
		if ( $status < 200 || $status >= 300 ) { throw new RuntimeException( 'TIKTOK_HTTP_ERROR' ); }
		if ( ! is_string( $body ) || strlen( $body ) > self::MAX_BYTES ) { throw new RuntimeException( 'TIKTOK_INVALID_RESPONSE' ); }
		$data = json_decode( $body, true, 32, JSON_BIGINT_AS_STRING );
		if ( ! is_array( $data ) || ! is_int( $data['code'] ?? null ) ) { throw new RuntimeException( 'TIKTOK_INVALID_RESPONSE' ); }
		$this->last_diagnostic['provider_code'] = $data['code']; $id = $data['request_id'] ?? null;
		$sensitive = array_intersect_key( $query, array_flip( array( 'app_secret', 'auth_code', 'refresh_token', 'sign', 'access_token' ) ) ); $sensitive = array_merge( array_values( $sensitive ), array_values( $headers ) );
		$clean = is_string( $id ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,128}$/D', $id );
		foreach ( $sensitive as $value ) { if ( is_string( $value ) && '' !== $value && $clean && str_contains( $id, $value ) ) { $clean = false; } }
		if ( $clean ) { $this->last_diagnostic['request_id'] = $id; }
		// Provider message/body can echo credentials or buyer data: never project them.
		if ( 0 !== $data['code'] ) { throw new RuntimeException( 'TIKTOK_PROVIDER_ERROR' ); }
		if ( ! is_array( $data['data'] ?? null ) ) { throw new RuntimeException( 'TIKTOK_INVALID_RESPONSE' ); }
		return $data['data'];
	}
}
