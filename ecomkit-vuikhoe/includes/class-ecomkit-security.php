<?php
/**
 * Authorization helpers.
 */

defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Security {
	public const MANAGEMENT_CAPABILITY = 'manage_options';

	/**
	 * Stops page rendering when the current user cannot manage Ecomkit.
	 */
	public static function require_management_capability(): void {
		if ( ! current_user_can( self::MANAGEMENT_CAPABILITY ) ) {
			wp_die(
				esc_html__( 'Bạn không có quyền truy cập trang Ecomkit này.', 'ecomkit-vuikhoe' ),
				esc_html__( 'Không đủ quyền', 'ecomkit-vuikhoe' ),
				array( 'response' => 403 )
			);
		}
	}
}

