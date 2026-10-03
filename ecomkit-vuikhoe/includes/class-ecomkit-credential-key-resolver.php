<?php
/** Resolves credential master keys without database persistence. */
defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Credential_Key_Resolver {
	public const EXPLICIT = 'explicit_v1';
	public const WP_SALTS = 'wp_salts_v1';
	private const INFO = 'ecomkit-vuikhoe|credential-master|v1';
	private const WP_SECRET_NAMES = array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' );
	/** @param array<string,string>|null $secrets_override */
	public function __construct( private ?string $explicit_override = null, private ?array $secrets_override = null, private bool $ignore_explicit_constant = false ) {}
	public function resolve( ?string $required_source = null ): string {
		$source = $required_source ?? $this->source();
		if ( self::EXPLICIT === $source ) { return $this->explicit_key(); }
		if ( self::WP_SALTS === $source ) { return $this->wordpress_key(); }
		throw new RuntimeException( 'ECOMKIT_CREDENTIAL_KEY_SOURCE_UNSUPPORTED' );
	}
	public function source(): string {
		if ( null !== $this->explicit_override ) { $this->explicit_key(); return self::EXPLICIT; }
		if ( ! $this->ignore_explicit_constant && defined( 'ECOMKIT_CREDENTIAL_KEY' ) ) { $this->explicit_key(); return self::EXPLICIT; }
		$this->wordpress_key(); return self::WP_SALTS;
	}
	public function ready(): bool { try { $this->resolve(); return true; } catch ( Throwable $exception ) { return false; } }
	public function fingerprint(): string { return substr( hash( 'sha256', $this->resolve() ), 0, 16 ); }
	public function label(): string { return self::EXPLICIT === $this->source() ? 'Explicit ECOMKIT_CREDENTIAL_KEY' : 'WordPress Security Keys'; }
	private function explicit_key(): string {
		$encoded = $this->explicit_override ?? ( defined( 'ECOMKIT_CREDENTIAL_KEY' ) && is_string( ECOMKIT_CREDENTIAL_KEY ) ? ECOMKIT_CREDENTIAL_KEY : '' );
		$key = base64_decode( $encoded, true );
		if ( false === $key || 32 !== strlen( $key ) ) { throw new RuntimeException( 'ECOMKIT_CREDENTIAL_KEY_INVALID' ); }
		return $key;
	}
	private function wordpress_key(): string {
		$material = '';
		foreach ( self::WP_SECRET_NAMES as $name ) {
			$value = $this->secrets_override[ $name ] ?? ( defined( $name ) && is_string( constant( $name ) ) ? constant( $name ) : '' );
			if ( ! $this->valid_wordpress_secret( $value ) ) { throw new RuntimeException( 'ECOMKIT_WORDPRESS_SECURITY_KEYS_INVALID' ); }
			$material .= $name . "\0" . $value . "\0";
		}
		$key = hash_hkdf( 'sha256', $material, 32, self::INFO, '' );
		if ( 32 !== strlen( $key ) ) { throw new RuntimeException( 'ECOMKIT_CREDENTIAL_KEY_DERIVATION_FAILED' ); }
		return $key;
	}
	private function valid_wordpress_secret( string $value ): bool { $value = trim( $value ); return strlen( $value ) >= 32 && false === stripos( $value, 'put your unique phrase here' ); }
}
