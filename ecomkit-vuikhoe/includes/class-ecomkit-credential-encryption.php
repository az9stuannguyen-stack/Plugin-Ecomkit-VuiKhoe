<?php
/** Authenticated encryption for Ecomkit credentials. */
defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Credential_Encryption {
	private const CIPHER = 'aes-256-gcm';
	private const VERSION = 1;
	private Ecomkit_Vuikhoe_Credential_Key_Resolver $resolver;
	public function __construct( ?string $test_key = null, ?Ecomkit_Vuikhoe_Credential_Key_Resolver $resolver = null ) { $this->resolver = $resolver ?? new Ecomkit_Vuikhoe_Credential_Key_Resolver( $test_key ); }

	public function ready(): bool {
		return $this->resolver->ready();
	}
	public function key_source(): string { return $this->resolver->source(); }
	public function key_source_label(): string { return $this->resolver->label(); }

	/** @param array<string,mixed> $plaintext */
	public function encrypt( array $plaintext, string $aad ): string {
		$iv = random_bytes( 12 );
		$tag = '';
		$json = wp_json_encode( $plaintext, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) { throw new RuntimeException( 'ECOMKIT_CREDENTIAL_ENCRYPT_FAILED' ); }
		$source = $this->resolver->source();
		$ciphertext = openssl_encrypt( $json, self::CIPHER, $this->resolver->resolve( $source ), OPENSSL_RAW_DATA, $iv, $tag, $aad, 16 );
		if ( false === $ciphertext || 16 !== strlen( $tag ) ) { throw new RuntimeException( 'ECOMKIT_CREDENTIAL_ENCRYPT_FAILED' ); }
		$envelope = wp_json_encode( array( 'v' => self::VERSION, 'alg' => 'AES-256-GCM', 'key_source' => $source, 'iv' => base64_encode( $iv ), 'tag' => base64_encode( $tag ), 'ciphertext' => base64_encode( $ciphertext ) ) );
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
			$source = isset( $data['key_source'] ) ? (string) $data['key_source'] : null;
			$json = openssl_decrypt( $ciphertext, self::CIPHER, $this->resolver->resolve( $source ), OPENSSL_RAW_DATA, $iv, $tag, $aad );
			if ( false === $json ) { throw new RuntimeException(); }
			$plaintext = json_decode( $json, true, 16, JSON_THROW_ON_ERROR );
			if ( ! is_array( $plaintext ) ) { throw new RuntimeException(); }
			return $plaintext;
		} catch ( Throwable $exception ) {
			throw new RuntimeException( 'ECOMKIT_CREDENTIAL_DECRYPT_FAILED', 0, $exception );
		}
	}

	public function envelope_key_source( string $envelope ): ?string { $data = json_decode( $envelope, true ); return is_array( $data ) && isset( $data['key_source'] ) && is_string( $data['key_source'] ) ? $data['key_source'] : null; }
}
