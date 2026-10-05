<?php
/** Existing user caches + WP admin_menu-before-admin_init regression. No DB/provider. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' );
function verify_upgrade( bool $ok, string $message ): void { if ( ! $ok ) { throw new RuntimeException( $message ); } }
final class UpgradeRole {
	public function __construct( public array $caps ) {}
	public function has_cap( string $cap ): bool { return ! empty( $this->caps[$cap] ); }
	public function add_cap( string $cap ): void { $this->caps[$cap] = true; $GLOBALS['writes']++; }
}
$GLOBALS['writes'] = 0;
$GLOBALS['roles'] = array( 'administrator' => new UpgradeRole( array( 'manage_options' => true, 'unrelated_cap' => true ) ), 'ecomkit_operator' => new UpgradeRole( array( 'read' => true, 'unrelated_cap' => true ) ) );
function get_role( string $role ): ?UpgradeRole { return $GLOBALS['roles'][$role] ?? null; }
function add_role( string $role, string $label, array $caps ): void { if ( ! isset( $GLOBALS['roles'][$role] ) ) { $GLOBALS['roles'][$role] = new UpgradeRole( $caps ); $GLOBALS['writes']++; } }
function get_option( string $key ): mixed { return $GLOBALS['options'][$key] ?? false; }
function update_option( string $key, mixed $value, bool $autoload ): void { $GLOBALS['options'][$key] = $value; $GLOBALS['writes']++; }
final class ExistingUpgradeUser {
	public array $allcaps = array();
	public function __construct( public string $role ) { $this->get_role_caps(); }
	public function exists(): bool { return true; }
	public function get_role_caps(): array { return $this->allcaps = get_role( $this->role )->caps; }
}
$GLOBALS['user'] = new ExistingUpgradeUser( 'ecomkit_operator' );
function wp_get_current_user(): ExistingUpgradeUser { return $GLOBALS['user']; }
function current_user_can( string $cap ): bool { return ! empty( $GLOBALS['user']->allcaps[$cap] ); }
function __( string $text, string $domain ): string { return $text; }
function esc_html__( string $text, string $domain ): string { return $text; }
function wp_die( mixed ...$args ): never { throw new RuntimeException( 'DENIED_403' ); }
function add_menu_page( mixed ...$args ): void { if ( current_user_can( $args[2] ) ) { $GLOBALS['registered'][$args[3]] = $args[4]; } }
function add_submenu_page( mixed ...$args ): void { if ( current_user_can( $args[3] ) ) { $GLOBALS['registered'][$args[4]] = $args[5]; } }
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-security.php';
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-admin.php';
$admin = new Ecomkit_Vuikhoe_Admin();
// Old behavior: menu/page hooks were rejected before admin_init provisioned caps.
$admin->add_menu();
verify_upgrade( ! isset( $GLOBALS['registered']['ecomkit-vuikhoe-process'] ), 'Old denial not reproduced.' );
Ecomkit_Vuikhoe_Security::provision_capabilities();
$admin->add_menu();
foreach ( array( 'ecomkit-vuikhoe', 'ecomkit-vuikhoe-process', 'ecomkit-vuikhoe-results', 'ecomkit-vuikhoe-history', 'ecomkit-vuikhoe-errors' ) as $slug ) { verify_upgrade( isset( $GLOBALS['registered'][$slug] ), 'Direct page not registered: ' . $slug ); }
verify_upgrade( 'process_page' === $GLOBALS['registered']['ecomkit-vuikhoe'][1], 'Parent landing mismatch.' );
verify_upgrade( current_user_can( 'ecomkit_use' ), 'Existing user cache not refreshed.' );
verify_upgrade( ! current_user_can( 'ecomkit_manage' ) && ! current_user_can( 'manage_options' ), 'Operator privilege escalated.' );
foreach ( array( 'marketplace_page', 'settings_page', 'dashboard_page', 'handle_shopee_payment_test', 'handle_shopee_income_test', 'handle_shopee_fee_audit', 'handle_batch_state_export', 'handle_shopee_refresh_token', 'handle_shopee_test_order_api' ) as $method ) {
	try { $admin->$method(); throw new RuntimeException( 'Technical action allowed: ' . $method ); }
	catch ( RuntimeException $e ) { verify_upgrade( 'DENIED_403' === $e->getMessage(), 'Technical denial failed: ' . $method ); }
}
$writes = $GLOBALS['writes'];
Ecomkit_Vuikhoe_Security::provision_capabilities();
verify_upgrade( $writes === $GLOBALS['writes'], 'Repeated provisioning wrote role/option state.' );
verify_upgrade( get_role( 'administrator' )->has_cap( 'ecomkit_use' ) && get_role( 'administrator' )->has_cap( 'ecomkit_manage' ), 'Admin upgrade incomplete.' );
verify_upgrade( get_role( 'administrator' )->has_cap( 'unrelated_cap' ) && get_role( 'ecomkit_operator' )->has_cap( 'unrelated_cap' ), 'Unrelated caps removed.' );
$GLOBALS['user'] = new ExistingUpgradeUser( 'administrator' );
$admin->add_menu();
foreach ( array( 'ecomkit-vuikhoe-marketplace', 'ecomkit-vuikhoe-settings', 'ecomkit-vuikhoe-dashboard' ) as $slug ) { verify_upgrade( isset( $GLOBALS['registered'][$slug] ), 'Admin page unavailable.' ); }
unset( $GLOBALS['roles']['ecomkit_operator'] );
Ecomkit_Vuikhoe_Security::provision_capabilities();
verify_upgrade( get_role( 'ecomkit_operator' )->has_cap( 'ecomkit_use' ), 'Fresh role not provisioned.' );
$plugin = file_get_contents( __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-plugin.php' );
verify_upgrade( str_contains( $plugin, "add_action( 'init', array( 'Ecomkit_Vuikhoe_Security', 'provision_capabilities' ), 1 )" ), 'Provisioning must precede admin_menu.' );
echo "WP.6I.1 existing-user upgrade/menu ordering: PASS\n";
