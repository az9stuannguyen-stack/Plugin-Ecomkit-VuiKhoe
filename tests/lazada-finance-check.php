<?php
/** Fake Finance HTTP only; real signer, lifecycle, lossless decoder, admin AJAX and projection. */
declare(strict_types=1);
ob_start();
require __DIR__ . '/lazada-oauth-check.php';
define('ECOMKIT_VUIKHOE_VERSION','0.7.28');
$db=$GLOBALS['wpdb'];
$contracts=json_decode(file_get_contents(__DIR__.'/../docs/lazada-finance-contract.json'),true);
auth_check(count($contracts)===3 && $contracts[0]['path']===Ecomkit_Vuikhoe_Lazada_Finance_Client::TRANSACTIONS && $contracts[1]['path']===Ecomkit_Vuikhoe_Lazada_Finance_Client::QUERY_TRANSACTIONS && $contracts[2]['path']===Ecomkit_Vuikhoe_Lazada_Finance_Client::PAYOUT,'Official contract paths');
foreach($contracts as $contract){auth_check($contract['method']==='get' && $contract['response_list']==='data','Official Finance method/list');}
auth_check(in_array('fee_type',$contracts[1]['response_fields'],true) && in_array('order_no',$contracts[0]['response_fields'],true) && in_array('paid',$contracts[2]['response_fields'],true),'Documented fields');
$GLOBALS['reply']=$fixture; $tokens->authorize('finance-fixture');
$large='987654321098765432109876543210'; $finance_calls=[]; $mode='success';
$record=['order_no'=>$large,'orderItem_no'=>'501','reference'=>'501','amount'=>'12345678901234567890.12345678901234567890','fee_type'=>'13','fee_name'=>'Synthetic Credit','transaction_type'=>'Synthetic Item Credit','transaction_number'=>'SYNTH-1','transaction_date'=>'2026-10-03','statement'=>'SYNTH-STATEMENT','paid_status'=>'Not paid','currency'=>'VND','VAT_in_amount'=>'0.01','WHT_amount'=>'0.00','buyer_name'=>'DO-NOT-EXPOSE','details'=>'DO-NOT-EXPOSE','comment'=>'DO-NOT-EXPOSE','access_token'=>'DO-NOT-EXPOSE'];
$finance_transport=static function(string $url,array $options) use(&$finance_calls,&$mode,$record,$config): mixed {
 parse_str((string)parse_url($url,PHP_URL_QUERY),$p);$path=substr((string)parse_url($url,PHP_URL_PATH),5);
 auth_check(parse_url($url,PHP_URL_HOST)==='api.lazada.vn' && parse_url($url,PHP_URL_SCHEME)==='https','Finance base');
 auth_check($options['method']==='GET' && !isset($options['body']) && $options['timeout']===20 && $options['sslverify'] && $options['redirection']===0 && $options['limit_response_size']===2097152,'Finance transport');
 auth_check($p['sign']===Ecomkit_Vuikhoe_Lazada_Signer::sign($path,$p,$config->credentials()['app_secret']) && !isset($p['app_secret']) && $p['sign_method']==='sha256','Finance signed/sent values');
 $finance_calls[]=['path'=>$path,'business'=>array_diff_key($p,array_flip(['app_key','access_token','timestamp','sign_method','sign']))];
 if($mode==='network')return new AuthNetworkError();
 if($mode==='throw')throw new RuntimeException($url);
 if($mode==='invalid')return ['status'=>200,'body'=>'<html>DO-NOT-EXPOSE</html>'];
 if($mode==='provider' || $mode==='http')return ['status'=>$mode==='http'?500:200,'body'=>json_encode(['code'=>'15','message'=>$p['access_token'].' '.$p['sign'].' '.$config->credentials()['app_secret'].' refresh_token=private','request_id'=>'safe-finance-request'])];
 if($path===Ecomkit_Vuikhoe_Lazada_Finance_Client::PAYOUT)return ['status'=>200,'body'=>json_encode(['code'=>'0','request_id'=>'safe-payout','data'=>[['payout'=>'3962.4100 VND','paid'=>'1','statement_number'=>'SYNTH-STATEMENT','refunds'=>'-12.500','closing_balance'=>'3962.4100','buyer_phone'=>'DO-NOT-EXPOSE']]])];
 $rows=[$record,array_merge($record,['orderItem_no'=>'502','reference'=>'502','amount'=>'-0.6200','fee_name'=>'Synthetic Reversal','transaction_type'=>'Synthetic Reversal','transaction_number'=>'SYNTH-2']),array_merge($record,['orderItem_no'=>null,'reference'=>null,'amount'=>'0.00','transaction_number'=>'SYNTH-3','fee_name'=>'Synthetic Order Adjustment']),array_merge($record,['order_no'=>'123456789','amount'=>'55'])];
 if($mode==='empty')$rows=[];
 if($mode==='pages')$rows=$p['offset']==='0'?array_slice($rows,0,2):array_slice($rows,2,1);
 if($mode==='precision')return ['status'=>200,'body'=>'{"code":0,"data":[{"order_no":987654321098765432109876543210,"amount":-12345678901234567890.12345678901234567890}],"request_id":"precision"}'];
 if($mode==='bad-money')$rows[0]['amount']='1e4';
 if($mode==='shape')return ['status'=>200,'body'=>'{"code":"0","data":{}}'];
 return ['status'=>200,'body'=>json_encode(['code'=>'0','request_id'=>'safe-finance-request','data'=>$rows])];
};
$client=new Ecomkit_Vuikhoe_Lazada_Finance_Client($id,$tokens,$config,$finance_transport);
$snapshot=[$db->rows,$GLOBALS['options'],$GLOBALS['transients']];
$r=$client->transactions($large,'2026-10-01','2026-10-06');
auth_check(count($r['records'])===3 && $r['page_count']===4 && $r['records'][0]['amount']===$record['amount'] && $r['records'][1]['amount']==='-0.6200' && $r['records'][2]['amount']==='0.00','Money/sign/multiple fees');
auth_check($r['records'][0]['orderItem_no']==='501' && $r['records'][1]['orderItem_no']==='502' && $r['records'][2]['linkage_scope']==='ORDER_REFERENCE_ONLY','Multi-item/order-level scope');
auth_check(!str_contains(json_encode($r),'DO-NOT-EXPOSE') && !str_contains(json_encode($r),'access_token'),'PII/secret whitelist');
auth_check(!isset(end($finance_calls)['business']['trade_order_id']),'Singular invented order filter');
$client->transactions($large,'2026-10-01','2026-10-06',0,100,true);auth_check(end($finance_calls)['business']['trade_order_id']===$large && end($finance_calls)['path']===Ecomkit_Vuikhoe_Lazada_Finance_Client::QUERY_TRANSACTIONS,'Query exact string order filter');
$mode='pages';$r=$client->transactions($large,'2026-10-01','2026-10-06',0,2);auth_check($r['next_offset']===2 && $r['coverage']==='PAGE_ONLY_NOT_COMPLETE','Pagination first');$r=$client->transactions($large,'2026-10-01','2026-10-06',2,2);auth_check($r['next_offset']===null && $r['coverage']==='PAGE_ONLY_NOT_COMPLETE','Pagination terminal partial view');
$mode='precision';$r=$client->transactions($large,'2026-10-01','2026-10-06');auth_check($r['records'][0]['order_no']===$large && $r['records'][0]['amount']==='-12345678901234567890.12345678901234567890' && $r['records'][0]['currency']===null,'Unquoted money/ID precision/currency assumption');
foreach(['provider'=>'LAZADA_FINANCE_PROVIDER_ERROR','http'=>'LAZADA_FINANCE_HTTP_ERROR','network'=>'LAZADA_FINANCE_NETWORK_ERROR','throw'=>'LAZADA_FINANCE_NETWORK_ERROR','invalid'=>'LAZADA_FINANCE_INVALID_RESPONSE','bad-money'=>'LAZADA_FINANCE_INVALID_RESPONSE','shape'=>'LAZADA_FINANCE_INVALID_RESPONSE'] as $m=>$code){$mode=$m;try{$client->transactions($large,'2026-10-01','2026-10-06');throw new RuntimeException('No failure');}catch(Throwable $e){auth_check($e->getMessage()===$code,'Finance error classification '.$m);if($e instanceof Ecomkit_Vuikhoe_Lazada_Provider_Exception){$safe=json_encode($e->diagnostic);auth_check(!str_contains($safe,'private') && !str_contains($safe,'api.lazada.vn/rest') && !str_contains($safe,'synthetic-secret'),'Error redaction');if(in_array($m,['provider','http'],true))auth_check($e->diagnostic['provider_code']==='15' && $e->diagnostic['request_id']==='safe-finance-request','Provider JSON error preserved');}}}
$mode='success';$p=$client->payouts('2026-10-01');auth_check($p['statements'][0]['paid']==='1' && $p['statements'][0]['payout_amount']==='3962.4100' && $p['statements'][0]['payout_currency']==='VND' && $p['order_payout_link']==='UNKNOWN' && !str_contains(json_encode($p),'DO-NOT-EXPOSE'),'Statement payout');
$before=count($finance_calls);expect_auth_error('LAZADA_FINANCE_INPUT_INVALID',fn()=>$client->transactions($large,'2026-01-01','2026-07-01'));expect_auth_error('LAZADA_FINANCE_INPUT_INVALID',fn()=>$client->transactions($large,'2026-10-06','2026-10-01'));expect_auth_error('LAZADA_FINANCE_INPUT_INVALID',fn()=>$client->transactions($large,'2026-02-30','2026-10-01'));expect_auth_error('LAZADA_FINANCE_INPUT_INVALID',fn()=>$client->transactions($large,'2026-10-01','2026-10-06',0,501));auth_check(count($finance_calls)===$before,'Invalid input sent request');
function check_ajax_referer(string $a,string $k,bool $stop=true): int|false {auth_check($a==='ecomkit_lazada_finance_diagnostic' && $k==='nonce' && !$stop,'Finance nonce');return $GLOBALS['finance_nonce']??false;}
function nocache_headers(): void {}
function wp_unslash(array $v): array {return $v;}
final class FinanceJson extends RuntimeException {public function __construct(public array $body,public int $status){parent::__construct('JSON');}}
function wp_send_json_error(array $v,int $status=200): never {throw new FinanceJson(['success'=>false,'data'=>$v],$status);}
function wp_send_json_success(array $v): never {throw new FinanceJson(['success'=>true,'data'=>$v],200);}
$order_reads=0;
$order_factory=static function(int $c) use(&$order_reads,$large): object { return new class($order_reads,$large) {
 public function __construct(private mixed &$reads,private string $id){}
 public function get_order(string $id): array {++$this->reads;auth_check($id===$this->id,'Candidate order ID');return ['providerOrderId'=>$id,'rawStatuses'=>['shipped'],'price'=>'312000.0100','voucher'=>'5.00','shippingFee'=>'0','addressShipping'=>['buyer'=>'DO-NOT-EXPOSE']];}
 public function get_order_items(string $id): array {++$this->reads;return [['providerOrderId'=>$id,'orderItemId'=>'501','status'=>'shipped','itemPrice'=>'312000.0100','paidPrice'=>'311995.0100','name'=>'DO-NOT-EXPOSE'],['providerOrderId'=>$id,'orderItemId'=>'502','status'=>'confirmed','itemPrice'=>'1.00','paidPrice'=>'1.00']];}
 public function last_evidence(): array {return ['diagnostic'=>['api_path'=>'/order/get','request_id'=>'synthetic-order']];}
 };};
