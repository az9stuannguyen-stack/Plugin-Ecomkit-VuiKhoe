<?php
/**
 * Focused WP.1 checks that do not require a running WordPress installation.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/wordpress-placeholder/');
define('ECOMKIT_VUIKHOE_DB_VERSION', 1);
define('ECOMKIT_VUIKHOE_VERSION', '0.1.0');

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
}

$orders = $sql[2];
check(str_contains($orders, 'UNIQUE KEY connection_order (connection_id,marketplace_order_id)'), 'Multi-shop uniqueness is missing.');

$activator = file_get_contents(__DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-activator.php');
$uninstall = file_get_contents(__DIR__ . '/../ecomkit-vuikhoe/uninstall.php');
$admin = file_get_contents(__DIR__ . '/../ecomkit-vuikhoe/includes/class-ecomkit-admin.php');
$runtime = implode("\n", array_map(
    static fn(string $path): string => (string) file_get_contents($path),
    array_merge(
        glob(__DIR__ . '/../ecomkit-vuikhoe/*.php') ?: [],
        glob(__DIR__ . '/../ecomkit-vuikhoe/includes/*.php') ?: []
    )
));

check(!preg_match('/DROP\s+TABLE|DELETE\s+FROM|TRUNCATE/i', (string) $activator), 'Lifecycle contains destructive SQL.');
check(!preg_match('/DROP\s+TABLE|DELETE\s+FROM|TRUNCATE|delete_option\s*\(/i', (string) $uninstall), 'Uninstall is destructive.');
check(str_contains((string) $uninstall, "defined( 'WP_UNINSTALL_PLUGIN' ) || exit;"), 'Uninstall guard is missing.');
check(str_contains((string) $admin, 'Ecomkit_Vuikhoe_Security::require_management_capability()'), 'Admin render authorization is missing.');
check(substr_count((string) $admin, 'add_submenu_page(') === 7, 'Exactly seven submenu registrations are required.');
check(!preg_match('/wp_remote_(get|post|request)|curl_(init|exec)|\/api\/v2\//i', $runtime), 'Provider/network call detected.');
check(!preg_match('/partner[_ -]?key\s*[=:]\s*[\'\"][A-Za-z0-9]{12,}|access[_ -]?token\s*[=:]\s*[\'\"][A-Za-z0-9]{12,}/i', $runtime), 'Real-looking provider secret detected.');

echo "WP.1 foundation checks passed.\n";

