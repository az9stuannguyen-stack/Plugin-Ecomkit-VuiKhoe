<?php
/** Foundation-only fixtures: no provider transport and no real credentials. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' ); define( 'ARRAY_A', 'ARRAY_A' );
function tiktok_check( bool $ok, string $message ): void { if ( ! $ok ) { throw new RuntimeException( $message ); } }
function wp_json_encode( mixed $v, int $flags = 0 ): string|false { return json_encode( $v, $flags ); }
function get_option( string $k, mixed $default = false ): mixed { return $GLOBALS['options'][$k] ?? $default; }
function update_option( string $k, mixed $v, bool $autoload ): bool { $GLOBALS['options'][$k] = $v; return true; }
function current_time( string $f, bool $utc ): string { return '2026-10-05 00:00:00'; }
function wp_remote_get(): never { throw new RuntimeException( 'PROVIDER_CALLED' ); }
function wp_remote_post(): never { throw new RuntimeException( 'PROVIDER_CALLED' ); }
function current_user_can( string $cap ): bool { return ! empty( $GLOBALS['caps'][$cap] ); }
function esc_html__( string $text, string $domain ): string { return $text; }
function wp_die( mixed ...$args ): never { throw new RuntimeException( 'DENIED_403' ); }
require __DIR__ . '/../ecomkit-vuikhoe/vendor/autoload.php';
$encryption = new Ecomkit_Vuikhoe_Credential_Encryption( base64_encode( str_repeat( 'k', 32 ) ) );
$config = new Ecomkit_Vuikhoe_Tiktok_Config( $encryption );
tiktok_check( ! $config->safe_state()['configured'], 'Empty config reported ready.' );
$secret = 'synthetic-tiktok-secret';
$config->save( 'test-app', $secret, 'service-1' );
$stored = get_option( Ecomkit_Vuikhoe_Tiktok_Config::OPTION );
tiktok_check( ! str_contains( json_encode( $stored ), $secret ) && ! str_contains( json_encode( $config->safe_state() ), $secret ), 'Secret escaped encryption/UI projection.' );
tiktok_check( $secret === $config->credentials()['app_secret'] && ! $config->safe_state()['connected'], 'Config incorrectly implies OAuth.' );
$config->save( 'test-app', '', 'service-1' );
tiktok_check( $stored === get_option( Ecomkit_Vuikhoe_Tiktok_Config::OPTION ), 'Blank edit changed envelope.' );
try { $config->save( 'another-app', '', 'service-1' ); throw new RuntimeException( 'Wrong app kept old secret.' ); } catch ( InvalidArgumentException ) {}
$config->save( 'test-app', 'replacement-test-secret', 'service-1' );
tiktok_check( $stored['credential_envelope'] !== get_option( Ecomkit_Vuikhoe_Tiktok_Config::OPTION )['credential_envelope'] && 'replacement-test-secret' === $config->credentials()['app_secret'], 'Replacement not encrypted.' );
try { $encryption->decrypt( $stored['credential_envelope'], 'ecomkit|shopee|provider-config' ); throw new RuntimeException( 'Cross-platform decrypt accepted.' ); } catch ( RuntimeException $e ) { tiktok_check( 'ECOMKIT_CREDENTIAL_DECRYPT_FAILED' === $e->getMessage(), 'Credential AAD isolation failed.' ); }
$GLOBALS['options'][Ecomkit_Vuikhoe_Tiktok_Config::OPTION]['credential_envelope'] = '{bad';
tiktok_check( ! $config->safe_state()['configured'], 'Malformed envelope accepted.' );
try { ( new Ecomkit_Vuikhoe_Admin() )->handle_tiktok_save_config(); throw new RuntimeException( 'Unauthorized config accepted.' ); } catch ( RuntimeException $e ) { tiktok_check( 'DENIED_403' === $e->getMessage(), 'Config permission not enforced.' ); }
final class TiktokFoundationDb {
	public string $prefix = 'test_'; public int $insert_id = 0; public array $rows = array();
	public function prepare( string $sql, mixed ...$args ): string { return json_encode( array( $sql, $args ) ); }
	public function get_var( string $query ): ?int { [$sql,$args] = json_decode( $query, true ); foreach ( $this->rows as $r ) { if ( $r['platform'] === $args[0] && $r['external_shop_id'] === $args[1] ) { return $r['id']; } } return null; }
	public function insert( string $table, array $row ): int { $row['id'] = ++$this->insert_id; $this->rows[] = $row; return 1; }
	public function get_results( string $query, mixed $mode ): array { [$sql,$args] = json_decode( $query, true ); return array_values( array_filter( $this->rows, static fn( array $r ): bool => $r['platform'] === $args[0] ) ); }
}
$GLOBALS['wpdb'] = new TiktokFoundationDb();
$connections = new Ecomkit_Vuikhoe_Marketplace_Connection_Service( $encryption );
$a = $connections->create_pending( 'SHOPEE', 'same-id' ); $b = $connections->create_pending( 'TIKTOK', 'same-id' );
tiktok_check( $a !== $b && $b === $connections->create_pending( 'TIKTOK', 'same-id' ), 'Platform/shop identity failed.' );
tiktok_check( 1 === count( $connections->list_platform( 'SHOPEE' ) ) && 1 === count( $connections->list_platform( 'TIKTOK' ) ), 'Platform coexistence failed.' );
$connections->create_pending( 'TIKTOK', 'second-shop' );
tiktok_check( 2 === count( $connections->list_platform( 'TIKTOK' ) ), 'Multi-shop capability lost.' );
foreach ( $GLOBALS['wpdb']->rows as $row ) { tiktok_check( 'PENDING_AUTH' === $row['status'] && null === $row['credential_envelope'], 'Invented token/connected state.' ); }
try { Ecomkit_Vuikhoe_Marketplace_Platform::validate( 'UNKNOWN' ); throw new RuntimeException( 'Unsupported platform allowed.' ); } catch ( InvalidArgumentException ) {}
$view = file_get_contents( __DIR__ . '/../ecomkit-vuikhoe/admin/views/tiktok-foundation.php' );
tiktok_check( str_contains( $view, 'name="app_secret" type="password" value=""' ) && str_contains( $view, 'ecomkit_tiktok_nonce' ), 'Secret edit or nonce UI unsafe.' );

function admin_url(string $p): string { return ($GLOBALS['scheme'] ?? 'https').'://example.test/wp-admin/'.$p; }
function add_query_arg(mixed $key,string $value,?string $url=null): string {return is_array($key)?$value.'?'.http_build_query($key):$url.'?'.http_build_query([$key=>$value]); }
function check_admin_referer(string $a,string $n): void { tiktok_check($a==='ecomkit_tiktok_save_config' && $n==='ecomkit_tiktok_nonce','Nonce contract'); if(empty($GLOBALS['valid_nonce']))throw new RuntimeException('NONCE_DENIED'); }
foreach (['edit_pages','manage_woocommerce','edit_posts','read','ecomkit_use'] as $cap) {
 $GLOBALS['caps']=[$cap=>true];
 try {(new Ecomkit_Vuikhoe_Admin())->handle_tiktok_save_config();throw new RuntimeException('Allowed');}catch(RuntimeException $e){tiktok_check($e->getMessage()==='DENIED_403','Non-admin save allowed');}
 ob_start();try {require __DIR__.'/../ecomkit-vuikhoe/admin/views/tiktok-foundation.php';throw new RuntimeException('Card rendered');}catch(RuntimeException $e){tiktok_check($e->getMessage()==='DENIED_403' && ob_get_contents()==='','Card leaked');}finally{ob_end_clean();}
}
$GLOBALS['caps']=['manage_options'=>true];
try {(new Ecomkit_Vuikhoe_Admin())->handle_tiktok_save_config();throw new RuntimeException('Nonce bypass');}catch(RuntimeException $e){tiktok_check($e->getMessage()==='NONCE_DENIED','Missing nonce not denied');}
tiktok_check($config->callback_url()==='https://example.test/wp-admin/admin-post.php?action=ecomkit_tiktok_oauth_callback','Stable callback');
$GLOBALS['scheme']='http';try {$config->callback_url();throw new RuntimeException('HTTP callback accepted');}catch(RuntimeException $e){tiktok_check($e->getMessage()==='TIKTOK_CALLBACK_HTTPS_REQUIRED','HTTPS boundary');}unset($GLOBALS['scheme']);
define('ECOMKIT_CREDENTIAL_KEY',base64_encode(str_repeat('k',32)));
$config->save('test-app','never-render-this-secret','service-1');
function esc_html(mixed $s): string{return htmlspecialchars((string)$s);}
function esc_attr(mixed $s): string{return esc_html($s);}function esc_url(string $s): string{return $s;}
function wp_nonce_field(string $a,string $n): void {echo '<input type="hidden" name="'.$n.'" value="synthetic">';}
function submit_button(mixed ...$v): void {echo '<button>'.$v[0].'</button>';}
ob_start();require __DIR__.'/../ecomkit-vuikhoe/admin/views/tiktok-foundation.php';$html=ob_get_clean();
tiktok_check(str_contains($html,'name="app_key"') && str_contains($html,'value="test-app"') && !str_contains($html,'never-render-this-secret') && !str_contains($html,'name="access_token"'),'Admin card/secret/token boundary');
$GLOBALS['options']['ecomkit_vuikhoe_shopee_config']=['sentinel'=>'unchanged'];$GLOBALS['options']['ecomkit_vuikhoe_lazada_config']=['sentinel'=>'unchanged'];
$config->save('test-app','new-secret','service-1');
tiktok_check($GLOBALS['options']['ecomkit_vuikhoe_shopee_config']===['sentinel'=>'unchanged'] && $GLOBALS['options']['ecomkit_vuikhoe_lazada_config']===['sentinel'=>'unchanged'],'Provider config overwrite');
foreach(['ecomkit|lazada|provider-config|v1','ecomkit|shopee|provider-config'] as $aad){try {$encryption->decrypt(get_option($config::OPTION)['credential_envelope'],$aad);throw new RuntimeException('Cross decrypt');}catch(RuntimeException $e){tiktok_check($e->getMessage()==='ECOMKIT_CREDENTIAL_DECRYPT_FAILED','AAD isolation');}}
$method=new ReflectionMethod(Ecomkit_Vuikhoe_Excel_Service::class,'parse_combined_identity');$parser=(new ReflectionClass(Ecomkit_Vuikhoe_Excel_Service::class))->newInstanceWithoutConstructor();
foreach(['TikTok','TikTok Shop','TIKTOK'] as $label)tiktok_check($method->invoke($parser,$label."\n987654321098765432109876")['error']==='EXCEL_UNKNOWN_PLATFORM','Unverified alias introduced');
tiktok_check($method->invoke($parser,"Shopee\n987654321098765432109876")['code']==='987654321098765432109876','Exact Excel ID regression');
function wp_unslash(mixed $v): mixed{return $v;}
function wp_safe_redirect(string $url): never {throw new RuntimeException($url);}
$GLOBALS['valid_nonce']=true;$_POST=['app_key'=>'test-app','app_secret'=>'controller-secret','service_id'=>'service-1'];
try {(new Ecomkit_Vuikhoe_Admin())->handle_tiktok_save_config();throw new RuntimeException('No redirect');}catch(RuntimeException $e){tiktok_check(str_contains($e->getMessage(),'tiktok_notice=saved'),'Authorized save callback');}
tiktok_check($config->credentials()['app_secret']==='controller-secret','Authorized save not persisted');
try {(new Ecomkit_Vuikhoe_Admin())->handle_tiktok_foundation_callback();throw new RuntimeException('Callback performed auth');}catch(RuntimeException $e){tiktok_check($e->getMessage()==='DENIED_403','Foundation callback boundary');}
try {(new Ecomkit_Vuikhoe_Tiktok_Http_Client())->request();throw new RuntimeException('HTTP call allowed');}catch(LogicException $e){tiktok_check($e->getMessage()==='TIKTOK_CLIENT_NOT_IMPLEMENTED','Inert HTTP boundary');}
echo "WP.6L.1 TikTok foundation: PASS (encryption, isolation, pending multi-shop, actual card, permissions, nonce, callback, parser audit; zero provider calls)\n";
