<?php
/**
 * Authorization helpers.
 */

defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Security {
	public const MANAGEMENT_CAPABILITY = 'manage_options';

	/** Native capabilities only; never provisions roles or custom grants. */
	public static function use_menu_capability(): ?string {
		foreach ( array( self::MANAGEMENT_CAPABILITY, 'edit_pages', 'manage_woocommerce' ) as $capability ) {
			if ( current_user_can( $capability ) ) { return $capability; }
		}
		return null;
	}
	public static function can_use_ecomkit(): bool { return null !== self::use_menu_capability(); }
	public static function can_manage_ecomkit(): bool { return current_user_can( self::MANAGEMENT_CAPABILITY ); }
	public static function require_use_capability(): void {
		if ( ! self::can_use_ecomkit() ) { self::deny(); }
	}
	private static function deny(): void {
		wp_die( esc_html__( 'Ecomkit access denied.', 'ecomkit-vuikhoe' ), esc_html__( 'Permission denied', 'ecomkit-vuikhoe' ), array( 'response' => 403 ) );
	}

	/**
	 * Stops page rendering when the current user cannot manage Ecomkit.
	 */
	public static function require_management_capability(): void {
		if ( ! self::can_manage_ecomkit() ) {
			wp_die(
				esc_html__( 'Bạn không có quyền truy cập trang Ecomkit này.', 'ecomkit-vuikhoe' ),
				esc_html__( 'Không đủ quyền', 'ecomkit-vuikhoe' ),
				array( 'response' => 403 )
			);
		}
	}
}

