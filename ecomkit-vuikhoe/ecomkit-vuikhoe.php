<?php
/**
 * Plugin Name: Ecomkit - Vui Khỏe
 * Description: Nền tảng quản lý và đối chiếu đơn hàng thương mại điện tử cho Vui Khỏe.
 * Version: 0.7.20
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: Vui Khỏe
 * Text Domain: ecomkit-vuikhoe
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'ECOMKIT_VUIKHOE_VERSION', '0.7.20' );
define( 'ECOMKIT_VUIKHOE_DB_VERSION', 9 );
define( 'ECOMKIT_VUIKHOE_MIN_PHP', '8.1' );
define( 'ECOMKIT_VUIKHOE_MIN_WP', '6.6' );
define( 'ECOMKIT_VUIKHOE_FILE', __FILE__ );
define( 'ECOMKIT_VUIKHOE_DIR', plugin_dir_path( __FILE__ ) );
define( 'ECOMKIT_VUIKHOE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Returns whether the host satisfies the minimum runtime versions.
 */
function ecomkit_vuikhoe_is_compatible() {
	global $wp_version;

	return version_compare( PHP_VERSION, ECOMKIT_VUIKHOE_MIN_PHP, '>=' )
		&& version_compare( (string) $wp_version, ECOMKIT_VUIKHOE_MIN_WP, '>=' );
}

/**
 * Displays a safe compatibility notice without loading the plugin runtime.
 */
function ecomkit_vuikhoe_compatibility_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$message = sprintf(
		/* translators: 1: minimum PHP version, 2: minimum WordPress version. */
		__( 'Ecomkit - Vui Khỏe cần PHP %1$s trở lên và WordPress %2$s trở lên. Plugin chưa tải runtime trên môi trường này.', 'ecomkit-vuikhoe' ),
		ECOMKIT_VUIKHOE_MIN_PHP,
		ECOMKIT_VUIKHOE_MIN_WP
	);

	echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
}

if ( ! ecomkit_vuikhoe_is_compatible() ) {
	add_action( 'admin_notices', 'ecomkit_vuikhoe_compatibility_notice' );
	return;
}

$ecomkit_vuikhoe_autoload = ECOMKIT_VUIKHOE_DIR . 'vendor/autoload.php';

if ( ! is_readable( $ecomkit_vuikhoe_autoload ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( current_user_can( 'manage_options' ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Ecomkit - Vui Khỏe thiếu Composer autoload. Hãy cài lại plugin từ gói phát hành đầy đủ.', 'ecomkit-vuikhoe' ) . '</p></div>';
			}
		}
	);
	return;
}

require_once $ecomkit_vuikhoe_autoload;
foreach ( array( 'class-ecomkit-credential-key-resolver.php', 'class-ecomkit-credential-encryption.php', 'class-ecomkit-credential-mutation-lock.php', 'class-ecomkit-shopee-environment.php', 'class-ecomkit-shopee-signer.php', 'class-ecomkit-shopee-config.php', 'class-ecomkit-shopee-http-client.php', 'class-ecomkit-marketplace-connection-service.php', 'class-ecomkit-shopee-token-service.php', 'class-ecomkit-shopee-order-service.php', 'class-ecomkit-shopee-order-normalizer.php', 'class-ecomkit-shopee-payment-normalizer.php', 'class-ecomkit-shopee-payment-service.php', 'class-ecomkit-shopee-income-normalizer.php', 'class-ecomkit-shopee-income-service.php', 'class-ecomkit-auto-pipeline.php', 'class-ecomkit-batch-state-audit.php', 'class-ecomkit-shopee-financial-enrichment-service.php', 'class-ecomkit-shopee-reconciliation-service.php', 'class-ecomkit-shopee-oauth.php', 'class-ecomkit-money-formatter.php' ) as $ecomkit_vuikhoe_class ) {
	require_once ECOMKIT_VUIKHOE_DIR . 'includes/' . $ecomkit_vuikhoe_class;
}

register_activation_hook( __FILE__, array( 'Ecomkit_Vuikhoe_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Ecomkit_Vuikhoe_Activator', 'deactivate' ) );

Ecomkit_Vuikhoe_Plugin::instance()->run();

