<?php
/** Synthetic only: real Lazada client, signer and lifecycle; transactional fake generic DB. */
declare(strict_types=1);
require __DIR__ . '/lazada-order-check.php';
final class LazadaReconDb {
	public string $prefix = 'test_'; public array $rows; public array $orders = array(); public array $batches = array(); public array $errors = array(); public array $items = array();
	public string $last_error = ''; public int $insert_id; public int $fail_order = 0; private ?array $snapshot = null;
	public function __construct( private AuthDb $auth ) { $this->rows =& $auth->rows; $this->insert_id =& $auth->insert_id; }
	public function prepare( string $q, mixed ...$a ): string { return json_encode( array( $q, $a ) ); }
	public function get_var( string $q ): mixed { return $this->auth->get_var( $q ); }
	public function get_row( string $q, mixed $type ): ?array { [$sql,$args] = json_decode( $q, true ); if (str_contains($sql,'ecomkit_orders'))return $this->orders[$args[0]]??null; return str_contains( $sql, 'ecomkit_batches' ) ? ( $this->batches[$args[0]] ?? null ) : $this->auth->get_row( $q, $type ); }
	public function get_results( string $q, mixed $type ): array {
		[$sql,$args] = json_decode( $q, true );
		if ( ! str_contains( $sql, 'ecomkit_orders' ) ) { return $this->auth->get_results( $q, $type ); }
		$rows = array_values( array_filter( $this->orders, static fn( array $r ): bool => $r['batch_id'] === $args[0] && $r['platform'] === $args[1] ) );
		usort( $rows, static fn( array $a, array $b ): int => $a['id'] <=> $b['id'] ); return $rows;
	}
	public function update( string $table, array $data, array $where ): int|false {
		if ( str_ends_with( $table, 'ecomkit_orders' ) ) {
			if ( $where['id'] === $this->fail_order ) { return false; }
			foreach ( $where as $k => $v ) { if ( $this->orders[$where['id']][$k] !== $v ) { return 0; } }
			$new = array_merge( $this->orders[$where['id']], $data );
			foreach ( $this->orders as $r ) { if ( $r['id'] !== $new['id'] && !empty($r['connection_id']) && $r['batch_id'] === $new['batch_id'] && $r['connection_id'] === $new['connection_id'] && $r['marketplace_order_id'] === $new['marketplace_order_id'] ) { $this->last_error = 'Duplicate key'; return false; } }
			$this->orders[$where['id']] = $new; return 1;
		}
		if ( str_ends_with( $table, 'ecomkit_batches' ) ) { $this->batches[$where['id']] = array_merge( $this->batches[$where['id']], $data ); return 1; }
		return $this->auth->update( $table, $data, $where );
	}
	public function insert( string $table, array $data ): int|false { if ( str_ends_with( $table, 'ecomkit_errors' ) ) { $this->errors[] = $data; return 1; } return $this->auth->insert( $table, $data ); }
	public function delete( string $table, array $where ): int|false {
		auth_check(str_ends_with($table,'ecomkit_errors'),'Unexpected delete');
		$this->errors = array_values( array_filter( $this->errors, static function( array $e ) use ( $where ): bool { foreach($where as $k=>$v) { if(($e[$k]??null)!==$v)return true; } return false; } ) ); return 1;
	}
	public function query( string $q ): int|false {
		if($q==='START TRANSACTION') $this->snapshot=[$this->orders,$this->batches,$this->errors,$this->items];
		if($q==='ROLLBACK') { [$this->orders,$this->batches,$this->errors,$this->items]=$this->snapshot; $this->snapshot=null; }
		if($q==='COMMIT') $this->snapshot=null;
		return 1;
	}
}
$GLOBALS['wpdb'] = $db = new LazadaReconDb( $GLOBALS['wpdb'] );
$GLOBALS['reply']=$fixture; $tokens->authorize('reconciliation-ready');
$service = new Ecomkit_Vuikhoe_Lazada_Reconciliation_Service( $tokens, static fn(int $connection) => new Ecomkit_Vuikhoe_Lazada_Order_Client($connection,$tokens,$config,$seller_transport) );
$sample='532935709720247'; $large='987654321098765432109876543210';
function recon_batch(int $batch,array $ids): void {
	global $db; $db->batches[$batch]=['id'=>$batch,'source_type'=>'EXCEL','status'=>'SUCCESS','source_filename'=>'synthetic.xlsx','source_metadata'=>json_encode(['shopee_reconciliation'=>['status'=>'KEEP'],'auto_pipeline'=>['status'=>'KEEP']])];
	foreach($ids as $oid=>$spec){$db->orders[$oid]=['id'=>$oid,'batch_id'=>$batch,'platform'=>$spec[0],'marketplace_order_id'=>$spec[1],'connection_id'=>null,'matching_status'=>null,'provider_normalized_data'=>null,'provider_raw_data'=>null,'source_refs'=>'{"sheet":"Orders","row":4}','raw_source_metadata'=>'{"excel":"KEEP"}','canonical_data'=>'KEEP','order_status'=>null,'fixed_platform_fee'=>null];}
}
function complete_recon(int $batch,int $connection): array { global $service; for($i=0;$i<100;$i++){ $r=$service->reconcile_batch($batch,$connection); if($r['status']!=='PROCESSING') return $r; } throw new RuntimeException('Continuation stalled'); }
$GLOBALS['recon_modes']=[];
$GLOBALS['seller_reply']=static function(string $path,array $p): mixed {
	auth_check($path!=='/orders/get','Reconciliation called list'); $id=$p['order_id']; $mode=$GLOBALS['recon_modes']['id:'.$id]??'match';
	if($mode==='network') return new AuthNetworkError();
	if($mode==='provider') return ['code'=>'15','message'=>'safe denied','request_id'=>'safe-request'];
	if($mode==='invalid') return '{invalid';
	if($path==='/order/get') { if($mode==='missing')return ['code'=>'0','data'=>[]]; $o=synthetic_order(123); $o['order_id']=$mode==='mismatch'?'532935709720248':$id; $o['statuses']=['delivered']; if($mode==='no-pii')unset($o['address_shipping']); return ['code'=>'0','data'=>$o]; }
	if($mode==='items-error')return ['code'=>'15','message'=>'items denied'];
	return ['code'=>'0','data'=>[['order_id'=>$id,'order_item_id'=>'501','sku'=>'SAME','status'=>'delivered','paid_price'=>'12.50'],['order_id'=>$id,'order_item_id'=>'502','sku'=>'SAME','status'=>'delivered','paid_price'=>'12.50']]];
};
recon_batch(100,[1001=>['LAZADA',$sample],1002=>['LAZADA',$large],1003=>['SHOPEE','KEEP-SHOPEE'],1004=>['UNKNOWN','KEEP-UNKNOWN'],1005=>['LAZADA','']]);
$db->items=[['order_id'=>1001,'external_item_id'=>'EXCEL-KEEP']]; $shopee=$db->orders[1003]; $unknown=$db->orders[1004]; $excelitems=$db->items;
$before=count($GLOBALS['seller_calls']); $r=$service->reconcile_batch(100,$id);
auth_check($r['status']==='PROCESSING' && $r['processed']===1 && $r['matched']===1 && $r['last_order_id']===1001,'Bounded checkpoint failed');
$r=$service->reconcile_batch(100,$id);
auth_check($r['status']==='SUCCESS' && $r['matched']===2 && $r['total_lazada']===3 && $r['skipped_blank']===1 && $r['get_order_success']===2 && $r['get_items_success']===2,'Summary eligibility');
auth_check(count($GLOBALS['seller_calls'])===$before+4 && $db->orders[1003]===$shopee && $db->orders[1004]===$unknown && $db->items===$excelitems,'Mixed/blank/Excel items changed');
$stored=json_decode($db->orders[1001]['provider_normalized_data'],true);
auth_check($db->orders[1001]['matching_status']==='MATCHED' && $stored['providerOrderId']===$sample && $stored['order']['rawStatuses']===['delivered'] && $stored['order']['piiAvailability']==='MASKED' && count($stored['items'])===2 && $stored['items'][0]['orderItemId']!==$stored['items'][1]['orderItemId'],'Exact/masked/same SKU');
auth_check(!str_contains(json_encode($stored),'Synthetic Example') && !str_contains(json_encode($stored),'addressShipping') && !str_contains(json_encode($stored),'fake-access') && $stored['order']['price']==='312000.01','PII/secret/safe values');
auth_check(json_decode($db->orders[1002]['provider_normalized_data'],true)['providerOrderId']===$large && $db->orders[1002]['marketplace_order_id']===$large,'Large ID DB/JSON precision');
$r=complete_recon(100,$id); auth_check($r['matched']===2 && count($db->orders)===5 && count($db->errors)===0 && count(json_decode($db->orders[1001]['provider_normalized_data'],true)['items'])===2,'Idempotence');
recon_batch(101,[1011=>['LAZADA',$sample]]); $r=complete_recon(101,$id); auth_check($r['matched']===1 && $db->orders[1001]['connection_id']===$id && $db->orders[1011]['connection_id']===$id,'Cross batch uniqueness');
foreach(['mismatch','missing','network','provider','invalid','items-error','no-pii'] as $i=>$mode){
	$batch=200+$i;$oid=2001+$i;$sampleid=$mode==='mismatch'?$sample:(string)(600000+$i);$GLOBALS['recon_modes']['id:'.$sampleid]=$mode;recon_batch($batch,[$oid=>['LAZADA',$sampleid]]);$r=complete_recon($batch,$id);
	$expected=$mode==='missing'?'NOT_FOUND_IN_LAZADA':($mode==='no-pii'?'MATCHED':'ERROR');auth_check($db->orders[$oid]['matching_status']===$expected,'Failure classified as unmatched: '.$mode);
	if($mode==='items-error'){ $e=json_decode($db->orders[$oid]['provider_normalized_data'],true);auth_check($e['reconciliation']['exact_match'] && $e['items']===null && $r['get_order_success']===1 && $r['get_items_success']===0,'Items partial failure'); }
	if($mode!=='no-pii'){ $count=count($db->errors);complete_recon($batch,$id);auth_check(count($db->errors)===$count,'Duplicate error on rerun'); }
}
$GLOBALS['recon_modes']=[];
recon_batch(300,[3001=>['LAZADA','300001'],3002=>['LAZADA','300002'],3003=>['LAZADA','300003']]);$GLOBALS['recon_modes']['id:300002']='provider';$r=complete_recon(300,$id);auth_check($r['matched']===2 && $r['errors']===1 && $db->orders[3001]['matching_status']==='MATCHED' && $db->orders[3003]['matching_status']==='MATCHED','Partial failure corrupted matches');
// Token reauthorization stops before seller transport and records ERROR, not unmatched.
$meta=json_decode($db->rows[$id]['metadata'],true);$meta['credential_lifecycle']='REAUTH_REQUIRED';$db->rows[$id]['metadata']=json_encode($meta);
recon_batch(400,[4001=>['LAZADA','400001']]);$before=count($GLOBALS['seller_calls']);$r=complete_recon(400,$id);auth_check($r['errors']===1 && count($GLOBALS['seller_calls'])===$before && end($db->errors)['error_code']==='LAZADA_ORDER_AUTH_REQUIRED','Auth failed open');
$tokens->authorize('restore-ready');
// Permission and nonce gates; handler never invokes the new service before either gate.
$GLOBALS['authorized']=false;expect_auth_error('DENIED_403',fn()=>$service->reconcile_batch(100,$id));expect_auth_error('DENIED_403',fn()=>(new Ecomkit_Vuikhoe_Admin())->handle_lazada_reconcile_batch());$GLOBALS['authorized']=true;expect_auth_error('NONCE_DENIED',fn()=>(new Ecomkit_Vuikhoe_Admin())->handle_lazada_reconcile_batch());
// Wrong shop cannot reuse or overwrite prior provider linkage/evidence.
recon_batch(500,[5001=>['LAZADA','500001']]);$db->orders[5001]['connection_id']=$id+1;$db->orders[5001]['provider_normalized_data']='KEEP-OTHER-SHOP';$before=count($GLOBALS['seller_calls']);$r=complete_recon(500,$id);auth_check($r['errors']===1 && count($GLOBALS['seller_calls'])===$before && $db->orders[5001]['connection_id']===$id+1 && $db->orders[5001]['provider_normalized_data']==='KEEP-OTHER-SHOP','Shop scope violated');
// Failed DB write rolls back only that order and leaves checkpoint resumable.
recon_batch(600,[6001=>['LAZADA','600001'],6002=>['LAZADA','600002']]);$service->reconcile_batch(600,$id);$db->fail_order=6002;expect_auth_error('LAZADA_RECON_PERSIST_FAILED',fn()=>$service->reconcile_batch(600,$id));auth_check($db->orders[6001]['matching_status']==='MATCHED' && $db->orders[6002]['matching_status']===null && json_decode($db->batches[600]['source_metadata'],true)['lazada_reconciliation']['processed']===1,'Persistence rollback/checkpoint');$db->fail_order=0;$r=complete_recon(600,$id);auth_check($r['matched']===2,'Failed order did not resume');
$canonical=(new Ecomkit_Vuikhoe_Canonical_Result_Materializer())->materialize($db->orders[1001]);auth_check(count($canonical['columns'])===24 && $canonical['version']==='v9','Canonical contract');foreach(['order_status','product_price_vat_8','fixed_platform_fee','service_platform_fee','transaction_platform_fee','discount_vuikhoe','total_amount_to_collect'] as $field)auth_check($canonical['columns'][$field]===null,'Lazada applied financial/status mapping: '.$field);
auth_check($db->orders[1001]['canonical_data']==='KEEP' && $db->orders[1001]['raw_source_metadata']==='{"excel":"KEEP"}' && json_decode($db->batches[100]['source_metadata'],true)['auto_pipeline']['status']==='KEEP','Excel/canonical/pipeline modified');
recon_batch(700,[7001=>['SHOPEE','SHP'],7002=>['LAZADA','']]);$before=count($GLOBALS['seller_calls']);$r=$service->reconcile_batch(700);auth_check($r['eligible_count']===0 && count($GLOBALS['seller_calls'])===$before,'Empty batch called provider');
recon_batch(701,[7011=>['LAZADA','701001']]);$meta=json_decode($db->batches[701]['source_metadata'],true);$meta['auto_pipeline']['status']='PROCESSING';$db->batches[701]['source_metadata']=json_encode($meta);expect_auth_error('LAZADA_RECON_BATCH_BUSY',fn()=>$service->reconcile_batch(701,$id));auth_check(count($GLOBALS['seller_calls'])===$before,'Busy pipeline called provider');
recon_batch(702,[7021=>['LAZADA','702001'],7022=>['LAZADA','702002']]);$service->reconcile_batch(702,$id);$before=count($GLOBALS['seller_calls']);expect_auth_error('LAZADA_RECON_CONNECTION_CONFLICT',fn()=>$service->reconcile_batch(702,$id+1));auth_check(count($GLOBALS['seller_calls'])===$before,'Continuation changed shop');
function esc_attr(string $s): string { return htmlspecialchars($s,ENT_QUOTES); }
function esc_html(string $s): string { return htmlspecialchars($s,ENT_QUOTES); }
function esc_url(string $s): string { return htmlspecialchars($s,ENT_QUOTES); }
function wp_nonce_field(string $a,string $n): void { echo '<input name="'.esc_attr($n).'" value="synthetic-nonce">'; }
$data=['lazada_connections'=>$service->active_connections(),'lazada_evidence'=>$service->batch_evidence(100)];$batch=$db->batches[100];$batch['metadata']=json_decode($batch['source_metadata'],true);ob_start();require __DIR__.'/../ecomkit-vuikhoe/admin/views/lazada-batch-reconciliation.php';$html=ob_get_clean();auth_check(str_contains($html,$large) && str_contains($html,'MASKED') && str_contains($html,'delivered') && str_contains($html,'name="action" value="ecomkit_lazada_reconcile_batch"') && !str_contains($html,'fake-access') && !str_contains($html,'<details open'),'Safe admin evidence/large ID/action');
(new Ecomkit_Vuikhoe_Admin())->register();auth_check(isset($GLOBALS['hooks']['admin_post_ecomkit_lazada_reconcile_batch']) && !isset($GLOBALS['hooks']['admin_post_nopriv_ecomkit_lazada_reconcile_batch']),'Admin route missing/public');
$GLOBALS['reply']=$fixture;$GLOBALS['reply']['country_user_info_list'][0]['seller_id']='seller-18';$other=$tokens->authorize('synthetic-second-shop');
recon_batch(800,[8001=>['LAZADA',$sample]]);$before=count($GLOBALS['seller_calls']);expect_auth_error('LAZADA_RECON_CONNECTION_AMBIGUOUS',fn()=>$service->reconcile_batch(800));auth_check(count($GLOBALS['seller_calls'])===$before,'Ambiguous shop called provider');$r=complete_recon(800,$other);$e=json_decode($db->orders[8001]['provider_normalized_data'],true);auth_check($r['shop_id']==='seller-18' && $db->orders[8001]['connection_id']===$other && $e['connectionId']===$other && $e['providerOrderId']===$sample,'Same ID in another shop not scoped');
$old=$db->orders[1001]['provider_normalized_data'];$r=complete_recon(100,999);auth_check($r['errors']===2 && $db->orders[1001]['connection_id']===$id && $db->orders[1001]['provider_normalized_data']===$old,'Inactive unknown shop rebound old evidence');
// Provider strings are escaped in the server-rendered admin preview, not inserted as HTML.
$e=json_decode($db->orders[1011]['provider_normalized_data'],true);$e['order']['rawStatuses']=['<img src=x onerror=alert(1)>'];$db->orders[1011]['provider_normalized_data']=json_encode($e);$data['lazada_evidence']=$service->batch_evidence(101);$batch=$db->batches[101];$batch['metadata']=json_decode($batch['source_metadata'],true);ob_start();require __DIR__.'/../ecomkit-vuikhoe/admin/views/lazada-batch-reconciliation.php';$html=ob_get_clean();auth_check(!str_contains($html,'<img') && str_contains($html,'&lt;img'),'Admin provider text executes HTML');
echo "WP.6J.4 Lazada reconciliation: PASS (exact, failures, items, rerun, cross-batch, large ID, mixed, checkpoint, rollback; zero real calls)\n";
