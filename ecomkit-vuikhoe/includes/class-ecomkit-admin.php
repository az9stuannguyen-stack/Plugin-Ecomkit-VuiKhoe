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
		$this->render(
			'process',
			array(
				'batch'           => $batch_id ? $imports->get_batch_summary( $batch_id ) : null,
				'max_upload_size' => $imports->max_upload_bytes(),
				'max_rows'        => Ecomkit_Vuikhoe_Excel_Service::MAX_ROWS,
			)
		);
	}

	public function results_page(): void {
		$batch_id = isset( $_GET['batch_id'] ) ? absint( wp_unslash( $_GET['batch_id'] ) ) : 0;
		$this->render( 'results', array( 'batch_id' => $batch_id ) );
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
		$this->render( 'marketplace' );
	}

	public function settings_page(): void {
		$test = null;
		if ( isset( $_GET['runtime_result'], $_GET['runtime_stage'], $_GET['runtime_code'] ) ) {
			$result = sanitize_key( wp_unslash( $_GET['runtime_result'] ) );
			$stage = sanitize_key( wp_unslash( $_GET['runtime_stage'] ) );
			$code = sanitize_key( wp_unslash( $_GET['runtime_code'] ) );
			$test = array( 'ok' => 'pass' === $result, 'stage' => strtoupper( $stage ), 'classification' => strtoupper( $code ) );
		}
		$this->render( 'settings', array( 'diagnostic' => Ecomkit_Vuikhoe_DB::diagnose(), 'database_runtime' => Ecomkit_Vuikhoe_DB::database_runtime_diagnostic(), 'excel_runtime' => ( new Ecomkit_Vuikhoe_Runtime_Diagnostics() )->snapshot(), 'runtime_test' => $test ) );
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
			$url      = add_query_arg(
				array(
					'page'     => 'ecomkit-vuikhoe-process',
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

