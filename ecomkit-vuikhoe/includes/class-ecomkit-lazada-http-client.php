<?php
defined( 'ABSPATH' ) || exit;
final class Ecomkit_Vuikhoe_Lazada_Provider_Exception extends RuntimeException {
	public function __construct( string $classification, public readonly array $diagnostic = array() ) { parent::__construct( $classification ); }
}
/** Token endpoints only. Sensitive parameters travel in form POST body, never URL. */
final class Ecomkit_Vuikhoe_Lazada_HTTP_Client {
	public function __construct( private mixed $transport = null ) { $this->transport ??= 'wp_remote_post'; }
	public function exchange( array $credentials, string $code ): array { return $this->request( Ecomkit_Vuikhoe_Lazada_Config::TOKEN_CREATE, $credentials, array( 'code' => $code ) ); }
	public function refresh( array $credentials, string $token ): array { return $this->request( Ecomkit_Vuikhoe_Lazada_Config::TOKEN_REFRESH, $credentials, array( 'refresh_token' => $token ) ); }
	private function request( string $path, array $credentials, array $business ): array {
		$params = array_merge( array( 'app_key' => $credentials['app_key'], 'timestamp' => (string) ( time() * 1000 ), 'sign_method' => 'sha256' ), $business );
		$params['sign'] = Ecomkit_Vuikhoe_Lazada_Signer::sign( $path, $params, $credentials['app_secret'] );
		$diagnostic = array( 'stage' => 'TOKEN_HTTP_REQUEST', 'api_path' => $path, 'http_status' => null, 'provider_error' => '', 'safe_provider_message' => '', 'request_id' => '' );
		try { $response = ( $this->transport )( Ecomkit_Vuikhoe_Lazada_Config::TOKEN_BASE . $path, array( 'timeout' => 20, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 262144, 'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ), 'body' => http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ) ) ); }
		catch ( Throwable ) { throw new Ecomkit_Vuikhoe_Lazada_Provider_Exception( 'LAZADA_NETWORK_ERROR', $diagnostic ); }
		if ( is_wp_error( $response ) ) { throw new Ecomkit_Vuikhoe_Lazada_Provider_Exception( 'LAZADA_NETWORK_ERROR', $diagnostic ); }
		$diagnostic['http_status'] = (int) wp_remote_retrieve_response_code( $response );
		$diagnostic['stage'] = 'TOKEN_PROVIDER_RESPONSE';
		try { $body = wp_remote_retrieve_body( $response ); if ( ! is_string( $body ) || strlen( $body ) >= 262144 ) { throw new RuntimeException(); } $data = json_decode( $body, true, 20, JSON_THROW_ON_ERROR ); if ( ! is_array( $data ) ) { throw new RuntimeException(); } }
		catch ( Throwable ) { throw new Ecomkit_Vuikhoe_Lazada_Provider_Exception( 'LAZADA_INVALID_TOKEN_RESPONSE', $diagnostic ); }
		$secrets = array_merge( array_values( $business ), array( $credentials['app_secret'], $data['access_token'] ?? '', $data['refresh_token'] ?? '', $params['sign'] ) );
		$diagnostic['request_id'] = self::safe_text( $data['request_id'] ?? '', $secrets );
		$provider_code = is_string( $data['code'] ?? null ) || is_int( $data['code'] ?? null ) ? (string) $data['code'] : '';
		$diagnostic['provider_error'] = self::safe_text( $provider_code, $secrets );
		$diagnostic['safe_provider_message'] = self::safe_text( $data['message'] ?? '', $secrets );
		if ( '' !== $provider_code && '0' !== $provider_code ) {
			$classification = 'IllegalRefreshToken' === $provider_code && Ecomkit_Vuikhoe_Lazada_Config::TOKEN_REFRESH === $path ? 'LAZADA_REFRESH_EXPIRED' : ( Ecomkit_Vuikhoe_Lazada_Config::TOKEN_REFRESH === $path ? 'LAZADA_REFRESH_FAILED' : 'LAZADA_TOKEN_EXCHANGE_FAILED' );
			throw new Ecomkit_Vuikhoe_Lazada_Provider_Exception( $classification, $diagnostic );
		}
		if ( $diagnostic['http_status'] < 200 || $diagnostic['http_status'] >= 300 ) { $diagnostic['stage'] = 'TOKEN_HTTP_STATUS'; throw new Ecomkit_Vuikhoe_Lazada_Provider_Exception( 'LAZADA_HTTP_ERROR', $diagnostic ); }
		if ( '0' !== $provider_code ) { throw new Ecomkit_Vuikhoe_Lazada_Provider_Exception( 'LAZADA_INVALID_TOKEN_RESPONSE', $diagnostic ); }
		return array( 'data' => $data, 'diagnostic' => $diagnostic );
	}
	public static function safe_text( mixed $value, array $secrets = array() ): string {
		if ( ! is_string( $value ) && ! is_int( $value ) ) { return ''; }
		$text = (string) $value;
		foreach ( $secrets as $secret ) { if ( is_string( $secret ) && '' !== $secret ) { $text = str_replace( array( $secret, rawurlencode( $secret ), urlencode( $secret ) ), '[redacted]', $text ); } }
		$text = preg_replace( '~https?://\S+|(?:access_token|refresh_token|app_secret|code|sign)\s*[=:]\s*\S+|[A-Za-z0-9_-]{32,}~i', '[redacted]', $text );
		return mb_substr( trim( strip_tags( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $text ) ) ), 0, 240 );
	}
}
