<?php
/**
 * WordPress Admin menu and safe placeholder pages.
 */

defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Admin {
	private const MENU_SLUG = 'ecomkit-vuikhoe';

	/**
	 * Registers administrator-only hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_notices', array( $this, 'database_notice' ) );
		add_action( 'admin_post_ecomkit_vuikhoe_import_excel', array( $this, 'handle_excel_import' ) );
		add_action( 'admin_post_ecomkit_vuikhoe_test_excel_runtime', array( $this, 'handle_excel_runtime_test' ) );
		add_action( 'admin_post_ecomkit_shopee_save_config', array( $this, 'handle_shopee_save_config' ) );
		add_action( 'admin_post_ecomkit_shopee_replace_partner_key', array( $this, 'handle_shopee_replace_partner_key' ) );
		add_action( 'admin_post_ecomkit_shopee_test_config', array( $this, 'handle_shopee_test_config' ) );
		add_action( 'admin_post_ecomkit_shopee_refresh_token', array( $this, 'handle_shopee_refresh_token' ) );
		add_action( 'admin_post_ecomkit_shopee_test_order_api', array( $this, 'handle_shopee_test_order_api' ) );
		add_action( 'admin_post_ecomkit_shopee_reconcile_batch', array( $this, 'handle_shopee_reconcile_batch' ) );
		add_action( 'admin_post_ecomkit_vuikhoe_materialize_results', array( $this, 'handle_materialize_results' ) );
		add_action( 'admin_post_ecomkit_shopee_payment_test', array( $this, 'handle_shopee_payment_test' ) );
		add_action( 'admin_post_ecomkit_shopee_income_test', array( $this, 'handle_shopee_income_test' ) );
		add_action( 'admin_post_ecomkit_pipeline_resume', array( $this, 'handle_pipeline_resume' ) );
		add_action( 'admin_post_ecomkit_shopee_financial_enrich', array( $this, 'handle_shopee_financial_enrich' ) );
	}

	public function add_menu(): void {
		$capability = Ecomkit_Vuikhoe_Security::MANAGEMENT_CAPABILITY;

		add_menu_page( __( 'Ecomkit - Vui Khỏe', 'ecomkit-vuikhoe' ), __( 'Ecomkit', 'ecomkit-vuikhoe' ), $capability, self::MENU_SLUG, array( $this, 'dashboard_page' ), 'dashicons-store', 56 );
		add_submenu_page( self::MENU_SLUG, __( 'Tổng quan', 'ecomkit-vuikhoe' ), __( 'Tổng quan', 'ecomkit-vuikhoe' ), $capability, self::MENU_SLUG, array( $this, 'dashboard_page' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Xử lý đơn hàng', 'ecomkit-vuikhoe' ), __( 'Xử lý đơn hàng', 'ecomkit-vuikhoe' ), $capability, 'ecomkit-vuikhoe-process', array( $this, 'process_page' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Kết quả', 'ecomkit-vuikhoe' ), __( 'Kết quả', 'ecomkit-vuikhoe' ), $capability, 'ecomkit-vuikhoe-results', array( $this, 'results_page' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Lỗi', 'ecomkit-vuikhoe' ), __( 'Lỗi', 'ecomkit-vuikhoe' ), $capability, 'ecomkit-vuikhoe-errors', array( $this, 'errors_page' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Lịch sử', 'ecomkit-vuikhoe' ), __( 'Lịch sử', 'ecomkit-vuikhoe' ), $capability, 'ecomkit-vuikhoe-history', array( $this, 'history_page' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Marketplace', 'ecomkit-vuikhoe' ), __( 'Marketplace', 'ecomkit-vuikhoe' ), $capability, 'ecomkit-vuikhoe-marketplace', array( $this, 'marketplace_page' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Cài đặt', 'ecomkit-vuikhoe' ), __( 'Cài đặt', 'ecomkit-vuikhoe' ), $capability, 'ecomkit-vuikhoe-settings', array( $this, 'settings_page' ) );
	}

	public function dashboard_page(): void {
		$this->render( 'dashboard', array( 'diagnostic' => Ecomkit_Vuikhoe_DB::diagnose() ) );
	}

	public function process_page(): void {
		$batch_id = isset( $_GET['batch_id'] ) ? absint( wp_unslash( $_GET['batch_id'] ) ) : 0;
		$imports  = new Ecomkit_Vuikhoe_Import_Service();
		$reconciliation = new Ecomkit_Vuikhoe_Shopee_Reconciliation_Service();
		$this->render(
			'process',
			array(
				'batch'           => $batch_id ? $imports->get_batch_summary( $batch_id ) : null,
				'max_upload_size' => $imports->max_upload_bytes(),
				'max_rows'        => Ecomkit_Vuikhoe_Excel_Service::MAX_ROWS,
				'ready_connections' => $batch_id ? $reconciliation->ready_connections() : array(),
			)
		);
	}

	public function results_page(): void {
		$batch_id = isset( $_GET['batch_id'] ) ? absint( wp_unslash( $_GET['batch_id'] ) ) : 0;
		$platform = isset( $_GET['platform'] ) && in_array( (string) $_GET['platform'], array( 'SHOPEE', 'LAZADA' ), true ) ? (string) $_GET['platform'] : '';
		$matching = isset( $_GET['matching'] ) && in_array( (string) $_GET['matching'], array( 'MATCHED', 'NOT_FOUND_IN_SHOPEE', 'DETAIL_MISSING', '__BLANK__' ), true ) ? (string) $_GET['matching'] : '';
		$service = new Ecomkit_Vuikhoe_Canonical_Result_Service();
		$payment_test = null;
		$income_test = null;
		$financial_run = null;
		if ( isset( $_GET['financial_run'] ) ) {
			$reference = sanitize_key( wp_unslash( $_GET['financial_run'] ) );
			$stored = get_transient( 'ecomkit_shopee_financial_run_' . $reference );
			delete_transient( 'ecomkit_shopee_financial_run_' . $reference );
			if ( is_array( $stored ) && (int) ( $stored['user_id'] ?? 0 ) === get_current_user_id() && (int) ( $stored['batch_id'] ?? 0 ) === $batch_id ) { $financial_run = $stored['result'] ?? null; }
		}
		if ( isset( $_GET['payment_test'] ) ) {
			$reference = sanitize_key( wp_unslash( $_GET['payment_test'] ) );
			$stored = get_transient( 'ecomkit_shopee_payment_test_' . $reference );
			delete_transient( 'ecomkit_shopee_payment_test_' . $reference );
			if ( is_array( $stored ) && (int) ( $stored['user_id'] ?? 0 ) === get_current_user_id() && (int) ( $stored['batch_id'] ?? 0 ) === $batch_id ) { $payment_test = $stored['result'] ?? null; }
		}
		if ( isset( $_GET['income_test'] ) ) {
			$reference = sanitize_key( wp_unslash( $_GET['income_test'] ) );
			$stored = get_transient( 'ecomkit_shopee_income_test_' . $reference );
			delete_transient( 'ecomkit_shopee_income_test_' . $reference );
			if ( is_array( $stored ) && (int) ( $stored['user_id'] ?? 0 ) === get_current_user_id() && (int) ( $stored['batch_id'] ?? 0 ) === $batch_id ) { $income_test = $stored['result'] ?? null; }
		}
		$pipeline = null;
		if ( $batch_id ) { try { $automatic = new Ecomkit_Vuikhoe_Auto_Pipeline(); $pipeline = $automatic->state( $batch_id ); if ( is_array( $pipeline ) && ! in_array( (string) ( $pipeline['status'] ?? '' ), array( 'SUCCESS', 'WARNING', 'ERROR' ), true ) ) { $pipeline = $automatic->start( $batch_id ); } } catch ( Throwable ) { /* Retain ordinary Result access if scheduling is unavailable. */ } }
		$this->render( 'results', array( 'batch_id' => $batch_id, 'batches' => $service->list_batches(), 'result' => $batch_id ? $service->get_batch_result( $batch_id, $platform, $matching ) : null, 'pipeline' => $pipeline, 'platform_filter' => $platform, 'matching_filter' => $matching, 'payment_orders' => $batch_id ? ( new Ecomkit_Vuikhoe_Shopee_Payment_Service() )->eligible_orders( $batch_id ) : array(), 'payment_test' => $payment_test, 'income_test' => $income_test, 'financial_run' => $financial_run ) );
	}

	public function handle_pipeline_resume(): void {
		Ecomkit_Vuikhoe_Security::require_management_capability();
		check_admin_referer( 'ecomkit_pipeline_resume', 'ecomkit_pipeline_nonce' );
		$batch_id = absint( wp_unslash( $_POST['batch_id'] ?? 0 ) );
		try { ( new Ecomkit_Vuikhoe_Auto_Pipeline() )->start( $batch_id, true ); } catch ( Throwable ) { /* Show persisted Batch/Result without exposing internals. */ }
		wp_safe_redirect( add_query_arg( array( 'page' => 'ecomkit-vuikhoe-results', 'batch_id' => $batch_id ), admin_url( 'admin.php' ) ) ); exit;
	}

	public function handle_shopee_income_test(): void {
		Ecomkit_Vuikhoe_Security::require_management_capability();
		check_admin_referer( 'ecomkit_shopee_income_test', 'ecomkit_income_nonce' );
		$batch_id = absint( wp_unslash( $_POST['batch_id'] ?? 0 ) );
		$order_id = absint( wp_unslash( $_POST['order_id'] ?? 0 ) );
		$bucket = sanitize_text_field( wp_unslash( $_POST['income_bucket'] ?? '' ) );
		$from = sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) );
		$to = sanitize_text_field( wp_unslash( $_POST['date_to'] ?? '' ) );
		$service = new Ecomkit_Vuikhoe_Shopee_Income_Service();
		try { $result = array( 'ok' => true, 'data' => $service->inspect_matched_order( $batch_id, $order_id, $bucket, $from, $to ) ); }
		catch ( Throwable $exception ) {
			$result = array( 'ok' => false, 'classification' => strtoupper( sanitize_key( $exception->getMessage() ) ), 'diagnostic' => array_intersect_key( $service->last_diagnostic, array_flip( array( 'stage', 'api_path', 'method', 'http_status', 'request_id', 'pages', 'provider_error', 'provider_message', 'top_level_keys', 'income_response_type', 'income_response_keys', 'income_list_type', 'next_page_type' ) ) ) );
		}
		$reference = bin2hex( random_bytes( 16 ) );
		set_transient( 'ecomkit_shopee_income_test_' . $reference, array( 'user_id' => get_current_user_id(), 'batch_id' => $batch_id, 'result' => $result ), 600 );
		wp_safe_redirect( add_query_arg( array( 'page' => 'ecomkit-vuikhoe-results', 'batch_id' => $batch_id, 'income_test' => $reference ), admin_url( 'admin.php' ) ) ); exit;
	}

	public function handle_shopee_financial_enrich(): void {
		Ecomkit_Vuikhoe_Security::require_management_capability();
		check_admin_referer( 'ecomkit_shopee_financial_enrich', 'ecomkit_financial_nonce' );
		$batch_id = absint( wp_unslash( $_POST['batch_id'] ?? 0 ) );
		try { $result = ( new Ecomkit_Vuikhoe_Shopee_Financial_Enrichment_Service() )->enrich_batch( $batch_id, ! empty( $_POST['refresh_existing'] ) ); }
		catch ( Throwable $exception ) { $result = array( 'eligible' => 0, 'fetched' => 0, 'reused' => 0, 'failed' => 0, 'canonical_rematerialized' => 0, 'provider_calls' => 0, 'status' => 'ERROR', 'failures' => array( array( 'order_id' => null, 'classification' => in_array( $exception->getMessage(), array( 'SHOPEE_PAYMENT_SOURCE_NOT_READY', 'SHOPEE_PAYMENT_BATCH_NOT_RECONCILED' ), true ) ? $exception->getMessage() : 'SHOPEE_PAYMENT_FETCH_FAILED' ) ) ); }
		$reference = bin2hex( random_bytes( 16 ) );
		set_transient( 'ecomkit_shopee_financial_run_' . $reference, array( 'user_id' => get_current_user_id(), 'batch_id' => $batch_id, 'result' => $result ), 600 );
		wp_safe_redirect( add_query_arg( array( 'page' => 'ecomkit-vuikhoe-results', 'batch_id' => $batch_id, 'financial_run' => $reference ), admin_url( 'admin.php' ) ) ); exit;
	}

	public function handle_shopee_payment_test(): void {
		Ecomkit_Vuikhoe_Security::require_management_capability();
		check_admin_referer( 'ecomkit_shopee_payment_test', 'ecomkit_payment_nonce' );
		$batch_id = absint( wp_unslash( $_POST['batch_id'] ?? 0 ) );
		$order_id = absint( wp_unslash( $_POST['order_id'] ?? 0 ) );
		$service = new Ecomkit_Vuikhoe_Shopee_Payment_Service();
		try { $result = array( 'ok' => true, 'data' => $service->inspect_matched_order( $batch_id, $order_id ) ); }
		catch ( Throwable $exception ) {
			$result = array( 'ok' => false, 'classification' => strtoupper( sanitize_key( $exception->getMessage() ) ), 'diagnostic' => array_intersect_key( $service->last_diagnostic, array_flip( array( 'api_path', 'method', 'http_status', 'request_id', 'provider_error', 'provider_message', 'top_level_keys', 'response_type', 'response_keys', 'order_income_type', 'order_income_keys', 'order_income_field_types' ) ) ) );
		}
		$reference = bin2hex( random_bytes( 16 ) );
		set_transient( 'ecomkit_shopee_payment_test_' . $reference, array( 'user_id' => get_current_user_id(), 'batch_id' => $batch_id, 'result' => $result ), 600 );
		wp_safe_redirect( add_query_arg( array( 'page' => 'ecomkit-vuikhoe-results', 'batch_id' => $batch_id, 'payment_test' => $reference ), admin_url( 'admin.php' ) ) ); exit;
	}

	public function handle_materialize_results(): void {
		Ecomkit_Vuikhoe_Security::require_management_capability();
		check_admin_referer( 'ecomkit_vuikhoe_materialize_results', 'ecomkit_result_nonce' );
		$batch_id = absint( wp_unslash( $_POST['batch_id'] ?? 0 ) );
		try {
			( new Ecomkit_Vuikhoe_Canonical_Result_Service() )->materialize_batch( $batch_id );
			$args = array( 'page' => 'ecomkit-vuikhoe-results', 'batch_id' => $batch_id, 'materialize_notice' => 'success' );
		} catch ( Throwable $exception ) {
			$allowed = array( 'CANONICAL_MAPPING_CONTRACT_INVALID', 'CANONICAL_ROW_BUILD_FAILED', 'CANONICAL_RESULT_PERSIST_FAILED', 'CANONICAL_SOURCE_INVALID' );
			$code = in_array( $exception->getMessage(), $allowed, true ) ? $exception->getMessage() : 'CANONICAL_ROW_BUILD_FAILED';
			$args = array( 'page' => 'ecomkit-vuikhoe-results', 'batch_id' => $batch_id, 'materialize_error' => strtolower( $code ) );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) ); exit;
	}

	public function handle_shopee_reconcile_batch(): void {
		Ecomkit_Vuikhoe_Security::require_management_capability();
		check_admin_referer( 'ecomkit_shopee_reconcile_batch', 'ecomkit_reconcile_nonce' );
		$batch_id = absint( wp_unslash( $_POST['batch_id'] ?? 0 ) );
		$connection_id = absint( wp_unslash( $_POST['connection_id'] ?? 0 ) );
		try {
			( new Ecomkit_Vuikhoe_Shopee_Reconciliation_Service() )->reconcile_batch( $batch_id, $connection_id > 0 ? $connection_id : null );
			$url = add_query_arg( array( 'page' => 'ecomkit-vuikhoe-results', 'batch_id' => $batch_id, 'reconcile_notice' => 'completed' ), admin_url( 'admin.php' ) );
		} catch ( Throwable $exception ) {
			$url = add_query_arg( array( 'page' => 'ecomkit-vuikhoe-process', 'batch_id' => $batch_id, 'reconcile_error' => sanitize_key( $exception->getMessage() ) ), admin_url( 'admin.php' ) );
		}
		wp_safe_redirect( $url );
		exit;
	}

	public function errors_page(): void {
		$imports = new Ecomkit_Vuikhoe_Import_Service();
		$this->render( 'errors', array( 'errors' => $imports->list_errors() ) );
	}

	public function history_page(): void {
		$imports = new Ecomkit_Vuikhoe_Import_Service();
		$this->render( 'history', array( 'batches' => $imports->list_batches() ) );
	}

	public function marketplace_page(): void {
		$config_service = new Ecomkit_Vuikhoe_Shopee_Config();
		$config = $config_service->get();
		$encryption = new Ecomkit_Vuikhoe_Credential_Encryption();
		$oauth_diagnostic = null;
		if ( isset( $_GET['oauth_diag'] ) ) {
			$reference = sanitize_key( wp_unslash( $_GET['oauth_diag'] ) );
			$stored = get_transient( 'ecomkit_shopee_diag_' . $reference );
			delete_transient( 'ecomkit_shopee_diag_' . $reference );
			if ( is_array( $stored ) && (int) ( $stored['user_id'] ?? 0 ) === get_current_user_id() && is_array( $stored['diagnostic'] ?? null ) ) {
				$oauth_diagnostic = $stored['diagnostic'];
			}
		}
		if ( isset( $_GET['refresh_diag'] ) ) {
			$reference = sanitize_key( wp_unslash( $_GET['refresh_diag'] ) ); $stored = get_transient( 'ecomkit_shopee_refresh_diag_' . $reference ); delete_transient( 'ecomkit_shopee_refresh_diag_' . $reference );
			if ( is_array( $stored ) && (int) ( $stored['user_id'] ?? 0 ) === get_current_user_id() && is_array( $stored['diagnostic'] ?? null ) ) { $oauth_diagnostic = $stored['diagnostic']; }
		}
		$order_test = null; if ( isset( $_GET['order_test'] ) ) { $reference = sanitize_key( wp_unslash( $_GET['order_test'] ) ); $stored = get_transient( 'ecomkit_shopee_order_test_' . $reference ); delete_transient( 'ecomkit_shopee_order_test_' . $reference ); if ( is_array( $stored ) && (int) ( $stored['user_id'] ?? 0 ) === get_current_user_id() && is_array( $stored['result'] ?? null ) ) { $order_test = $stored['result']; } }
		$this->render( 'marketplace', array( 'shopee_config' => $config, 'partner_key_ui' => $config_service->partner_key_ui_state(), 'shopee_readiness' => $config_service->readiness(), 'encryption_ready' => $encryption->ready(), 'key_source' => $encryption->ready() ? $encryption->key_source_label() : '', 'callback_url' => $config_service->callback_url(), 'connections' => ( new Ecomkit_Vuikhoe_Marketplace_Connection_Service() )->list_shopee( (string) ( $config['fingerprint'] ?? '' ) ), 'oauth_diagnostic' => $oauth_diagnostic, 'order_test' => $order_test ) );
	}

	public function handle_shopee_refresh_token(): void {
		Ecomkit_Vuikhoe_Security::require_management_capability(); check_admin_referer( 'ecomkit_shopee_refresh_token', 'ecomkit_shopee_nonce' );
		$connection_id = absint( wp_unslash( $_POST['connection_id'] ?? 0 ) ); $service = new Ecomkit_Vuikhoe_Shopee_Token_Service();
		try { $service->ensure_usable_access_token( $connection_id, true ); $args = array( 'shopee_notice' => 'token_refreshed' ); }
		catch ( Throwable $exception ) { $reference = bin2hex( random_bytes( 16 ) ); $diagnostic = array_merge( $service->last_diagnostic, array( 'classification' => strtoupper( sanitize_key( $exception->getMessage() ) ) ) ); set_transient( 'ecomkit_shopee_refresh_diag_' . $reference, array( 'user_id' => get_current_user_id(), 'diagnostic' => $diagnostic ), 600 ); $args = array( 'shopee_error' => 'shopee_refresh_failed', 'refresh_diag' => $reference ); }
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => 'ecomkit-vuikhoe-marketplace' ), $args ), admin_url( 'admin.php' ) ) ); exit;
	}

	public function handle_shopee_test_order_api(): void {
		Ecomkit_Vuikhoe_Security::require_management_capability(); check_admin_referer( 'ecomkit_shopee_test_order_api', 'ecomkit_shopee_nonce' );
		$connection_id = absint( wp_unslash( $_POST['connection_id'] ?? 0 ) ); $field = sanitize_key( wp_unslash( $_POST['time_range_field'] ?? 'create_time' ) ); $status = strtoupper( sanitize_key( wp_unslash( $_POST['order_status'] ?? '' ) ) ); $page_size = min( 5, max( 1, absint( wp_unslash( $_POST['page_size'] ?? 5 ) ) ) );
		$reference = bin2hex( random_bytes( 16 ) ); $service = new Ecomkit_Vuikhoe_Shopee_Order_Service();
		try {
			$timezone = wp_timezone(); [ $time_from, $time_to ] = Ecomkit_Vuikhoe_Shopee_Order_Service::local_day_window( sanitize_text_field( wp_unslash( $_POST['test_date'] ?? '' ) ), $timezone );
			$list = $service->get_order_list_page( $connection_id, $field, $time_from, $time_to, $page_size, null, '' === $status ? null : $status ); $list_by_sn = array(); foreach ( $list['orders'] as $list_order ) { $list_by_sn[ $list_order['order_sn'] ] = $list_order; } $sample = array_slice( array_column( $list['orders'], 'order_sn' ), 0, 5 ); $details = $sample ? $service->get_order_detail_page( $connection_id, $sample, array( 'item_list' ) ) : null; if ( $details && ! $details['complete'] ) { throw new RuntimeException( 'SHOPEE_ORDER_DETAIL_INCOMPLETE' ); } $preview = array();
			foreach ( $sample as $sn ) { $order = $details['orders_by_sn'][ $sn ] ?? array(); $preview[] = array( 'order_sn' => $sn, 'order_status' => (string) ( $order['order_status'] ?? $list_by_sn[ $sn ]['order_status'] ?? '' ), 'total_amount' => isset( $order['total_amount'] ) && is_scalar( $order['total_amount'] ) ? (string) $order['total_amount'] : '', 'item_count' => is_array( $order['item_list'] ?? null ) ? count( $order['item_list'] ) : null ); }
			$result = array( 'ok' => true, 'list_count' => count( $list['orders'] ), 'detail_count' => $details ? $details['returned_count'] : 0, 'list_request_id' => $list['request_id'], 'detail_request_id' => $details['request_id'] ?? '', 'preview' => $preview, 'empty' => ! $list['orders'] );
		} catch ( Throwable $exception ) { $result = array( 'ok' => false, 'classification' => strtoupper( sanitize_key( $exception->getMessage() ) ), 'diagnostic' => array_intersect_key( $service->last_diagnostic, array_flip( array( 'stage', 'classification', 'provider_error', 'provider_message', 'request_id', 'http_status', 'api_path', 'duration_ms', 'top_level_keys', 'response_type', 'response_keys', 'order_list_type', 'order_list_count', 'first_order_keys' ) ) ) ); }
		set_transient( 'ecomkit_shopee_order_test_' . $reference, array( 'user_id' => get_current_user_id(), 'result' => $result ), 600 ); wp_safe_redirect( add_query_arg( array( 'page' => 'ecomkit-vuikhoe-marketplace', 'order_test' => $reference ), admin_url( 'admin.php' ) ) ); exit;
	}

	public function settings_page(): void {
		$test = null;
		if ( isset( $_GET['runtime_result'], $_GET['runtime_stage'], $_GET['runtime_code'] ) ) {
			$result = sanitize_key( wp_unslash( $_GET['runtime_result'] ) );
			$stage = sanitize_key( wp_unslash( $_GET['runtime_stage'] ) );
			$code = sanitize_key( wp_unslash( $_GET['runtime_code'] ) );
			$test = array( 'ok' => 'pass' === $result, 'stage' => strtoupper( $stage ), 'classification' => strtoupper( $code ) );
		}
		$shopee = new Ecomkit_Vuikhoe_Shopee_Config();
		$encryption = new Ecomkit_Vuikhoe_Credential_Encryption();
		$this->render( 'settings', array( 'diagnostic' => Ecomkit_Vuikhoe_DB::diagnose(), 'database_runtime' => Ecomkit_Vuikhoe_DB::database_runtime_diagnostic(), 'orders_schema' => Ecomkit_Vuikhoe_DB::orders_schema_diagnostic(), 'excel_runtime' => ( new Ecomkit_Vuikhoe_Runtime_Diagnostics() )->snapshot(), 'runtime_test' => $test, 'marketplace_security' => array( 'encryption' => $encryption->ready(), 'key_source' => $encryption->ready() ? $encryption->key_source_label() : '', 'shopee' => $shopee->readiness(), 'callback_https' => 'https' === strtolower( (string) wp_parse_url( $shopee->callback_url(), PHP_URL_SCHEME ) ) ) ) );
	}

	public function handle_shopee_save_config(): void {
		Ecomkit_Vuikhoe_Security::require_management_capability();
		check_admin_referer( 'ecomkit_shopee_save_config', 'ecomkit_shopee_nonce' );
		try {
			( new Ecomkit_Vuikhoe_Shopee_Config() )->save( sanitize_key( wp_unslash( $_POST['environment'] ?? '' ) ), trim( sanitize_text_field( wp_unslash( $_POST['partner_id'] ?? '' ) ) ), (string) wp_unslash( $_POST['partner_key'] ?? '' ) );
			$args = array( 'shopee_notice' => 'config_saved' );
		} catch ( Throwable $exception ) { $args = array( 'shopee_error' => strtolower( sanitize_key( $exception->getMessage() ) ) ); }
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => 'ecomkit-vuikhoe-marketplace' ), $args ), admin_url( 'admin.php' ) ) ); exit;
	}

	public function handle_shopee_test_config(): void {
		Ecomkit_Vuikhoe_Security::require_management_capability();
		check_admin_referer( 'ecomkit_shopee_test_config', 'ecomkit_shopee_nonce' );
		$result = ( new Ecomkit_Vuikhoe_Shopee_Config() )->readiness();
		$args = array( 'page' => 'ecomkit-vuikhoe-marketplace' );
		$args[ $result['ready'] ? 'shopee_notice' : 'shopee_error' ] = strtolower( $result['code'] );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) ); exit;
	}

	public function handle_shopee_replace_partner_key(): void {
		Ecomkit_Vuikhoe_Security::require_management_capability();
		check_admin_referer( 'ecomkit_shopee_replace_partner_key', 'ecomkit_shopee_nonce' );
		$config_service = new Ecomkit_Vuikhoe_Shopee_Config();
		$config = $config_service->get();
		$new_key = (string) wp_unslash( $_POST['partner_key_new'] ?? '' );
		try {
			if ( '' === trim( $new_key ) ) { throw new InvalidArgumentException( 'SHOPEE_PARTNER_KEY_REQUIRED' ); }
			$config_service->save( (string) ( $config['environment'] ?? '' ), (string) ( $config['partner_id'] ?? '' ), $new_key );
			$args = array( 'shopee_notice' => 'partner_key_saved' );
		} catch ( Throwable $exception ) {
			$args = array( 'shopee_error' => strtolower( sanitize_key( $exception->getMessage() ) ) );
		}
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => 'ecomkit-vuikhoe-marketplace' ), $args ), admin_url( 'admin.php' ) ) ); exit;
	}

	public function handle_excel_runtime_test(): void {
		Ecomkit_Vuikhoe_Security::require_management_capability();
		check_admin_referer( 'ecomkit_vuikhoe_test_excel_runtime', 'ecomkit_runtime_nonce' );
		$result = ( new Ecomkit_Vuikhoe_Runtime_Diagnostics() )->self_test();
		$url = add_query_arg(
			array(
				'page'           => 'ecomkit-vuikhoe-settings',
				'runtime_result' => $result['ok'] ? 'pass' : 'fail',
				'runtime_stage'  => strtolower( $result['stage'] ),
				'runtime_code'   => strtolower( $result['classification'] ),
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Handles the only mutating WP.2 administrator action.
	 */
	public function handle_excel_import(): void {
		Ecomkit_Vuikhoe_Security::require_management_capability();
		check_admin_referer( 'ecomkit_vuikhoe_import_excel', 'ecomkit_nonce' );

		$file = isset( $_FILES['excel_file'] ) && is_array( $_FILES['excel_file'] ) ? $_FILES['excel_file'] : array();

		try {
			$batch_id = ( new Ecomkit_Vuikhoe_Import_Service() )->import_upload( $file, get_current_user_id() );
			try { ( new Ecomkit_Vuikhoe_Auto_Pipeline() )->start( $batch_id ); } catch ( Throwable ) { /* Import remains available for diagnosis even if automation cannot start. */ }
			$url      = add_query_arg(
				array(
					'page'     => 'ecomkit-vuikhoe-results',
					'batch_id' => $batch_id,
				),
				admin_url( 'admin.php' )
			);
		} catch ( Throwable $exception ) {
			$url = add_query_arg(
				array(
					'page'           => 'ecomkit-vuikhoe-process',
					'import_failure' => 1,
				),
				admin_url( 'admin.php' )
			);
		}

		wp_safe_redirect( $url );
		exit;
	}

	public function database_notice(): void {
		if ( ! current_user_can( Ecomkit_Vuikhoe_Security::MANAGEMENT_CAPABILITY ) ) {
			return;
		}

		$diagnostic = Ecomkit_Vuikhoe_DB::diagnose();
		if ( $diagnostic['tables_ok'] && $diagnostic['stored_version'] === $diagnostic['expected_version'] && ! get_option( Ecomkit_Vuikhoe_DB::INSTALL_ERROR_OPTION ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>' . esc_html__( 'Ecomkit phát hiện cơ sở dữ liệu chưa sẵn sàng. Website công khai không bị gián đoạn; quản trị viên vui lòng kiểm tra quyền cơ sở dữ liệu hoặc cài đặt lại gói plugin.', 'ecomkit-vuikhoe' ) . '</p></div>';
	}

	/**
	 * @param string               $view View filename without extension.
	 * @param array<string,mixed>  $data Safe data passed to the view.
	 */
	private function render( string $view, array $data = array() ): void {
		Ecomkit_Vuikhoe_Security::require_management_capability();

		$allowed = array( 'dashboard', 'process', 'results', 'errors', 'history', 'marketplace', 'settings' );
		if ( ! in_array( $view, $allowed, true ) ) {
			wp_die( esc_html__( 'Trang Ecomkit không hợp lệ.', 'ecomkit-vuikhoe' ) );
		}

		$view_file = ECOMKIT_VUIKHOE_DIR . 'admin/views/' . $view . '.php';
		if ( is_readable( $view_file ) ) {
			require $view_file;
		}
	}
}

