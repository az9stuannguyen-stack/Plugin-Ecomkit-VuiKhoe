<?php
/** Large-window reconciliation through real services and fake provider/Cron transport. */
declare(strict_types=1);

require __DIR__ . '/shopee-reconciliation-check.php';
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-auto-pipeline.php';

$GLOBALS['detail_cron'] = array();
function add_option( string $key, mixed $value, string $deprecated = '', bool $autoload = false ): bool { if ( array_key_exists( $key, $GLOBALS['recon_options'] ) ) { return false; } $GLOBALS['recon_options'][ $key ] = $value; return true; }
function delete_option( string $key ): bool { unset( $GLOBALS['recon_options'][ $key ] ); return true; }
function wp_next_scheduled( string $hook, array $args ): int|false { foreach ( $GLOBALS['detail_cron'] as $event ) { if ( $event['hook'] === $hook && $event['args'] === $args ) { return $event['time']; } } return false; }
function wp_schedule_single_event( int $time, string $hook, array $args ): bool { $GLOBALS['detail_cron'][] = compact( 'time', 'hook', 'args' ); return true; }

function detail_fixture( int $batch_id, int $count, int $lazada = 0 ): array {
	global $wpdb, $base;
	$wpdb->batches[ $batch_id ] = array( 'id' => $batch_id, 'source_type' => 'EXCEL', 'status' => 'WARNING', 'order_count' => $count + $lazada, 'source_filename' => 'synthetic-large-window.xlsx', 'source_metadata' => json_encode( array( 'platform_counts' => array( 'SHOPEE' => $count, 'LAZADA' => $lazada ), 'item_rows' => 146 ) ) );
	$ids = array();
	for ( $offset = 0; $offset < $count + $lazada; $offset++ ) {
		$id = $batch_id * 1000 + $offset;
		$sn = sprintf( 'TEST-%03d-%03d', $batch_id, $offset );
		$wpdb->orders[ $id ] = array_merge( $base, array( 'id' => $id, 'batch_id' => $batch_id, 'platform' => $offset < $count ? 'SHOPEE' : 'LAZADA', 'marketplace_order_id' => $sn, 'order_date' => '2026-10-03 10:00:00', 'raw_source_metadata' => '{}', 'source_refs' => '{}', 'payment_normalized_data' => null ) );
		if ( $offset < $count ) { $ids[] = $sn; }
	}
	return $ids;
}
function detail_queue_list( array $ids ): void {
	$chunks = array_chunk( $ids, 100 );
	foreach ( $chunks as $page => $chunk ) { $GLOBALS['recon_get_responses'][] = recon_response( array( 'error' => '', 'request_id' => 'list-' . $page, 'response' => array( 'more' => $page + 1 < count( $chunks ), 'next_cursor' => $page + 1 < count( $chunks ) ? 'list-next-' . $page : '', 'order_list' => array_map( static fn( string $sn ): array => array( 'order_sn' => $sn ), $chunk ) ) ) ); }
}
function detail_queue_details( array $ids ): void {
	foreach ( array_chunk( $ids, 50 ) as $chunk ) { $GLOBALS['recon_get_responses'][] = recon_response( array( 'error' => '', 'request_id' => 'detail-50', 'response' => array( 'order_list' => array_map( static fn( string $sn ): array => array( 'order_sn' => $sn, 'update_time' => 200, 'buyer_username' => 'PRIVATE_DETAIL_MARKER' ), $chunk ) ) ) ); }
}
function detail_worker( Ecomkit_Vuikhoe_Auto_Pipeline $pipeline ): void { $event = array_shift( $GLOBALS['detail_cron'] ); recon_check( is_array( $event ), 'Expected a scheduled worker.' ); $pipeline->run_one( (int) $event['args'][0] ); }

