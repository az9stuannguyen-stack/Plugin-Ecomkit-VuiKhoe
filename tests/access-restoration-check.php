<?php
/** Pre-WP.6I authorization boundary: native manage_options only. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' );
function access_check( bool $ok, string $message ): void { if ( ! $ok ) { throw new RuntimeException( $message ); } }
function current_user_can( string $cap ): bool { return ! empty( $GLOBALS['caps'][$cap] ); }
function esc_html__( string $text, string $domain ): string { return $text; }
function __( string $text, string $domain ): string { return $text; }
function wp_die( mixed ...$args ): never { throw new RuntimeException( 'DENIED_403' ); }
function add_menu_page( mixed ...$args ): void { if ( current_user_can( $args[2] ) ) { $GLOBALS['pages'][$args[3]] = $args[4]; } }
function add_submenu_page( mixed ...$args ): void { if ( current_user_can( $args[3] ) ) { $GLOBALS['pages'][$args[4]] = $args[5]; } }
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-security.php';
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-admin.php';
$admin = new Ecomkit_Vuikhoe_Admin();
$GLOBALS['caps'] = array( 'manage_options' => true );
Ecomkit_Vuikhoe_Security::require_management_capability();
$admin->add_menu();
foreach ( array( 'ecomkit-vuikhoe', 'ecomkit-vuikhoe-process', 'ecomkit-vuikhoe-results', 'ecomkit-vuikhoe-history', 'ecomkit-vuikhoe-errors', 'ecomkit-vuikhoe-marketplace', 'ecomkit-vuikhoe-settings' ) as $slug ) { access_check( isset( $GLOBALS['pages'][$slug] ), 'Known-good admin page unavailable: ' . $slug ); }
$GLOBALS['caps'] = array( 'ecomkit_use' => true, 'ecomkit_manage' => true );
foreach ( array( 'results_page', 'handle_excel_import', 'handle_pipeline_progress', 'handle_shopee_spx_pdf', 'handle_shopee_payment_test', 'handle_shopee_fee_audit', 'handle_batch_state_export', 'handle_shopee_refresh_token' ) as $method ) {
	try { $admin->$method(); throw new RuntimeException( 'Custom caps bypassed native policy.' ); }
	catch ( RuntimeException $e ) { access_check( 'DENIED_403' === $e->getMessage(), 'Native capability not enforced: ' . $method ); }
}
access_check( 'manage_options' === Ecomkit_Vuikhoe_Security::MANAGEMENT_CAPABILITY, 'Baseline capability changed.' );
$source = file_get_contents( __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-security.php' );
access_check( ! str_contains( $source, 'add_role' ) && ! str_contains( $source, 'update_option' ), 'Role provisioning remains.' );
echo "WP.6I-R native access restoration: PASS\n";
