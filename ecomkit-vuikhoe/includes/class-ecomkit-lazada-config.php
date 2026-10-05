<?php
/** Lazada application foundation. No HTTP/OAuth/token operations. */
defined( 'ABSPATH' ) || exit;
final class Ecomkit_Vuikhoe_Lazada_Config implements Ecomkit_Vuikhoe_Provider_Configuration {
	public const OPTION = 'ecomkit_vuikhoe_lazada_config';
	public const COUNTRY = 'vn';
	private const AAD = 'ecomkit|lazada|provider-config|v1';
	public function __construct( private ?Ecomkit_Vuikhoe_Credential_Encryption $encryption = null ) { $this->encryption ??= new Ecomkit_Vuikhoe_Credential_Encryption(); }
	public function platform(): string { return Ecomkit_Vuikhoe_Marketplace_Platform::LAZADA; }
	/** Routes deliberately unset until the OAuth/API contracts are implemented. */
	public static function endpoint_contract(): array { return array( 'country' => self::COUNTRY, 'api_base' => null, 'authorization_url' => null, 'token_route' => null ); }
	/** Future token payload contract, not token data; official expiry units require OAuth-stage validation. */
	public static function credential_contract(): array {
		return array( 'version' => 1, 'application_fields' => array( 'app_key', 'app_secret', 'country' ), 'future_authorization_fields' => array( 'access_token', 'refresh_token', 'expires_in', 'refresh_expires_in', 'account_id', 'country_user_info_list' ) );
	}
	public function credentials(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) || 1 !== ( $stored['v'] ?? null ) ) { throw new RuntimeException( 'LAZADA_CONFIG_UNAVAILABLE' ); }
		$plain = $this->encryption->decrypt( (string) ( $stored['credential_envelope'] ?? '' ), self::AAD );
		if ( 1 !== ( $plain['v'] ?? null ) || self::COUNTRY !== ( $plain['country'] ?? null ) || ! is_string( $plain['app_key'] ?? null ) || ! is_string( $plain['app_secret'] ?? null ) || '' === $plain['app_secret'] ) { throw new RuntimeException( 'LAZADA_CONFIG_INVALID' ); }
		return $plain;
	}
	public function safe_state(): array {
		try { $plain = $this->credentials(); return array( 'platform' => $this->platform(), 'country' => self::COUNTRY, 'app_key' => $plain['app_key'], 'secret_status' => 'Đã lưu an toàn', 'configured' => true, 'connected' => false, 'status' => 'Sẵn sàng cấu hình cho bước ủy quyền — chưa kết nối' ); }
		catch ( Throwable ) { return array( 'platform' => $this->platform(), 'country' => self::COUNTRY, 'app_key' => '', 'secret_status' => 'Chưa cấu hình hoặc không thể đọc cấu hình an toàn', 'configured' => false, 'connected' => false, 'status' => 'Chưa cấu hình' ); }
	}
	public function save( string $app_key, string $app_secret ): void {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{1,128}$/D', $app_key ) || strlen( $app_secret ) > 4096 || preg_match( '/[\x00-\x1F\x7F]/', $app_secret ) ) { throw new InvalidArgumentException( 'LAZADA_CONFIG_INVALID' ); }
		if ( '' === $app_secret ) {
			$current = $this->credentials();
			if ( $app_key !== $current['app_key'] ) { throw new InvalidArgumentException( 'LAZADA_SECRET_REPLACEMENT_REQUIRED' ); }
			return; // Blank edit preserves the existing envelope byte-for-byte.
		}
		$plain = array( 'v' => 1, 'country' => self::COUNTRY, 'app_key' => $app_key, 'app_secret' => $app_secret );
		$stored = array( 'v' => 1, 'credential_envelope' => $this->encryption->encrypt( $plain, self::AAD ), 'updated_at' => current_time( 'mysql', true ) );
		if ( ! update_option( self::OPTION, $stored, false ) ) { throw new RuntimeException( 'LAZADA_CONFIG_PERSIST_FAILED' ); }
	}
}
