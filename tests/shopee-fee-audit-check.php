<?php
/** Synthetic-only WP.6H.1A audit projection; no provider or business writes. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ECOMKIT_VUIKHOE_VERSION', '0.7.4' );
function audit_check( bool $ok, string $message ): void { if ( ! $ok ) { throw new RuntimeException( $message ); } }
function wp_remote_post(): never { $GLOBALS['provider_calls']++; throw new RuntimeException( 'Provider call forbidden.' ); }
function wp_remote_get(): never { $GLOBALS['provider_calls']++; throw new RuntimeException( 'Provider call forbidden.' ); }
final class FeeAuditWpdb {
	public string $prefix = 'audit_';
	public array $rows = array();
	public array $queries = array();
	public int $writes = 0;
	public function prepare( string $sql, mixed ...$args ): string { return json_encode( array( 'sql' => $sql, 'args' => $args ) ); }
	public function get_results( string $query, string $mode ): array {
		$this->queries[] = json_decode( $query, true );
		$args = $this->queries[ array_key_last( $this->queries ) ]['args'];
		return array_values( array_filter( $this->rows, static fn( array $row ): bool => (int) $row['batch_id'] === (int) $args[0] && 'SHOPEE' === $args[1] && $row['marketplace_order_id'] === $args[2] ) );
	}
	public function update(): never { $this->writes++; throw new RuntimeException( 'Business write forbidden.' ); }
	public function insert(): never { $this->writes++; throw new RuntimeException( 'Business write forbidden.' ); }
}
$GLOBALS['provider_calls'] = 0;
$GLOBALS['wpdb'] = new FeeAuditWpdb();
require __DIR__ . '/../ecomkit-vuikhoe/vendor/autoload.php';

function fixture_row( string $id, int $service, bool $candidate = true ): array {
	$income = array(
		'service_fee' => $service, 'commission_fee' => 84810, 'seller_transaction_fee' => 30840,
		'buyer_name' => 'PRIVATE_PERSON', 'recipient_address' => 'PRIVATE_ADDRESS',
		'phone' => 'PRIVATE_PHONE', 'access_token' => 'PRIVATE_SECRET',
		'seller_income_breakdown' => array( 'program_fee' => $candidate ? 5700 : 5500, 'additional_service_fee' => $candidate ? '5700.00' : '5500.00', 'seller_user_id' => 12345 ),
		'buyer_total_amount' => 123456,
	);
	return array( 'batch_id' => 7, 'marketplace_order_id' => $id, 'payment_raw_data' => json_encode( array( 'order_sn' => $id, 'order_income' => $income ) ), 'payment_normalized_data' => json_encode( array( 'marketplaceOrderId' => $id, 'serviceFee' => $service, 'commissionFee' => 84810, 'sellerTransactionFee' => 30840, 'buyerTotalAmount' => 123456, 'buyerPaymentMethod' => 'COD', 'currency' => 'VND', 'buyerName' => 'PRIVATE_PERSON', 'accessToken' => 'PRIVATE_SECRET' ) ), 'payment_fetched_at' => '2026-10-05 01:02:03', 'payment_request_id' => 'req-safe-1' );
}
$db = $GLOBALS['wpdb'];
$db->rows = array( fixture_row( '260922NNXT8KEM', 31270 ), fixture_row( '260922NUU8C6TR', 8940 ) );
$service = new Ecomkit_Vuikhoe_Shopee_Fee_Audit();
$ids = Ecomkit_Vuikhoe_Shopee_Fee_Audit::parse_order_ids( "260922NNXT8KEM\n260922NUU8C6TR" );
$report = $service->inspect_batch( 7, $ids );
audit_check( 2 === count( $report['orders'] ) && '0.7.4' === $report['plugin_version'], 'Both exact IDs were not found in persisted rows.' );
audit_check( array( 'plugin_version', 'orders' ) === array_keys( $report ) && array( 'marketplace_order_id', 'payment_fetched_at', 'payment_request_id', 'normalized_financial', 'financial_paths', 'exact_5700_matches' ) === array_keys( $report['orders'][0] ), 'JSON export schema contains an unsafe extra field.' );
foreach ( $report['orders'] as $index => $order ) {
	$expected = 0 === $index ? 31270 : 8940;
	$raw_service = array_values( array_filter( $order['financial_paths'], static fn( array $entry ): bool => 'payment_raw_data.order_income.service_fee' === $entry['path'] ) );
	audit_check( 1 === count( $raw_service ) && $expected === $raw_service[0]['value'] && $expected === $order['normalized_financial']['serviceFee'], 'Direct persisted serviceFee origin/value was lost.' );
	audit_check( 2 === count( $order['exact_5700_matches'] ) && 'payment_raw_data.order_income.seller_income_breakdown.program_fee' === $order['exact_5700_matches'][0]['path'] && 'payment_raw_data.order_income.seller_income_breakdown.additional_service_fee' === $order['exact_5700_matches'][1]['path'], 'All separate exact 5700 candidates were not listed.' );
	audit_check( 'COD' === $order['normalized_financial']['buyerPaymentMethod'] && 'VND' === $order['normalized_financial']['currency'], 'Safe normalized fields were dropped.' );
}
$safe_json = json_encode( $report );
foreach ( array( 'PRIVATE_PERSON', 'PRIVATE_ADDRESS', 'PRIVATE_PHONE', 'PRIVATE_SECRET', 'buyer_name', 'recipient_address', 'seller_user_id', 'buyer_total_amount', 'access_token', 'eshop_order_code' ) as $forbidden ) { audit_check( ! str_contains( $safe_json, $forbidden ), "Unsafe field/value leaked: $forbidden" ); }
audit_check( 2 === count( $db->queries ) && 0 === $db->writes && 0 === $GLOBALS['provider_calls'], 'Audit performed a write or provider call.' );
foreach ( $db->queries as $query ) { audit_check( str_starts_with( $query['sql'], 'SELECT marketplace_order_id, payment_raw_data, payment_normalized_data, payment_fetched_at, payment_request_id' ) && str_contains( $query['sql'], 'batch_id = %d' ) && ! str_contains( $query['sql'], 'eshop_order_code' ), 'Lookup SQL read forbidden fields or used eShop identity.' ); }
$no_match = Ecomkit_Vuikhoe_Shopee_Fee_Audit::project( fixture_row( 'NO5700', 8940, false ) );
audit_check( array() === $no_match['exact_5700_matches'], '5700 was fabricated when absent.' );
try { $service->inspect_batch( 8, $ids ); throw new RuntimeException( 'Cross-Batch lookup succeeded.' ); } catch ( RuntimeException $error ) { audit_check( 'PAYMENT_AUDIT_ORDER_NOT_UNIQUE' === $error->getMessage(), 'Cross-Batch lookup failed incorrectly.' ); }
try { Ecomkit_Vuikhoe_Shopee_Fee_Audit::parse_order_ids( "260922NNXT8KEM\n<script>" ); throw new RuntimeException( 'Unsafe ID accepted.' ); } catch ( InvalidArgumentException ) {}
$wrong_raw = fixture_row( 'MISMATCH', 31270 ); $wrong_raw['payment_raw_data'] = json_encode( array( 'order_sn' => 'OTHER', 'order_income' => array( 'service_fee' => 31270 ) ) );
try { Ecomkit_Vuikhoe_Shopee_Fee_Audit::project( $wrong_raw ); throw new RuntimeException( 'Snapshot identity mismatch accepted.' ); } catch ( RuntimeException $error ) { audit_check( 'PAYMENT_AUDIT_IDENTITY_MISMATCH' === $error->getMessage(), 'Identity mismatch classification changed.' ); }
$admin = file_get_contents( __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-admin.php' );
audit_check( str_contains( $admin, "check_ajax_referer( 'ecomkit_shopee_fee_audit', 'nonce' )" ) && str_contains( $admin, "check_admin_referer( 'ecomkit_shopee_fee_audit_export', 'ecomkit_fee_audit_nonce' )" ) && str_contains( $admin, 'Ecomkit_Vuikhoe_Security::require_management_capability();' ), 'Admin capability or nonce guard missing.' );
$view = file_get_contents( __DIR__ . '/../ecomkit-vuikhoe/admin/views/results.php' );
audit_check( str_contains( $view, "line(row, item.path, 'td')" ) && ! str_contains( $view, 'output.innerHTML' ) && str_contains( $admin, "Content-Type: application/json; charset=utf-8" ) && str_contains( $admin, "X-Content-Type-Options: nosniff" ), 'Browser output or JSON response safety changed.' );
echo "WP.6H.1A safe Payment audit checks: PASS\n";
