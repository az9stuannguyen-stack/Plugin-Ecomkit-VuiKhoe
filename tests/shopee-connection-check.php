<?php
/** WP.3 isolated MarketplaceConnection persistence checks. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' ); define( 'ARRAY_A', 'ARRAY_A' ); define( 'ECOMKIT_CREDENTIAL_KEY', base64_encode( str_repeat( 'C', 32 ) ) );
function wp_json_encode( mixed $v, int $f = 0 ): string|false { return json_encode( $v, $f ); }
function current_time( string $t, bool $g = false ): string { return '2026-10-03 00:00:00'; }
function conn_check( bool $v, string $m ): void { if ( ! $v ) throw new RuntimeException( $m ); }
final class ConnectionWpdb {
	public string $prefix = 'test_'; public int $insert_id = 0; public array $rows = array(); public bool $fail_update = false;
	public function prepare( string $q, mixed ...$a ): string { return json_encode( $a ); }
	public function get_var( string $q ): int { $a = json_decode( $q, true ); foreach ( $this->rows as $id => $r ) if ( $r['platform'] === $a[0] && $r['external_shop_id'] === $a[1] ) return $id; return 0; }
	public function insert( string $t, array $d ): int { $this->insert_id++; $this->rows[ $this->insert_id ] = $d + array( 'id' => $this->insert_id ); return 1; }
	public function update( string $t, array $d, array $w ): int|false { if ( $this->fail_update ) return false; $this->rows[ $w['id'] ] = $d + array( 'id' => $w['id'] ); return 1; }
	public function get_results( string $q, string $o ): array { return array_values( array_filter( $this->rows, fn( $r ) => 'SHOPEE' === $r['platform'] ) ); }
}
$GLOBALS['wpdb'] = new ConnectionWpdb();
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-db.php';
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-credential-key-resolver.php';
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-credential-encryption.php';
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-marketplace-connection-service.php';
$service = new Ecomkit_Vuikhoe_Marketplace_Connection_Service();
$tokens_a = array( 'access_token' => 'obviously-fake-access-a', 'refresh_token' => 'obviously-fake-refresh-a', 'expire_in' => 100 );
$id_a = $service->upsert_shopee( '1001', $tokens_a, 'fingerprint-a' );
$id_b = $service->upsert_shopee( '1002', array( 'access_token' => 'obviously-fake-access-b', 'refresh_token' => 'obviously-fake-refresh-b', 'expire_in' => 200 ), 'fingerprint-a' );
conn_check( 2 === count( $GLOBALS['wpdb']->rows ) && $id_a !== $id_b, 'Multi-shop persistence failed.' );
conn_check( ! str_contains( json_encode( $GLOBALS['wpdb']->rows ), 'obviously-fake-access' ) && ! str_contains( json_encode( $GLOBALS['wpdb']->rows ), 'obviously-fake-refresh' ), 'Tokens stored plaintext.' );
$old = $GLOBALS['wpdb']->rows[ $id_a ]['credential_envelope'];
$same = $service->upsert_shopee( '1001', array( 'access_token' => 'obviously-fake-new-access', 'refresh_token' => 'obviously-fake-new-refresh', 'expire_in' => 300 ), 'fingerprint-a' );
conn_check( $id_a === $same && 2 === count( $GLOBALS['wpdb']->rows ) && $old !== $GLOBALS['wpdb']->rows[ $id_a ]['credential_envelope'], 'Same-shop reauthorization did not replace one connection.' );
$preserved = $GLOBALS['wpdb']->rows[ $id_a ]['credential_envelope']; $GLOBALS['wpdb']->fail_update = true;
try { $service->upsert_shopee( '1001', $tokens_a, 'fingerprint-a' ); throw new RuntimeException( 'Failed update accepted.' ); } catch ( RuntimeException $e ) { conn_check( 'SHOPEE_CONNECTION_PERSIST_FAILED' === $e->getMessage(), 'Persistence failure classification wrong.' ); }
conn_check( $preserved === $GLOBALS['wpdb']->rows[ $id_a ]['credential_envelope'], 'Failed reauthorization destroyed prior credential.' );
$GLOBALS['wpdb']->fail_update = false;
$ready = $service->list_shopee( 'fingerprint-a' ); conn_check( true === $ready[0]['credential_ready'], 'Valid credential not CONNECTED.' );
$stale = $service->list_shopee( 'fingerprint-changed' ); conn_check( false === $stale[0]['credential_ready'], 'Config change did not require reauthorization.' );
echo "WP.3 MarketplaceConnection checks passed.\n";
