<?php
/** WP.3 synthetic-only Shopee security/OAuth checks. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ECOMKIT_CREDENTIAL_KEY', base64_encode( str_repeat( 'K', 32 ) ) );
function wp_json_encode( mixed $v, int $f = 0 ): string|false { return json_encode( $v, $f ); }
function get_option( string $k, mixed $d = false ): mixed { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( string $k, mixed $v, bool $a = false ): bool { $GLOBALS['opts'][ $k ] = $v; return true; }
function current_time( string $t, bool $g = false ): string { return '2026-10-03 00:00:00'; }
function rest_url( string $p ): string { return 'https://example.test/wp-json/' . $p; }
function wp_http_validate_url( string $u ): string|false { return filter_var( $u, FILTER_VALIDATE_URL ); }
function wp_parse_url( string $u, int $c = -1 ): mixed { return parse_url( $u, $c ); }
function add_query_arg( array $a, string $u ): string { return $u . ( str_contains( $u, '?' ) ? '&' : '?' ) . http_build_query( $a ); }
function sanitize_text_field( string $v ): string { return trim( preg_replace( '/[\x00-\x1F\x7F]/', '', $v ) ); }
function sanitize_key( string $v ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $v ) ); }
function is_wp_error( mixed $v ): bool { return false; }
function wp_remote_post( string $url, array $args ): array { $GLOBALS['http_calls'][] = compact( 'url', 'args' ); return $GLOBALS['http_response']; }
function wp_remote_retrieve_response_code( array $r ): int { return $r['status']; }
function wp_remote_retrieve_body( array $r ): string { return $r['body']; }
function get_current_user_id(): int { return 7; }
function get_transient( string $k ): mixed { return $GLOBALS['transients'][ $k ]['value'] ?? false; }
function set_transient( string $k, mixed $v, int $ttl ): bool { $GLOBALS['transients'][ $k ] = array( 'value' => $v, 'ttl' => $ttl ); return true; }
function delete_transient( string $k ): bool { unset( $GLOBALS['transients'][ $k ] ); return true; }
function wp_unslash( mixed $v ): mixed { return $v; }
function admin_url( string $p ): string { return 'https://example.test/wp-admin/' . $p; }
function is_ssl(): bool { return true; }
class WP_REST_Request { public function __construct( private array $p = array() ) {} public function get_param( string $k ): mixed { return $this->p[ $k ] ?? null; } }
class WP_REST_Response { public function __construct( public mixed $data = null, public int $status = 200, public array $headers = array() ) {} }
function wp3_check( bool $ok, string $message ): void { if ( ! $ok ) { throw new RuntimeException( $message ); } }

require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-credential-encryption.php';
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-shopee-environment.php';
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-shopee-signer.php';
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-shopee-config.php';
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-shopee-http-client.php';

$crypto = new Ecomkit_Vuikhoe_Credential_Encryption();
$one = $crypto->encrypt( array( 'secret' => 'obviously-fake-secret' ), 'test-aad' );
$two = $crypto->encrypt( array( 'secret' => 'obviously-fake-secret' ), 'test-aad' );
wp3_check( $one !== $two, 'Random IV did not vary ciphertext.' );
wp3_check( 'obviously-fake-secret' === $crypto->decrypt( $one, 'test-aad' )['secret'], 'Encryption round trip failed.' );
foreach ( array( 'ciphertext', 'tag' ) as $field ) {
	$bad = json_decode( $one, true ); $bad[ $field ] = base64_encode( str_repeat( 'X', strlen( base64_decode( $bad[ $field] ) ) ) );
	try { $crypto->decrypt( json_encode( $bad ), 'test-aad' ); throw new RuntimeException( 'Tamper accepted.' ); } catch ( RuntimeException $e ) { wp3_check( 'ECOMKIT_CREDENTIAL_DECRYPT_FAILED' === $e->getMessage(), 'Tamper classification wrong.' ); }
}
try { ( new Ecomkit_Vuikhoe_Credential_Encryption( base64_encode( str_repeat( 'W', 32 ) ) ) )->decrypt( $one, 'test-aad' ); throw new RuntimeException( 'Wrong key accepted.' ); } catch ( RuntimeException $e ) { wp3_check( 'ECOMKIT_CREDENTIAL_DECRYPT_FAILED' === $e->getMessage(), 'Wrong-key classification wrong.' ); }
foreach ( array( '{}', '{bad', json_encode( array( 'v' => 2 ) ) ) as $bad ) { try { $crypto->decrypt( $bad, 'test-aad' ); throw new RuntimeException( 'Malformed envelope accepted.' ); } catch ( RuntimeException $e ) { wp3_check( 'ECOMKIT_CREDENTIAL_DECRYPT_FAILED' === $e->getMessage(), 'Malformed envelope classification wrong.' ); } }

wp3_check( '5943410340670f58082e8df34264fbb195ad0fdf7745a91f92f021531817ab5e' === Ecomkit_Vuikhoe_Shopee_Signer::sign( '123', '/api/v2/shop/auth_partner', 1700000000, 'obviously-fake-partner-key' ), 'Authorization signature vector failed.' );
wp3_check( '58f591ad3835a6c4aaf697d0446facd2ec73d6f186a166c8803012a406358b6d' === Ecomkit_Vuikhoe_Shopee_Signer::sign( '123', '/api/v2/auth/token/get', 1700000000, 'obviously-fake-partner-key' ), 'Token signature vector failed.' );
wp3_check( str_contains( Ecomkit_Vuikhoe_Shopee_Environment::host( 'sandbox' ), 'test-stable' ) && 'https://partner.shopeemobile.com' === Ecomkit_Vuikhoe_Shopee_Environment::host( 'production' ), 'Environment resolver failed.' );

$GLOBALS['opts'] = array();
$config_service = new Ecomkit_Vuikhoe_Shopee_Config( $crypto );
$saved = $config_service->save( 'sandbox', '123', 'obviously-fake-partner-key' );
wp3_check( ! str_contains( json_encode( $GLOBALS['opts'] ), 'obviously-fake-partner-key' ), 'Partner Key stored plaintext.' );
$envelope = $saved['encrypted_partner_key'];
$saved2 = $config_service->save( 'sandbox', '123', '' );
wp3_check( $envelope === $saved2['encrypted_partner_key'], 'Blank Partner Key edit did not preserve secret.' );
$saved3 = $config_service->save( 'sandbox', '123', 'obviously-fake-replacement-key' );
wp3_check( $envelope !== $saved3['encrypted_partner_key'], 'New Partner Key did not replace envelope.' );
foreach ( array( '', '-1', '1.2', 'abc', '01' ) as $id ) { try { $config_service->save( 'sandbox', $id, 'x' ); throw new RuntimeException( 'Invalid Partner ID accepted.' ); } catch ( InvalidArgumentException $e ) {} }

$GLOBALS['http_calls'] = array();
$GLOBALS['http_response'] = array( 'status' => 200, 'body' => json_encode( array( 'error' => '', 'access_token' => 'obviously-fake-access', 'refresh_token' => 'obviously-fake-refresh', 'expire_in' => 14400, 'shop_id_list' => array( 456 ) ) ) );
$tokens = ( new Ecomkit_Vuikhoe_Shopee_HTTP_Client() )->exchange( $saved3, 'obviously-fake-replacement-key', 'obviously-fake-code', '456' );
wp3_check( 1 === count( $GLOBALS['http_calls'] ) && 15 === $GLOBALS['http_calls'][0]['args']['timeout'] && true === $GLOBALS['http_calls'][0]['args']['sslverify'], 'Token transport contract failed.' );
wp3_check( ! str_contains( $GLOBALS['http_calls'][0]['args']['body'], 'replacement-key' ), 'Partner Key leaked into request body.' );
wp3_check( 14400 === $tokens['expire_in'], 'Provider expire_in was not retained.' );
$GLOBALS['http_response']['body'] = json_encode( array( 'error' => 'invalid_code', 'request_id' => 'safe-request-1' ) );
try { ( new Ecomkit_Vuikhoe_Shopee_HTTP_Client() )->exchange( $saved3, 'obviously-fake-replacement-key', 'bad', '456' ); throw new RuntimeException( 'Provider error accepted.' ); } catch ( Ecomkit_Vuikhoe_Shopee_Provider_Exception $e ) { wp3_check( 'SHOPEE_AUTH_CODE_INVALID' === $e->getMessage() && 'safe-request-1' === $e->request_id, 'Provider error mapping failed.' ); }
$GLOBALS['http_response']['body'] = json_encode( array( 'error' => '', 'access_token' => 'a', 'refresh_token' => 'r', 'expire_in' => 1, 'shop_id_list' => array( 999 ) ) );
try { ( new Ecomkit_Vuikhoe_Shopee_HTTP_Client() )->exchange( $saved3, 'obviously-fake-replacement-key', 'code', '456' ); throw new RuntimeException( 'Shop mismatch accepted.' ); } catch ( RuntimeException $e ) { wp3_check( 'SHOPEE_OAUTH_SHOP_MISMATCH' === $e->getMessage(), 'Shop mismatch classification failed.' ); }

echo "WP.3 Shopee encryption, config, signing and token checks passed.\n";
