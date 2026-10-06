<?php
declare(strict_types=1);
require __DIR__ . '/lazada-order-check.php';
function check_ajax_referer(string $a,string $k): never { throw new RuntimeException('NONCE_DENIED'); }
$GLOBALS['reply']=$fixture; $tokens->authorize('diagnostic-restore');
$diagnostic=new Ecomkit_Vuikhoe_Lazada_Order_Diagnostic($tokens,static fn(int $connection): Ecomkit_Vuikhoe_Lazada_Order_Client=>$client);
$diagnostic->register(); auth_check(isset($GLOBALS['hooks']['wp_ajax_ecomkit_lazada_order_diagnostic']) && !isset($GLOBALS['hooks']['wp_ajax_nopriv_ecomkit_lazada_order_diagnostic']),'Public hook');
$input=['connection_id'=>(string)$id,'order_id'=>'987654321098765432109876543210','order_date'=>'','offset'=>'0'];
$GLOBALS['authorized']=false; expect_auth_error('DENIED_403',fn()=>$diagnostic->run($input)); expect_auth_error('DENIED_403',fn()=>$diagnostic->ajax()); $GLOBALS['authorized']=true;
expect_auth_error('NONCE_DENIED',fn()=>$diagnostic->ajax());
$bad=$input; $bad['connection_id']='999'; expect_auth_error('LAZADA_ORDER_AUTH_REQUIRED',fn()=>$diagnostic->run($bad));
$snapshot=[$GLOBALS['wpdb']->rows,$GLOBALS['options'],$GLOBALS['transients']];
$GLOBALS['seller_reply']=static function(string $path,array $p) use ($input): array {
 $o=synthetic_order(123); $o['order_id']=$input['order_id'];
 if ($path==='/order/get') return ['code'=>'0','data'=>$o];
 if ($path==='/order/items/get') return ['code'=>'0','data'=>[['order_id'=>$input['order_id'],'order_item_id'=>'501','status'=>'delivered']]];
 return ['code'=>'0','request_id'=>'safe-request','data'=>['orders'=>[$o],'countTotal'=>1,'count'=>1]];
};
$before=count($GLOBALS['seller_calls']); $r=$diagnostic->run($input); $calls=array_slice($GLOBALS['seller_calls'],$before);
auth_check(array_column($calls,'path')===['/order/get','/order/items/get'],'Direct sequence');
auth_check($calls[0]['params']['order_id']===$input['order_id'] && $calls[1]['params']['order_id']===$input['order_id'],'Exact long ID');
auth_check($r['checks']['order']['data']['comparison']==='MATCH' && $r['checks']['items']['data']['item_count']===1,'Direct results');
auth_check(!str_contains(json_encode($r),'Synthetic Example') && !str_contains(json_encode($r),'fake-access') && $r['persistence']===false,'Leak/persistence');
$input['check_list']='1'; $input['order_date']='2026-10-03'; date_default_timezone_set('America/New_York');
$r=$diagnostic->run($input); $list=$r['checks']['orders']['data'];
auth_check($r['request_count']===3 && $list['comparison']==='MATCH' && $list['created_after']==='2026-10-03T00:00:00+07:00' && $list['provider_end_bound_sent']===false,'Date/list contract');
auth_check(!isset(end($GLOBALS['seller_calls'])['params']['created_before']),'Invented bound');
$input['order_id']='0987654321098765432109876543210'; $r=$diagnostic->run($input);
auth_check($r['checks']['orders']['data']['comparison']==='NO MATCH' && !$r['checks']['order']['success'],'Fuzzy mismatch');
$input['order_date']='2026-02-30'; $before=count($GLOBALS['seller_calls']); expect_auth_error('LAZADA_ORDER_DIAGNOSTIC_WINDOW_INVALID',fn()=>$diagnostic->run($input)); auth_check(count($GLOBALS['seller_calls'])===$before,'Invalid date called provider');
$input['check_list']=''; $input['order_id']='123';
$GLOBALS['seller_reply']=['code'=>'0','data'=>[]]; $r=$diagnostic->run($input); auth_check($r['checks']['order']['classification']==='LAZADA_ORDER_NOT_FOUND' && $r['checks']['items']['success'],'Independent failures');
$GLOBALS['seller_reply']='{invalid'; $r=$diagnostic->run($input); auth_check(!$r['checks']['order']['success'] && !$r['checks']['items']['success'],'Invalid provider JSON');
$GLOBALS['seller_reply']=['code'=>'15','message'=>'fake-access-one test-app-secret sign=hidden']; $r=$diagnostic->run($input); auth_check(!str_contains(json_encode($r),'test-app-secret') && !str_contains(json_encode($r),'fake-access-one'),'Error leak');
auth_check($snapshot===[$GLOBALS['wpdb']->rows,$GLOBALS['options'],$GLOBALS['transients']],'Diagnostic persisted');
$js=file_get_contents(__DIR__.'/../ecomkit-vuikhoe/assets/js/lazada-order-diagnostic.js'); auth_check(!str_contains($js,'innerHTML') && !str_contains($js,'parseInt(') && !str_contains($js,'Number('),'Unsafe browser');
// Render actual collapsed diagnostic partial with safe WordPress UI stubs.
define('ECOMKIT_VUIKHOE_FILE',__DIR__.'/../ecomkit-vuikhoe/ecomkit-vuikhoe.php'); define('ECOMKIT_VUIKHOE_VERSION','0.7.21');
function wp_enqueue_script(mixed ...$args): void {}
function plugins_url(string $path,string $file): string { return 'https://wordpress.example/plugin/'.$path; }
function esc_attr(string $v): string { return htmlspecialchars($v,ENT_QUOTES); }
function esc_html(string $v): string { return htmlspecialchars($v,ENT_QUOTES); }
function esc_url(string $v): string { return htmlspecialchars($v,ENT_QUOTES); }
function wp_nonce_field(string $a,string $n): void { echo '<input name="'.esc_attr($n).'" value="synthetic-nonce">'; }
function disabled(bool $value): void { if($value) echo 'disabled'; }
function wp_date(string $format,int $time,DateTimeZone $zone): string { return (new DateTimeImmutable('@'.$time))->setTimezone($zone)->format($format); }
$lazada_connections=$tokens->safe_connections(); ob_start(); require __DIR__.'/../ecomkit-vuikhoe/admin/views/lazada-order-diagnostic.php'; $html=ob_get_clean();
auth_check(str_contains($html,'type="date"') && !str_contains($html,'datetime-local') && !str_contains($html,'excel_order_id') && substr_count($html,'type="submit"')===1 && !str_contains($html,'<details open'), 'Simplified UI contract failed.');
auth_check(preg_match('/<details><summary>[^<]+<\/summary><p><label>Offset/s',$html)===1 && preg_match('/<details><summary>[^<]+<\/summary><pre data-lazada-output/s',$html)===1 && !str_contains($html,'name="to"'),'Advanced controls are not collapsed');
auth_check(!str_contains($html,'fake-access') && !str_contains($html,'fake-refresh') && !str_contains($html,'test-app-secret'),'Rendered secrets.');
echo "WP.6J.3B.1 Lazada diagnostic: PASS (zero real calls, no payload persistence)\n";
