<?php
/** WP.5 fake-transport reconciliation and normalization checks. */
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ECOMKIT_CREDENTIAL_KEY', base64_encode( str_repeat( 'R', 32 ) ) );
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['recon_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool $autoload = false ): bool { $GLOBALS['recon_options'][ $key ] = $value; return true; }
function current_time( string $type, bool $gmt = false ): string { return '2026-10-03 01:02:03'; }
function wp_timezone(): DateTimeZone { return new DateTimeZone( 'America/New_York' ); }
function rest_url( string $path ): string { return 'https://example.test/wp-json/' . $path; }
function wp_http_validate_url( string $url ): string|false { return filter_var( $url, FILTER_VALIDATE_URL ); }
function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); }
function add_query_arg( array $args, string $url ): string { return $url . '?' . http_build_query( $args ); }
function sanitize_text_field( string $value ): string { return trim( $value ); }
function sanitize_key( string $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
final class ReconWpError {}
function is_wp_error( mixed $value ): bool { return $value instanceof ReconWpError; }
function wp_remote_get( string $url, array $args ): mixed { $GLOBALS['recon_get_calls'][] = compact( 'url', 'args' ); return array_shift( $GLOBALS['recon_get_responses'] ); }
function wp_remote_post( string $url, array $args ): mixed { $GLOBALS['recon_post_calls'][] = compact( 'url', 'args' ); return array_shift( $GLOBALS['recon_post_responses'] ); }
function wp_remote_retrieve_response_code( array $response ): int { return $response['status']; }
function wp_remote_retrieve_body( array $response ): string { return $response['body']; }
function recon_response( array $data, int $status = 200 ): array { return array( 'status' => $status, 'body' => json_encode( $data ) ); }
function recon_check( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }

final class ReconWpdb {
	public string $prefix = 'recon_';
	public int $insert_id = 0;
	public array $connections = array();
	public array $batches = array();
	public array $orders = array();
	public array $errors = array();
	public array $queries = array();
	public function prepare( string $query, mixed ...$args ): string { return json_encode( compact( 'query', 'args' ) ); }
	private function prepared( string $query ): array { $value = json_decode( $query, true ); return is_array( $value ) ? $value : array( 'query' => $query, 'args' => array() ); }
	public function get_var( string $query ): mixed {
		$p = $this->prepared( $query );
		if ( str_contains( $p['query'], 'GET_LOCK' ) || str_contains( $p['query'], 'RELEASE_LOCK' ) ) { return 1; }
		if ( str_contains( $p['query'], 'marketplace_connections' ) ) { foreach ( $this->connections as $id => $row ) { if ( $row['platform'] === ( $p['args'][0] ?? null ) && $row['external_shop_id'] === ( $p['args'][1] ?? null ) ) { return $id; } } }
		return 0;
	}
	public function get_row( string $query, string $output ): ?array {
		$p = $this->prepared( $query );
		if ( str_contains( $p['query'], 'ecomkit_batches' ) ) { return $this->batches[ (int) ( $p['args'][0] ?? 0 ) ] ?? null; }
		if ( str_contains( $p['query'], 'marketplace_connections' ) ) { return $this->connections[ (int) ( $p['args'][0] ?? 0 ) ] ?? null; }
		return null;
	}
	public function get_results( string $query, string $output ): array {
		$p = $this->prepared( $query );
		if ( str_contains( $p['query'], 'marketplace_connections' ) ) { return array_values( array_filter( $this->connections, static fn( array $row ): bool => $row['platform'] === ( $p['args'][0] ?? '' ) ) ); }
		if ( str_contains( $p['query'], 'ecomkit_orders' ) ) { $batch = (int) ( $p['args'][0] ?? 0 ); $platform = (string) ( $p['args'][1] ?? '' ); return array_values( array_filter( $this->orders, static fn( array $row ): bool => (int) $row['batch_id'] === $batch && $row['platform'] === $platform ) ); }
		return array();
	}
	public function insert( string $table, array $data ): int|false {
		$this->insert_id++;
		if ( str_ends_with( $table, 'marketplace_connections' ) ) { $this->connections[ $this->insert_id ] = $data + array( 'id' => $this->insert_id ); }
		if ( str_ends_with( $table, 'ecomkit_errors' ) ) { $this->errors[] = $data + array( 'id' => $this->insert_id ); }
		return 1;
	}
	public function update( string $table, array $data, array $where ): int|false {
		$id = (int) $where['id'];
		if ( str_ends_with( $table, 'marketplace_connections' ) ) { $this->connections[ $id ] = array_merge( $this->connections[ $id ], $data ); }
		if ( str_ends_with( $table, 'ecomkit_orders' ) ) { $this->orders[ $id ] = array_merge( $this->orders[ $id ], $data ); }
		if ( str_ends_with( $table, 'ecomkit_batches' ) ) { $this->batches[ $id ] = array_merge( $this->batches[ $id ], $data ); }
		return 1;
	}
	public function query( string $query ): int|false { $this->queries[] = $query; return 1; }
}

$GLOBALS['wpdb'] = new ReconWpdb();
$GLOBALS['recon_options'] = array();
$GLOBALS['recon_get_calls'] = array();
$GLOBALS['recon_get_responses'] = array();
$GLOBALS['recon_post_calls'] = array();
$GLOBALS['recon_post_responses'] = array();
require __DIR__ . '/../ecomkit-vuikhoe/vendor/autoload.php';
foreach ( array( 'class-ecomkit-db.php', 'class-ecomkit-credential-key-resolver.php', 'class-ecomkit-credential-encryption.php', 'class-ecomkit-credential-mutation-lock.php', 'class-ecomkit-shopee-environment.php', 'class-ecomkit-shopee-signer.php', 'class-ecomkit-shopee-config.php', 'class-ecomkit-shopee-http-client.php', 'class-ecomkit-marketplace-connection-service.php', 'class-ecomkit-shopee-token-service.php', 'class-ecomkit-shopee-order-service.php', 'class-ecomkit-shopee-order-normalizer.php', 'class-ecomkit-shopee-reconciliation-service.php' ) as $file ) { require __DIR__ . '/../ecomkit-vuikhoe/includes/' . $file; }

$sets = Ecomkit_Vuikhoe_Shopee_Reconciliation_Service::compare_exact_sets( array( 'A', 'B', 'C', 'ABC123' ), array( 'A', 'B', 'D', 'abc123' ) );
recon_check( array( 'A', 'B' ) === $sets['matched'] && array( 'C', 'ABC123' ) === $sets['missing'] && array( 'D', 'abc123' ) === $sets['extra'], 'Exact, case-sensitive set comparison failed.' );
$timezone = wp_timezone();
$windows = Ecomkit_Vuikhoe_Shopee_Reconciliation_Service::derive_windows( array( '2026-09-16', '2026-09-17', '2026-10-01', '2026-10-02' ), $timezone );
recon_check( 2 === count( $windows ) && '2026-09-16' === $windows[0]['start_date'] && '2026-09-17' === $windows[0]['end_date'], 'Automatic contiguous date windows failed.' );
$long_dates = array_map( static fn( int $day ): string => ( new DateTimeImmutable( '2026-01-01' ) )->modify( '+' . $day . ' days' )->format( 'Y-m-d' ), range( 0, 20 ) );
$long_windows = Ecomkit_Vuikhoe_Shopee_Reconciliation_Service::derive_windows( $long_dates, $timezone );
recon_check( 2 === count( $long_windows ) && $long_windows[0]['time_to'] - $long_windows[0]['time_from'] <= 1296000, '>15-day window was not split safely.' );
$dst_dates = array_map( static fn( int $day ): string => ( new DateTimeImmutable( '2026-10-25' ) )->modify( '+' . $day . ' days' )->format( 'Y-m-d' ), range( 0, 14 ) );
foreach ( Ecomkit_Vuikhoe_Shopee_Reconciliation_Service::derive_windows( $dst_dates, $timezone ) as $dst_window ) { recon_check( $dst_window['time_to'] - $dst_window['time_from'] <= 1296000, 'DST produced an oversized provider window.' ); }
recon_check( '2026-09-17' === Ecomkit_Vuikhoe_Shopee_Reconciliation_Service::parse_excel_local_date( '17/09/2026 10:30', $timezone ) && null === Ecomkit_Vuikhoe_Shopee_Reconciliation_Service::parse_excel_local_date( '', $timezone ), 'Excel date parsing/missing-date behavior failed.' );

$normal = Ecomkit_Vuikhoe_Shopee_Order_Normalizer::normalize( array( 'order_sn' => 'A', 'order_status' => 'COMPLETED', 'create_time' => 100, 'update_time' => 200, 'buyer_username' => 'buyer', 'recipient_address' => array( 'name' => 'Recipient', 'phone' => 'phone', 'full_address' => 'address' ), 'item_list' => array( array( 'item_id' => 1, 'item_name' => 'Item', 'model_quantity_purchased' => 2, 'model_discounted_price' => 50 ) ), 'total_amount' => 100 ) );
recon_check( 'A' === $normal['marketplaceOrderId'] && 'A' === $normal['rawOrderCode'] && 100 === $normal['totalAmount'] && null === $normal['actualShippingFee'] && ! array_key_exists( 'sellerSettlement', $normal ), 'Pure normalizer or financial safety failed.' );

$config = ( new Ecomkit_Vuikhoe_Shopee_Config() )->save( 'sandbox', '123', 'fake-partner-key-not-secret' );
$connection_id = ( new Ecomkit_Vuikhoe_Marketplace_Connection_Service() )->upsert_shopee( '456', array( 'access_token' => 'FAKE_ACCESS', 'refresh_token' => 'FAKE_REFRESH', 'expire_in' => 3600 ), $config['fingerprint'] );
$wpdb = $GLOBALS['wpdb'];
$wpdb->batches[10] = array( 'id' => 10, 'source_type' => 'EXCEL', 'status' => 'SUCCESS', 'source_filename' => 'fixture.xlsx', 'source_metadata' => json_encode( array( 'parser_version' => 'wp2b-v1', 'date_column' => 3 ) ) );
$base = array( 'batch_id' => 10, 'platform' => 'SHOPEE', 'matching_status' => null, 'connection_id' => null, 'provider_raw_data' => null, 'provider_normalized_data' => null, 'provider_updated_at' => null );
$wpdb->orders[1] = $base + array( 'id' => 1, 'marketplace_order_id' => 'A', 'order_date' => '2026-09-16 10:00:00', 'raw_source_metadata' => json_encode( array( 'cells' => array( '3' => '16/09/2026 10:00' ), 'excel_marker' => 'KEEP-A' ) ), 'source_refs' => json_encode( array( 'sheet' => 'Orders', 'row' => 4 ) ) );
$wpdb->orders[2] = $base + array( 'id' => 2, 'marketplace_order_id' => 'B', 'order_date' => '2026-09-17 10:00:00', 'raw_source_metadata' => json_encode( array( 'cells' => array( '3' => '17/09/2026 10:00' ) ) ), 'source_refs' => json_encode( array( 'sheet' => 'Orders', 'row' => 5 ) ) );
$wpdb->orders[3] = $base + array( 'id' => 3, 'marketplace_order_id' => 'C', 'order_date' => null, 'raw_source_metadata' => json_encode( array( 'cells' => array( '3' => '17/09/2026 11:00' ) ) ), 'source_refs' => json_encode( array( 'sheet' => 'Orders', 'row' => 6 ) ) );
$wpdb->orders[4] = $base + array( 'id' => 4, 'marketplace_order_id' => 'NO-DATE', 'order_date' => null, 'raw_source_metadata' => json_encode( array( 'cells' => array( '3' => '' ) ) ), 'source_refs' => json_encode( array( 'sheet' => 'Orders', 'row' => 7 ) ) );
$wpdb->orders[5] = array_merge( $base, array( 'id' => 5, 'batch_id' => 10, 'platform' => 'LAZADA', 'marketplace_order_id' => 'LZ', 'order_date' => '2026-09-17 10:00:00', 'raw_source_metadata' => '{}', 'source_refs' => '{}' ) );
$excel_raw_before = $wpdb->orders[1]['raw_source_metadata'];

function queue_reconciliation( int $update_time ): void {
	$GLOBALS['recon_get_responses'][] = recon_response(
		array( 'error' => '', 'request_id' => 'list', 'response' => array( 'more' => false, 'next_cursor' => '', 'order_list' => array( array( 'order_sn' => 'A' ), array( 'order_sn' => 'B' ), array( 'order_sn' => 'D' ) ) ) )
	);
	$GLOBALS['recon_get_responses'][] = recon_response(
		array( 'error' => '', 'request_id' => 'detail', 'response' => array( 'order_list' => array( array( 'order_sn' => 'A', 'order_status' => 'COMPLETED', 'create_time' => 100, 'update_time' => $update_time, 'item_list' => array(), 'total_amount' => $update_time ), array( 'order_sn' => 'B', 'order_status' => 'SHIPPED', 'create_time' => 101, 'update_time' => $update_time, 'item_list' => array() ) ) ) )
	);
}

$service = new Ecomkit_Vuikhoe_Shopee_Reconciliation_Service();
queue_reconciliation( 100 );
$summary = $service->reconcile_batch( 10 );
recon_check( 4 === $summary['excel_shopee_count'] && 3 === $summary['provider_count'] && 2 === $summary['matched_count'] && 1 === $summary['missing_count'] && 1 === $summary['extra_count'] && 2 === $summary['detail_count'] && 1 === $summary['missing_date_count'] && 'WARNING' === $summary['status'], 'Reconciliation counts are wrong.' );
recon_check( 'MATCHED' === $wpdb->orders[1]['matching_status'] && 'MATCHED' === $wpdb->orders[2]['matching_status'] && 'NOT_FOUND_IN_SHOPEE' === $wpdb->orders[3]['matching_status'] && null === $wpdb->orders[4]['matching_status'], 'Matching status policy failed.' );
recon_check( $connection_id === $wpdb->orders[1]['connection_id'] && null === $wpdb->orders[5]['connection_id'] && null === $wpdb->orders[5]['matching_status'], 'Connection binding or LAZADA isolation failed.' );
recon_check( $excel_raw_before === $wpdb->orders[1]['raw_source_metadata'] && ! empty( $wpdb->orders[1]['provider_raw_data'] ) && ! empty( $wpdb->orders[1]['provider_normalized_data'] ), 'Excel/provider raw-normalized separation failed.' );
recon_check( 2 === count( $GLOBALS['recon_get_calls'] ) && str_contains( $GLOBALS['recon_get_calls'][0]['url'], 'time_range_field=create_time' ), 'Provider calls were not bounded to list plus matched-only detail.' );
recon_check( (bool) array_filter( $wpdb->errors, static fn( array $error ): bool => 'SHOPEE_RECON_ORDER_DATE_MISSING' === $error['error_code'] ), 'Missing Excel date error was not persisted.' );

$order_count = count( $wpdb->orders );
$old_raw = $wpdb->orders[1]['provider_raw_data'];
queue_reconciliation( 50 );
$service->reconcile_batch( 10 );
recon_check( $old_raw === $wpdb->orders[1]['provider_raw_data'], 'Older provider snapshot overwrote newer evidence.' );
queue_reconciliation( 200 );
$service->reconcile_batch( 10 );
recon_check( $old_raw !== $wpdb->orders[1]['provider_raw_data'] && count( $wpdb->orders ) === $order_count, 'Newer snapshot or idempotent rerun failed.' );

$GLOBALS['recon_get_responses'][] = recon_response( array( 'error' => '', 'response' => array( 'more' => false, 'next_cursor' => '', 'order_list' => array( array( 'order_sn' => 'A' ), array( 'order_sn' => 'B' ), array( 'order_sn' => 'D' ) ) ) ) );
$GLOBALS['recon_get_responses'][] = recon_response( array( 'error' => '', 'response' => array( 'order_list' => array( array( 'order_sn' => 'A', 'update_time' => 200 ) ) ) ) );
$detail_incomplete = $service->reconcile_batch( 10 );
recon_check( 'DETAIL_MISSING' === $wpdb->orders[2]['matching_status'] && 1 === $detail_incomplete['detail_missing_count'] && (bool) array_filter( $wpdb->errors, static fn( array $error ): bool => 'SHOPEE_RECON_DETAIL_MISSING' === $error['error_code'] ), 'Missing matched detail was not handled safely.' );

$status_before_failure = $wpdb->orders[3]['matching_status'];
$GLOBALS['recon_get_responses'][] = recon_response( array( 'error' => '', 'response' => array( 'more' => true, 'next_cursor' => 'NEXT', 'order_list' => array( array( 'order_sn' => 'A' ) ) ) ) );
$GLOBALS['recon_get_responses'][] = recon_response( array( 'error' => 'error_server', 'message' => 'safe', 'request_id' => 'failed-page' ) );
$incomplete = $service->reconcile_batch( 10 );
recon_check( 'INCOMPLETE' === $incomplete['status'] && $status_before_failure === $wpdb->orders[3]['matching_status'] && (bool) array_filter( $wpdb->errors, static fn( array $error ): bool => 'SHOPEE_RECON_PAGINATION_INCOMPLETE' === $error['error_code'] ), 'Partial pagination produced false NOT_FOUND or lost its actionable error.' );

$seventy_five = array_map( static fn( int $n ): string => 'BATCH-' . $n, range( 1, 75 ) );
$GLOBALS['recon_get_responses'][] = recon_response( array( 'error' => '', 'response' => array( 'order_list' => array_map( static fn( string $sn ): array => array( 'order_sn' => $sn ), array_slice( $seventy_five, 0, 50 ) ) ) ) );
$GLOBALS['recon_get_responses'][] = recon_response( array( 'error' => '', 'response' => array( 'order_list' => array_map( static fn( string $sn ): array => array( 'order_sn' => $sn ), array_slice( $seventy_five, 50 ) ) ) ) );
$before_detail_calls = count( $GLOBALS['recon_get_calls'] );
$detail_75 = ( new Ecomkit_Vuikhoe_Shopee_Order_Service() )->get_order_details_batched( $connection_id, $seventy_five );
recon_check( 75 === $detail_75['returned_count'] && 2 === count( $GLOBALS['recon_get_calls'] ) - $before_detail_calls, '75 details were not batched as 50 + 25.' );

$wpdb->batches[11] = array( 'id' => 11, 'source_type' => 'EXCEL', 'status' => 'SUCCESS', 'source_filename' => 'lazada-only.xlsx', 'source_metadata' => '{}' );
$wpdb->orders[6] = array_merge( $base, array( 'id' => 6, 'batch_id' => 11, 'platform' => 'LAZADA', 'marketplace_order_id' => 'ONLY-LAZADA', 'order_date' => '2026-09-17 10:00:00', 'raw_source_metadata' => '{}', 'source_refs' => '{}' ) );
$calls_before_empty = count( $GLOBALS['recon_get_calls'] );
try { $service->reconcile_batch( 11 ); throw new RuntimeException( 'LAZADA-only Batch was accepted.' ); } catch ( Ecomkit_Vuikhoe_Shopee_Reconciliation_Exception $exception ) { recon_check( 'SHOPEE_RECON_NO_SHOPEE_ORDERS' === $exception->getMessage(), 'No-Shopee classification failed.' ); }
recon_check( $calls_before_empty === count( $GLOBALS['recon_get_calls'] ), 'LAZADA-only Batch called Shopee.' );

( new Ecomkit_Vuikhoe_Marketplace_Connection_Service() )->upsert_shopee( '789', array( 'access_token' => 'FAKE_ACCESS_2', 'refresh_token' => 'FAKE_REFRESH_2', 'expire_in' => 3600 ), $config['fingerprint'] );
$calls_before_ambiguous = count( $GLOBALS['recon_get_calls'] );
try { $service->reconcile_batch( 10 ); throw new RuntimeException( 'Ambiguous multi-shop selection was accepted.' ); } catch ( Ecomkit_Vuikhoe_Shopee_Reconciliation_Exception $exception ) { recon_check( 'SHOPEE_RECON_CONNECTION_AMBIGUOUS' === $exception->getMessage(), 'Multi-shop ambiguity classification failed.' ); }
recon_check( $calls_before_ambiguous === count( $GLOBALS['recon_get_calls'] ), 'Ambiguous multi-shop Batch called Shopee.' );

$admin_source = file_get_contents( __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-admin.php' );
$process_view = file_get_contents( __DIR__ . '/../ecomkit-vuikhoe/admin/views/process.php' );
$results_view = file_get_contents( __DIR__ . '/../ecomkit-vuikhoe/admin/views/results.php' );
recon_check( str_contains( (string) $admin_source, "require_management_capability();\n\t\tcheck_admin_referer( 'ecomkit_shopee_reconcile_batch'" ), 'Reconciliation capability/nonce enforcement is missing.' );
recon_check( str_contains( (string) $process_view, 'ecomkit_shopee_reconcile_batch' ) && ! str_contains( (string) $process_view, 'time_from' ) && ! str_contains( (string) $process_view, 'time_to' ) && ! str_contains( (string) $process_view, 'order_sn_list' ), 'Admin reconciliation asks for manual dates or order IDs.' );
recon_check( ! str_contains( (string) $results_view, 'provider_raw_data' ) && ! str_contains( (string) $results_view, 'recipientPhone' ), 'Provider raw data or PII reached the summary view.' );
recon_check( 0 === count( $GLOBALS['recon_post_calls'] ), 'Unexpected token/provider POST occurred during fake reconciliation.' );
echo "WP.5 Shopee reconciliation checks passed.\n";
