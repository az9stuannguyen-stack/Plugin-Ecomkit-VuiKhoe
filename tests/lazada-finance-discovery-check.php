<?php
/** Explicit scan only, real Finance client + diagnostic + exact DB lookup, fake transport/DB. */
declare(strict_types=1);
require __DIR__.'/lazada-finance-check.php';
$db->rows[$id]['status']='ACTIVE';$mode='success';
$original=$GLOBALS['wpdb'];
$GLOBALS['wpdb']=new class($original,$large,$id) {
 public string $prefix='test_';public int $lookups=0;
 public function __construct(private object $original,private string $large,private int $connection){}
 public function __call(string $method,array $args): mixed {return $this->original->$method(...$args);}
 public function get_results(string $q,mixed $type): array {
  [$sql,$args]=json_decode($q,true);if(!str_contains($sql,'ecomkit_orders'))return $this->original->get_results($q,$type);
  ++$this->lookups;auth_check(str_contains($sql,'BINARY marketplace_order_id = %s') && $args[0]==='LAZADA' && $args[1]===$this->connection && is_string($args[2]),'Lookup must be exact/platform/shop scoped');
  return [
   ['id'=>1,'batch_id'=>31,'platform'=>'LAZADA','connection_id'=>$this->connection,'marketplace_order_id'=>$this->large],
   ['id'=>2,'batch_id'=>40,'platform'=>'LAZADA','connection_id'=>$this->connection,'marketplace_order_id'=>$this->large],
   ['id'=>3,'batch_id'=>31,'platform'=>'SHOPEE','connection_id'=>$this->connection,'marketplace_order_id'=>$this->large],
   ['id'=>4,'batch_id'=>31,'platform'=>'LAZADA','connection_id'=>$this->connection+1,'marketplace_order_id'=>$this->large],
   ['id'=>5,'batch_id'=>31,'platform'=>'LAZADA','connection_id'=>$this->connection,'marketplace_order_id'=>$this->large.'0']
  ];
 }
};
$account_calls=[];$account_mode='success';
$account_transport=static function(string $url,array $args) use(&$account_calls,&$account_mode,$config): array {
 auth_check($url==='https://api.lazada.vn/rest/finance/transaction/accountTransactions/query' && $args['method']==='POST' && $args['sslverify'] && $args['timeout']===20,'Account POST transport');
 $p=$args['body'];auth_check($p['sign']===Ecomkit_Vuikhoe_Lazada_Signer::sign(Ecomkit_Vuikhoe_Lazada_Finance_Client::ACCOUNT,$p,$config->credentials()['app_secret']),'Account signing');
 auth_check($p['start_time']==='20260901' && $p['end_time']==='20261006' && $p['page_size']==='100' && !isset($p['offset'],$p['limit'],$p['trade_order_id'],$p['trans_type']),'Account official parameters');$account_calls[]=$p;
 if($account_mode==='provider')return ['status'=>200,'body'=>json_encode(['success'=>false,'error_code'=>'IllegalAccessToken','msg'=>$p['access_token'].' sign='.$p['sign'],'request_id'=>'safe-account'])];
 if($account_mode==='shape')return ['status'=>200,'body'=>json_encode(['success'=>true,'data'=>['transactions'=>new stdClass()],'request_id'=>'safe-account'])];
 $rows=[];if($account_mode!=='empty')foreach(['12345678901234567890.12345678901234567890','-0.6200','0.00'] as $i=>$amount)$rows[]=['type'=>'Payment','sub_type'=>'Settlement','amount'=>$amount,'currency'=>'VND','pmt_reference'=>'987654321098765432109876543210','transaction_number'=>'SYNTH-'.$i,'transaction_time'=>'2026-10-03','payee_account'=>['account'=>'DO-NOT-EXPOSE'],'remarks'=>'DO-NOT-EXPOSE'];
 return ['status'=>200,'body'=>json_encode(['success'=>true,'error_code'=>'','msg'=>null,'request_id'=>'safe-account','data'=>['page_info'=>['page_num'=>$p['page_num'],'page_size'=>100,'total_page'=>2,'total_count'=>6],'transactions'=>$rows]])];
};
$account=new Ecomkit_Vuikhoe_Lazada_Finance_Client($id,$tokens,$config,$account_transport);
$diagnostic=new Ecomkit_Vuikhoe_Lazada_Finance_Diagnostic($tokens,static fn(int $c)=>$account);
$input=['connection_id'=>(string)$id,'audit_mode'=>'scan','order_id'=>'','start_date'=>'2026-09-01','end_date'=>'2026-10-06','page_num'=>'1','check_order'=>'1'];
$snapshot=[$db->rows,$GLOBALS['options'],$GLOBALS['transients']];$r=$diagnostic->run($input);$d=$r['checks']['transactions']['data'];
auth_check(count($account_calls)===1 && $d['next_page']===2 && $d['page_num']===1 && $d['diagnostic']['response_container']==='data.transactions[]','Account paging/shape');
auth_check($d['records'][0]['amount']==='12345678901234567890.12345678901234567890' && $d['records'][1]['amount']==='-0.6200' && $d['records'][2]['amount']==='0.00','Exact account money');
auth_check($d['records'][0]['pmt_reference']===$large && $d['records'][0]['order_no']===null && $GLOBALS['wpdb']->lookups===0,'Account reference is not order ID');
auth_check($r['distinct_names'][0]['frequency']===3 && $r['distinct_names'][0]['positive']===1 && $r['distinct_names'][0]['negative']===1 && $r['distinct_names'][0]['zero']===1,'Account type/sign summary');
auth_check(!str_contains(json_encode($r),'DO-NOT-EXPOSE') && !$r['canonical_write'] && !$r['finance_persistence'] && $snapshot===[$db->rows,$GLOBALS['options'],$GLOBALS['transients']],'Account no writes/secrets');
$input['page_num']='2';$r=$diagnostic->run($input);auth_check(count($account_calls)===2 && $r['checks']['transactions']['data']['next_page']===null,'Explicit account next page');
foreach(['provider','shape'] as $m){$account_mode=$m;$r=$diagnostic->run($input);$e=$r['checks']['transactions'];auth_check(!$e['success'] && $e['evidence']['api_path']===Ecomkit_Vuikhoe_Lazada_Finance_Client::ACCOUNT && $e['evidence']['http_status']===200 && $e['evidence']['request_id']==='safe-account','Account failure preserves evidence');auth_check(!str_contains(json_encode($e),'synthetic-secret'),'Account redaction');}
$account_mode='empty';$r=$diagnostic->run($input);auth_check($r['checks']['transactions']['success'] && $r['checks']['transactions']['data']['page_count']===0,'Explicit empty account result');
// Reproduce old blank evidence: post-HTTP decimal failure was a plain RuntimeException.
$mode='bad-money';try{$client->transactions($large,'2026-09-01','2026-10-06',0,100,true);}catch(Ecomkit_Vuikhoe_Lazada_Provider_Exception $e){auth_check($e->diagnostic['api_path']===Ecomkit_Vuikhoe_Lazada_Finance_Client::QUERY_TRANSACTIONS && $e->diagnostic['http_status']===200 && $e->diagnostic['request_id']==='safe-finance-request','Normalizer evidence retained');}
$mode='success';$order=new Ecomkit_Vuikhoe_Lazada_Finance_Diagnostic($tokens,static fn(int $c)=>$client);$input['audit_mode']='order';$input['order_id']=$large;$input['endpoint']='details';$input['check_order']='';$r=$order->run($input);
auth_check(end($finance_calls)['business']['trade_order_id']===$large && $r['checks']['transactions']['data']['records'][0]['ecomkit_references']===['Batch #31 / Order #1','Batch #40 / Order #2'],'Order mode exact shop-scoped linkage unchanged');
$GLOBALS['wpdb']=$original;
echo "WP.6J.5.3 account scan: PASS (official POST/page/date/envelope, exact money, no invented order link, safe failures, no writes; zero real calls)\n";
