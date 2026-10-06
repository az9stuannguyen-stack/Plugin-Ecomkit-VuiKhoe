<?php
/** Actual Result template + POST controller, fake WordPress/DB/provider only. */
declare(strict_types=1);
define('ECOMKIT_CREDENTIAL_KEY',base64_encode(str_repeat('k',32)));
require __DIR__.'/lazada-reconciliation-check.php';
function __(string $s,string $domain): string { return $s; }
function selected(mixed $a,mixed $b): void { if($a==$b) echo 'selected'; }
function disabled(bool $v): void { if($v) echo 'disabled'; }
function submit_button(string $s,mixed ...$args): void { echo '<button>'.esc_html($s).'</button>'; }
function wp_create_nonce(string $s): string { return 'synthetic-nonce'; }
function wp_max_upload_size(): int { return 1000000; }
function wp_timezone(): DateTimeZone { return new DateTimeZone('Asia/Ho_Chi_Minh'); }
function wp_date(string $format,int $time,DateTimeZone $tz): string { return (new DateTimeImmutable('@'.$time))->setTimezone($tz)->format($format); }
function wp_unslash(mixed $v): mixed { return $v; }
function absint(mixed $v): int { return abs((int)$v); }
final class ControlRedirect extends RuntimeException { public function __construct(public string $url){parent::__construct('REDIRECT');} }
function wp_safe_redirect(string $url): never { throw new ControlRedirect($url); }
function control_html(int $batch_id): string {
	global $db,$service;
	$batch=$db->batches[$batch_id]; $total=0;$lazada=0;$shopee=0;
	foreach($db->orders as $o){if($o['batch_id']===$batch_id){$total++;if($o['platform']==='LAZADA')$lazada++;if($o['platform']==='SHOPEE')$shopee++;}}
	$data=['batch_id'=>$batch_id,'batches'=>[$batch],'result'=>['batch'=>$batch,'reconciliation'=>null,'counts'=>['total'=>$total,'SHOPEE'=>$shopee,'LAZADA'=>$lazada,'MATCHED'=>0,'NOT_FOUND_IN_SHOPEE'=>0,'DETAIL_MISSING'=>0,'ready'=>0,'warnings'=>0],'rows'=>[],'columns'=>Ecomkit_Vuikhoe_Canonical_Columns::all()],'pipeline'=>null,'progress'=>null,'platform_filter'=>'','matching_filter'=>'','payment_orders'=>[],'lazada_connections'=>array_merge($service->active_connections(),[['id'=>999,'shop'=>'DO-NOT-RENDER','status'=>'SUSPENDED','lifecycle'=>'READY']]),'lazada_evidence'=>$service->batch_evidence($batch_id),'lazada_return_page'=>'ecomkit-vuikhoe-results'];
	ob_start();require __DIR__.'/../ecomkit-vuikhoe/admin/views/results.php';return ob_get_clean();
}
recon_batch(31,[]);$db->batches[31]['created_at']='2026-10-03 00:00:00';
$spec=[];for($i=1;$i<=7;$i++)$spec[3100+$i]=['LAZADA',$i===1?'532935709720247':(string)(900000+$i)];for($i=8;$i<=50;$i++)$spec[3100+$i]=['SHOPEE','KEEP-SHP-'.$i];recon_batch(31,$spec);$db->batches[31]['created_at']='2026-10-03 00:00:00';
$db->rows[$id]['external_shop_id']='100070635';$GLOBALS['reply']=$fixture;$GLOBALS['reply']['country_user_info_list'][0]['seller_id']='100070635';$tokens->authorize('synthetic-live-shop');$db->rows[$other]['status']='SUSPENDED';
// Existing fake responses: exact ID, one item for this control fixture only.
$GLOBALS['recon_modes']=[];$GLOBALS['synthetic_lazada_read_transport']=static function(string $url,array $args) use($seller_transport): mixed {
	$r=$seller_transport($url,$args);if(str_contains($url,'/order/items/get?')){ $data=json_decode($r['body'],true);$data['data']=array_slice($data['data'],0,1);$r['body']=json_encode($data); } return $r;
};
$html=control_html(31);auth_check(str_contains($html,'<details ><summary>') && !str_contains($html,'<details open>'),'Advanced tools collapsed initially');auth_check(strpos($html,'Công cụ quản trị nâng cao')<strpos($html,'<h2>Đối chiếu Lazada cho Batch</h2>') && str_contains($html,'>Đối chiếu Lazada</button>') && str_contains($html,'100070635') && !str_contains($html,'DO-NOT-RENDER') && str_contains($html,'name="return_page" value="ecomkit-vuikhoe-results"'),'Result location/control/context');
$before=count($GLOBALS['seller_calls']);$_POST=['batch_id'=>'31','connection_id'=>(string)$id,'return_page'=>'ecomkit-vuikhoe-results','ecomkit_lazada_reconcile_nonce'=>'synthetic-nonce'];
$GLOBALS['synthetic_lazada_control_nonce']=true;
try{(new Ecomkit_Vuikhoe_Admin())->handle_lazada_reconcile_batch();throw new RuntimeException('No redirect');}catch(ControlRedirect $r){auth_check(str_contains($r->url,'page=ecomkit-vuikhoe-results') && str_contains($r->url,'batch_id=31'),'Returned to wrong page');}
$summary=json_decode($db->batches[31]['source_metadata'],true)['lazada_reconciliation'];auth_check($summary['processed']===1 && $summary['matched']===1 && count($GLOBALS['seller_calls'])===$before+2,'Primary controller did not process one checkpoint');
$_GET['lazada_reconcile_notice']='saved';$html=control_html(31);auth_check(str_contains($html,'Còn chờ: 6') && str_contains($html,'Đã xử lý: 1') && str_contains($html,'>Tiếp tục đối chiếu Lazada</button>') && !str_contains($html,'<label>Shop Lazada <select') && str_contains($html,'<details open>'),'Pending/continue/pinned shop/expanded tools');
auth_check(str_contains($html,'<td>532935709720247</td><td>MATCHED</td><td>PASS</td><td>PASS</td>') && str_contains($html,'<td>1</td>') && str_contains($html,'delivered') && str_contains($html,'MASKED') && !str_contains($html,'fake-access'),'Per-order safe evidence');
for($i=0;$i<6;$i++){try{(new Ecomkit_Vuikhoe_Admin())->handle_lazada_reconcile_batch();}catch(ControlRedirect $r){}}
$summary=json_decode($db->batches[31]['source_metadata'],true)['lazada_reconciliation'];$html=control_html(31);auth_check($summary['matched']===7 && str_contains($html,'Còn chờ: 0') && !str_contains($html,'>Tiếp tục đối chiếu Lazada</button>'),'Full continuation');
auth_check($db->orders[3101]['canonical_data']==='KEEP' && count($db->orders)===count(array_unique(array_keys($db->orders))),'Auto materialization/duplicate Order');
$count=count($db->errors);$r=complete_recon(31,$id);auth_check($r['matched']===7 && count($db->errors)===$count && count(json_decode($db->orders[3101]['provider_normalized_data'],true)['items'])===2,'Rerun duplicate evidence');
recon_batch(32,[3201=>['SHOPEE','ONLY-SHOPEE']]);$db->batches[32]['created_at']='2026-10-03 00:00:00';$html=control_html(32);auth_check(!str_contains($html,'<h2>Đối chiếu Lazada cho Batch</h2>'),'No Lazada section should be hidden');
$GLOBALS['authorized']=false;expect_auth_error('DENIED_403',fn()=>(new Ecomkit_Vuikhoe_Admin())->handle_lazada_reconcile_batch());$GLOBALS['authorized']=true;$GLOBALS['synthetic_lazada_control_nonce']=false;expect_auth_error('NONCE_DENIED',fn()=>(new Ecomkit_Vuikhoe_Admin())->handle_lazada_reconcile_batch());
$admin=file_get_contents(__DIR__.'/../ecomkit-vuikhoe/includes/class-ecomkit-admin.php');$handler=substr($admin,strpos($admin,'public function handle_lazada_reconcile_batch'),strpos($admin,'public function handle_shopee_reconcile_batch')-strpos($admin,'public function handle_lazada_reconcile_batch'));auth_check(str_contains($handler,'Lazada_Reconciliation_Service() )->reconcile_batch') && !str_contains($handler,'materialize') && !str_contains($handler,'get_order('),'Handler duplicated business/auto refresh');
echo "WP.6J.4.1 Lazada Batch controls: PASS (actual Result template/controller, one checkpoint, continuation, safe evidence, no automatic materialization; zero real calls)\n";
