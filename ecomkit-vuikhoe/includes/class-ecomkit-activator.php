<?php
/**
 * Activation and deactivation lifecycle.
 */

defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Activator {
	/**
	 * Verifies compatibility and installs the idempotent schema.
	 */
	public static function activate(): void {
		if ( ! ecomkit_vuikhoe_is_compatible() ) {
			deactivate_plugins( plugin_basename( ECOMKIT_VUIKHOE_FILE ) );
			wp_die(
				esc_html__( 'Không thể kích hoạt Ecomkit - Vui Khỏe: phiên bản PHP hoặc WordPress chưa đáp ứng yêu cầu tối thiểu.', 'ecomkit-vuikhoe' ),
				esc_html__( 'Môi trường không tương thích', 'ecomkit-vuikhoe' ),
				array( 'back_link' => true )
			);
		}

		try {
			Ecomkit_Vuikhoe_DB::install();
			Ecomkit_Vuikhoe_Security::provision_capabilities();
		} catch ( Throwable $exception ) {
			deactivate_plugins( plugin_basename( ECOMKIT_VUIKHOE_FILE ) );
			wp_die(
				esc_html__( 'Không thể khởi tạo cơ sở dữ liệu Ecomkit. Không có dữ liệu nào bị xóa. Vui lòng kiểm tra quyền cơ sở dữ liệu và thử lại.', 'ecomkit-vuikhoe' ),
				esc_html__( 'Khởi tạo Ecomkit thất bại', 'ecomkit-vuikhoe' ),
				array( 'back_link' => true )
			);
		}
	}

	/**
	 * Deliberately preserves every table and option.
	 */
	public static function deactivate(): void {
		// WP.1 registers no schedules, locks, or temporary runtime state.
	}
}

