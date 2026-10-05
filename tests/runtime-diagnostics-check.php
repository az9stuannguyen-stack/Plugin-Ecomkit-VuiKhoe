<?php
/** WP.2C Excel runtime diagnostic checks. */
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' );
define( 'ECOMKIT_VUIKHOE_VERSION', '0.7.1' );
define( 'ECOMKIT_VUIKHOE_DB_VERSION', 8 );
define( 'ECOMKIT_VUIKHOE_DIR', __DIR__ . '/../ecomkit-vuikhoe/' );
$GLOBALS['wp_version'] = '6.6';

function get_temp_dir(): string { return sys_get_temp_dir() . DIRECTORY_SEPARATOR; }
function wp_upload_dir( mixed $time = null, bool $create = true ): array { return array( 'basedir' => sys_get_temp_dir(), 'error' => false ); }
function wp_tempnam( string $filename = '', string $dir = '' ): string|false { return tempnam( sys_get_temp_dir(), 'ecomkit-runtime-' ); }
function wp_delete_file( string $path ): void { @unlink( $path ); }

require __DIR__ . '/../ecomkit-vuikhoe/vendor/autoload.php';

function runtime_check( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }

$service = new Ecomkit_Vuikhoe_Runtime_Diagnostics();
$ready = $service->snapshot();

$missing_autoload = $ready; $missing_autoload['composer_autoload'] = false;
runtime_check( 'EXCEL_RUNTIME_DEPENDENCY_MISSING' === $service->dependency_check( $missing_autoload )['classification'], 'Missing Composer autoload was not classified.' );

$missing_class = $ready; $missing_class['phpspreadsheet'] = false;
runtime_check( 'EXCEL_RUNTIME_DEPENDENCY_MISSING' === $service->dependency_check( $missing_class )['classification'], 'Missing PhpSpreadsheet class was not classified.' );

$unwritable = $ready; $unwritable['temp_available'] = true; $unwritable['temp_writable'] = false; $unwritable['missing_extensions'] = array();
runtime_check( 'EXCEL_RUNTIME_TEMP_UNWRITABLE' === $service->self_test( null, $unwritable )['classification'], 'Unwritable temp directory was not classified.' );

runtime_check( 'EXCEL_WORKBOOK_SOURCE_MISSING' === $service->source_check( '/not-used', array( 'exists' => false, 'readable' => false, 'size' => 0 ) )['classification'], 'Missing source was not classified.' );
runtime_check( 'EXCEL_WORKBOOK_SOURCE_UNREADABLE' === $service->source_check( '/not-used', array( 'exists' => true, 'readable' => false, 'size' => 100 ) )['classification'], 'Unreadable source was not classified.' );

$ready['composer_autoload'] = true; $ready['phpspreadsheet'] = true; $ready['missing_extensions'] = array(); $ready['temp_available'] = true; $ready['temp_writable'] = true;
$roundtrip = $service->self_test( null, $ready );
runtime_check( true === $roundtrip['ok'] && 'EXCEL_RUNTIME_READY' === $roundtrip['classification'], 'Synthetic XLSX write/read failed.' );

echo "WP.2C runtime diagnostic checks passed.\n";
