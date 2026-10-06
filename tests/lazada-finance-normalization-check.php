<?php
/** Synthetic account response only; no real provider calls or PII. */
declare(strict_types=1);
require __DIR__.'/lazada-finance-discovery-check.php';
$normalization_calls=0;
$normalization_transport=static function(string $url,array $args) use(&$normalization_calls,$config): array {
 ++$normalization_calls;$p=$args['body'];auth_check($args['method']==='POST' && $url==='https://api.lazada.vn/rest'.Ecomkit_Vuikhoe_Lazada_Finance_Client::ACCOUNT && $p['sign']===Ecomkit_Vuikhoe_Lazada_Signer::sign(Ecomkit_Vuikhoe_Lazada_Finance_Client::ACCOUNT,$p,$config->credentials()['app_secret']),'Normalization changed transport/signing');
 return ['status'=>200,'body'=>'{"code":0,"success":true,"request_id":"safe-normalization","data":{"page_info":{"page_num":1,"page_size":100,"total_page":1},"transactions":[
 {"amount":"12345.67","type":"Payment","pmt_reference":"987654321098765432109876543210","unknown_extra":"DO-NOT-EXPOSE"},
 {"amount":12345,"sub_type":null,"transaction_number":987654321098765432109876543210,"payee_account":{"account":"DO-NOT-EXPOSE"}},
 {"amount":123.45678901234567890123,"transaction_time":null,"tracking_list":[{"remark":"DO-NOT-EXPOSE"}]},
 {"amount":-1000,"type":"Reversal"}, {"amount":0}, {"amount":"-1000.00"}, {"amount":null}, {},
 {"amount":"bad-money-DO-NOT-EXPOSE"}, {"amount":false}, {"amount":{"private":"DO-NOT-EXPOSE"}},
 {"amount":1.25e3,"pmt_reference":12345}, {"amount":"0.00","type":{"private":"DO-NOT-EXPOSE"}}, []
 ]}}'];
};
$nclient=new Ecomkit_Vuikhoe_Lazada_Finance_Client($id,$tokens,$config,$normalization_transport);
$ndiagnostic=new Ecomkit_Vuikhoe_Lazada_Finance_Diagnostic($tokens,static fn(int $c)=>$nclient);
$snapshot=[$db->rows,$GLOBALS['options'],$GLOBALS['transients']];
$r=$ndiagnostic->run(['connection_id'=>(string)$id,'audit_mode'=>'scan','order_id'=>'','start_date'=>'2026-09-01','end_date'=>'2026-10-06','page_num'=>'1']);$check=$r['checks']['transactions'];$d=$check['data'];
auth_check($check['success'] && $d['provider_success'] && $d['diagnostic']['http_status']===200 && $d['diagnostic']['provider_code']==='0' && $d['diagnostic']['response_container']==='data.transactions[]','Successful provider misclassified');
auth_check($d['page_count']===14 && $d['normalized_count']===10 && $d['normalization_error_count']===4 && $normalization_calls===1,'Record failure erased page or retried');
$rows=array_column($d['records'],null,'transaction_index');
auth_check($rows[0]['amount']==='12345.67' && $rows[1]['amount']==='12345' && $rows[2]['amount']==='123.45678901234567890123' && $rows[3]['amount']==='-1000' && $rows[4]['amount']==='0' && $rows[5]['amount']==='-1000.00' && $rows[11]['amount']==='1250','Exact numeric lexemes/signs/exponent');
auth_check($rows[0]['pmt_reference']===$large && $rows[1]['transaction_number']===$large && $rows[11]['pmt_reference']==='12345','Exact string/numeric references');
auth_check($rows[6]['amount']===null && $rows[7]['amount']===null && $rows[12]['type']===null && count($d['normalization_warnings'])===1,'Optional fields rejected');
foreach($d['normalization_errors'] as $i=>$e){auth_check($e['classification']==='LAZADA_FINANCE_NORMALIZATION_ERROR' && $e['transaction_index']===[8,9,10,13][$i] && $e['observed_type']===['string','bool','object','array'][$i] && $e['field']===($i===3?'$record':'amount'),'Exact structural failure evidence');}
auth_check($d['field_inventory'][0]['fields'][0]['observed_type']==='string' && $d['field_inventory'][1]['fields'][0]['observed_type']==='number' && $d['field_inventory'][2]['fields'][0]['observed_type']==='number','Original JSON types lost');
auth_check(!str_contains(json_encode($r),'DO-NOT-EXPOSE') && $snapshot===[$db->rows,$GLOBALS['options'],$GLOBALS['transients']] && !$r['canonical_write'] && !$r['finance_persistence'],'Unknown sensitive value exposed or persisted');
echo "WP.6J.5.4 normalization: PASS (exact numeric lexemes, original types, nullable/unknown fields, partial errors, no retries/writes; zero real calls)\n";
