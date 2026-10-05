<?php
/** WP.6E.1 persisted progress projection and read-only admin poll. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' );
define( 'ARRAY_A', 'ARRAY_A' );
function progress_check( bool $ok, string $message ): void { if ( ! $ok ) { throw new RuntimeException( $message ); } }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function current_time( string $format, bool $utc = false ): string { return gmdate( 'Y-m-d H:i:s' ); }
function wp_timezone(): DateTimeZone { return new DateTimeZone( 'Asia/Bangkok' ); }
function wp_date( string $format, int $timestamp, ?DateTimeZone $timezone = null ): string { return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone ?? wp_timezone() )->format( $format ); }
function get_option( string $key ): string { return 'Y-m-d'; }
function absint( mixed $value ): int { return abs( (int) $value ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function current_user_can( string $capability ): bool { return ! empty( $GLOBALS['allowed'] ) && 'ecomkit_use' === $capability; }
function esc_html__( string $message, string $domain ): string { return $message; }
function wp_die( mixed $message ): never { throw new RuntimeException( 'DENIED' ); }
function check_ajax_referer( string $action, string $field ): void { progress_check( 'ecomkit_pipeline_progress' === $action && 'nonce' === $field && 'valid' === ( $_GET['nonce'] ?? '' ), 'Nonce not enforced.' ); }
final class ProgressResponse extends RuntimeException { public function __construct( public array $data, public bool $success ) { parent::__construct( 'JSON' ); } }
function wp_send_json_success( array $data ): never { throw new ProgressResponse( $data, true ); }
function wp_send_json_error( array $data, int $status ): never { throw new ProgressResponse( $data, false ); }
final class ProgressDb {
	public string $prefix = 'test_'; public int $reads = 0; public int $writes = 0; public array $batch = array();
	public function prepare( string $sql, mixed ...$args ): string { return (string) ( $args[0] ?? 0 ); }
	public function get_row( string $query, string $mode ): array { $this->reads++; return $this->batch; }
	public function update(): never { $this->writes++; throw new RuntimeException( 'POLL_WROTE_DB' ); }
}
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-db.php';
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-security.php';
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-auto-pipeline.php';
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-admin.php';

$base = array( 'run_sequence' => 1, 'status' => 'QUEUED', 'stage' => 'RECONCILE', 'import_status' => 'SUCCESS', 'reconciliation_status' => 'QUEUED', 'payment_status' => 'QUEUED', 'income_status' => 'QUEUED', 'canonical_status' => 'QUEUED', 'updated_at' => gmdate( 'Y-m-d H:i:s' ), 'counts' => array( 'excel_orders' => 8, 'excel_items' => 18, 'shopee_orders' => 4, 'lazada_orders' => 4, 'shopee_matched' => 0, 'reconcile_checked' => 0, 'detail_fetched' => 0, 'payment_total' => 0, 'payment_fetched' => 0, 'payment_reused' => 0, 'payment_failed' => 0, 'canonical_rows' => 0 ), 'warnings' => array(), 'errors' => array() );
$queued = Ecomkit_Vuikhoe_Auto_Pipeline::progress( $base );
progress_check( 15 === $queued['percent'] && ! $queued['terminal'] && 'RECONCILIATION' === $queued['stage'] && 18 === $queued['items'], 'Import/reconcile baseline wrong.' );
$one_window = $base; $one_window['status'] = 'PROCESSING'; $one_window['reconcile_total_windows'] = 2; $one_window['reconcile_window_offset'] = 1; $one_window['counts']['shopee_matched'] = 2; $one_window['counts']['reconcile_checked'] = 2; $one_window['counts']['detail_fetched'] = 1;
$window_progress = Ecomkit_Vuikhoe_Auto_Pipeline::progress( $one_window );
progress_check( $window_progress['percent'] > 15 && $window_progress['percent'] < 45 && 'ORDER_DETAIL' === $window_progress['stage'], 'Partial reconciliation/detail work was not reflected.' );
$reconciled = $base; $reconciled['stage'] = 'PAYMENT'; $reconciled['status'] = 'PROCESSING'; $reconciled['reconciliation_status'] = 'SUCCESS'; $reconciled['reconcile_total_windows'] = 2; $reconciled['reconcile_window_offset'] = 2; $reconciled['counts']['shopee_matched'] = 4; $reconciled['counts']['reconcile_checked'] = 4; $reconciled['counts']['detail_fetched'] = 4; $reconciled['counts']['payment_total'] = 4;
$before_payment = Ecomkit_Vuikhoe_Auto_Pipeline::progress( $reconciled );
progress_check( 45 === $before_payment['percent'] && 4 === $before_payment['detail_done'], 'Reconciliation/detail weight wrong.' );
$partial = $reconciled; $partial['counts']['payment_reused'] = 2;
$half_payment = Ecomkit_Vuikhoe_Auto_Pipeline::progress( $partial );
progress_check( 60 === $half_payment['percent'] && 2 === $half_payment['payment_done'], '2/4 reused Payment should contribute half of 30 points.' );
$partial['counts']['payment_fetched'] = 2; $partial['payment_status'] = 'SUCCESS'; $partial['stage'] = 'INCOME'; $partial['income_plan'] = array( array( 'bucket' => 'PENDING' ), array( 'bucket' => 'RELEASED' ) );
progress_check( 75 === Ecomkit_Vuikhoe_Auto_Pipeline::progress( $partial )['percent'], 'Reused/fetched Payment did not complete stage.' );
$partial['income_index'] = 1; progress_check( 80 === Ecomkit_Vuikhoe_Auto_Pipeline::progress( $partial )['percent'], 'Income query count did not contribute progress.' );
$partial['income_index'] = 2; $partial['income_status'] = 'EMPTY'; $partial['stage'] = 'MATERIALIZE';
$income_empty = Ecomkit_Vuikhoe_Auto_Pipeline::progress( $partial );
progress_check( 85 === $income_empty['percent'] && 'Không có dữ liệu Income bổ sung' === $income_empty['income_label'], 'Income EMPTY was treated as incomplete.' );
$partial['counts']['canonical_rows'] = 8; $partial['canonical_status'] = 'READY'; $partial['stage'] = 'RESULT_READY'; $partial['status'] = 'SUCCESS';
progress_check( 100 === Ecomkit_Vuikhoe_Auto_Pipeline::progress( $partial )['percent'], '8 Result rows did not complete 24-column pipeline.' );
$with_source_gaps = $partial; $with_source_gaps['source_gaps'] = array( 'product_price_vat_8', 'affiliate_fee_vuikhoe', 'discount_vuikhoe' );
progress_check( 100 === Ecomkit_Vuikhoe_Auto_Pipeline::progress( $with_source_gaps )['percent'], 'Missing-source canonical cells blocked pipeline completion.' );
$warning = $partial; $warning['counts']['payment_fetched'] = 1; $warning['counts']['payment_failed'] = 1; $warning['payment_status'] = 'WARNING'; $warning['warnings'] = array( 'PAYMENT_UNAVAILABLE' ); $warning['status'] = 'WARNING';
progress_check( 100 === Ecomkit_Vuikhoe_Auto_Pipeline::progress( $warning )['percent'] && 4 === Ecomkit_Vuikhoe_Auto_Pipeline::progress( $warning )['payment_done'], 'Soft Payment warning blocked completion.' );
$hard = $partial; $hard['status'] = 'ERROR'; $hard['stage'] = 'PAYMENT'; $hard['canonical_status'] = 'QUEUED'; $hard['counts']['canonical_rows'] = 0;
progress_check( Ecomkit_Vuikhoe_Auto_Pipeline::progress( $hard )['percent'] < 100, 'Hard error falsely became 100%.' );
$stalled = $base; $stalled['updated_at'] = gmdate( 'Y-m-d H:i:s', time() - 100 );
progress_check( Ecomkit_Vuikhoe_Auto_Pipeline::progress( $stalled )['stalled'] && ! Ecomkit_Vuikhoe_Auto_Pipeline::progress( $base )['stalled'], '90-second Cron stall warning wrong.' );
$floor = $partial; $floor['progress_percent'] = 72; $floor['status'] = 'PROCESSING'; $floor['stage'] = 'PAYMENT'; $floor['payment_status'] = 'QUEUED'; $floor['counts']['payment_reused'] = 0; $floor['counts']['payment_fetched'] = 0; $floor['counts']['canonical_rows'] = 0; $floor['canonical_status'] = 'QUEUED';
progress_check( 72 === Ecomkit_Vuikhoe_Auto_Pipeline::progress( $floor )['percent'], 'Progress high-water mark moved backward.' );

$db = new ProgressDb(); $GLOBALS['wpdb'] = $db; $GLOBALS['allowed'] = true; $_GET = array( 'batch_id' => '8', 'nonce' => 'valid' );
$db->batch = array( 'id' => 8, 'source_type' => 'EXCEL', 'status' => 'SUCCESS', 'order_count' => 8, 'source_metadata' => json_encode( array( 'auto_pipeline' => $partial ) ) );
$admin = new Ecomkit_Vuikhoe_Admin();
for ( $i = 0; $i < 3; $i++ ) { try { $admin->handle_pipeline_progress(); } catch ( ProgressResponse $response ) { progress_check( $response->success && 100 === $response->data['progress']['percent'] && 8 === $response->data['batch_id'], 'Polling returned wrong safe progress.' ); progress_check( ! preg_match( '/token|secret|phone|address|raw_data|credential|recipient/i', json_encode( $response->data ) ), 'Polling leaked unsafe data.' ); } }
progress_check( 3 === $db->reads && 0 === $db->writes, 'Polling did not remain one-read/no-write per request.' );
$GLOBALS['allowed'] = false;
try { $admin->handle_pipeline_progress(); throw new RuntimeException( 'Unauthorized poll allowed.' ); } catch ( RuntimeException $exception ) { progress_check( 'DENIED' === $exception->getMessage(), 'Admin capability not enforced.' ); }
progress_check( 3 === $db->reads, 'Unauthorized poll read Batch state.' );
$GLOBALS['allowed'] = true; $_GET['nonce'] = 'invalid';
try { $admin->handle_pipeline_progress(); throw new RuntimeException( 'Invalid nonce allowed.' ); } catch ( RuntimeException $exception ) { progress_check( 'Nonce not enforced.' === $exception->getMessage(), 'Nonce protection failed.' ); }
progress_check( 3 === $db->reads && 0 === $db->writes, 'Rejected poll changed or read persisted state.' );
echo "WP.6E.1 progress projection and safe polling: PASS\n";
