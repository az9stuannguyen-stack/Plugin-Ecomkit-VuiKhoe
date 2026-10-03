<?php
/** WP.3A missing master-key fail-closed check (intentionally no key constant). */
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
$GLOBALS['opts'] = array();
try { ( new Ecomkit_Vuikhoe_Shopee_Config() )->save( 'sandbox', '123', 'plaintext-must-not-persist' ); throw new RuntimeException( 'Missing master key accepted.' ); } catch ( RuntimeException $e ) {
	if ( 'ECOMKIT_WORDPRESS_SECURITY_KEYS_INVALID' !== $e->getMessage() || array() !== $GLOBALS['opts'] ) { throw new RuntimeException( 'Missing key did not fail closed.' ); }
}
echo "WP.3A missing credential key check passed.\n";
