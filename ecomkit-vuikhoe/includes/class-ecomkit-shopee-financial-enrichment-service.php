<?php
/** Explicit Batch Payment enrichment; individual failures do not erase prior evidence. */
defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Shopee_Financial_Enrichment_Service {
	public function __construct( private ?Ecomkit_Vuikhoe_Shopee_Payment_Service $payment = null, private ?Ecomkit_Vuikhoe_Canonical_Result_Service $canonical = null ) {
		$this->payment ??= new Ecomkit_Vuikhoe_Shopee_Payment_Service();
		$this->canonical ??= new Ecomkit_Vuikhoe_Canonical_Result_Service();
	}

	/** @return array<string,mixed> Safe counts/classifications only. */
	public function enrich_batch( int $batch_id, bool $refresh_existing = false ): array {
		global $wpdb;
		$tables = Ecomkit_Vuikhoe_DB::table_names();
		if ( $batch_id < 1 || ! Ecomkit_Vuikhoe_DB::payment_schema_ready() ) { throw new RuntimeException( 'SHOPEE_PAYMENT_SOURCE_NOT_READY' ); }
		$batch = $wpdb->get_row( $wpdb->prepare( "SELECT id, source_type, source_metadata FROM {$tables['batches']} WHERE id = %d AND source_type = %s", $batch_id, 'EXCEL' ), ARRAY_A );
		$metadata = is_array( $batch ) ? json_decode( (string) ( $batch['source_metadata'] ?? '' ), true ) : null;
		if ( ! is_array( $batch ) || ! is_array( $metadata ) || ! is_array( $metadata['shopee_reconciliation'] ?? null ) ) { throw new RuntimeException( 'SHOPEE_PAYMENT_BATCH_NOT_RECONCILED' ); }
		$orders = $wpdb->get_results( $wpdb->prepare( "SELECT id, platform, matching_status, marketplace_order_id, connection_id, payment_normalized_data FROM {$tables['orders']} WHERE batch_id = %d ORDER BY id ASC", $batch_id ), ARRAY_A );
		$summary = array( 'eligible' => 0, 'fetched' => 0, 'reused' => 0, 'failed' => 0, 'canonical_rematerialized' => 0, 'provider_calls' => 0, 'status' => 'ERROR', 'failures' => array() );
		foreach ( is_array( $orders ) ? $orders : array() as $order ) {
			if ( 'SHOPEE' !== (string) ( $order['platform'] ?? '' ) || 'MATCHED' !== (string) ( $order['matching_status'] ?? '' ) || (int) ( $order['connection_id'] ?? 0 ) < 1 || '' === (string) ( $order['marketplace_order_id'] ?? '' ) ) { continue; }
			$summary['eligible']++;
			$snapshot = json_decode( (string) ( $order['payment_normalized_data'] ?? '' ), true );
			$valid = is_array( $snapshot ) && (string) ( $snapshot['marketplaceOrderId'] ?? '' ) === (string) $order['marketplace_order_id'];
			if ( $valid && ! $refresh_existing ) { $summary['reused']++; continue; }
			try {
				$summary['provider_calls']++;
				$this->payment->inspect_matched_order( $batch_id, (int) $order['id'] );
				$summary['fetched']++;
			} catch ( Throwable $exception ) {
				$summary['failed']++;
				$summary['failures'][] = array( 'order_id' => (int) $order['id'], 'classification' => $exception instanceof Ecomkit_Vuikhoe_Shopee_Payment_Exception ? $exception->getMessage() : 'SHOPEE_PAYMENT_FETCH_FAILED' );
				if ( $valid ) { $summary['reused']++; }
			}
		}
		try { $summary['canonical_rematerialized'] = $this->canonical->materialize_batch( $batch_id )['rows']; }
		catch ( Throwable ) { $summary['status'] = 'ERROR'; $summary['failures'][] = array( 'order_id' => null, 'classification' => 'CANONICAL_FINANCIAL_MAPPING_FAILED' ); return $summary; }
		$summary['status'] = $summary['failed'] ? 'WARNING' : 'SUCCESS';
		return $summary;
	}
}
