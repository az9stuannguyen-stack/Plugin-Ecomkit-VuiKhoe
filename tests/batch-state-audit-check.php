<?php
/** WP.6H.2S: synthetic persisted-state projection; zero provider calls/writes. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' ); define( 'ARRAY_A', 'ARRAY_A' ); define( 'ECOMKIT_VUIKHOE_VERSION', '0.7.7' );
function state_check( bool $ok, string $message ): void { if ( ! $ok ) { throw new RuntimeException( $message ); } }
function wp_remote_post(): never { throw new RuntimeException( 'PROVIDER_CALLED' ); }
function wp_remote_get(): never { throw new RuntimeException( 'PROVIDER_CALLED' ); }
function wp_next_scheduled( string $hook, array $args ): int|false { return $GLOBALS['cron_due'] ?? false; }
function wp_unslash( mixed $value ): mixed { return $value; }
function absint( mixed $value ): int { return abs( (int) $value ); }
function current_user_can( string $capability ): bool { return ! empty( $GLOBALS['admin_allowed'] ) && 'manage_options' === $capability; }
function esc_html__( string $message, string $domain ): string { return $message; }
function check_ajax_referer( string $action, string $field ): void { state_check( 'ecomkit_batch_state_audit' === $action && 'nonce' === $field && 'valid' === ( $_POST['nonce'] ?? '' ), 'Nonce absent.' ); }
function wp_die(): never { throw new RuntimeException( 'DENIED' ); }
final class StateResponse extends RuntimeException { public function __construct( public array $data, public bool $success ) { parent::__construct( 'JSON' ); } }
function wp_send_json_success( array $data ): never { throw new StateResponse( $data, true ); }
function wp_send_json_error( array $data, int $code ): never { throw new StateResponse( $data, false ); }
final class StateDb {
	public string $prefix = 'test_'; public int $reads = 0; public int $writes = 0; public array $batch = array(); public array $connection = array();
	public function prepare( string $sql, mixed ...$args ): string { return json_encode( array( 'sql' => $sql, 'args' => $args ) ); }
	public function get_row( string $query, string $mode ): ?array { $this->reads++; $parts = json_decode( $query, true ); return str_contains( $parts['sql'], 'marketplace_connections' ) ? $this->connection : $this->batch; }
	public function update(): never { $this->writes++; throw new RuntimeException( 'WRITE_FORBIDDEN' ); }
	public function insert(): never { $this->writes++; throw new RuntimeException( 'WRITE_FORBIDDEN' ); }
}
$db = new StateDb(); $GLOBALS['wpdb'] = $db;
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-db.php';
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-auto-pipeline.php';
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-batch-state-audit.php';
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-security.php';
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-admin.php';
$batch = array( 'id' => 23, 'source_filename' => 'danh-sach-don-hang.xlsx', 'status' => 'SUCCESS', 'order_count' => 11 );
$empty = Ecomkit_Vuikhoe_Batch_State_Audit::project( $batch, array() );
state_check( 'NOT_PRESENT' === $empty['auto_pipeline']['stage'] && 'NOT_PRESENT' === $empty['auto_pipeline']['terminal_reason'] && 'NOT_PRESENT' === $empty['diagnostic_interpretation_inputs']['pagination_complete'] && ! $empty['diagnostic_interpretation_inputs']['provider_request_evidence_present'], 'Missing historical state was invented.' );
$queued = array( 'platform_counts' => array( 'SHOPEE' => 11 ), 'auto_pipeline' => array( 'stage' => 'RECONCILE', 'status' => 'QUEUED', 'counts' => array( 'shopee_orders' => 11, 'reconcile_checked' => 0 ) ) );
$never = Ecomkit_Vuikhoe_Batch_State_Audit::project( $batch, $queued );
state_check( 'NONE' === $never['auto_pipeline']['scheduled_step_current'] && false === $never['diagnostic_interpretation_inputs']['reconciliation_started'], 'Never-scheduled state wrong.' );
$GLOBALS['cron_due'] = 1791180000; $waiting = Ecomkit_Vuikhoe_Batch_State_Audit::project( $batch, $queued, array(), $GLOBALS['cron_due'] );
state_check( str_contains( $waiting['auto_pipeline']['scheduled_step_current'], 'UTC' ) && 'QUEUED' === $waiting['auto_pipeline']['status'], 'Scheduled-not-executed state wrong.' );
$failed = $queued; $failed['auto_pipeline']['status'] = 'WARNING'; $failed['auto_pipeline']['warnings'] = array( 'SHOPEE_RECON_NETWORK_ERROR' ); $failed['auto_pipeline']['reconciliation_block_reason'] = 'SHOPEE_RECON_PROVIDER_ERROR_TERMINAL'; $failed['auto_pipeline']['reconcile_inflight'] = true;
$failure = Ecomkit_Vuikhoe_Batch_State_Audit::project( $batch, $failed );
state_check( true === $failure['diagnostic_interpretation_inputs']['reconciliation_started'] && true === $failure['diagnostic_interpretation_inputs']['terminal_reason_present'] && ! $failure['diagnostic_interpretation_inputs']['provider_request_evidence_present'], 'Provider-failure evidence overclaimed a request.' );
$failed['shopee_reconciliation_persist_diagnostic'] = array( 'operation' => 'ORDER_CONNECTION_UPDATE', 'table' => 'orders', 'error_classification' => 'DUPLICATE_KEY', 'failure_code' => 'SHOPEE_RECON_PERSIST_FAILED', 'payload_bytes' => 0, 'timestamp' => '2026-10-05 10:00:00', 'db_sql' => 'PRIVATE_SQL' );
$local_failure = Ecomkit_Vuikhoe_Batch_State_Audit::project( $batch, $failed );
state_check( 'DUPLICATE_KEY' === $local_failure['shopee_reconciliation']['local_persistence_diagnostic']['error_classification'] && ! str_contains( json_encode( $local_failure ), 'PRIVATE_SQL' ), 'Safe local persistence diagnostic was dropped or exposed SQL.' );
$paged = $queued; $paged['auto_pipeline']['reconcile_continuation'] = array( 'cursor' => 'SECRET_CURSOR', 'provider_ids' => array( 'A' => true, 'B' => true ), 'page_count' => 2, 'request_ids' => array( 'safe_req_1' ), 'access_token' => 'PRIVATE_TOKEN' );
$page = Ecomkit_Vuikhoe_Batch_State_Audit::project( $batch, $paged );
state_check( true === $page['auto_pipeline']['cursor_present'] && 2 === $page['auto_pipeline']['accumulated_provider_order_count'] && 2 === $page['auto_pipeline']['page_count'] && true === $page['diagnostic_interpretation_inputs']['provider_request_evidence_present'], 'Pagination evidence lost.' );
$blocked = $queued; $blocked['auto_pipeline']['reconciliation_block_reason'] = 'SHOPEE_CONNECTION_NOT_READY'; $block = Ecomkit_Vuikhoe_Batch_State_Audit::project( $batch, $blocked );
state_check( 'SHOPEE_CONNECTION_NOT_READY' === $block['auto_pipeline']['terminal_reason'], 'Connection block not preserved.' );
$complete = $queued; $complete['auto_pipeline']['status'] = 'SUCCESS'; $complete['auto_pipeline']['counts']['reconcile_checked'] = 11; $complete['shopee_reconciliation'] = array( 'connection_id' => 9, 'matched_count' => 11, 'status' => 'SUCCESS', 'safe_provider_message' => 'buyer_name PRIVATE_PERSON', 'windows' => array( array( 'time_range_field' => 'create_time', 'start_date' => '2026-09-24', 'pagination_complete' => true, 'request_ids' => array( 'req_1' ), 'excel_order_ids' => array( '260924TSBR7FC0' ), 'provider_order_ids' => array( '260924TSBR7FC0' ), 'intersection' => array( '260924TSBR7FC0' ), 'recipient_phone' => 'PRIVATE_PHONE', 'access_token' => 'PRIVATE_TOKEN' ) ) );
$connection = array( 'id' => 9, 'platform' => 'SHOPEE', 'external_shop_id' => '123456', 'status' => 'ACTIVE', 'credential_source' => 'OAUTH', 'credential_envelope' => 'PRIVATE_ENVELOPE', 'metadata' => json_encode( array( 'refresh_ownership' => 'ECOMKIT', 'credential_lifecycle' => 'READY', 'access_token' => 'PRIVATE_TOKEN' ) ) );
$success = Ecomkit_Vuikhoe_Batch_State_Audit::project( $batch, $complete, $connection );
state_check( true === $success['diagnostic_interpretation_inputs']['pagination_complete'] && 11 === $success['shopee_reconciliation']['matched_count'] && 'CURRENT_CONNECTION_STATE' === $success['connection_current']['label'] && array( '260924TSBR7FC0' ) === $success['shopee_reconciliation']['windows'][0]['intersection'], 'Successful exact-set evidence missing.' );
$serialized = json_encode( array( $page, $success ) ); foreach ( array( 'SECRET_CURSOR', 'PRIVATE_TOKEN', 'PRIVATE_PHONE', 'PRIVATE_PERSON', 'PRIVATE_ENVELOPE', 'access_token', 'recipient_phone', 'credential_envelope' ) as $forbidden ) { state_check( ! str_contains( $serialized, $forbidden ), 'Sensitive value/key exposed: ' . $forbidden ); }
$db->batch = $batch + array( 'source_metadata' => json_encode( $complete ) ); $db->connection = $connection; $GLOBALS['cron_due'] = false;
$inspected = ( new Ecomkit_Vuikhoe_Batch_State_Audit() )->inspect_batch( 23 );
state_check( 2 === $db->reads && 0 === $db->writes && 11 === $inspected['shopee_reconciliation']['matched_count'], 'Inspection was not read-only or selected wrong state.' );
$GLOBALS['admin_allowed'] = false; $_POST = array( 'batch_id' => '23', 'nonce' => 'valid' ); $admin = new Ecomkit_Vuikhoe_Admin();
try { $admin->handle_batch_state_audit(); throw new RuntimeException( 'USER_ALLOWED' ); } catch ( RuntimeException $error ) { state_check( 'DENIED' === $error->getMessage(), 'Normal user not denied.' ); }
try { $admin->handle_batch_state_export(); throw new RuntimeException( 'USER_EXPORT_ALLOWED' ); } catch ( RuntimeException $error ) { state_check( 'DENIED' === $error->getMessage(), 'Normal user export not denied.' ); }
state_check( 2 === $db->reads, 'Denied user read Batch state.' );
$GLOBALS['admin_allowed'] = true;
try { $admin->handle_batch_state_audit(); } catch ( StateResponse $response ) { state_check( $response->success && 23 === $response->data['batch']['id'], 'Admin AJAX failed.' ); }
state_check( 4 === $db->reads && 0 === $db->writes, 'Admin AJAX mutated data.' );
$view = file_get_contents( __DIR__ . '/../ecomkit-vuikhoe/admin/views/results.php' );
state_check( str_contains( $view, "current_user_can( 'manage_options' )" ) && str_contains( $view, 'Xuất JSON trạng thái Batch' ) && ! str_contains( $view, 'output.innerHTML' ), 'Admin-only UI/DOM safety missing.' );
echo "WP.6H.2S Batch state audit: PASS\n";
