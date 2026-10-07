<?php
/** TikTok Shop application foundation. No HTTP/OAuth/token operations. */
defined( 'ABSPATH' ) || exit;
final class Ecomkit_Vuikhoe_Tiktok_Config implements Ecomkit_Vuikhoe_Provider_Configuration {
	public const OPTION = 'ecomkit_vuikhoe_tiktok_config';
	public const COUNTRY = 'vn';
	public const API_BASE = 'https://open-api.tiktokglobalshop.com';
	private const AAD = 'ecomkit|tiktok|provider-config|v1';
	public function __construct( private ?Ecomkit_Vuikhoe_Credential_Encryption $encryption = null ) { $this->encryption ??= new Ecomkit_Vuikhoe_Credential_Encryption(); }
	public function platform(): string { return Ecomkit_Vuikhoe_Marketplace_Platform::TIKTOK; }
	/** Reserved callback URL; the foundation handler only denies premature use. */
	public function callback_url(): string {
		$url = add_query_arg( 'action', 'ecomkit_tiktok_oauth_callback', admin_url( 'admin-post.php' ) );
		if ( 'https' !== parse_url( $url, PHP_URL_SCHEME ) ) { throw new RuntimeException( 'TIKTOK_CALLBACK_HTTPS_REQUIRED' ); }
		return $url;
	}
	public function fingerprint(): string { $stored = get_option( self::OPTION, array() ); return hash( 'sha256', (string) ( is_array( $stored ) ? ( $stored['credential_envelope'] ?? '' ) : '' ) ); }
	public function credentials(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) || 1 !== ( $stored['v'] ?? null ) ) { throw new RuntimeException( 'TIKTOK_CONFIG_UNAVAILABLE' ); }
		$plain = $this->encryption->decrypt( (string) ( $stored['credential_envelope'] ?? '' ), self::AAD );
		if ( 1 !== ( $plain['v'] ?? null ) || self::COUNTRY !== ( $plain['country'] ?? null ) || ! is_string( $plain['app_key'] ?? null ) || ! is_string( $plain['app_secret'] ?? null ) || ! is_string( $plain['service_id'] ?? null ) || '' === $plain['app_secret'] ) { throw new RuntimeException( 'TIKTOK_CONFIG_INVALID' ); }
		return $plain;
	}
	public function safe_state(): array {
		try { $plain = $this->credentials(); return array( 'platform' => $this->platform(), 'country' => self::COUNTRY, 'app_key' => $plain['app_key'], 'service_id' => $plain['service_id'], 'secret_status' => 'Đã lưu an toàn', 'configured' => true, 'connected' => false, 'status' => 'Sẵn sàng cấu hình cho bước ủy quyền — chưa kết nối' ); }
		catch ( Throwable ) { return array( 'platform' => $this->platform(), 'country' => self::COUNTRY, 'app_key' => '', 'service_id' => '', 'secret_status' => 'Chưa cấu hình hoặc không thể đọc cấu hình an toàn', 'configured' => false, 'connected' => false, 'status' => 'Chưa cấu hình' ); }
	}
	public function save( string $app_key, string $app_secret, string $service_id ): void {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{1,128}$/D', $service_id ) || 1 !== preg_match( '/^[A-Za-z0-9_-]{1,128}$/D', $app_key ) || strlen( $app_secret ) > 4096 || preg_match( '/[\x00-\x1F\x7F]/', $app_secret ) ) { throw new InvalidArgumentException( 'TIKTOK_CONFIG_INVALID' ); }
		if ( '' === $app_secret ) {
			$current = $this->credentials();
			if ( $app_key !== $current['app_key'] || $service_id !== $current['service_id'] ) { throw new InvalidArgumentException( 'TIKTOK_SECRET_REPLACEMENT_REQUIRED' ); }
			return; // Blank edit preserves the existing envelope byte-for-byte.
		}
		$plain = array( 'v' => 1, 'country' => self::COUNTRY, 'app_key' => $app_key, 'app_secret' => $app_secret, 'service_id' => $service_id );
		$stored = array( 'v' => 1, 'credential_envelope' => $this->encryption->encrypt( $plain, self::AAD ), 'updated_at' => current_time( 'mysql', true ) );
		if ( ! update_option( self::OPTION, $stored, false ) ) { throw new RuntimeException( 'TIKTOK_CONFIG_PERSIST_FAILED' ); }
	}
}
