<?php
/**
 * Plugin coordinator.
 */

defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Plugin {
	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function run(): void {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( 'Ecomkit_Vuikhoe_DB', 'maybe_upgrade' ), 20 );
		( new Ecomkit_Vuikhoe_Shopee_OAuth() )->register();
		( new Ecomkit_Vuikhoe_Lazada_OAuth() )->register();
		( new Ecomkit_Vuikhoe_Tiktok_OAuth() )->register();
		( new Ecomkit_Vuikhoe_Lazada_Order_Diagnostic() )->register();
		( new Ecomkit_Vuikhoe_Lazada_Finance_Diagnostic() )->register();
		( new Ecomkit_Vuikhoe_Auto_Pipeline() )->register();

		if ( is_admin() ) {
			$admin = new Ecomkit_Vuikhoe_Admin();
			$admin->register();
		}
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'ecomkit-vuikhoe', false, dirname( plugin_basename( ECOMKIT_VUIKHOE_FILE ) ) . '/languages' );
	}

	private function __construct() {}
}