$diagnostic=new Ecomkit_Vuikhoe_Lazada_Finance_Diagnostic($tokens,static fn(int $c)=>$client,$order_factory);
$diagnostic->register();auth_check(isset($GLOBALS['hooks']['wp_ajax_ecomkit_lazada_finance_diagnostic']) && !isset($GLOBALS['hooks']['wp_ajax_nopriv_ecomkit_lazada_finance_diagnostic']),'Finance hook');
$input=['connection_id'=>(string)$id,'order_id'=>$large,'start_date'=>'2026-10-01','end_date'=>'2026-10-06','offset'=>'0','endpoint'=>'details','check_payout'=>'1'];
$r=$diagnostic->run($input);auth_check($r['checks']['transactions']['success'] && !$r['canonical_write'] && !$r['finance_persistence'] && count($r['distinct_names'])===3 && $r['distinct_names'][1]['negative']===1 && $r['distinct_names'][2]['zero']===1,'Safe source audit names/signs');
auth_check($snapshot===[$db->rows,$GLOBALS['options'],$GLOBALS['transients']],'Audit persisted/canonical state');
$compare=$input;$compare['check_order']='1';$r=$diagnostic->run($compare);auth_check($order_reads===2 && count($r['checks']['item_candidates']['data'])===2 && $r['checks']['order_candidates']['data']['raw_statuses']===['shipped'] && !str_contains(json_encode($r),'DO-NOT-EXPOSE'),'Price/items/PII candidate projection');
$callback=$GLOBALS['hooks']['wp_ajax_ecomkit_lazada_finance_diagnostic'];$before=count($finance_calls);
$GLOBALS['authorized']=false;expect_auth_error('DENIED_403',fn()=>$diagnostic->run($input));try{$callback();}catch(FinanceJson $j){$t=$j->body['data']['transport'];auth_check($j->status===403 && $t['handler_reached'] && !$t['permission_passed'] && !$t['nonce_passed'] && !$t['finance_client_invoked'],'Finance permission');}$GLOBALS['authorized']=true;
try{$callback();}catch(FinanceJson $j){$t=$j->body['data']['transport'];auth_check($j->status===403 && $j->body['data']['classification']==='WORDPRESS_NONCE_INVALID' && $t['handler_reached'] && $t['permission_passed'] && !$t['nonce_passed'] && !$t['finance_client_invoked'],'Finance nonce missing');}
auth_check(count($finance_calls)===$before,'Denied route called Finance provider');
$GLOBALS['finance_nonce']=1;$_POST=$input;try{$callback();}catch(FinanceJson $j){$t=$j->body['data']['transport'];auth_check($j->body['success'] && $j->body['data']['order_id']===$large && $t['handler_reached'] && $t['permission_passed'] && $t['nonce_passed'] && $t['finance_client_invoked'] && $t['plugin_version']==='0.7.28','Finance JSON success');}
$mode='provider';try{$callback();}catch(FinanceJson $j){auth_check($j->body['success'] && !$j->body['data']['checks']['transactions']['success'] && $j->body['data']['transport']['finance_client_invoked'] && $j->body['data']['checks']['transactions']['evidence']['api_path']===Ecomkit_Vuikhoe_Lazada_Finance_Client::QUERY_TRANSACTIONS,'Structured provider failure');}
$mode='empty';$r=$diagnostic->run($input);auth_check($r['checks']['transactions']['data']['matched_count']===0 && $r['canonical_confidence']==='UNKNOWN','Empty is not zero fees');
$db->rows[$id]['status']='SUSPENDED';$before=count($finance_calls);expect_auth_error('LAZADA_FINANCE_AUTH_REQUIRED',fn()=>$client->transactions($large,'2026-10-01','2026-10-06'));auth_check(count($finance_calls)===$before,'Auth sent finance request');
$view=file_get_contents(__DIR__.'/../ecomkit-vuikhoe/admin/views/lazada-finance-diagnostic.php');auth_check(str_contains($view,'<details ') && !str_contains($view,'<details open') && str_contains($view,"admin_url( 'admin-ajax.php' )") && str_contains($view,'type="text" name="order_id"') && str_contains($view,'ECOMKIT_VUIKHOE_VERSION'),'Finance UI/version/ID');
auth_check(str_contains($view,'name="action" value="ecomkit_lazada_finance_diagnostic"') && isset($GLOBALS['hooks']['wp_ajax_ecomkit_lazada_finance_diagnostic']),'Frontend/backend action mismatch');
$client_source=file_get_contents(__DIR__.'/../ecomkit-vuikhoe/includes/class-ecomkit-lazada-finance-client.php');auth_check(!str_contains($client_source,'$wpdb') && !str_contains($client_source,'error_log(') && !str_contains($client_source,'(float)'),'No finance writes/logs/float');
auth_check(str_contains(file_get_contents(__DIR__.'/../ecomkit-vuikhoe/ecomkit-vuikhoe.php'), "define( 'ECOMKIT_VUIKHOE_DB_VERSION', 9 )"),'Finance migrated DB');
echo "WP.6J.5 Finance source audit: PASS (GET/signing, precision, signs, scopes, pages, payout, failures, AJAX security, no writes; zero real provider calls)\n";

ob_end_flush();
