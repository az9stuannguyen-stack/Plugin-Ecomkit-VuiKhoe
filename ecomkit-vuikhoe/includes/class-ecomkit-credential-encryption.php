<?php
/** Authenticated encryption for Ecomkit credentials. */
defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Credential_Encryption {
	private const CIPHER = 'aes-256-gcm';
	private const VERSION = 1;
	public function __construct( private ?string $test_key = null ) {}

	public function ready(): bool {
		try { $this->key(); return true; } catch ( Throwable $exception ) { return false; }
	}

	/** @param array<string,mixed> $plaintext */
	public function encrypt( array $plaintext, string $aad ): string {
		$iv = random_bytes( 12 );
		$tag = '';
		$json = wp_json_encode( $plaintext, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) { throw new RuntimeException( 'ECOMKIT_CREDENTIAL_ENCRYPT_FAILED' ); }
		$ciphertext = openssl_encrypt( $json, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag, $aad, 16 );
		if ( false === $ciphertext || 16 !== strlen( $tag ) ) { throw new RuntimeException( 'ECOMKIT_CREDENTIAL_ENCRYPT_FAILED' ); }
		$envelope = wp_json_encode( array( 'v' => self::VERSION, 'alg' => 'AES-256-GCM', 'iv' => base64_encode( $iv ), 'tag' => base64_encode( $tag ), 'ciphertext' => base64_encode( $ciphertext ) ) );
		if ( ! is_string( $envelope ) ) { throw new RuntimeException( 'ECOMKIT_CREDENTIAL_ENCRYPT_FAILED' ); }
		return $envelope;
	}

	/** @return array<string,mixed> */
	public function decrypt( string $envelope, string $aad ): array {
		try {
			$data = json_decode( $envelope, true, 8, JSON_THROW_ON_ERROR );
			if ( ! is_array( $data ) || self::VERSION !== ( $data['v'] ?? null ) || 'AES-256-GCM' !== ( $data['alg'] ?? null ) ) { throw new RuntimeException(); }
			$iv = base64_decode( (string) ( $data['iv'] ?? '' ), true );
			$tag = base64_decode( (string) ( $data['tag'] ?? '' ), true );
			$ciphertext = base64_decode( (string) ( $data['ciphertext'] ?? '' ), true );
			if ( false === $iv || 12 !== strlen( $iv ) || false === $tag || 16 !== strlen( $tag ) || false === $ciphertext ) { throw new RuntimeException(); }
			$json = openssl_decrypt( $ciphertext, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag, $aad );
			if ( false === $json ) { throw new RuntimeException(); }
			$plaintext = json_decode( $json, true, 16, JSON_THROW_ON_ERROR );
			if ( ! is_array( $plaintext ) ) { throw new RuntimeException(); }
			return $plaintext;
		} catch ( Throwable $exception ) {
			throw new RuntimeException( 'ECOMKIT_CREDENTIAL_DECRYPT_FAILED', 0, $exception );
		}
	}

	private function key(): string {
		$encoded = $this->test_key ?? ( defined( 'ECOMKIT_CREDENTIAL_KEY' ) && is_string( ECOMKIT_CREDENTIAL_KEY ) ? ECOMKIT_CREDENTIAL_KEY : '' );
		$key = base64_decode( $encoded, true );
		if ( false === $key || 32 !== strlen( $key ) ) { throw new RuntimeException( 'ECOMKIT_CREDENTIAL_KEY_INVALID' ); }
		return $key;
	}
}