// Batch 44 shape: 79 exact Shopee matches in 151 provider IDs, plus 10 untouched Lazada orders.
$ids = detail_fixture( 44, 79, 10 );
$lazada_before = array_slice( array_values( array_filter( $wpdb->orders, static fn( array $row ): bool => 44 === $row['batch_id'] && 'LAZADA' === $row['platform'] ) ), 0 );
detail_queue_list( array_merge( $ids, array_map( static fn( int $i ): string => 'EXTRA-' . $i, range( 1, 72 ) ) ) );
detail_queue_details( $ids );
$calls_before = count( $GLOBALS['recon_get_calls'] ); $payments = 0; $materializations = 0;
$ops = array(
	'ready_connections' => static fn(): array => array( array( 'id' => $connection_id ) ),
	'reconcile' => static fn( int $batch, int $connection, array $limits ): array => ( new Ecomkit_Vuikhoe_Shopee_Reconciliation_Service() )->reconcile_batch( $batch, $connection, $limits ),
	'matched_orders' => static fn( int $batch ): array => array_values( array_filter( $GLOBALS['wpdb']->orders, static fn( array $row ): bool => $batch === $row['batch_id'] && 'SHOPEE' === $row['platform'] && 'MATCHED' === $row['matching_status'] ) ),
	'payment' => static function ( int $batch, int $order ) use ( &$payments ): void { $payments++; $row = &$GLOBALS['wpdb']->orders[ $order ]; $row['payment_normalized_data'] = json_encode( array( 'marketplaceOrderId' => $row['marketplace_order_id'] ) ); },
	'income_page' => static fn(): array => array( 'classification' => 'SHOPEE_INCOME_EMPTY', 'records' => array(), 'next_cursor' => '', 'request_id' => 'income-empty' ),
	'materialize' => static function ( int $batch ) use ( &$materializations ): array { $materializations++; return array( 'rows' => count( array_filter( $GLOBALS['wpdb']->orders, static fn( array $row ): bool => $batch === $row['batch_id'] ) ) ); },
);
// A stopped old run must be recoverable using the existing explicit Resume action.
$meta = json_decode( $wpdb->batches[44]['source_metadata'], true );
$meta['auto_pipeline'] = array( 'status' => 'ERROR', 'stage' => 'MATERIALIZE', 'run_sequence' => 2, 'reconciliation_status' => 'INCOMPLETE', 'counts' => array( 'shopee_orders' => 79, 'reconcile_checked' => 79 ), 'reconcile_total_windows' => 1, 'reconcile_window_offset' => 1 );
$meta['shopee_reconciliation'] = array( 'status' => 'INCOMPLETE', 'matched_count' => 79, 'detail_count' => 0 );
$wpdb->batches[44]['source_metadata'] = json_encode( $meta );
$pipeline = new Ecomkit_Vuikhoe_Auto_Pipeline( $ops ); $pipeline->start( 44, true ); detail_worker( $pipeline );
$partial = $pipeline->state( 44 );
recon_check( 'RECONCILE' === $partial['stage'] && 'PROCESSING' === $partial['reconciliation_status'] && 79 === $partial['counts']['shopee_matched'] && 50 === $partial['counts']['detail_fetched'] && 0 === $partial['reconcile_window_offset'] && 0 === $payments && 0 === $materializations && 'ORDER_DETAIL' === Ecomkit_Vuikhoe_Auto_Pipeline::progress( $partial )['stage'], '79-order window advanced before the remaining 29 details or hid 50 saved details.' );
recon_check( 3 === count( $GLOBALS['recon_get_calls'] ) - $calls_before && ! str_contains( json_encode( $partial ), 'PRIVATE_DETAIL_MARKER' ) && ! str_contains( json_encode( $partial['reconcile_continuation'] ), 'orders_by_sn' ), 'First worker exceeded its request bound or stored PII in the checkpoint.' );
recon_check( 'MATCHED' === $wpdb->orders[44000]['matching_status'] && null === $wpdb->orders[44050]['matching_status'], 'First detail chunk was not saved independently.' );
$GLOBALS['detail_cron'] = array(); // Simulate process/tab interruption and explicit resume.
$pipeline = new Ecomkit_Vuikhoe_Auto_Pipeline( $ops ); $pipeline->start( 44, true );
recon_check( 50 === $pipeline->state(44)['counts']['detail_fetched'] && 79 === $pipeline->state(44)['counts']['shopee_matched'], 'Explicit resume hid the persisted detail/match counts.' );
detail_worker( $pipeline );
$summary = json_decode( $wpdb->batches[44]['source_metadata'], true )['shopee_reconciliation'];
recon_check( 'PAYMENT' === $pipeline->state(44)['stage'] && 'SUCCESS' === $summary['status'] && 79 === $summary['detail_count'] && 79 === $summary['matched_count'] && 151 === $summary['provider_count'] && 72 === $summary['extra_count'] && 4 === $summary['shopee_api_calls'] && 1 === count( $summary['windows'] ) && 4 === count( $GLOBALS['recon_get_calls'] ) - $calls_before, 'Resume refetched the list/first 50 details or double-counted provider evidence.' );
for ( $step = 0; $step < 120 && $GLOBALS['detail_cron']; $step++ ) { detail_worker( $pipeline ); }
$done = $pipeline->state(44);
recon_check( ! $GLOBALS['detail_cron'] && 'RESULT_READY' === $done['stage'] && 89 === $done['counts']['canonical_rows'] && 79 === $payments && 1 === $materializations && 100 === Ecomkit_Vuikhoe_Auto_Pipeline::progress( $done )['percent'] && ! $done['errors'], '79 Shopee + 10 Lazada orchestration did not finish at 89 rows.' );
recon_check( $lazada_before === array_values( array_filter( $wpdb->orders, static fn( array $row ): bool => 44 === $row['batch_id'] && 'LAZADA' === $row['platform'] ) ), 'Shopee detail continuation changed Lazada evidence.' );

