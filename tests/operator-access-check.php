<?php
/** WP.6I capability, menu, and endpoint boundary checks without a provider or database. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' );
function operator_check( bool $ok, string $message ): void { if ( ! $ok ) { throw new RuntimeException( $message ); } }
final class OperatorRole {
	public array $caps = array();
	public function add_cap( string $cap ): void { $this->caps[ $cap ] = true; }
	public function has_cap( string $cap ): bool { return ! empty( $this->caps[ $cap ] ); }
}
$GLOBALS['roles'] = array( 'administrator' => new OperatorRole() );
function get_role( string $name ): ?OperatorRole { return $GLOBALS['roles'][ $name ] ?? null; }
function add_role( string $name, string $label, array $caps ): void { $GLOBALS['roles'][ $name ] ??= new OperatorRole(); foreach ( $caps as $cap => $enabled ) { if ( $enabled ) { $GLOBALS['roles'][ $name ]->add_cap( $cap ); } } }
function current_user_can( string $cap ): bool { return ! empty( $GLOBALS['roles'][ $GLOBALS['active_role'] ?? '' ]->caps[ $cap ] ); }
function esc_html__( string $text, string $domain ): string { return $text; }
function wp_die( mixed $message, mixed $title = null, mixed $args = null ): never { throw new RuntimeException( 'DENIED_403' ); }
$GLOBALS['menus'] = array();
function __( string $text, string $domain ): string { return $text; }
function add_menu_page( mixed ...$args ): void { $GLOBALS['menus'][] = array( 'slug' => $args[3], 'cap' => $args[2] ); }
function add_submenu_page( mixed ...$args ): void { $GLOBALS['menus'][] = array( 'slug' => $args[4], 'cap' => $args[3] ); }
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-security.php';
require __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-admin.php';
Ecomkit_Vuikhoe_Security::register_operator_role();
Ecomkit_Vuikhoe_Security::grant_administrator_capabilities();
Ecomkit_Vuikhoe_Security::register_operator_role();
operator_check( get_role( 'administrator' )->has_cap( 'ecomkit_use' ) && get_role( 'administrator' )->has_cap( 'ecomkit_manage' ), 'Administrator capabilities missing.' );
operator_check( get_role( 'ecomkit_operator' )->has_cap( 'ecomkit_use' ) && ! get_role( 'ecomkit_operator' )->has_cap( 'ecomkit_manage' ), 'Operator gained technical capability.' );
operator_check( ! get_role( 'ecomkit_operator' )->has_cap( 'manage_options' ), 'Operator gained WordPress settings capability.' );
$GLOBALS['active_role'] = 'ecomkit_operator';
Ecomkit_Vuikhoe_Security::require_use_capability();
try { Ecomkit_Vuikhoe_Security::require_management_capability(); throw new RuntimeException( 'Technical access allowed.' ); } catch ( RuntimeException $e ) { operator_check( 'DENIED_403' === $e->getMessage(), 'Operator technical access not denied.' ); }
( new Ecomkit_Vuikhoe_Admin() )->add_menu();
$visible = array_values( array_map( static fn( array $menu ): string => $menu['slug'], array_filter( $GLOBALS['menus'], static fn( array $menu ): bool => current_user_can( $menu['cap'] ) ) ) );
foreach ( array( 'ecomkit-vuikhoe', 'ecomkit-vuikhoe-process', 'ecomkit-vuikhoe-results', 'ecomkit-vuikhoe-history', 'ecomkit-vuikhoe-errors' ) as $slug ) { operator_check( in_array( $slug, $visible, true ), 'Operator menu missing: ' . $slug ); }
foreach ( array( 'ecomkit-vuikhoe-marketplace', 'ecomkit-vuikhoe-settings', 'ecomkit-vuikhoe-dashboard' ) as $slug ) { operator_check( ! in_array( $slug, $visible, true ), 'Technical menu visible: ' . $slug ); }
$GLOBALS['active_role'] = 'administrator';
operator_check( current_user_can( 'ecomkit_manage' ), 'Admin management access missing.' );
$admin = file_get_contents( __DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-admin.php' );
$result = file_get_contents( __DIR__ . '/../ecomkit-vuikhoe/admin/views/results.php' );
$process = file_get_contents( __DIR__ . '/../ecomkit-vuikhoe/admin/views/process.php' );
operator_check( str_contains( $result, "if ( current_user_can( Ecomkit_Vuikhoe_Security::MANAGEMENT_CAPABILITY ) ) : ?><details>" ), 'Result technical section not server-gated.' );
operator_check( str_contains( $process, "if ( current_user_can( Ecomkit_Vuikhoe_Security::MANAGEMENT_CAPABILITY ) ) : ?><details>" ), 'Process technical section not server-gated.' );
foreach ( array( 'handle_shopee_spx_pdf', 'handle_pipeline_progress', 'handle_excel_import' ) as $handler ) { operator_check( 1 === preg_match( '/function ' . $handler . '\(\): void\s*\{\s*Ecomkit_Vuikhoe_Security::require_use_capability\(\)/', $admin ), 'Operator workflow endpoint blocked: ' . $handler ); }
foreach ( array( 'handle_shopee_fee_audit', 'handle_batch_state_audit', 'handle_shopee_payment_test', 'handle_shopee_income_test', 'handle_shopee_refresh_token', 'handle_shopee_reconcile_batch' ) as $handler ) { operator_check( 1 === preg_match( '/function ' . $handler . '\(\): void\s*\{\s*Ecomkit_Vuikhoe_Security::require_management_capability\(\)/', $admin ), 'Technical endpoint not protected: ' . $handler ); }
echo "WP.6I operator access: PASS\n";
