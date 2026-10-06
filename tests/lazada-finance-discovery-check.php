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
$diagnostic=new Ecomkit_Vuikhoe_Lazada_Finance_Diagnostic($tokens,static fn(int $c)=>$client);
$input=['connection_id'=>(string)$id,'audit_mode'=>'scan','order_id'=>'','start_date'=>'2026-09-01','end_date'=>'2026-10-06','offset'=>'0','endpoint'=>'detail','check_order'=>'1'];
$snapshot=[$db->rows,$GLOBALS['options'],$GLOBALS['transients']];$before=count($finance_calls);
$r=$diagnostic->run($input);$rows=$r['checks']['transactions']['data']['records'];$call=end($finance_calls);
auth_check($r['audit_mode']==='scan' && $r['order_id']==='' && count($finance_calls)===$before+1 && !isset($r['checks']['order_candidates']),'Scan blank ID triggered Order reads');
auth_check($call['path']===Ecomkit_Vuikhoe_Lazada_Finance_Client::QUERY_TRANSACTIONS && !isset($call['business']['trade_order_id'],$call['business']['trade_order_line_id']) && $call['business']['start_time']==='2026-09-01' && $call['business']['end_time']==='2026-10-06' && $call['business']['limit']==='100','Scan API/window/no filter');
auth_check(count($rows)===4 && $rows[0]['order_no']===$large && $rows[0]['orderItem_no']==='501' && $rows[1]['orderItem_no']==='502' && $rows[1]['amount']==='-0.6200' && $rows[2]['amount']==='0.00','Scan discarded rows or changed precision/sign/item identity');
auth_check($rows[0]['ecomkit_references']===['Batch #31 / Order #1','Batch #40 / Order #2'] && $rows[3]['ecomkit_references']===[] && $GLOBALS['wpdb']->lookups===2,'Exact lookup/cross-batch/cache/fuzzy/shop regression');
auth_check($rows[0]['ecomkit_presence']==='Có trong Ecomkit' && $rows[3]['ecomkit_presence']==='Chưa có trong Batch đang biết','Discovery labels');
auth_check($r['distinct_names'][0]['frequency']===2 && $r['distinct_names'][0]['positive']===2 && $r['distinct_names'][1]['negative']===1 && $r['distinct_names'][2]['zero']===1,'Scan distinct/sign frequency');
auth_check($snapshot===[$db->rows,$GLOBALS['options'],$GLOBALS['transients']] && !$r['canonical_write'] && !$r['finance_persistence'] && !str_contains(json_encode($r),'DO-NOT-EXPOSE'),'Scan writes/secret/PII');
$mode='empty';$r=$diagnostic->run($input);auth_check($r['checks']['transactions']['data']['page_count']===0 && $r['checks']['transactions']['data']['next_offset']===null && $r['canonical_confidence']==='UNKNOWN','Empty scan incorrect');
$mode='success';$paged_transport=static function(string $url,array $args) use($finance_transport,$record): mixed {
 $r=$finance_transport($url,$args);parse_str((string)parse_url($url,PHP_URL_QUERY),$p);$rows=[];
 for($i=0;$i<($p['offset']==='0'?100:1);$i++)$rows[]=array_merge($record,['transaction_number'=>'PAGE-'.$p['offset'].'-'.$i]);
 $r['body']=json_encode(['code'=>'0','request_id'=>'page','data'=>$rows]);return $r;
};
$paged_client=new Ecomkit_Vuikhoe_Lazada_Finance_Client($id,$tokens,$config,$paged_transport);
$paged=new Ecomkit_Vuikhoe_Lazada_Finance_Diagnostic($tokens,static fn(int $c)=>$paged_client);
$before=count($finance_calls);$r=$paged->run($input);auth_check(count($finance_calls)===$before+1 && $r['checks']['transactions']['data']['next_offset']===100 && count($r['checks']['transactions']['data']['records'])===100,'Scan auto-crawled or full page missing');
$input['offset']='100';$r=$paged->run($input);auth_check(count($finance_calls)===$before+2 && end($finance_calls)['business']['offset']==='100' && $r['checks']['transactions']['data']['next_offset']===null,'Explicit next page');
$input['audit_mode']='order';$input['order_id']=$large;$input['endpoint']='details';$input['check_order']='';$paged->run($input);auth_check(end($finance_calls)['business']['trade_order_id']===$large,'Order mode lost exact filter');
$GLOBALS['wpdb']=$original;
echo "WP.6J.5.2 discovery: PASS (blank scan, exact lookup/shop/multi-batch, signs, names, explicit pages, no writes; zero real calls)\n";