// Two date windows; the second needs three detail workers. Completed-window counts must stay stable.
$multi_ids = detail_fixture( 45, 124 );
foreach ( range( 0, 2 ) as $offset ) { $wpdb->orders[45000 + $offset]['order_date'] = '2026-09-24 10:00:00'; }
detail_queue_list( array_slice( $multi_ids, 0, 3 ) ); detail_queue_details( array_slice( $multi_ids, 0, 3 ) );
detail_queue_list( array_slice( $multi_ids, 3 ) ); detail_queue_details( array_slice( $multi_ids, 3 ) );
$limits = array( 'window_offset' => 0, 'max_windows' => 1, 'max_pages' => 2, 'max_detail_batches' => 1 );
$service = new Ecomkit_Vuikhoe_Shopee_Reconciliation_Service(); $first_window = $service->reconcile_batch( 45, $connection_id, $limits );
recon_check( 3 === $first_window['detail_count'] && 1 === $first_window['next_window_offset'] && 2 === $first_window['total_windows'], 'First date window failed.' );
$limits['window_offset'] = 1;
foreach ( array( 53, 103, 124 ) as $expected ) {
	$chunk = ( new Ecomkit_Vuikhoe_Shopee_Reconciliation_Service() )->reconcile_batch( 45, $connection_id, $limits );
	recon_check( $expected === $chunk['detail_count'] && 124 === $chunk['matched_count'] && 2 === count( $chunk['windows'] ), 'Detail continuation lost or duplicated completed-window counts.' );
	$limits['continuation'] = $chunk['continuation'] ?? array();
}
recon_check( 'SUCCESS' === $chunk['status'] && 2 === $chunk['next_window_offset'] && 7 === $chunk['shopee_api_calls'], 'Three detail chunks did not finish with exact request/window totals.' );

