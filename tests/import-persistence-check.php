<?php
/**
 * WP.2 persistence contract checks with an in-memory wpdb double.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/wordpress-placeholder/');
define('ECOMKIT_VUIKHOE_VERSION', '0.2.0');
define('ECOMKIT_VUIKHOE_DB_VERSION', 2);
define('ARRAY_A', 'ARRAY_A');

function wp_max_upload_size(): int { return 20 * 1024 * 1024; }
function size_format(int $bytes): string { return (string) $bytes; }
function current_time(string $type, bool $gmt = false): string { return '2026-10-01 00:00:00'; }
function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }

final class FakeWpdb
{
    public string $prefix = 'tenant_2_';
    public int $insert_id = 0;
    /** @var array<int,array{table:string,data:array<string,mixed>}> */
    public array $inserts = [];
    /** @var array<int,array{table:string,data:array<string,mixed>,where:array<string,mixed>}> */
    public array $updates = [];
    /** @var string[] */
    public array $queries = [];

    public function insert(string $table, array $data): int
    {
        $this->inserts[] = compact('table', 'data');
        $this->insert_id++;
        return 1;
    }

    public function update(string $table, array $data, array $where): int
    {
        $this->updates[] = compact('table', 'data', 'where');
        return 1;
    }

    public function query(string $sql): int
    {
        $this->queries[] = $sql;
        return 1;
    }
}

function check_import(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$GLOBALS['wpdb'] = new FakeWpdb();
require __DIR__ . '/../ecomkit-vuikhoe/vendor/autoload.php';

$service = new Ecomkit_Vuikhoe_Import_Service();
$persist = new ReflectionMethod($service, 'persist_result');
$persist->invoke($service, 42, 'synthetic.xlsx', 1000, [
    'status' => 'WARNING',
    'total_rows' => 2,
    'valid_rows' => 1,
    'orders' => [[
        'order_code' => '0000A-01',
        'sheet' => 'Orders',
        'row' => 2,
        'raw_cells' => ['1' => '0000A-01'],
    ]],
    'errors' => [[
        'error_code' => 'EXCEL_EMPTY_ORDER_CODE',
        'message' => 'Dòng 3 chưa có Mã đơn sàn.',
        'suggestion' => 'Điền mã.',
        'sheet' => 'Orders',
        'row' => 3,
        'column' => 'Mã đơn sàn',
        'field' => 'Mã đơn sàn',
    ]],
    'raw' => ['parser_version' => 'wp2-v1'],
]);

$wpdb = $GLOBALS['wpdb'];
check_import($wpdb->queries === ['START TRANSACTION', 'COMMIT'], 'Persistence did not commit one transaction.');
check_import(count($wpdb->inserts) === 2, 'Expected one Order and one Error insert.');
$order = $wpdb->inserts[0]['data'];
check_import($order['batch_id'] === 42 && $order['marketplace_order_id'] === '0000A-01' && $order['raw_order_code'] === '0000A-01', 'Order identity was not preserved.');
check_import($order['connection_id'] === null && $order['platform'] === 'UNKNOWN' && $order['matching_status'] === null, 'Excel import fabricated marketplace state.');
check_import(str_contains((string) $order['source_refs'], 'Orders') && str_contains((string) $order['source_refs'], '2'), 'Order source location was not preserved.');
$error = $wpdb->inserts[1]['data'];
check_import($error['batch_id'] === 42 && $error['sheet_name'] === 'Orders' && $error['row_number'] === 3, 'Error location was not preserved.');
check_import(count($wpdb->updates) === 1, 'Batch was not updated exactly once.');
$batch = $wpdb->updates[0]['data'];
check_import($batch['status'] === 'WARNING' && $batch['order_count'] === 1 && $batch['error_count'] === 1, 'Batch counts/status are incoherent.');

$validate = new ReflectionMethod($service, 'validate_upload');
$unsupported = $validate->invoke($service, ['error' => UPLOAD_ERR_OK, 'tmp_name' => 'x', 'size' => 100], 'fixture.csv');
check_import($unsupported['error_code'] === 'EXCEL_UNSUPPORTED_FILE_TYPE', 'Unsupported type was not blocked.');
$oversized = $validate->invoke($service, ['error' => UPLOAD_ERR_OK, 'tmp_name' => 'x', 'size' => 11 * 1024 * 1024], 'fixture.xlsx');
check_import($oversized['error_code'] === 'EXCEL_FILE_TOO_LARGE', 'Oversized upload was not blocked.');

echo "WP.2 import persistence checks passed.\n";
