<?php
/** WP.6C synthetic Batch orchestration: bounded calls, reuse and partial failure. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' ); define( 'ARRAY_A', 'ARRAY_A' );
function financial_check( bool $v, string $m ): void { if ( ! $v ) { throw new RuntimeException( $m ); } }
final class Ecomkit_Vuikhoe_DB { public static function payment_schema_ready(): bool { return true; } public static function table_names(): array { return array( 'batches' => 'test_ecomkit_batches', 'orders' => 'test_ecomkit_orders' ); } }
final class Ecomkit_Vuikhoe_Shopee_Payment_Exception extends RuntimeException {}
final class Ecomkit_Vuikhoe_Shopee_Payment_Service {
	public array $calls = array(); public array $fail = array();
	public function inspect_matched_order( int $batch, int $order ): array { $this->calls[] = $order; if ( in_array( $order, $this->fail, true ) ) { throw new Ecomkit_Vuikhoe_Shopee_Payment_Exception( 'SHOPEE_PAYMENT_FETCH_FAILED' ); } return array(); }
}
final class Ecomkit_Vuikhoe_Canonical_Result_Service { public int $calls = 0; public function materialize_batch( int $batch ): array { $this->calls++; return array( 'rows' => 8 ); } }
final class FinancialWpdb {
	public string $prefix = 'test_'; public array $orders = array();
	public function prepare( string $q, mixed ...$a ): string { return $q; }
	public function get_row( string $q, string $mode ): array { return array( 'id' => 7, 'source_type' => 'EXCEL', 'source_metadata' => json_encode( array( 'shopee_reconciliation' => array( 'status' => 'SUCCESS' ) ) ) ); }
	public function get_results( string $q, string $mode ): array { return $this->orders; }
}
$GLOBALS['wpdb'] = new FinancialWpdb();
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-shopee-financial-enrichment-service.php';
for ( $i = 1; $i <= 4; $i++ ) { $GLOBALS['wpdb']->orders[] = array( 'id' => $i, 'platform' => 'SHOPEE', 'matching_status' => 'MATCHED', 'marketplace_order_id' => 'TEST-SHP-' . $i, 'connection_id' => 9, 'payment_normalized_data' => null ); }
for ( $i = 5; $i <= 8; $i++ ) { $GLOBALS['wpdb']->orders[] = array( 'id' => $i, 'platform' => 'LAZADA', 'matching_status' => null, 'marketplace_order_id' => 'TEST-LAZ-' . $i, 'connection_id' => null ); }
$payment = new Ecomkit_Vuikhoe_Shopee_Payment_Service(); $canonical = new Ecomkit_Vuikhoe_Canonical_Result_Service();
$batch = new Ecomkit_Vuikhoe_Shopee_Financial_Enrichment_Service( $payment, $canonical );
$payment->fail = array( 4 ); $partial = $batch->enrich_batch( 7 );
financial_check( 4 === $partial['eligible'] && 4 === $partial['provider_calls'] && 3 === $partial['fetched'] && 1 === $partial['failed'] && 8 === $partial['canonical_rematerialized'] && 'WARNING' === $partial['status'] && array( 1, 2, 3, 4 ) === $payment->calls, '4 Shopee / 4 Lazada partial failure policy failed.' );
$payment->calls = array(); $payment->fail = array();
foreach ( $GLOBALS['wpdb']->orders as &$order ) { if ( 'SHOPEE' === $order['platform'] ) { $order['payment_normalized_data'] = json_encode( array( 'marketplaceOrderId' => $order['marketplace_order_id'], 'commissionFee' => 0 ) ); } } unset( $order );
$reused = $batch->enrich_batch( 7 );
financial_check( 0 === $reused['provider_calls'] && 4 === $reused['reused'] && 8 === $reused['canonical_rematerialized'] && array() === $payment->calls, 'Persisted Payment snapshots were not reused locally.' );
$refreshed = $batch->enrich_batch( 7, true );
financial_check( 4 === $refreshed['provider_calls'] && 4 === $refreshed['fetched'] && 4 === $refreshed['eligible'] && 8 === $refreshed['canonical_rematerialized'], 'Explicit refresh exceeded one call per matched Shopee Order.' );
echo "WP.6C financial Batch checks passed.\n";
