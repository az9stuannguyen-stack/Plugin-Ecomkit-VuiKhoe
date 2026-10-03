<?php
/** WP.3B deterministic WordPress-salt key derivation checks. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' );
function wp_json_encode( mixed $v, int $f = 0 ): string|false { return json_encode( $v, $f ); }
function get_option( string $k, mixed $d = false ): mixed { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( string $k, mixed $v, bool $a = false ): bool { $GLOBALS['opts'][ $k ] = $v; return true; }
function current_time( string $t, bool $g = false ): string { return '2026-10-03 00:00:00'; }
function rest_url( string $p ): string { return 'https://example.test/wp-json/' . $p; }
function wp_http_validate_url( string $u ): string|false { return filter_var( $u, FILTER_VALIDATE_URL ); }
function wp_parse_url( string $u, int $c = -1 ): mixed { return parse_url( $u, $c ); }
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-credential-key-resolver.php';
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-credential-encryption.php';
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-shopee-environment.php';
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-shopee-signer.php';
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-shopee-config.php';
function resolver_check( bool $ok, string $message ): void { if ( ! $ok ) throw new RuntimeException( $message ); }
function salt_set( string $suffix ): array {
	$values = array();
	foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $name ) { $values[ $name ] = 'synthetic-wordpress-secret-' . $suffix . '-' . $name . '-0123456789abcdef'; }
	return $values;
}
$a1 = new Ecomkit_Vuikhoe_Credential_Key_Resolver( null, salt_set( 'A' ), true );
$a2 = new Ecomkit_Vuikhoe_Credential_Key_Resolver( null, salt_set( 'A' ), true );
$b = new Ecomkit_Vuikhoe_Credential_Key_Resolver( null, salt_set( 'B' ), true );
resolver_check( 'wp_salts_v1' === $a1->source() && 32 === strlen( $a1->resolve() ), 'WordPress-derived mode is not ready.' );
resolver_check( hash_equals( $a1->resolve(), $a2->resolve() ), 'WordPress KDF is not deterministic.' );
resolver_check( ! hash_equals( $a1->resolve(), $b->resolve() ), 'Different WordPress salts produced the same key.' );
$crypto_a = new Ecomkit_Vuikhoe_Credential_Encryption( null, $a1 );
$crypto_b = new Ecomkit_Vuikhoe_Credential_Encryption( null, $b );
$envelope = $crypto_a->encrypt( array( 'partner_key' => 'obviously-fake-derived-secret' ), 'test-aad' );
resolver_check( 'wp_salts_v1' === json_decode( $envelope, true )['key_source'], 'Derived envelope source missing.' );
resolver_check( 'obviously-fake-derived-secret' === $crypto_a->decrypt( $envelope, 'test-aad' )['partner_key'], 'Derived-key AES round trip failed.' );
try { $crypto_b->decrypt( $envelope, 'test-aad' ); throw new RuntimeException( 'Rotated salts decrypted old credential.' ); } catch ( RuntimeException $e ) { resolver_check( 'ECOMKIT_CREDENTIAL_DECRYPT_FAILED' === $e->getMessage(), 'Salt rotation did not fail safely.' ); }
$GLOBALS['opts'] = array();
$config_a = new Ecomkit_Vuikhoe_Shopee_Config( $crypto_a ); $config_a->save( 'sandbox', '123', 'obviously-fake-derived-partner-key' );
$stored_before_rotation = $GLOBALS['opts'];
$rotation_ui = ( new Ecomkit_Vuikhoe_Shopee_Config( $crypto_b ) )->partner_key_ui_state();
resolver_check( 'rotation_required' === $rotation_ui['state'] && '' === $rotation_ui['mask'] && $stored_before_rotation === $GLOBALS['opts'], 'Salt rotation UI/state handling is unsafe.' );
$placeholder = salt_set( 'A' ); $placeholder['AUTH_KEY'] = 'put your unique phrase here';
$invalid = new Ecomkit_Vuikhoe_Credential_Key_Resolver( null, $placeholder, true );
resolver_check( ! $invalid->ready(), 'Placeholder WordPress key was accepted.' );
$explicit = new Ecomkit_Vuikhoe_Credential_Key_Resolver( base64_encode( str_repeat( 'E', 32 ) ), salt_set( 'A' ), true );
resolver_check( 'explicit_v1' === $explicit->source() && str_repeat( 'E', 32 ) === $explicit->resolve(), 'Explicit key did not retain priority.' );
echo "WP.3B credential key resolver checks passed.\n";
