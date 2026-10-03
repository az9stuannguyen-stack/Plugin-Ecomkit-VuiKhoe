<?php
/** WP.3 public callback, one-time state and synthetic transport checks. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' ); define( 'ARRAY_A', 'ARRAY_A' ); define( 'ECOMKIT_CREDENTIAL_KEY', base64_encode( str_repeat( 'O', 32 ) ) );
function wp_json_encode( mixed $v, int $f = 0 ): string|false { return json_encode( $v, $f ); }
function get_option( string $k, mixed $d = false ): mixed { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( string $k, mixed $v, bool $a = false ): bool { $GLOBALS['opts'][ $k ] = $v; return true; }
function current_time( string $t, bool $g = false ): string { return '2026-10-03 00:00:00'; }
function rest_url( string $p ): string { return 'https://example.test/wp-json/' . $p; }
function wp_http_validate_url( string $u ): string|false { return filter_var( $u, FILTER_VALIDATE_URL ); }
function wp_parse_url( string $u, int $c = -1 ): mixed { return parse_url( $u, $c ); }
function add_query_arg( array $a, string $u ): string { return $u . '?' . http_build_query( $a ); }
function sanitize_text_field( string $v ): string { return trim( $v ); }
function sanitize_key( string $v ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $v ) ); }
function wp_unslash( mixed $v ): mixed { return $v; }
function admin_url( string $p ): string { return 'https://example.test/wp-admin/' . $p; }
function is_ssl(): bool { return true; }
function get_current_user_id(): int { return 7; }
function get_transient( string $k ): mixed { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( string $k, mixed $v, int $ttl ): bool { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( string $k ): bool { unset( $GLOBALS['transients'][ $k ] ); return true; }
function absint( mixed $v ): int { return abs( (int) $v ); }
function is_wp_error( mixed $v ): bool { return false; }
function wp_remote_post( string $url, array $args ): array { $GLOBALS['calls'][] = compact( 'url', 'args' ); return $GLOBALS['response']; }
function wp_remote_retrieve_response_code( array $r ): int { return $r['status']; }
function wp_remote_retrieve_body( array $r ): string { return $r['body']; }
class WP_REST_Request { public function __construct( private array $p = array() ) {} public function get_param( string $k ): mixed { return $this->p[ $k ] ?? null; } }
class WP_REST_Response { public function __construct( public mixed $data = null, public int $status = 200, public array $headers = array() ) {} }
function oauth_check( bool $v, string $m ): void { if ( ! $v ) throw new RuntimeException( $m ); }
final class OAuthWpdb {
	public string $prefix = 'oauth_'; public int $insert_id = 0; public array $rows = array();
	public function prepare( string $q, mixed ...$a ): string { return json_encode( $a ); }
	public function get_var( string $q ): int { $a=json_decode($q,true); if(isset($a[0])&&str_starts_with((string)$a[0],'ecomkit_shopee_credential_')) return 1; foreach($this->rows as $id=>$r) if($r['platform']===$a[0]&&$r['external_shop_id']===$a[1]) return $id; return 0; }
	public function insert( string $t, array $d ): int { $this->insert_id++; $this->rows[$this->insert_id]=$d+array('id'=>$this->insert_id); return 1; }
	public function update( string $t, array $d, array $w ): int { $this->rows[$w['id']]=$d+array('id'=>$w['id']); return 1; }
}
$GLOBALS['wpdb'] = new OAuthWpdb(); $GLOBALS['opts']=array(); $GLOBALS['transients']=array(); $GLOBALS['calls']=array();
foreach ( array( 'class-ecomkit-db.php', 'class-ecomkit-credential-key-resolver.php', 'class-ecomkit-credential-encryption.php', 'class-ecomkit-credential-mutation-lock.php', 'class-ecomkit-shopee-environment.php', 'class-ecomkit-shopee-signer.php', 'class-ecomkit-shopee-config.php', 'class-ecomkit-shopee-http-client.php', 'class-ecomkit-marketplace-connection-service.php', 'class-ecomkit-shopee-oauth.php' ) as $file ) require __DIR__ . '/../ecomkit-vuikhoe/includes/' . $file;
$config_service = new Ecomkit_Vuikhoe_Shopee_Config(); $config = $config_service->save( 'sandbox', '123', 'obviously-fake-key' );
$oauth = new Ecomkit_Vuikhoe_Shopee_OAuth();
$nonce_a=$oauth->new_flow_nonce(); $nonce_b=$oauth->new_flow_nonce(); oauth_check($nonce_a!==$nonce_b&&43===strlen($nonce_a),'OAuth flow nonce entropy/uniqueness failed.');
$auth_a=$oauth->authorization_url($config,'obviously-fake-key',$config_service->callback_url(),1700000000); $auth_b=$oauth->authorization_url($config,'obviously-fake-key',$config_service->callback_url(),1700000001);
oauth_check($auth_a!==$auth_b&&str_starts_with($auth_a,'https://partner.test-stable.shopeemobile.com/api/v2/shop/auth_partner?')&&0===count($GLOBALS['calls']),'Authorization URL generation called provider or used wrong host/timestamp.');
unset( $_COOKIE['ecomkit_shopee_oauth'] ); $bare = $oauth->callback( new WP_REST_Request() );
oauth_check( 400 === $bare->status && 0 === count( $GLOBALS['calls'] ), 'Bare callback was not safe.' );

function arm_flow( array $config, int $age = 0 ): void { $state='opaque-state-' . count($GLOBALS['transients']); $_COOKIE['ecomkit_shopee_oauth']=$state; $GLOBALS['transients']['ecomkit_shopee_flow_'.hash('sha256',$state)] = array( 'user_id'=>7, 'environment'=>$config['environment'], 'partner_id'=>$config['partner_id'], 'fingerprint'=>$config['fingerprint'], 'callback'=>'https://example.test/wp-json/ecomkit/v1/shopee/callback', 'created_at'=>time()-$age ); }
arm_flow( $config ); $missing_code=$oauth->callback(new WP_REST_Request(array('shop_id'=>'456'))); oauth_check(400===$missing_code->status&&0===count($GLOBALS['calls']),'Missing code called provider.');
arm_flow( $config ); $missing_shop=$oauth->callback(new WP_REST_Request(array('code'=>'fake'))); oauth_check(400===$missing_shop->status&&0===count($GLOBALS['calls']),'Missing shop called provider.');
arm_flow( $config, 601 ); $expired=$oauth->callback(new WP_REST_Request(array('code'=>'fake','shop_id'=>'456'))); oauth_check(400===$expired->status&&0===count($GLOBALS['calls']),'Expired state called provider.');

$GLOBALS['response']=array('status'=>200,'body'=>json_encode(array('error'=>'','access_token'=>'obviously-fake-access','refresh_token'=>'obviously-fake-refresh','expire_in'=>120,'shop_id_list'=>array(456))));
arm_flow( $config ); $success=$oauth->callback(new WP_REST_Request(array('code'=>'obviously-fake-code','shop_id'=>'456')));
oauth_check(302===$success->status&&1===count($GLOBALS['calls'])&&1===count($GLOBALS['wpdb']->rows),'Valid callback did not exchange once and persist once.');
oauth_check(!str_contains(json_encode($GLOBALS['wpdb']->rows),'obviously-fake-access')&&!str_contains(json_encode($GLOBALS['wpdb']->rows),'obviously-fake-refresh'),'Callback persisted plaintext tokens.');
$replay=$oauth->callback(new WP_REST_Request(array('code'=>'obviously-fake-code','shop_id'=>'456'))); oauth_check(400===$replay->status&&1===count($GLOBALS['calls']),'Replay performed a second token exchange.');
oauth_check(str_contains($GLOBALS['calls'][0]['url'],'/api/v2/auth/token/get')&&!str_contains($GLOBALS['calls'][0]['url'],'order')&&!str_contains($GLOBALS['calls'][0]['url'],'escrow'),'Unauthorized provider endpoint called.');
$body=json_decode($GLOBALS['calls'][0]['args']['body'],true); oauth_check(array('code','partner_id')===array_keys($body)&&!isset($body['shop_id'])&&is_int($body['partner_id']),'Callback token request body is not minimal or typed.');

$GLOBALS['response']=array('status'=>200,'body'=>json_encode(array('error'=>'invalid_code','message'=>'The code is expired or used or invalid','request_id'=>'fake-request-id')));
arm_flow( $config ); $failure=$oauth->callback(new WP_REST_Request(array('code'=>'one-time-fake-code','shop_id'=>'456')));
$failure_query=array(); parse_str((string)parse_url($failure->headers['Location'],PHP_URL_QUERY),$failure_query); $diag_ref=$failure_query['oauth_diag']??''; $stored=$GLOBALS['transients']['ecomkit_shopee_diag_'.$diag_ref]??array();
oauth_check(302===$failure->status&&'shopee_oauth_failed'===($failure_query['shopee_error']??'')&&!isset($failure_query['request_id'])&&!str_contains($failure->headers['Location'],'one-time-fake-code'),'Failure redirect exposed details or code.');
oauth_check('TOKEN_PROVIDER_RESPONSE'===($stored['diagnostic']['stage']??'')&&'SHOPEE_AUTH_CODE_INVALID'===($stored['diagnostic']['classification']??'')&&'invalid_code'===($stored['diagnostic']['provider_error']??'')&&'fake-request-id'===($stored['diagnostic']['request_id']??''),'Safe callback diagnostic was not preserved.');
oauth_check(1===count($GLOBALS['wpdb']->rows),'Provider failure activated a connection.');
echo "WP.3 OAuth callback checks passed.\n";
