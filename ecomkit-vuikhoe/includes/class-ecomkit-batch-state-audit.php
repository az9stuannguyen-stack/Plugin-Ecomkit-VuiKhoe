<?php
/** Allowlisted, read-only projection of persisted Batch orchestration evidence. */
defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Batch_State_Audit {
	private const ABSENT = 'NOT_PRESENT';
	private const PIPELINE_KEYS = array( 'run_sequence', 'stage', 'status', 'terminal', 'next_step', 'last_completed_pipeline_step', 'import_status', 'reconciliation_status', 'reconciliation_complete', 'reconciliation_block_reason', 'detail_complete', 'payment_status', 'payment_complete', 'income_status', 'income_complete', 'canonical_status', 'canonical_complete', 'started_at', 'updated_at', 'completed_at', 'last_worker_timestamp', 'connection_id', 'reconcile_inflight', 'reconcile_window_offset', 'reconcile_total_windows', 'payment_index', 'payment_inflight', 'income_index', 'income_pages', 'income_inflight', 'progress_percent' );
	private const COUNT_KEYS = array( 'excel_orders', 'excel_items', 'shopee_orders', 'lazada_orders', 'reconcile_checked', 'shopee_matched', 'detail_fetched', 'detail_missing', 'payment_total', 'payment_fetched', 'payment_reused', 'payment_failed', 'income_matched', 'income_empty', 'canonical_rows' );
	private const RECON_KEYS = array( 'connection_id', 'shop_id', 'started_at', 'completed_at', 'provider_windows_executed', 'shopee_api_calls', 'excel_shopee_count', 'provider_count', 'matched_count', 'missing_count', 'extra_count', 'detail_count', 'detail_missing_count', 'missing_date_count', 'status', 'total_windows', 'next_window_offset', 'classification', 'request_contract_fingerprint', 'provider_error', 'safe_provider_message', 'http_status', 'request_id', 'api_path' );
	private const WINDOW_KEYS = array( 'connection_id', 'shop_reference', 'time_range_field', 'start_date', 'end_date', 'time_from', 'time_to', 'status', 'list_request_success', 'pagination_complete', 'page_count', 'excel_count', 'provider_count', 'intersection_count', 'request_contract_fingerprint', 'provider_error', 'safe_provider_message', 'http_status', 'request_id', 'api_path' );
	private const SET_KEYS = array( 'excel_order_ids', 'provider_order_ids', 'intersection', 'missing_in_provider', 'extra_in_provider' );

	public function inspect_batch( int $batch_id ): array {
		if ( $batch_id < 1 ) { throw new InvalidArgumentException( 'BATCH_STATE_ID_INVALID' ); }
		global $wpdb;
		$tables = Ecomkit_Vuikhoe_DB::table_names();
		$batch = $wpdb->get_row( $wpdb->prepare( "SELECT id, source_filename, status, order_count, source_metadata FROM {$tables['batches']} WHERE id = %d AND source_type = %s", $batch_id, 'EXCEL' ), ARRAY_A );
		if ( ! is_array( $batch ) ) { throw new RuntimeException( 'BATCH_STATE_NOT_FOUND' ); }
		$metadata = json_decode( (string) ( $batch['source_metadata'] ?? '' ), true );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$auto = is_array( $metadata['auto_pipeline'] ?? null ) ? $metadata['auto_pipeline'] : array();
		$recon = is_array( $metadata['shopee_reconciliation'] ?? null ) ? $metadata['shopee_reconciliation'] : array();
		$connection_id = (int) ( $auto['connection_id'] ?? $recon['connection_id'] ?? 0 );
		$connection = array();
		if ( $connection_id > 0 ) {
			$connection = $wpdb->get_row( $wpdb->prepare( "SELECT id, platform, external_shop_id, status, credential_source, metadata FROM {$tables['marketplace_connections']} WHERE id = %d", $connection_id ), ARRAY_A );
			$connection = is_array( $connection ) ? $connection : array();
		}
		$cron_due = wp_next_scheduled( Ecomkit_Vuikhoe_Auto_Pipeline::HOOK, array( $batch_id ) );
		return self::project( $batch, $metadata, $connection, $cron_due );
	}

	public static function project( array $batch, array $metadata, array $connection = array(), int|false $cron_due = false ): array {
		$auto = is_array( $metadata['auto_pipeline'] ?? null ) ? $metadata['auto_pipeline'] : array();
		$recon = is_array( $metadata['shopee_reconciliation'] ?? null ) ? $metadata['shopee_reconciliation'] : array();
		$pipeline = self::fields( $auto, self::PIPELINE_KEYS );
		$pipeline['counts'] = self::fields( is_array( $auto['counts'] ?? null ) ? $auto['counts'] : array(), self::COUNT_KEYS );
		$pipeline['warning_codes'] = self::codes( $auto['warnings'] ?? null );
		$pipeline['error_codes'] = self::codes( $auto['errors'] ?? null );
		$continuation = is_array( $auto['reconcile_continuation'] ?? null ) ? $auto['reconcile_continuation'] : array();
		$pipeline['continuation_pending'] = array_key_exists( 'reconcile_continuation', $auto ) ? ! empty( $continuation ) : self::ABSENT;
		$pipeline['cursor_present'] = array_key_exists( 'cursor', $continuation ) ? '' !== (string) $continuation['cursor'] : self::ABSENT;
		$pipeline['page_count'] = self::value( $continuation, 'page_count' );
		$pipeline['accumulated_provider_order_count'] = is_array( $continuation['provider_ids'] ?? null ) ? count( $continuation['provider_ids'] ) : self::ABSENT;
		$pipeline['request_ids'] = self::ids( $continuation['request_ids'] ?? null );
		$pipeline['scheduled_step_current'] = false === $cron_due ? 'NONE' : gmdate( 'Y-m-d H:i:s', $cron_due ) . ' UTC';
		$pipeline['schedule_attempted'] = self::ABSENT; // Not recorded by existing pipeline.
		$pipeline['worker_attempt_count'] = self::ABSENT;
		$pipeline['terminal_reason'] = self::value( $auto, 'reconciliation_block_reason' );
		$summary = self::fields( $recon, self::RECON_KEYS );
		$summary['extra_order_sns'] = self::order_ids( $recon['extra_order_sns'] ?? null );
		$summary['windows'] = array();
		foreach ( (array) ( $recon['windows'] ?? array() ) as $window ) {
			if ( ! is_array( $window ) ) { continue; }
			$entry = self::fields( $window, self::WINDOW_KEYS );
			foreach ( self::SET_KEYS as $key ) { $entry[ $key ] = self::order_ids( $window[ $key ] ?? null ); }
			$entry['request_ids'] = self::ids( $window['request_ids'] ?? null );
			$summary['windows'][] = $entry;
		}
		$connection_meta = json_decode( (string) ( $connection['metadata'] ?? '' ), true );
		$connection_meta = is_array( $connection_meta ) ? $connection_meta : array();
		$current = self::fields( $connection, array( 'id', 'platform', 'external_shop_id', 'status', 'credential_source' ) );
		$current['refresh_ownership'] = self::value( $connection_meta, 'refresh_ownership' );
		$current['credential_lifecycle'] = self::value( $connection_meta, 'credential_lifecycle' );
		$current['label'] = 'CURRENT_CONNECTION_STATE';
		$provider_evidence = ! empty( $summary['windows'] ) || ( is_array( $pipeline['request_ids'] ) && ! empty( $pipeline['request_ids'] ) ) || (int) ( $recon['shopee_api_calls'] ?? 0 ) > 0;
		$started = array_key_exists( 'reconcile_inflight', $auto ) || array_key_exists( 'connection_id', $auto ) || ! empty( $summary['windows'] ) || (int) ( $auto['counts']['reconcile_checked'] ?? 0 ) > 0;
		return array(
			'plugin_version' => ECOMKIT_VUIKHOE_VERSION,
			'batch' => array( 'id' => (int) ( $batch['id'] ?? 0 ), 'filename' => basename( (string) ( $batch['source_filename'] ?? '' ) ), 'status' => self::value( $batch, 'status' ), 'orders' => self::value( $batch, 'order_count' ), 'shopee_orders' => self::value( (array) ( $metadata['platform_counts'] ?? array() ), 'SHOPEE' ) ),
			'auto_pipeline' => $pipeline,
			'shopee_reconciliation' => $summary,
			'connection_current' => $current,
			'diagnostic_interpretation_inputs' => array( 'provider_request_evidence_present' => $provider_evidence, 'reconciliation_started' => $started, 'pagination_complete' => self::pagination_complete( $summary['windows'] ), 'terminal_reason_present' => 'NOT_PRESENT' !== $pipeline['terminal_reason'] ),
		);
	}

	private static function fields( array $source, array $keys ): array { $result = array(); foreach ( $keys as $key ) { $result[ $key ] = self::value( $source, $key ); } return $result; }
	private static function value( array $source, string $key ): mixed { if ( ! array_key_exists( $key, $source ) ) { return self::ABSENT; } $value = $source[ $key ]; if ( ! is_scalar( $value ) && null !== $value ) { return self::ABSENT; } if ( is_string( $value ) && ( strlen( $value ) > 256 || preg_match( '/(?:access[_-]?token|refresh[_-]?token|partner[_-]?key|signature|credential|authorization|recipient|buyer[_-]?(?:name|phone|address)|signed[_-]?url|https?:\/\/)/i', $value ) ) ) { return 'REDACTED'; } return $value; }
	private static function codes( mixed $codes ): array|string { return ! is_array( $codes ) ? self::ABSENT : array_values( array_filter( $codes, static fn( mixed $code ): bool => is_string( $code ) && 1 === preg_match( '/\A[A-Z][A-Z0-9_]{1,90}\z/D', $code ) ) ); }
	private static function ids( mixed $ids ): array|string { return ! is_array( $ids ) ? self::ABSENT : array_values( array_filter( $ids, static fn( mixed $id ): bool => is_string( $id ) && 1 === preg_match( '/\A[A-Za-z0-9_-]{1,128}\z/D', $id ) ) ); }
	private static function order_ids( mixed $ids ): array|string { return ! is_array( $ids ) ? self::ABSENT : array_values( array_filter( $ids, static fn( mixed $id ): bool => is_string( $id ) && 1 === preg_match( '/\A[A-Za-z0-9_-]{1,64}\z/D', $id ) ) ); }
	private static function pagination_complete( array $windows ): bool|string { if ( ! $windows ) { return self::ABSENT; } foreach ( $windows as $window ) { if ( true !== $window['pagination_complete'] ) { return false; } } return true; }
}