// A real detail API failure after chunk one keeps its 50 snapshots and produces a usable warning Result.
$failed_ids = detail_fixture( 46, 79 ); detail_queue_list( $failed_ids ); detail_queue_details( array_slice( $failed_ids, 0, 50 ) );
$GLOBALS['recon_get_responses'][] = recon_response( array( 'error' => 'error_server', 'request_id' => 'real-detail-error' ) );
$payments_before = $payments;
$failed_pipeline = new Ecomkit_Vuikhoe_Auto_Pipeline( $ops ); $failed_pipeline->start(46); detail_worker( $failed_pipeline ); detail_worker( $failed_pipeline ); detail_worker( $failed_pipeline );
$failed = $failed_pipeline->state(46);
recon_check( 'WARNING' === $failed['status'] && 'RESULT_READY' === $failed['stage'] && 79 === $failed['counts']['canonical_rows'] && 50 === $failed['counts']['detail_fetched'] && $payments_before === $payments && ! $failed['errors'] && in_array( 'SHOPEE_RECON_DETAIL_FAILED', $failed['warnings'], true ) && 'MATCHED' === $wpdb->orders[46000]['matching_status'] && null === $wpdb->orders[46050]['matching_status'], 'Real detail error destroyed earlier snapshots, called downstream Payment, or reproduced the 70% crash.' );

// Exact 50-ID boundary finishes once; 51 needs a continuation; a two-chunk budget handles 100.
foreach ( array( array( 47, 50, 1 ), array( 48, 51, 1 ), array( 49, 100, 2 ) ) as [ $batch, $size, $budget ] ) {
	$boundary_ids = detail_fixture( $batch, $size ); detail_queue_list( $boundary_ids ); detail_queue_details( $boundary_ids );
	$boundary_limits = array( 'max_windows' => 1, 'max_pages' => 2, 'max_detail_batches' => $budget );
	$boundary = $service->reconcile_batch( $batch, $connection_id, $boundary_limits );
	if ( 51 === $size ) {
		recon_check( 'PROCESSING' === $boundary['status'] && 50 === $boundary['detail_count'], '51-ID boundary did not checkpoint its first 50.' );
		$boundary_limits['continuation'] = $boundary['continuation'];
		$boundary = $service->reconcile_batch( $batch, $connection_id, $boundary_limits );
	}
	recon_check( 'SUCCESS' === $boundary['status'] && $size === $boundary['detail_count'] && ! isset( $boundary['continuation'] ), 'Exact detail chunk boundary failed.' );
}

// Missing details are distinguished from unrequested pending IDs and stay classified across chunks.
$missing_ids = detail_fixture( 50, 51 ); detail_queue_list( $missing_ids ); detail_queue_details( array_slice( $missing_ids, 0, 49 ) ); detail_queue_details( array_slice( $missing_ids, 50 ) );
$missing_limits = array( 'max_windows' => 1, 'max_pages' => 2, 'max_detail_batches' => 1 );
$missing_chunk = $service->reconcile_batch( 50, $connection_id, $missing_limits );
recon_check( 'PROCESSING' === $missing_chunk['status'] && 1 === $missing_chunk['detail_missing_count'] && 'DETAIL_MISSING' === $wpdb->orders[50049]['matching_status'] && null === $wpdb->orders[50050]['matching_status'], 'Pending ID was incorrectly classified as a missing provider detail.' );
$missing_limits['continuation'] = $missing_chunk['continuation'];
$missing_done = $service->reconcile_batch( 50, $connection_id, $missing_limits );
recon_check( 'INCOMPLETE' === $missing_done['status'] && 'SHOPEE_RECON_DETAIL_MISSING' === $missing_done['classification'] && 50 === $missing_done['detail_count'] && 1 === $missing_done['detail_missing_count'], 'Missing detail was forgotten on the next chunk.' );
recon_check( ! $GLOBALS['recon_get_responses'] && ! $GLOBALS['detail_cron'], 'Unused provider responses or pending workers remain.' );

echo "Large Shopee detail continuation and Batch 44 recovery: PASS\n";
