<?php
/** Actual menu/page/action callbacks, fake WordPress and business dependencies; zero provider calls. */
declare(strict_types=1);
ob_start();
require __DIR__.'/access-restoration-check.php';
define('ECOMKIT_VUIKHOE_DIR',__DIR__.'/../ecomkit-vuikhoe/');define('ECOMKIT_VUIKHOE_VERSION','0.7.32');define('ECOMKIT_VUIKHOE_MIN_PHP','8.1');define('ECOMKIT_VUIKHOE_MIN_WP','6.0');$wp_version='6.8';
function esc_html(mixed $s): string{return htmlspecialchars((string)$s);}
function esc_attr(mixed $s): string{return esc_html($s);}function esc_url(string $s): string{return $s;}
function admin_url(string $s): string{return 'https://example.test/wp-admin/'.$s;}
function wp_unslash(mixed $v): mixed{return $v;}function absint(mixed $v): int{return abs((int)$v);}
function get_current_user_id(): int{return 22;}function wp_max_upload_size(): int{return 1000000;}
function size_format(int $v): string{return (string)$v;}function get_option(string $k): string{return 'Y-m-d';}
function wp_timezone(): DateTimeZone{return new DateTimeZone('UTC');}
function wp_date(string $f,int $t,mixed $tz=null): string{return gmdate($f,$t);}
function get_transient(string $k): mixed{return false;}function delete_transient(string $k): void{}
function wp_nonce_field(string $a,string $k): void{echo '<input name="'.$k.'">';}
function disabled(mixed $v): void{}
function selected(mixed $a,mixed $b): void{}function submit_button(mixed ...$a): void{echo '<button>submit</button>';}
function check_admin_referer(string $a,string $k): void{if(empty($GLOBALS['nonce']))throw new RuntimeException('NONCE_DENIED');$GLOBALS['nonce_seen'][]=$a;}
function check_ajax_referer(string $a,string $k,bool $stop=true): int|false{if(empty($GLOBALS['nonce'])){if($stop)throw new RuntimeException('NONCE_DENIED');return false;}$GLOBALS['nonce_seen'][]=$a;return 1;}
function add_query_arg(mixed $a,string $url,mixed $third=null): string{if(is_string($a)){$a=[$a=>$url];$url=$third;}return $url.'?'.http_build_query($a);}
final class AccessRedirect extends RuntimeException{public function __construct(public string $url){parent::__construct('REDIRECT');}}
function wp_safe_redirect(string $url): never{throw new AccessRedirect($url);}
function nocache_headers(): void{}
function wp_json_encode(mixed $a,int $flags=0): string{return json_encode($a,$flags);}
function wp_create_nonce(string $a): string{return 'fake';}
final class AccessJson extends RuntimeException{public function __construct(public array $data,public bool $success,public int $status=200){parent::__construct('JSON');}}
function wp_send_json_success(array $a): never{throw new AccessJson($a,true);}
function wp_send_json_error(array $a,int $status=200): never{throw new AccessJson($a,false,$status);}
final class Ecomkit_Vuikhoe_DB{public static function diagnose(): array{return ['stored_version'=>9,'expected_version'=>9,'tables_ok'=>true,'tables'=>[]];}}
final class Ecomkit_Vuikhoe_Import_Service{
 public function max_upload_bytes(): int{return 1000000;}public function get_batch_summary(int $id): mixed{return ['id'=>$id,'created_at'=>'2026-10-06','source_filename'=>'synthetic.xlsx','status'=>'SUCCESS','metadata'=>['platform_counts'=>['LAZADA'=>7]],'order_count'=>7,'error_count'=>0,'orders'=>[],'errors'=>[]];}
 public function list_batches(): array{return [];}public function list_errors(): array{return [];}
 public function import_upload(array $file,int $user): int{access_check($file['name']==='synthetic.xlsx' && $user===22,'Upload inputs changed');++$GLOBALS['imports'];return 31;}
}
final class Ecomkit_Vuikhoe_Excel_Service{public const MAX_ROWS=10000;}
final class Ecomkit_Vuikhoe_Shopee_Reconciliation_Service{public static function classify_summary(array $s): ?string{return null;}}
final class Ecomkit_Vuikhoe_Lazada_Reconciliation_Service{}
final class Ecomkit_Vuikhoe_Canonical_Result_Service{public static function clipboard_tsv(array $r,bool $headers=false): string{return 'EXACT-SYNTH';}public function list_batches(): array{return [];}public function get_batch_result(int $id,string $p,string $m): array{return ['batch'=>['id'=>$id,'created_at'=>'2026-10-06','source_filename'=>'synthetic.xlsx','status'=>'SUCCESS'],'counts'=>array_fill_keys(['total','SHOPEE','LAZADA','MATCHED','NOT_FOUND_IN_SHOPEE','DETAIL_MISSING','ready','warnings'],0),'reconciliation'=>null,'rows'=>[],'columns'=>[]];}}
final class Ecomkit_Vuikhoe_Auto_Pipeline{public function start(int $id): void{++$GLOBALS['starts'];}public function state(int $id): ?array{return !empty($GLOBALS['polling'])?['status'=>'SUCCESS']:null;}public static function progress(array $s): array{return ['updated_at'=>'2026-10-06','terminal'=>true];}}
final class Ecomkit_Vuikhoe_Shopee_SPX_Labels{public const MAX_BYTES=1000000;public static function process_temporary_upload(array $u,array $r): array{++$GLOBALS['pdfs'];return ['records'=>[['order_sn'=>'EXACT-SYNTH']]];}}
require __DIR__.'/../ecomkit-vuikhoe/includes/class-ecomkit-canonical-columns.php';
$normal=['dashboard_page','process_page','results_page','errors_page','history_page'];
$technical=['marketplace_page','settings_page','handle_shopee_save_config','handle_lazada_save_config','handle_shopee_replace_partner_key','handle_shopee_refresh_token','handle_shopee_fee_audit','handle_batch_state_export','handle_excel_runtime_test','handle_shopee_reconcile_batch','handle_lazada_reconcile_batch','handle_shopee_financial_enrich','handle_materialize_results','handle_pipeline_resume'];
$roles=['administrator'=>['manage_options'=>true],'editor'=>['edit_pages'=>true],'shop_manager'=>['manage_woocommerce'=>true],'author'=>['edit_posts'=>true],'contributor'=>['edit_posts'=>true],'subscriber'=>['read'=>true],'legacy_operator'=>['ecomkit_use'=>true,'ecomkit_manage'=>true],'multiple_roles'=>['edit_posts'=>true,'edit_pages'=>true]];
foreach($roles as $role=>$caps){
 $GLOBALS['caps']=$caps;$GLOBALS['pages']=[];$admin->add_menu();$allowed=in_array($role,['administrator','editor','shop_manager','multiple_roles'],true);$manage=$role==='administrator';
 access_check(count($GLOBALS['pages'])===($manage?7:($allowed?5:0)),'Menu matrix '.$role);
 foreach($normal as $method){$_GET=[];if($allowed){ob_start();$admin->$method();$html=ob_get_clean();access_check(!str_contains($html,'C?ng c? qu?n tr? n?ng cao'),'Empty-page advanced controls leak');}else{try{$admin->$method();throw new RuntimeException('Unexpected allow');}catch(RuntimeException $e){access_check($e->getMessage()==='DENIED_403','Low page denied '.$role.' '.$method);}}}
 if(!$manage)foreach($technical as $method){try{$admin->$method();throw new RuntimeException('Unexpected allow');}catch(RuntimeException $e){access_check($e->getMessage()==='DENIED_403','Admin boundary '.$role.' '.$method);}}
 foreach(['handle_excel_import','handle_pipeline_progress','handle_shopee_spx_pdf'] as $method){if(!$allowed){try{$admin->$method();throw new RuntimeException('Unexpected allow');}catch(RuntimeException $e){access_check($e->getMessage()==='DENIED_403','Low action '.$method);}continue;}
  $GLOBALS['nonce']=false;try{$admin->$method();throw new RuntimeException('Unexpected nonce allow');}catch(RuntimeException $e){access_check(in_array($e->getMessage(),['NONCE_DENIED','JSON'],true),'Nonce enforcement');if($e instanceof AccessJson)access_check($e->status===403,'PDF nonce status');}
  $GLOBALS['nonce']=true;$GLOBALS['imports']=0;$GLOBALS['starts']=0;$GLOBALS['pdfs']=0;$_FILES=['excel_file'=>['name'=>'synthetic.xlsx']];$_POST=['batch_id'=>'31'];$_GET=['batch_id'=>'31'];
  $GLOBALS['polling']=$method==='handle_pipeline_progress';try{$admin->$method();throw new RuntimeException('Expected response');}catch(AccessRedirect $e){access_check($GLOBALS['imports']===1 && $GLOBALS['starts']===1 && str_contains($e->url,'batch_id=31'),'Actual upload/pipeline controller');}catch(AccessJson $e){access_check($e->success && ($method==='handle_pipeline_progress'?isset($e->data['progress']):$GLOBALS['pdfs']===1),'Actual progress/PDF action');}
 }
}
foreach(['edit_pages','manage_woocommerce'] as $cap){$GLOBALS['caps']=[$cap=>true];$_GET=['batch_id'=>'31'];$GLOBALS['polling']=false;ob_start();$admin->results_page();$html=ob_get_clean();access_check(str_contains($html,'EXACT-SYNTH') && str_contains($html,'ecomkit-copy-values') && str_contains($html,'ecomkit-spx-pdf') && str_contains($html,'ecomkit-clear-spx-pdf') && !str_contains($html,'C?ng c? qu?n tr? n?ng cao') && !str_contains($html,'ecomkit_shopee_financial_enrich'),'Actual selected Result normal UI/admin isolation');ob_start();$admin->process_page();$process=ob_get_clean();access_check(str_contains($process,'synthetic.xlsx') && !str_contains($process,'<details') && !str_contains($process,'ecomkit_lazada_reconcile_batch') && !str_contains($process,'ecomkit_shopee_reconcile_batch'),'Selected Process admin tools hidden');}
foreach(['class-ecomkit-shopee-oauth.php','class-ecomkit-lazada-oauth.php','class-ecomkit-lazada-order-diagnostic.php','class-ecomkit-lazada-finance-diagnostic.php'] as $file)require __DIR__.'/../ecomkit-vuikhoe/includes/'.$file;
foreach(['edit_pages','manage_woocommerce','edit_posts','read'] as $cap){$GLOBALS['caps']=[$cap=>true];
 foreach(['Ecomkit_Vuikhoe_Shopee_OAuth','Ecomkit_Vuikhoe_Lazada_OAuth'] as $class){$oauth=(new ReflectionClass($class))->newInstanceWithoutConstructor();try{$oauth->start();throw new RuntimeException('OAuth allowed');}catch(RuntimeException $e){access_check($e->getMessage()==='DENIED_403','OAuth initiation denied');}}
 foreach(['Ecomkit_Vuikhoe_Lazada_Order_Diagnostic','Ecomkit_Vuikhoe_Lazada_Finance_Diagnostic'] as $class){$diagnostic=(new ReflectionClass($class))->newInstanceWithoutConstructor();try{$diagnostic->ajax();throw new RuntimeException('Diagnostic allowed');}catch(AccessJson $e){access_check(!$e->success && $e->status===403,'Direct diagnostic denied');}}
}
ob_end_flush();
echo "WP.6K.1 operational access: PASS (native multi-role matrix, actual menu/pages/upload/progress/PDF, admin/low-role denial, nonces; zero real calls)\n";
