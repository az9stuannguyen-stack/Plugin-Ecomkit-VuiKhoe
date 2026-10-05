<?php
/** Foundation-only fixtures: no provider transport and no real credentials. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' ); define( 'ARRAY_A', 'ARRAY_A' );
function lazada_check( bool $ok, string $message ): void { if ( ! $ok ) { throw new RuntimeException( $message ); } }
function wp_json_encode( mixed $v, int $flags = 0 ): string|false { return json_encode( $v, $flags ); }
function get_option( string $k, mixed $default = false ): mixed { return $GLOBALS['options'][$k] ?? $default; }
function update_option( string $k, mixed $v, bool $autoload ): bool { $GLOBALS['options'][$k] = $v; return true; }
function current_time( string $f, bool $utc ): string { return '2026-10-05 00:00:00'; }
function wp_remote_get(): never { throw new RuntimeException( 'PROVIDER_CALLED' ); }
function wp_remote_post(): never { throw new RuntimeException( 'PROVIDER_CALLED' ); }
function current_user_can( string $cap ): bool { return false; }
function esc_html__( string $text, string $domain ): string { return $text; }
function wp_die( mixed ...$args ): never { throw new RuntimeException( 'DENIED_403' ); }
require __DIR__ . '/../ecomkit-vuikhoe/vendor/autoload.php';
$encryption = new Ecomkit_Vuikhoe_Credential_Encryption( base64_encode( str_repeat( 'k', 32 ) ) );
$config = new Ecomkit_Vuikhoe_Lazada_Config( $encryption );
lazada_check( ! $config->safe_state()['configured'], 'Empty config reported ready.' );
$secret = 'synthetic-lazada-secret';
$config->save( 'test-app', $secret );
$stored = get_option( Ecomkit_Vuikhoe_Lazada_Config::OPTION );
lazada_check( ! str_contains( json_encode( $stored ), $secret ) && ! str_contains( json_encode( $config->safe_state() ), $secret ), 'Secret escaped encryption/UI projection.' );
lazada_check( $secret === $config->credentials()['app_secret'] && ! $config->safe_state()['connected'], 'Config incorrectly implies OAuth.' );
$config->save( 'test-app', '' );
lazada_check( $stored === get_option( Ecomkit_Vuikhoe_Lazada_Config::OPTION ), 'Blank edit changed envelope.' );
try { $config->save( 'another-app', '' ); throw new RuntimeException( 'Wrong app kept old secret.' ); } catch ( InvalidArgumentException ) {}
$config->save( 'test-app', 'replacement-test-secret' );
lazada_check( $stored['credential_envelope'] !== get_option( Ecomkit_Vuikhoe_Lazada_Config::OPTION )['credential_envelope'] && 'replacement-test-secret' === $config->credentials()['app_secret'], 'Replacement not encrypted.' );
try { $encryption->decrypt( $stored['credential_envelope'], 'ecomkit|shopee|provider-config' ); throw new RuntimeException( 'Cross-platform decrypt accepted.' ); } catch ( RuntimeException $e ) { lazada_check( 'ECOMKIT_CREDENTIAL_DECRYPT_FAILED' === $e->getMessage(), 'Credential AAD isolation failed.' ); }
$GLOBALS['options'][Ecomkit_Vuikhoe_Lazada_Config::OPTION]['credential_envelope'] = '{bad';
lazada_check( ! $config->safe_state()['configured'], 'Malformed envelope accepted.' );
try { ( new Ecomkit_Vuikhoe_Admin() )->handle_lazada_save_config(); throw new RuntimeException( 'Unauthorized config accepted.' ); } catch ( RuntimeException $e ) { lazada_check( 'DENIED_403' === $e->getMessage(), 'Config permission not enforced.' ); }
final class LazadaFoundationDb {
	public string $prefix = 'test_'; public int $insert_id = 0; public array $rows = array();
	public function prepare( string $sql, mixed ...$args ): string { return json_encode( array( $sql, $args ) ); }
	public function get_var( string $query ): ?int { [$sql,$args] = json_decode( $query, true ); foreach ( $this->rows as $r ) { if ( $r['platform'] === $args[0] && $r['external_shop_id'] === $args[1] ) { return $r['id']; } } return null; }
	public function insert( string $table, array $row ): int { $row['id'] = ++$this->insert_id; $this->rows[] = $row; return 1; }
	public function get_results( string $query, mixed $mode ): array { [$sql,$args] = json_decode( $query, true ); return array_values( array_filter( $this->rows, static fn( array $r ): bool => $r['platform'] === $args[0] ) ); }
}
$GLOBALS['wpdb'] = new LazadaFoundationDb();
$connections = new Ecomkit_Vuikhoe_Marketplace_Connection_Service( $encryption );
$a = $connections->create_pending( 'SHOPEE', 'same-id' ); $b = $connections->create_pending( 'LAZADA', 'same-id' );
lazada_check( $a !== $b && $b === $connections->create_pending( 'LAZADA', 'same-id' ), 'Platform/shop identity failed.' );
lazada_check( 1 === count( $connections->list_platform( 'SHOPEE' ) ) && 1 === count( $connections->list_platform( 'LAZADA' ) ), 'Platform coexistence failed.' );
$connections->create_pending( 'LAZADA', 'second-shop' );
lazada_check( 2 === count( $connections->list_platform( 'LAZADA' ) ), 'Multi-shop capability lost.' );
foreach ( $GLOBALS['wpdb']->rows as $row ) { lazada_check( 'PENDING_AUTH' === $row['status'] && null === $row['credential_envelope'], 'Invented token/connected state.' ); }
try { Ecomkit_Vuikhoe_Marketplace_Platform::validate( 'UNKNOWN' ); throw new RuntimeException( 'Unsupported platform allowed.' ); } catch ( InvalidArgumentException ) {}
$view = file_get_contents( __DIR__ . '/../ecomkit-vuikhoe/admin/views/marketplace.php' );
lazada_check( str_contains( $view, 'name="app_secret" type="password" value=""' ) && str_contains( $view, 'ecomkit_lazada_nonce' ), 'Secret edit or nonce UI unsafe.' );
echo "WP.6J.1 Lazada foundation: PASS (zero provider calls)\n";
