<?php
/**
 * Focused WP.1 checks that do not require a running WordPress installation.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/wordpress-placeholder/');
define('ECOMKIT_VUIKHOE_DB_VERSION', 8);
define('ECOMKIT_VUIKHOE_VERSION', '0.7.4');

require __DIR__ . '/../ecomkit-vuikhoe/vendor/autoload.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

check(class_exists('Ecomkit_Vuikhoe_DB'), 'Composer did not autoload the DB class.');
check(class_exists('Ecomkit_Vuikhoe_Admin'), 'Composer did not autoload the Admin class.');
check(class_exists('Ecomkit_Vuikhoe_Security'), 'Composer did not autoload the Security class.');

$prefix = 'tenant_7_';
$tables = [
    'batches' => $prefix . 'ecomkit_batches',
    'orders' => $prefix . 'ecomkit_orders',
    'order_items' => $prefix . 'ecomkit_order_items',
    'errors' => $prefix . 'ecomkit_errors',
    'marketplace_connections' => $prefix . 'ecomkit_marketplace_connections',
    'sync_runs' => $prefix . 'ecomkit_sync_runs',
];
$sql = Ecomkit_Vuikhoe_DB::schema_sql($tables, 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

check(count($sql) === 6, 'Schema must contain exactly six foundation tables.');
foreach ($tables as $table) {
    check(count(array_filter($sql, static fn(string $statement): bool => str_contains($statement, "CREATE TABLE {$table}"))) === 1, "Missing dynamic table {$table}.");
}
foreach ($sql as $statement) {
    check(str_contains($statement, 'PRIMARY KEY  (id)'), 'dbDelta primary-key format is missing.');
    check(str_contains($statement, 'utf8mb4'), 'Charset/collation was not propagated.');
    check(!str_contains($statement, 'wp_ecomkit_'), 'A hard-coded wp_ prefix was found.');
	check(str_contains($statement, ') ENGINE=InnoDB DEFAULT CHARACTER SET'), 'Fresh-install table is not explicitly InnoDB.');
}

$orders = $sql[2];
check(str_contains($orders, 'UNIQUE KEY connection_order (batch_id,connection_id,marketplace_order_id)'), 'Batch-scoped shop/order uniqueness is missing.');
check(str_contains($orders, 'matching_status varchar(32) DEFAULT NULL'), 'Pre-matching order status must remain nullable.');
foreach ( array( 'canonical_data longtext DEFAULT NULL', 'canonical_result_version varchar(16) DEFAULT NULL', 'canonical_materialized_at datetime DEFAULT NULL', 'canonical_source_fingerprint varchar(64) DEFAULT NULL' ) as $canonical_column ) {
    check(str_contains($orders, $canonical_column), "Canonical schema mismatch: {$canonical_column}.");
}
$items = $sql[3];
foreach ( array( 'order_id bigint(20) unsigned NOT NULL', 'product_name varchar(255) DEFAULT NULL', 'sku varchar(191) DEFAULT NULL', 'quantity int(11) DEFAULT NULL', 'raw_product_metadata longtext DEFAULT NULL' ) as $required_item_column ) {
    check(str_contains($items, $required_item_column), "OrderItem schema mismatch: {$required_item_column}.");
}

$activator = file_get_contents(__DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-activator.php');
$uninstall = file_get_contents(__DIR__ . '/../ecomkit-vuikhoe/uninstall.php');
$admin = file_get_contents(__DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-admin.php');
$import = file_get_contents(__DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-import-service.php');
$db = file_get_contents(__DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-db.php');
$oauth = file_get_contents(__DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-shopee-oauth.php');
$plugin_file = file_get_contents(__DIR__ . '/../ecomkit-vuikhoe/ecomkit-vuikhoe.php');
$key_resolver = file_get_contents(__DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-credential-key-resolver.php');
$runtime = implode("\n", array_map(
    static fn(string $path): string => (string) file_get_contents($path),
    array_merge(
        glob(__DIR__ . '/../ecomkit-vuikhoe/*.php') ?: [],
        glob(__DIR__ . '/../ecomkit-vuikhoe/includes/*.php') ?: []
    )
));

check(!preg_match('/DROP\s+TABLE|DELETE\s+FROM|TRUNCATE/i', (string) $activator), 'Lifecycle contains destructive SQL.');
check(!preg_match('/DROP\s+TABLE|DELETE\s+FROM|TRUNCATE/i', (string) $db), 'Database migration contains destructive SQL.');
check(str_contains((string) $db, 'ALTER TABLE `$identifier` ENGINE=InnoDB'), 'Explicit existing-table InnoDB migration is missing.');
check(str_contains((string) $db, 'MODIFY `$name` $type NULL DEFAULT NULL'), 'Explicit Order nullability migration is missing.');
check(str_contains((string) $db, 'SHOW FULL COLUMNS FROM'), 'Order schema metadata inspection is missing.');
check(strpos((string) $db, 'self::migrate_tables_to_innodb();') < strpos((string) $db, 'update_option( self::SCHEMA_OPTION'), 'Schema version may advance before engine migration succeeds.');
check(!preg_match('/DROP\s+TABLE|DELETE\s+FROM|TRUNCATE|delete_option\s*\(/i', (string) $uninstall), 'Uninstall is destructive.');
check(str_contains((string) $uninstall, "defined( 'WP_UNINSTALL_PLUGIN' ) || exit;"), 'Uninstall guard is missing.');
check(str_contains((string) $admin, 'Ecomkit_Vuikhoe_Security::require_management_capability()'), 'Admin render authorization is missing.');
check(str_contains((string) $admin, "check_admin_referer( 'ecomkit_vuikhoe_import_excel', 'ecomkit_nonce' )"), 'Excel action nonce validation is missing.');
check(str_contains((string) $admin, 'admin_post_ecomkit_vuikhoe_import_excel'), 'Authenticated Excel action is missing.');
check(str_contains((string) $admin, 'admin_post_ecomkit_vuikhoe_test_excel_runtime'), 'Authenticated Excel runtime-test action is missing.');
check(str_contains((string) $admin, 'admin_post_ecomkit_vuikhoe_materialize_results'), 'Authenticated canonical materialization action is missing.');
check(str_contains((string) $admin, "check_admin_referer( 'ecomkit_vuikhoe_materialize_results', 'ecomkit_result_nonce' )"), 'Canonical materialization nonce validation is missing.');
check(str_contains((string) $admin, "check_admin_referer( 'ecomkit_vuikhoe_test_excel_runtime', 'ecomkit_runtime_nonce' )"), 'Excel runtime-test nonce validation is missing.');
check(str_contains((string) $admin, "check_admin_referer( 'ecomkit_shopee_save_config', 'ecomkit_shopee_nonce' )"), 'Shopee config nonce validation is missing.');
check(str_contains((string) $admin, 'handle_shopee_save_config'), 'Shopee provider config handler is missing.');
check(str_contains((string) $admin, "check_admin_referer( 'ecomkit_shopee_replace_partner_key', 'ecomkit_shopee_nonce' )"), 'Partner Key replacement nonce validation is missing.');
check(str_contains((string) $admin, 'handle_shopee_replace_partner_key'), 'Partner Key replacement handler is missing.');
check(substr_count((string) $admin, 'Ecomkit_Vuikhoe_Security::require_management_capability()') >= 4, 'Shopee secret actions are not consistently admin-only.');
check(str_contains((string) $oauth, "check_admin_referer( 'ecomkit_shopee_oauth_start', 'ecomkit_shopee_nonce' )"), 'Shopee OAuth start nonce validation is missing.');
check(str_contains((string) $oauth, 'Ecomkit_Vuikhoe_Security::require_management_capability()'), 'Shopee OAuth start capability validation is missing.');
check(str_contains((string) $oauth, "'permission_callback' => '__return_true'"), 'Public Shopee callback route is missing.');
check(str_contains((string) $plugin_file, "define( 'ECOMKIT_VUIKHOE_VERSION', '0.7.15' )"), 'WP.6I.1 plugin version is wrong.');
check(str_contains((string) $plugin_file, "define( 'ECOMKIT_VUIKHOE_DB_VERSION', 9 )"), 'WP.6H.2T database schema version is wrong.');
check(str_contains((string) $key_resolver, "hash_hkdf( 'sha256'"), 'Credential resolver must use HKDF-SHA256.');
check(str_contains((string) $key_resolver, "'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'"), 'Canonical WordPress secret order changed.');
check(!preg_match('/get_option|update_option|set_transient|file_put_contents|wp-config\.php/i', (string) $key_resolver), 'Credential resolver persists key material or edits configuration.');
check(str_contains((string) $import, "'xlsx' !== strtolower"), 'XLSX-only extension validation is missing.');
check(str_contains((string) $import, 'wp_check_filetype_and_ext'), 'WordPress content/type validation is missing.');
check(str_contains((string) $import, 'is_uploaded_file'), 'PHP upload provenance validation is missing.');
check(str_contains((string) $import, 'wp_delete_file'), 'Temporary upload cleanup is missing.');
check(str_contains((string) $import, "'connection_id'           => null"), 'Excel import must not create a fake connection.');
check(str_contains((string) $import, "'platform'                => (string) \$order['platform']"), 'Excel import must persist only the parser-derived platform.');
check(str_contains((string) $import, "\$wpdb->query( 'START TRANSACTION' )"), 'Import transaction is missing.');
check(substr_count((string) $admin, 'add_submenu_page(') === 8, 'Exactly eight submenu registrations are required.');
check(!preg_match('/curl_(init|exec)/i', $runtime), 'Direct cURL provider call detected.');
check(!preg_match('/partner[_ -]?key\s*[=:]\s*[\'\"][A-Za-z0-9]{12,}|access[_ -]?token\s*[=:]\s*[\'\"][A-Za-z0-9]{12,}/i', $runtime), 'Real-looking provider secret detected.');

echo "WP.1 foundation checks passed.\n";

