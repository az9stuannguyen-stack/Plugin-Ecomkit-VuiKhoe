<?php
/**
 * Authorization helpers.
 */

defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Security {
	public const USE_CAPABILITY = 'ecomkit_use';
	public const MANAGEMENT_CAPABILITY = 'ecomkit_manage';

	public static function grant_administrator_capabilities(): void {
		$role = get_role( 'administrator' );
		if ( $role ) {
			if ( ! $role->has_cap( self::USE_CAPABILITY ) ) { $role->add_cap( self::USE_CAPABILITY ); }
			if ( ! $role->has_cap( self::MANAGEMENT_CAPABILITY ) ) { $role->add_cap( self::MANAGEMENT_CAPABILITY ); }
		}
	}

	public static function register_operator_role(): void {
		add_role( 'ecomkit_operator', 'Ecomkit Operator', array( 'read' => true, self::USE_CAPABILITY => true ) );
		$role = get_role( 'ecomkit_operator' );
		if ( $role && ! $role->has_cap( self::USE_CAPABILITY ) ) { $role->add_cap( self::USE_CAPABILITY ); }
	}

	public static function require_use_capability(): void {
		if ( ! current_user_can( self::USE_CAPABILITY ) ) {
			wp_die( esc_html__( 'Bạn không có quyền sử dụng Ecomkit.', 'ecomkit-vuikhoe' ), esc_html__( 'Không đủ quyền', 'ecomkit-vuikhoe' ), array( 'response' => 403 ) );
		}
	}

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

