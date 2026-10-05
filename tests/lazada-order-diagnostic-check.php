<?php
/** WP.6J.3B: fake provider only, admin/nonce gate and ephemeral diagnostic output. */
declare(strict_types=1);
require __DIR__ . '/lazada-order-check.php';
function wp_timezone(): DateTimeZone { return new DateTimeZone('Asia/Ho_Chi_Minh'); }
function check_ajax_referer( string $action, string $key ): never { throw new RuntimeException('NONCE_DENIED'); }
$GLOBALS['reply']=$fixture; $tokens->authorize('diagnostic-restore');
$diagnostic = new Ecomkit_Vuikhoe_Lazada_Order_Diagnostic($tokens, static fn(int $connection): Ecomkit_Vuikhoe_Lazada_Order_Client => $client);
$diagnostic->register(); auth_check(isset($GLOBALS['hooks']['wp_ajax_ecomkit_lazada_order_diagnostic']) && !isset($GLOBALS['hooks']['wp_ajax_nopriv_ecomkit_lazada_order_diagnostic']),'Unprotected AJAX hook.');
$input=array('connection_id'=>(string)$id,'operation'=>'orders','from'=>'2026-10-01T00:00','to'=>'2026-10-01T23:59','offset'=>'0','excel_order_id'=>'123');
$GLOBALS['authorized']=false;
expect_auth_error('DENIED_403',fn()=> $diagnostic->run($input)); expect_auth_error('DENIED_403',fn()=> $diagnostic->ajax()); $GLOBALS['authorized']=true;
expect_auth_error('NONCE_DENIED',fn()=> $diagnostic->ajax());
$before=count($GLOBALS['seller_calls']); $invalid=$input; $invalid['connection_id']='999'; expect_auth_error('LAZADA_ORDER_AUTH_REQUIRED',fn()=> $diagnostic->run($invalid));
$oldstatus=$GLOBALS['wpdb']->rows[$id]['status']; $GLOBALS['wpdb']->rows[$id]['status']='SUSPENDED'; expect_auth_error('LAZADA_ORDER_AUTH_REQUIRED',fn()=> $diagnostic->run($input)); $GLOBALS['wpdb']->rows[$id]['status']=$oldstatus;
auth_check($before===count($GLOBALS['seller_calls']),'Disconnected gate sent request.');
$snapshot=array($GLOBALS['wpdb']->rows,$GLOBALS['options'],$GLOBALS['transients']);
$GLOBALS['seller_reply']=array('code'=>'0','request_id'=>'synthetic-order-request','data'=>array('orders'=>array(synthetic_order(123)),'count'=>1,'countTotal'=>1));
$result=$diagnostic->run($input);
auth_check(1===$result['request_count'] && 1===$result['order_count'] && array('0')===$result['offsets'] && false===$result['window']['provider_end_bound_sent'],'Wrong page/window report.');
auth_check('MATCH'===$result['excel_comparison'][0]['result'] && '123'===$result['orders'][0]['providerOrderId'],'Exact comparison lost.');
auth_check(!isset(end($GLOBALS['seller_calls'])['params']['created_before']),'Invented end-bound parameter.');
auth_check('MASKED'===$result['orders'][0]['piiAvailability']['classification'] && !isset($result['orders'][0]['addressShipping']),'Full buyer PII exposed.');
$paths=$result['response_evidence']['field_paths']; auth_check(in_array('data.orders[].order_id',$paths,true) && in_array('data.orders[].statuses[]',$paths,true) && in_array('request_id',$paths,true),'Observed structural paths missing.');
auth_check(!str_contains(json_encode($result),'fake-access') && !str_contains(json_encode($result),'fake-refresh') && !str_contains(json_encode($result),'test-app-secret') && !str_contains(json_encode($result),'Synthetic Example'),'Token/PII leak.');
$input['excel_order_id']='00123'; auth_check('NO MATCH'===$diagnostic->run($input)['excel_comparison'][0]['result'],'Leading-zero ID fuzzy matched.');
$input['operation']='order'; $input['order_id']='123'; $input['excel_order_id']='123'; $single=synthetic_order(123); unset($single['address_shipping']); $GLOBALS['seller_reply']=array('code'=>'0','data'=>$single);
$detail=$diagnostic->run($input); auth_check('123'===$detail['order']['providerOrderId'] && 'MISSING'===$detail['order']['piiAvailability']['classification'] && '123'===end($GLOBALS['seller_calls'])['params']['order_id'],'Exact detail or missing PII failed.');
$input['operation']='items'; $GLOBALS['seller_reply']=array('code'=>'0','data'=>$items); $detail=$diagnostic->run($input);
auth_check(2===$detail['item_count'] && '123'===end($GLOBALS['seller_calls'])['params']['order_id'] && $detail['items'][0]['orderItemId']!==$detail['items'][1]['orderItemId'],'Items collapsed or exact parent lost.');
auth_check(in_array('data[].order_item_id',$detail['response_evidence']['field_paths'],true),'Item path missing.');
$GLOBALS['seller_reply']=array('code'=>'0','data'=>array()); $empty=$diagnostic->run($input); auth_check('NO MATCH'===$empty['excel_comparison']['result'] && null===$empty['excel_comparison']['provider_order_id'],'Empty items falsely proved provider ID match.');
auth_check($snapshot===array($GLOBALS['wpdb']->rows,$GLOBALS['options'],$GLOBALS['transients']),'Diagnostic persisted payload/business state.');
$input['operation']='orders'; $input['to']='2026-10-04T00:00'; $before=count($GLOBALS['seller_calls']); expect_auth_error('LAZADA_ORDER_DIAGNOSTIC_WINDOW_INVALID',fn()=> $diagnostic->run($input));
auth_check($before===count($GLOBALS['seller_calls']),'Large interval called provider.');
$input['to']='2026-10-01T23:59'; $GLOBALS['seller_reply']='{invalid'; expect_auth_error('LAZADA_ORDER_INVALID_RESPONSE',fn()=> $diagnostic->run($input));
$GLOBALS['seller_reply']=array('code'=>'15','message'=>'fake-access-one test-app-secret sign=hidden');
try { $diagnostic->run($input); throw new RuntimeException('Expected error'); } catch(Ecomkit_Vuikhoe_Lazada_Provider_Exception $e) { auth_check(!str_contains(json_encode($e->diagnostic),'fake-access-one') && !str_contains(json_encode($e->diagnostic),'test-app-secret'),'Error output secret leak.'); }
$js=file_get_contents(__DIR__.'/../ecomkit-vuikhoe/assets/js/lazada-order-diagnostic.js'); auth_check(!str_contains($js,'innerHTML') && !str_contains($js,'parseInt(') && !str_contains($js,'Number('),'Browser changes ID precision or unsafe HTML.');
// Render actual collapsed diagnostic partial with safe WordPress UI stubs.
define('ECOMKIT_VUIKHOE_FILE',__DIR__.'/../ecomkit-vuikhoe/ecomkit-vuikhoe.php'); define('ECOMKIT_VUIKHOE_VERSION','0.7.20');
function wp_enqueue_script(mixed ...$args): void {}
function plugins_url(string $path,string $file): string { return 'https://wordpress.example/plugin/'.$path; }
function esc_attr(string $v): string { return htmlspecialchars($v,ENT_QUOTES); }
function esc_html(string $v): string { return htmlspecialchars($v,ENT_QUOTES); }
function esc_url(string $v): string { return htmlspecialchars($v,ENT_QUOTES); }
function wp_nonce_field(string $a,string $n): void { echo '<input name="'.esc_attr($n).'" value="synthetic-nonce">'; }
function disabled(bool $value): void { if($value) echo 'disabled'; }
function wp_date(string $format,int $time,DateTimeZone $zone): string { return (new DateTimeImmutable('@'.$time))->setTimezone($zone)->format($format); }
$lazada_connections=$tokens->safe_connections(); ob_start(); require __DIR__.'/../ecomkit-vuikhoe/admin/views/lazada-order-diagnostic.php'; $html=ob_get_clean();
auth_check(str_contains($html,'<details') && !str_contains($html,'<details open') && str_contains($html,'Kiểm tra danh sách đơn Lazada') && str_contains($html,'Kiểm tra chi tiết đơn') && str_contains($html,'Kiểm tra sản phẩm đơn'),'Diagnostic UI buttons/collapse missing.');
auth_check(!str_contains($html,'fake-access') && !str_contains($html,'fake-refresh') && !str_contains($html,'test-app-secret'),'Rendered secrets.');
echo "WP.6J.3B Lazada diagnostic: PASS (zero real calls, no payload persistence)\n";
