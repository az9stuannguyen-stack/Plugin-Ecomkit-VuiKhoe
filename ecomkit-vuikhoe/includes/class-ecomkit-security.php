<?php
/**
 * Authorization helpers.
 */

defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Security {
	public const USE_CAPABILITY = 'ecomkit_use';
	public const MANAGEMENT_CAPABILITY = 'ecomkit_manage';
	private const CAPABILITY_VERSION = '1';
	private const CAPABILITY_VERSION_OPTION = 'ecomkit_vuikhoe_capability_version';

	/** Provision before WordPress builds menus; repair existing roles additively. */
	public static function provision_capabilities(): void {
		$admin = get_role( 'administrator' );
		$operator = get_role( 'ecomkit_operator' );
		$ready = $admin && $operator
			&& $admin->has_cap( self::USE_CAPABILITY )
			&& $admin->has_cap( self::MANAGEMENT_CAPABILITY )
			&& $operator->has_cap( self::USE_CAPABILITY );
		if ( self::CAPABILITY_VERSION === get_option( self::CAPABILITY_VERSION_OPTION ) && $ready ) { return; }
		self::register_operator_role();
		self::grant_administrator_capabilities();
		// WP_User caches role capabilities; refresh the already-loaded current user.
		$user = wp_get_current_user();
		if ( $user->exists() ) { $user->get_role_caps(); }
		$admin = get_role( 'administrator' );
		$operator = get_role( 'ecomkit_operator' );
		if ( $admin && $operator && $admin->has_cap( self::USE_CAPABILITY ) && $admin->has_cap( self::MANAGEMENT_CAPABILITY ) && $operator->has_cap( self::USE_CAPABILITY ) ) {
			update_option( self::CAPABILITY_VERSION_OPTION, self::CAPABILITY_VERSION, false );
		}
	}

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

