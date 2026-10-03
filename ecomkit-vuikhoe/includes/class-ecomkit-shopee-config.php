<?php
/** Encrypted app-level Shopee provider configuration. */
defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Shopee_Config {
	public const OPTION = 'ecomkit_vuikhoe_shopee_config';
	private const AAD = 'ecomkit|shopee|provider-config';
	public function __construct( private ?Ecomkit_Vuikhoe_Credential_Encryption $encryption = null ) { $this->encryption ??= new Ecomkit_Vuikhoe_Credential_Encryption(); }

	/** @return array<string,mixed> */
	public function get(): array {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? $value : array();
	}
	public static function valid_partner_id( string $value ): bool { return 1 === preg_match( '/^[1-9][0-9]*$/', $value ) && false !== filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ); }
	public function callback_url(): string { return rest_url( 'ecomkit/v1/shopee/callback' ); }
	public function fingerprint( array $config ): string { return hash( 'sha256', (string) ( $config['environment'] ?? '' ) . '|' . (string) ( $config['partner_id'] ?? '' ) . '|' . (string) ( $config['revision'] ?? 1 ) ); }

	/** @return array<string,mixed> */
	public function save( string $environment, string $partner_id, string $partner_key ): array {
		Ecomkit_Vuikhoe_Shopee_Environment::host( $environment );
		if ( ! self::valid_partner_id( $partner_id ) ) { throw new InvalidArgumentException( 'SHOPEE_PARTNER_ID_INVALID' ); }
		$current = $this->get();
		$encrypted = (string) ( $current['encrypted_partner_key'] ?? '' );
		$changed = $environment !== ( $current['environment'] ?? null ) || $partner_id !== ( $current['partner_id'] ?? null ) || '' !== $partner_key;
		if ( '' !== $partner_key ) { $encrypted = $this->encryption->encrypt( array( 'partner_key' => $partner_key ), self::AAD ); }
		if ( '' === $encrypted ) { throw new InvalidArgumentException( 'SHOPEE_PARTNER_KEY_REQUIRED' ); }
		$config = array( 'v' => 1, 'revision' => $changed ? (int) ( $current['revision'] ?? 0 ) + 1 : (int) ( $current['revision'] ?? 1 ), 'environment' => $environment, 'partner_id' => $partner_id, 'encrypted_partner_key' => $encrypted, 'updated_at' => current_time( 'mysql', true ) );
		$config['fingerprint'] = $this->fingerprint( $config );
		update_option( self::OPTION, $config, false );
		return $config;
	}

	public function partner_key( ?array $config = null ): string {
		$config ??= $this->get();
		$plain = $this->encryption->decrypt( (string) ( $config['encrypted_partner_key'] ?? '' ), self::AAD );
		$key = $plain['partner_key'] ?? null;
		if ( ! is_string( $key ) || '' === $key ) { throw new RuntimeException( 'ECOMKIT_CREDENTIAL_DECRYPT_FAILED' ); }
		return $key;
	}

	/** @return array{ready:bool,code:string} */
	public function readiness(): array {
		try {
			$config = $this->get();
			Ecomkit_Vuikhoe_Shopee_Environment::host( (string) ( $config['environment'] ?? '' ) );
			if ( ! self::valid_partner_id( (string) ( $config['partner_id'] ?? '' ) ) ) { throw new RuntimeException(); }
			$this->partner_key( $config );
			$url = $this->callback_url();
			if ( ! wp_http_validate_url( $url ) ) { throw new RuntimeException(); }
			if ( 'production' === $config['environment'] && 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) { return array( 'ready' => false, 'code' => 'SHOPEE_CALLBACK_NOT_HTTPS' ); }
			Ecomkit_Vuikhoe_Shopee_Signer::sign( (string) $config['partner_id'], '/api/v2/shop/auth_partner', 1, $this->partner_key( $config ) );
			return array( 'ready' => true, 'code' => 'SHOPEE_CONFIG_READY' );
		} catch ( Throwable $exception ) { return array( 'ready' => false, 'code' => 'SHOPEE_CONFIG_INCOMPLETE' ); }
	}
}
