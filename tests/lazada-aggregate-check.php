<?php
/** Persisted aggregate/checkpoint regression; synthetic WordPress and provider only. */
declare(strict_types=1);
require __DIR__ . '/lazada-batch-control-check.php';
$GLOBALS['synthetic_lazada_control_nonce'] = true;
$before = count($GLOBALS['seller_calls']);
$orders = $db->orders; $errors = $db->errors;
$metadata = json_decode($db->batches[31]['source_metadata'],true);
$metadata['lazada_reconciliation']['processed']=1;
$metadata['lazada_reconciliation']['matched']=1;
$metadata['lazada_reconciliation']['status']='PROCESSING';
$metadata['lazada_reconciliation']['last_order_id']=3101;
$db->batches[31]['source_metadata']=json_encode($metadata);
$s=$service->batch_summary(31);
auth_check($s['total_lazada']===7 && $s['processed']===7 && $s['matched']===7 && $s['pending']===0,'Stale checkpoint masks seven terminal rows');
for($i=0;$i<2;$i++) {
 $html=control_html(31);
 auth_check(str_contains($html,'Đã xử lý: 7') && str_contains($html,'Đã khớp: 7') && str_contains($html,'Còn chờ: 0') && str_contains($html,'Đối chiếu Lazada: 7/7') && !str_contains($html,'>Tiếp tục Đối chiếu Lazada</button>'),'Refresh/navigation stale summary');
}
$r=$service->reconcile_batch(31,$id);
auth_check($r['processed']===7 && $r['matched']===7 && $r['pending']===0 && $r['status']==='SUCCESS','Completed checkpoint restarted');
auth_check($db->orders===$orders && $db->errors===$errors && count($GLOBALS['seller_calls'])===$before,'Render/rerun invoked provider or changed evidence');
auth_check(json_decode($db->batches[31]['source_metadata'],true)['lazada_reconciliation']['processed']===7,'Checkpoint not repaired');
$db->orders[3106]['matching_status']='NOT_FOUND_IN_LAZADA';$db->orders[3107]['matching_status']='ERROR';
$s=$service->batch_summary(31);
auth_check($s['processed']===7 && $s['matched']===5 && $s['unmatched']===1 && $s['errors']===1 && $s['pending']===0,'Mixed terminal aggregate');
$html=control_html(31);auth_check(!str_contains($html,'>Tiếp tục Đối chiếu Lazada</button>'),'Mixed terminal shows continue');
// Holes before the old cursor must remain pending and be processed, rather than skipped.
$db->orders[3102]['matching_status']=null;$db->orders[3102]['provider_normalized_data']=null;
$s=$service->batch_summary(31);auth_check($s['processed']===6 && $s['pending']===1,'Pending hole lost');
$r=$service->reconcile_batch(31,$id);auth_check($r['processed']===7 && $r['pending']===0 && count($GLOBALS['seller_calls'])===$before+2,'Cursor skipped actual pending row');
$view=file_get_contents(__DIR__.'/../ecomkit-vuikhoe/admin/views/results.php');
auth_check(str_contains($view,'Đối chiếu Shopee:') && str_contains($view,'Chi tiết đơn Shopee:'),'Shopee summary semantics ambiguous');
echo "WP.6J.4.2 aggregate: PASS (stale metadata, refresh, terminal/mixed/pending holes, no-op rerun, zero real calls)\n";
