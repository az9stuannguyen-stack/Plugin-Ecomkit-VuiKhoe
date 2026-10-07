<?php
/** WP.6L.2 fake-transport seller OAuth, signing, shop binding and lifecycle. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' ); define( 'ARRAY_A', 'ARRAY_A' );
function auth_check( bool $ok, string $message ): void { if ( ! $ok ) { throw new RuntimeException( $message ); } }
function expect_auth_error( string $code, callable $fn ): void { try { $fn(); throw new RuntimeException( 'Expected ' . $code ); } catch ( RuntimeException $e ) { auth_check( $code === $e->getMessage(), 'Wrong failure classification: ' . $e->getMessage() ); } }
function wp_json_encode( mixed $v, int $flags = 0 ): string|false { return json_encode( $v, $flags ); }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['options'][$key] ?? $default; }
function update_option( string $key, mixed $value, bool $autoload ): bool { $GLOBALS['options'][$key] = $value; return true; }
function current_time( string $format, bool $utc ): string { return gmdate( 'Y-m-d H:i:s' ); }
function current_user_can( string $cap ): bool { return isset($GLOBALS['role_caps']) ? !empty($GLOBALS['role_caps'][$cap]) : 'manage_options' === $cap && $GLOBALS['authorized']; }
function get_current_user_id(): int { return $GLOBALS['user_id']; }
function wp_get_session_token(): string { return $GLOBALS['session']; }
function admin_url( string $path ): string { return 'https://wordpress.example/wp-admin/' . $path; }
function add_query_arg( mixed $args, mixed $second, mixed $third = null ): string { if ( is_array( $args ) ) { $url = $second; } else { $url = $third; $args = array( $args => $second ); } return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args ); }
function wp_parse_url( string $url, int $component ): mixed { return parse_url( $url, $component ); }
function wp_http_validate_url( string $url ): bool { return str_starts_with( $url, 'https://' ); }
function set_transient( string $key, mixed $v, int $ttl ): bool { $GLOBALS['transients'][$key] = $v; return true; }
function get_transient( string $key ): mixed { return $GLOBALS['transients'][$key] ?? false; }
function delete_transient( string $key ): bool { if ( ! isset( $GLOBALS['transients'][$key] ) ) { return false; } unset( $GLOBALS['transients'][$key] ); return true; }
function is_wp_error( mixed $r ): bool { return $r instanceof AuthNetworkError; }
final class AuthNetworkError {}
function wp_remote_retrieve_response_code( array $r ): int { return $r['status']; }
function wp_remote_retrieve_body( array $r ): string { return $r['body']; }
function wp_remote_post(): never { throw new RuntimeException( 'REAL_PROVIDER_CALL_FORBIDDEN' ); }
function wp_remote_get( string $url = '', array $args = array() ): mixed {
	if ( isset( $GLOBALS['synthetic_lazada_read_transport'] ) ) { return ( $GLOBALS['synthetic_lazada_read_transport'] )( $url, $args ); }
	throw new RuntimeException( 'REAL_PROVIDER_CALL_FORBIDDEN' );
}
function esc_html__( string $s, string $domain ): string { return $s; }
function wp_die( mixed ...$args ): never { throw new RuntimeException( 'DENIED_403' ); }
function check_admin_referer( string $action, string $field ): void {
	if ( str_starts_with($action, 'ecomkit_tiktok_oauth_') && !empty($GLOBALS['valid_nonce']) ) { return; }
	if ( 'ecomkit_lazada_reconcile_batch' === $action && 'ecomkit_lazada_reconcile_nonce' === $field && ! empty( $GLOBALS['synthetic_lazada_control_nonce'] ) && 'synthetic-nonce' === ( $_POST[$field] ?? '' ) ) { return; }
	throw new RuntimeException( 'NONCE_DENIED' );
}
function add_action( string $hook, mixed $handler ): void { $GLOBALS['hooks'][$hook] = $handler; }
final class AuthDb {
	public string $prefix = 'test_'; public array $rows = array(); public int $insert_id = 0; public mixed $on_lock = null; public bool $fail_update = false; public bool $fail_lock = false;
	public function prepare( string $sql, mixed ...$args ): string { return json_encode( array( $sql, $args ) ); }
	public function get_var( string $query ): mixed { [$sql,$args] = json_decode( $query, true ); if ( str_contains( $sql, 'GET_LOCK' ) ) { if($this->on_lock){$cb=$this->on_lock;$this->on_lock=null;$cb();} return $this->fail_lock ? 0 : 1; } if ( str_contains( $sql, 'RELEASE_LOCK' ) ) { return 1; } foreach ( $this->rows as $row ) { if ( $row['platform'] === $args[0] && $row['external_shop_id'] === $args[1] ) { return $row['id']; } } return null; }
	public function get_row( string $query, mixed $mode ): ?array { [$sql,$args] = json_decode( $query, true ); foreach ( $this->rows as $row ) { if ( (int) $row['id'] === $args[0] && $row['platform'] === $args[1] ) { return $row; } } return null; }
	public function get_results( string $query, mixed $mode ): array { [$sql,$args] = json_decode( $query, true ); return array_values( array_filter( $this->rows, static fn( array $row ): bool => $row['platform'] === $args[0] ) ); }
	public function insert( string $table, array $data ): int { $data['id'] = ++$this->insert_id; $this->rows[$data['id']] = $data; return 1; }
	public function update( string $table, array $data, array $where ): int|false { if ( $this->fail_update ) { return false; } foreach ( $this->rows as &$row ) { if ( $row['id'] === $where['id'] && $row['platform'] === $where['platform'] ) { $row = array_merge( $row, $data ); return 1; } } return 0; }
}
$GLOBALS['authorized'] = true; $GLOBALS['session'] = 'fake-admin-session'; $GLOBALS['user_id'] = 7; $GLOBALS['wpdb'] = new AuthDb();
require __DIR__ . '/../ecomkit-vuikhoe/vendor/autoload.php';
$encryption = new Ecomkit_Vuikhoe_Credential_Encryption( base64_encode( str_repeat( 'k', 32 ) ) );
$config = new Ecomkit_Vuikhoe_Tiktok_Config( $encryption ); $config->save( 'synthetic-app', 'synthetic-secret', 'service-17' );
$fixture = array( 'access_token' => 'fake-access-one', 'refresh_token' => 'fake-refresh-one', 'open_id' => 'seller-exact', 'user_type' => 0, 'granted_scopes' => array( 'seller.authorization.info' ), 'access_token_expire_in' => time()+7200, 'refresh_token_expire_in' => time()+86400 );
$shop = array( 'id' => '987654321098765432109876', 'cipher' => 'fake-shop-cipher', 'name' => 'Synthetic shop', 'region' => 'VN', 'seller_type' => 'LOCAL', 'code' => 'VN17' );
$GLOBALS['reply'] = $fixture; $GLOBALS['shops'] = array( $shop ); $GLOBALS['requests'] = array(); $GLOBALS['status'] = 200;
$transport = static function( string $url, array $args ) use ( $config ): mixed {
	parse_str( parse_url( $url, PHP_URL_QUERY ), $query ); $path = parse_url( $url, PHP_URL_PATH ); $GLOBALS['requests'][] = array( 'path'=>$path, 'query'=>$query );
	auth_check( $args['sslverify'] && 20 === $args['timeout'] && 0 === $args['redirection'] && !isset($args['body']) && $args['limit_response_size']===1048577, 'HTTP bounds or GET shape' );
	if ( str_contains($path,'/shops') ) {
		auth_check( parse_url($url,PHP_URL_HOST)==='open-api.tiktokglobalshop.com' && !isset($query['shop_cipher']) && isset($args['headers']['x-tts-access-token']) && preg_match('/^[0-9]{10}$/D',$query['timestamp']) && $query['sign']===Ecomkit_Vuikhoe_Tiktok_Signer::sign($path,$query,$config->credentials()['app_secret']), 'Authorized shops signing/contract' );
		$reply = array('shops'=>$GLOBALS['shops']);
	} else {
		auth_check(parse_url($url,PHP_URL_HOST)==='auth.tiktok-shops.com' && $query['app_key']==='synthetic-app' && $query['app_secret']===$config->credentials()['app_secret'],'Token host/config');
		auth_check($query['grant_type']===(str_ends_with($path,'refresh')?'refresh_token':'authorized_code'),'Grant type'); $reply=$GLOBALS['reply'];
	}
	if($reply instanceof AuthNetworkError)return $reply;
	return array('status'=>$GLOBALS['status'],'body'=>is_string($reply)?$reply:json_encode(array('code'=>$GLOBALS['code']??0,'request_id'=>$GLOBALS['request_id']??'req-safe','message'=>'untrusted synthetic-secret fake-access-one','data'=>$reply)));
};
$http = new Ecomkit_Vuikhoe_Tiktok_Http_Client($transport); $tokens = new Ecomkit_Vuikhoe_Tiktok_Token_Service($http,$config,$encryption); $oauth=new Ecomkit_Vuikhoe_Tiktok_OAuth($config,$tokens); $oauth->register();
auth_check(isset($GLOBALS['hooks']['admin_post_ecomkit_tiktok_oauth_callback'],$GLOBALS['hooks']['admin_post_nopriv_ecomkit_tiktok_oauth_callback']),'Callback registration');
$q=array('timestamp'=>'1700000000','app_key'=>'key','z'=>'last','sign'=>'excluded','access_token'=>'excluded');$path='/authorization/202309/shops';
$expected=hash_hmac('sha256','secret'.$path.'app_keykeytimestamp1700000000zlastsecret','secret');$signature=Ecomkit_Vuikhoe_Tiktok_Signer::sign($path,$q,'secret');
auth_check($signature===$expected && preg_match('/^[a-f0-9]{64}$/D',$signature),'Signer deterministic sorting, exclusion, hex');
auth_check(Ecomkit_Vuikhoe_Tiktok_Signer::sign($path,$q,'secret','{"a":1}')!==$signature && Ecomkit_Vuikhoe_Tiktok_Signer::sign('/different',$q,'secret')!==$signature,'Path/body signature distinction');
auth_check(Ecomkit_Vuikhoe_Tiktok_Signer::sign($path,$q,'secret','body','multipart/form-data; boundary=fake')===$signature,'Multipart body exclusion');
auth_check(Ecomkit_Vuikhoe_Tiktok_Signer::sign($path,$q,'secret','{"a":1}')===hash_hmac('sha256','secret'.$path.'app_keykeytimestamp1700000000zlast{"a":1}secret','secret'),'Exact body signed');
$state=$oauth->create_flow();parse_str(parse_url($oauth->authorization_url($state),PHP_URL_QUERY),$query);
auth_check($query===array('service_id'=>'service-17','state'=>$state) && !str_contains(json_encode($GLOBALS['transients']),'synthetic-secret'),'Seller URL/state minimal');
$result=$oauth->complete($state,'fake-code');$id=$result['connection_id'];$row=$GLOBALS['wpdb']->rows[$id];
auth_check(count($GLOBALS['requests'])===2 && $row['platform']==='TIKTOK' && $row['status']==='ACTIVE' && $row['external_shop_id']===$shop['id'],'Token plus shop activation');
$aad='ecomkit|tiktok|vn|shop:'.$shop['id'].'|v1';$plain=$encryption->decrypt($row['credential_envelope'],$aad);
auth_check($plain['tokens']===$fixture && $plain['shop']==$shop && !str_contains(json_encode($row),'fake-access-one') && !str_contains(json_encode($row),'fake-refresh-one'),'Encrypted pair/exact expiry/shop persistence');
auth_check($tokens->ensure_usable_access_token($id)==='fake-access-one' && count($GLOBALS['requests'])===2,'Ready no refresh');
expect_auth_error('TIKTOK_STATE_INVALID',fn()=>$oauth->complete($state,'fake-code'));
foreach(array(null,'',str_repeat('a',64),array('bad')) as $bad)expect_auth_error('TIKTOK_STATE_INVALID',fn()=>$oauth->complete($bad,'fake-code'));
foreach(array('platform'=>'LAZADA','user_id'=>99,'session_hash'=>'wrong','fingerprint'=>'wrong','callback'=>'https://other.test') as $field=>$value){$s=$oauth->create_flow();$GLOBALS['transients']['ecomkit_tiktok_flow_'.hash('sha256',$s)][$field]=$value;expect_auth_error('TIKTOK_STATE_INVALID',fn()=>$oauth->complete($s,'fake-code'));}
$s=$oauth->create_flow();$GLOBALS['transients']['ecomkit_tiktok_flow_'.hash('sha256',$s)]['expires_at']=time()-1;expect_auth_error('TIKTOK_STATE_EXPIRED',fn()=>$oauth->complete($s,'fake-code'));
$s=$oauth->create_flow();expect_auth_error('TIKTOK_AUTH_DENIED',fn()=>$oauth->complete($s,'null','auth_denied'));$s=$oauth->create_flow();expect_auth_error('TIKTOK_CODE_MISSING',fn()=>$oauth->complete($s,''));auth_check(count($GLOBALS['requests'])===2,'Bad callbacks made HTTP requests');
$saved=$GLOBALS['wpdb']->rows[$id];
foreach(array('user_type'=>1,'granted_scopes'=>array('seller.order.info'),'access_token_expire_in'=>7200,'refresh_token'=>null) as $field=>$value){$bad=$fixture;$bad[$field]=$value;$GLOBALS['reply']=$bad;$s=$oauth->create_flow();try{$oauth->complete($s,'bad-code');throw new LogicException('Bad token accepted');}catch(RuntimeException){}auth_check($GLOBALS['wpdb']->rows[$id]===$saved,'Bad reauth overwrote credentials');}
$GLOBALS['reply']=$fixture;$GLOBALS['shops']=array();expect_auth_error('TIKTOK_SHOP_NOT_FOUND',fn()=>$tokens->authorize('code'));auth_check($GLOBALS['wpdb']->rows[$id]===$saved,'Zero shops altered connection');
$GLOBALS['shops']=array($shop,array_merge($shop,array('id'=>'second-shop','cipher'=>'second-cipher')));$pending=$tokens->authorize('code');auth_check(isset($pending['selection']) && count($GLOBALS['wpdb']->rows)===1,'Multiple shops auto selected');
auth_check(!str_contains(json_encode($GLOBALS['transients']),'fake-access-one') && !str_contains(json_encode($tokens->safe_selection($pending['selection'])),'cipher'),'Pending selection secret redaction');
expect_auth_error('TIKTOK_SELECTION_INVALID',fn()=>$tokens->select_shop($pending['selection'],'987654321098765432109877'));auth_check($tokens->select_shop($pending['selection'],$shop['id'])===$id,'Exact explicit shop selection');expect_auth_error('TIKTOK_SELECTION_INVALID',fn()=>$tokens->select_shop($pending['selection'],$shop['id']));
$GLOBALS['shops']=array($shop);$GLOBALS['reply']=array_merge($fixture,array('access_token'=>'fake-access-two','refresh_token'=>'fake-refresh-two'));$before=count($GLOBALS['requests']);auth_check($tokens->ensure_usable_access_token($id,true)==='fake-access-two' && count($GLOBALS['requests'])===$before+1,'Manual refresh');
auth_check(end($GLOBALS['requests'])['query']['refresh_token']==='fake-refresh-one','Wrong previous refresh');
$GLOBALS['reply']=array_merge($fixture,array('access_token'=>'fake-access-three','refresh_token'=>'fake-refresh-two'));$tokens->ensure_usable_access_token($id,true);auth_check(end($GLOBALS['requests'])['query']['refresh_token']==='fake-refresh-two','Rotation not reused');
$safe=$tokens->safe_connections();auth_check($safe[0]['lifecycle']==='READY' && !str_contains(json_encode($safe),'fake-access') && !str_contains(json_encode($safe),'fake-refresh'),'Safe read-only projection');
// Simulate a competing refresh completing between snapshot and acquiring the lock.
$GLOBALS['reply']=array_merge($fixture,array('access_token'=>'concurrent-access','refresh_token'=>'concurrent-refresh'));$before=count($GLOBALS['requests']);$GLOBALS['wpdb']->on_lock=fn()=>$tokens->ensure_usable_access_token($id,true);
auth_check($tokens->ensure_usable_access_token($id,true)==='concurrent-access' && count($GLOBALS['requests'])===$before+1,'Two overlapping refreshes must replace once');
foreach(array(array_merge($fixture,array('refresh_token'=>null)),new AuthNetworkError(),'{invalid-json') as $bad){$GLOBALS['reply']=$fixture;$tokens->authorize('restore');$saved=$GLOBALS['wpdb']->rows[$id]['credential_envelope'];$GLOBALS['reply']=$bad;try{$tokens->ensure_usable_access_token($id,true);throw new LogicException('Bad refresh accepted');}catch(RuntimeException){}auth_check($saved===$GLOBALS['wpdb']->rows[$id]['credential_envelope'] && $tokens->safe_connections()[0]['lifecycle']==='INVALID','Partial refresh replaced pair or not fail closed');$before=count($GLOBALS['requests']);expect_auth_error('TIKTOK_REAUTH_REQUIRED',fn()=>$tokens->ensure_usable_access_token($id));auth_check(count($GLOBALS['requests'])===$before,'Blind refresh retry');}
$GLOBALS['reply']=$fixture;$tokens->authorize('restore');$p=$encryption->decrypt($GLOBALS['wpdb']->rows[$id]['credential_envelope'],$aad);$p['tokens']['access_token_expire_in']=time()+60;$GLOBALS['wpdb']->rows[$id]['credential_envelope']=$encryption->encrypt($p,$aad);auth_check($tokens->safe_connections()[0]['lifecycle']==='REFRESH_SOON','Proactive window');$before=count($GLOBALS['requests']);$tokens->ensure_usable_access_token($id);auth_check(count($GLOBALS['requests'])===$before+1,'Proactive refresh');
$p['tokens']['access_token_expire_in']=time()-1;$p['tokens']['refresh_token_expire_in']=time()-1;$GLOBALS['wpdb']->rows[$id]['credential_envelope']=$encryption->encrypt($p,$aad);$before=count($GLOBALS['requests']);expect_auth_error('TIKTOK_REAUTH_REQUIRED',fn()=>$tokens->ensure_usable_access_token($id,true));auth_check(count($GLOBALS['requests'])===$before && $tokens->safe_connections()[0]['lifecycle']==='REAUTH_REQUIRED','Expired refresh sent HTTP');
$GLOBALS['reply']=$fixture;$tokens->authorize('restore');$GLOBALS['code']=99;expect_auth_error('TIKTOK_PROVIDER_ERROR',fn()=>$http->exchange($config->credentials(),'fake-code'));auth_check(!str_contains(json_encode($http->last_diagnostic),'synthetic-secret'),'Provider diagnostic leak');unset($GLOBALS['code']);
$GLOBALS['status']=404;expect_auth_error('TIKTOK_HTTP_ERROR',fn()=>$http->exchange($config->credentials(),'fake-code'));$GLOBALS['status']=200;$GLOBALS['reply']='{invalid';expect_auth_error('TIKTOK_INVALID_RESPONSE',fn()=>$http->exchange($config->credentials(),'fake-code'));$GLOBALS['reply']=$fixture;
$GLOBALS['wpdb']->fail_lock=true;expect_auth_error('TIKTOK_LOCK_UNAVAILABLE',fn()=>$tokens->ensure_usable_access_token($id,true));$GLOBALS['wpdb']->fail_lock=false;
foreach(array('start','callback','refresh','select') as $action){$GLOBALS['authorized']=false;expect_auth_error('DENIED_403',fn()=>$oauth->$action());}$GLOBALS['authorized']=true;
foreach(array('start','refresh','select') as $action)expect_auth_error('NONCE_DENIED',fn()=>$oauth->$action());
$GLOBALS['wpdb']->fail_update=true;$before=count($GLOBALS['requests']);expect_auth_error('TIKTOK_PERSIST_FAILED',fn()=>$tokens->ensure_usable_access_token($id,true));auth_check(count($GLOBALS['requests'])===$before,'Refresh called before inflight persisted');$GLOBALS['wpdb']->fail_update=false;
// Actual authorized controllers, safe redirects and a connected real PHP card.
final class TikTokRedirect extends RuntimeException {}
function wp_safe_redirect(string $url): never {throw new TikTokRedirect($url);}
function add_filter(mixed ...$args): void {$GLOBALS['filters'][]=$args;}
function nocache_headers(): void {}
function esc_html(mixed $s): string {return htmlspecialchars((string)$s,ENT_QUOTES);}
function esc_attr(mixed $s): string {return esc_html($s);}
function esc_url(string $s): string {return esc_attr($s);}
function wp_nonce_field(string $action,string $name): void {echo '<input name="'.$name.'" value="fake-nonce">';}
function submit_button(string $label,string $type): void {echo '<button>'.$label.'</button>';}
define('ECOMKIT_CREDENTIAL_KEY',base64_encode(str_repeat('k',32)));
$GLOBALS['valid_nonce']=true;
try{$oauth->start();throw new LogicException('No start redirect');}catch(TikTokRedirect $r){auth_check(str_starts_with($r->getMessage(),'https://services.tiktokshop.com/open/authorize?service_id=service-17&state='),'Admin start redirect');parse_str(parse_url($r->getMessage(),PHP_URL_QUERY),$start_query);}
$_GET=array('state'=>$start_query['state'],'code'=>'real-looking-synthetic-code');try{$oauth->callback();throw new LogicException('No callback redirect');}catch(TikTokRedirect $r){auth_check(str_contains($r->getMessage(),'page=ecomkit-vuikhoe-marketplace') && str_contains($r->getMessage(),'tiktok_notice=connected') && !str_contains($r->getMessage(),'synthetic-code'),'Callback safe PRG');}
$_POST=array('connection_id'=>(string)$id);try{$oauth->refresh();throw new LogicException('No refresh redirect');}catch(TikTokRedirect $r){auth_check(str_contains($r->getMessage(),'tiktok_notice=refreshed'),'Admin refresh callback');}
$_GET=array();$before=count($GLOBALS['requests']);ob_start();require __DIR__.'/../ecomkit-vuikhoe/admin/views/tiktok-foundation.php';$html=ob_get_clean();
auth_check(str_contains($html,$shop['id']) && str_contains($html,'seller.authorization.info') && str_contains($html,'ecomkit_tiktok_oauth_refresh') && !str_contains($html,'fake-access-one') && !str_contains($html,'fake-refresh-one') && !str_contains($html,'synthetic-secret') && !str_contains($html,'fake-shop-cipher') && count($GLOBALS['requests'])===$before,'Connected card safe, exact, zero display calls');
$GLOBALS['shops']=array($shop,array_merge($shop,array('id'=>'second-shop')));$pending=$tokens->authorize('select-code');$_POST=array('selection'=>$pending['selection'],'shop_id'=>$shop['id']);try{$oauth->select();throw new LogicException('No select redirect');}catch(TikTokRedirect $r){auth_check(str_contains($r->getMessage(),'tiktok_notice=connected'),'Admin select controller');}$GLOBALS['shops']=array($shop);
// Denial consumes state without invoking the client; notices contain safe enums only.
$state=$oauth->create_flow();$_GET=array('state'=>$state,'code'=>'null','error'=>'auth_denied');$before=count($GLOBALS['requests']);try{$oauth->callback();throw new LogicException('No denied redirect');}catch(TikTokRedirect $r){auth_check(str_contains($r->getMessage(),'tiktok_notice=error') && !str_contains($r->getMessage(),'code=null') && count($GLOBALS['requests'])===$before,'Denied callback called provider');}
$GLOBALS['request_id']='prefix-synthetic-secret';$http->exchange($config->credentials(),'code');auth_check(!isset($http->last_diagnostic['request_id']),'Echoed secret in request id');unset($GLOBALS['request_id']);
$saved_option=get_option($config::OPTION);$valid_app=$config->credentials();
foreach(array('app_key','app_secret','service_id') as $field){$invalid=$valid_app;$invalid[$field]='';$GLOBALS['options'][$config::OPTION]=array('v'=>1,'credential_envelope'=>$encryption->encrypt($invalid,'ecomkit|tiktok|provider-config|v1'));expect_auth_error('TIKTOK_CONFIG_INVALID',fn()=>$oauth->create_flow());}$GLOBALS['options'][$config::OPTION]=$saved_option;
$s=$oauth->create_flow();$GLOBALS['session']='different-admin-session';expect_auth_error('TIKTOK_STATE_INVALID',fn()=>$oauth->complete($s,'code'));$GLOBALS['session']='fake-admin-session';
foreach(array('Editor'=>array('edit_pages'=>true),'Shop Manager'=>array('manage_woocommerce'=>true),'Author'=>array('edit_posts'=>true),'Contributor'=>array('edit_posts'=>true),'Subscriber'=>array('read'=>true),'Legacy Operator'=>array('ecomkit_use'=>true)) as $role=>$caps){$GLOBALS['role_caps']=$caps;foreach(array('start','callback','refresh','select') as $action)expect_auth_error('DENIED_403',fn()=>$oauth->$action());ob_start();try{require __DIR__.'/../ecomkit-vuikhoe/admin/views/tiktok-foundation.php';throw new LogicException('Role saw card');}catch(RuntimeException $e){auth_check($e->getMessage()==='DENIED_403' && ob_get_contents()==='',$role.' card denial');}finally{ob_end_clean();}}unset($GLOBALS['role_caps']);
$absolute=$fixture;$absolute['access_token_expire_in']=(string)$fixture['access_token_expire_in'];auth_check(Ecomkit_Vuikhoe_Tiktok_Token_Service::normalize($absolute)['access_token_expire_in']===$fixture['access_token_expire_in'],'Absolute timestamp string');
$GLOBALS['shops']=array(array_merge($shop,array('region'=>'TH')));expect_auth_error('TIKTOK_REGION_MISMATCH',fn()=>$tokens->authorize('non-vn'));$GLOBALS['shops']=array($shop);
$foreign=array();foreach(array('SHOPEE','LAZADA') as $platform){$GLOBALS['wpdb']->insert('connections',array('platform'=>$platform,'external_shop_id'=>$shop['id'],'status'=>'ACTIVE','credential_envelope'=>'foreign-sentinel'));$foreign[$GLOBALS['wpdb']->insert_id]=$GLOBALS['wpdb']->rows[$GLOBALS['wpdb']->insert_id];expect_auth_error('TIKTOK_CONNECTION_INVALID',fn()=>$tokens->ensure_usable_access_token($GLOBALS['wpdb']->insert_id,true));}$tokens->authorize('isolated-reauth');foreach($foreign as $key=>$sentinel)auth_check($GLOBALS['wpdb']->rows[$key]===$sentinel,'Foreign provider mutated');
$config->save('synthetic-app','changed-secret','service-17');$before=count($GLOBALS['requests']);expect_auth_error('TIKTOK_REAUTH_REQUIRED',fn()=>$tokens->ensure_usable_access_token($id,true));auth_check(count($GLOBALS['requests'])===$before,'Config change attempted refresh');
echo "WP.6L.2 TikTok seller authorization: PASS (fake HTTP only, state, signer, encrypted shop binding, rotation, concurrency, lifecycle, access, nonce; real provider calls 0)\n";
