<?php
/** WP.6J.2 fake-transport OAuth, state, signing, encrypted lifecycle and isolation. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' ); define( 'ARRAY_A', 'ARRAY_A' );
function auth_check( bool $ok, string $message ): void { if ( ! $ok ) { throw new RuntimeException( $message ); } }
function expect_auth_error( string $code, callable $fn ): void { try { $fn(); throw new RuntimeException( 'Expected ' . $code ); } catch ( RuntimeException $e ) { auth_check( $code === $e->getMessage(), 'Wrong failure classification: ' . $e->getMessage() ); } }
function wp_json_encode( mixed $v, int $flags = 0 ): string|false { return json_encode( $v, $flags ); }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['options'][$key] ?? $default; }
function update_option( string $key, mixed $value, bool $autoload ): bool { $GLOBALS['options'][$key] = $value; return true; }
function current_time( string $format, bool $utc ): string { return gmdate( 'Y-m-d H:i:s' ); }
function current_user_can( string $cap ): bool { return 'manage_options' === $cap && $GLOBALS['authorized']; }
function get_current_user_id(): int { return $GLOBALS['user_id']; }
function wp_get_session_token(): string { return $GLOBALS['session']; }
function admin_url( string $path ): string { return 'https://wordpress.example/wp-admin/' . $path; }
function add_query_arg( mixed $args, mixed $second, mixed $third = null ): string { if ( is_array( $args ) ) { $url = $second; } else { $url = $third; $args = array( $args => $second ); } return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args ); }
function wp_parse_url( string $url, int $component ): mixed { return parse_url( $url, $component ); }
function wp_http_validate_url( string $url ): bool { return str_starts_with( $url, 'https://' ); }
function set_transient( string $key, mixed $v, int $ttl ): bool { $GLOBALS['transients'][$key] = $v; return true; }
function get_transient( string $key ): mixed { return $GLOBALS['transients'][$key] ?? false; }
function delete_transient( string $key ): bool { if ( ! isset( $GLOBALS['transients'][$key] ) ) { return false; } unset( $GLOBALS['transients'][$key] ); return true; }
function is_wp_error( mixed $r ): bool { return $r instanceof AuthNetworkError; }
final class AuthNetworkError {}
function wp_remote_retrieve_response_code( array $r ): int { return $r['status']; }
function wp_remote_retrieve_body( array $r ): string { return $r['body']; }
function wp_remote_post(): never { throw new RuntimeException( 'REAL_PROVIDER_CALL_FORBIDDEN' ); }
function wp_remote_get( string $url = '', array $args = array() ): mixed {
	if ( isset( $GLOBALS['synthetic_lazada_read_transport'] ) ) { return ( $GLOBALS['synthetic_lazada_read_transport'] )( $url, $args ); }
	throw new RuntimeException( 'REAL_PROVIDER_CALL_FORBIDDEN' );
}
function esc_html__( string $s, string $domain ): string { return $s; }
function wp_die( mixed ...$args ): never { throw new RuntimeException( 'DENIED_403' ); }
function check_admin_referer( string $action, string $field ): void {
	if ( 'ecomkit_lazada_reconcile_batch' === $action && 'ecomkit_lazada_reconcile_nonce' === $field && ! empty( $GLOBALS['synthetic_lazada_control_nonce'] ) && 'synthetic-nonce' === ( $_POST[$field] ?? '' ) ) { return; }
	throw new RuntimeException( 'NONCE_DENIED' );
}
function add_action( string $hook, mixed $handler ): void { $GLOBALS['hooks'][$hook] = $handler; }
final class AuthDb {
	public string $prefix = 'test_'; public array $rows = array(); public int $insert_id = 0; public bool $fail_update = false; public bool $fail_lock = false;
	public function prepare( string $sql, mixed ...$args ): string { return json_encode( array( $sql, $args ) ); }
	public function get_var( string $query ): mixed { [$sql,$args] = json_decode( $query, true ); if ( str_contains( $sql, 'GET_LOCK' ) ) { return $this->fail_lock ? 0 : 1; } if ( str_contains( $sql, 'RELEASE_LOCK' ) ) { return 1; } foreach ( $this->rows as $row ) { if ( $row['platform'] === $args[0] && $row['external_shop_id'] === $args[1] ) { return $row['id']; } } return null; }
	public function get_row( string $query, mixed $mode ): ?array { [$sql,$args] = json_decode( $query, true ); foreach ( $this->rows as $row ) { if ( (int) $row['id'] === $args[0] && $row['platform'] === $args[1] ) { return $row; } } return null; }
	public function get_results( string $query, mixed $mode ): array { [$sql,$args] = json_decode( $query, true ); return array_values( array_filter( $this->rows, static fn( array $row ): bool => $row['platform'] === $args[0] ) ); }
	public function insert( string $table, array $data ): int { $data['id'] = ++$this->insert_id; $this->rows[$data['id']] = $data; return 1; }
	public function update( string $table, array $data, array $where ): int|false { if ( $this->fail_update ) { return false; } foreach ( $this->rows as &$row ) { if ( $row['id'] === $where['id'] && $row['platform'] === $where['platform'] ) { $row = array_merge( $row, $data ); return 1; } } return 0; }
}
$GLOBALS['authorized'] = true; $GLOBALS['session'] = 'fake-admin-session'; $GLOBALS['user_id'] = 7; $GLOBALS['wpdb'] = new AuthDb();
require __DIR__ . '/../ecomkit-vuikhoe/vendor/autoload.php';
$encryption = new Ecomkit_Vuikhoe_Credential_Encryption( base64_encode( str_repeat( 'k', 32 ) ) );
$config = new Ecomkit_Vuikhoe_Lazada_Config( $encryption ); $config->save( 'test-app', 'test-app-secret' );
$signature = Ecomkit_Vuikhoe_Lazada_Signer::sign( '/order/get', array( 'timestamp' => '1517820392000', 'sign' => 'ignore', 'order_id' => '1234', 'app_key' => '123456', 'access_token' => 'test', 'sign_method' => 'sha256' ), 'helloworld' );
auth_check( '4190D32361CFB9581350222F345CB77F3B19F0E31D162316848A2C1FFD5FAB4A' === $signature, 'Official signature fixture failed.' );
auth_check( Ecomkit_Vuikhoe_Lazada_Signer::sign( '/test/api', array( 'foo' => '1', 'bar' => '2', 'foo_bar' => '3', 'foobar' => '4' ), 'test' ) === strtoupper( hash_hmac( 'sha256', '/test/apibar2foo1foo_bar3foobar4', 'test' ) ), 'ASCII ordering/path prefix wrong.' );
try { Ecomkit_Vuikhoe_Lazada_Signer::sign( '/test/api', array( 'binary' => array( 1 ) ), 'test' ); throw new RuntimeException( 'Structured input signed.' ); } catch ( InvalidArgumentException ) {}
$fixture = array( 'code' => '0', 'country' => 'vn', 'country_user_info_list' => array( array( 'country' => 'vn', 'seller_id' => 'seller-17', 'user_id' => 'user-17', 'short_code' => 'VN17' ) ), 'access_token' => 'fake-access-one', 'refresh_token' => 'fake-refresh-one', 'expires_in' => 7200, 'refresh_expires_in' => 86400, 'request_id' => 'req-one' );
$GLOBALS['requests'] = array(); $GLOBALS['reply'] = $fixture; $GLOBALS['reply_status'] = 200;
$transport = static function ( string $url, array $options ) use ( $config ): mixed {
	parse_str( $options['body'], $params ); $GLOBALS['requests'][] = array( $url, $params, $options );
	auth_check( ! str_contains( $url, '?' ) && ! str_contains( $url, 'fake-' ) && ! str_contains( $url, 'secret' ), 'Sensitive request URL.' );
	auth_check( $options['sslverify'] && 0 === $options['redirection'] && 262144 === $options['limit_response_size'], 'HTTP bounds/TLS missing.' );
	$path = substr( $url, strlen( Ecomkit_Vuikhoe_Lazada_Config::TOKEN_BASE ) );
	auth_check( $params['sign'] === Ecomkit_Vuikhoe_Lazada_Signer::sign( $path, $params, $config->credentials()['app_secret'] ), 'Request signature mismatch.' );
	if ( $GLOBALS['reply'] instanceof AuthNetworkError ) { return $GLOBALS['reply']; }
	return array( 'status' => $GLOBALS['reply_status'], 'body' => is_string( $GLOBALS['reply'] ) ? $GLOBALS['reply'] : json_encode( $GLOBALS['reply'] ) );
};
$http = new Ecomkit_Vuikhoe_Lazada_HTTP_Client( $transport );
$tokens = new Ecomkit_Vuikhoe_Lazada_Token_Service( $http, $config, $encryption );
$oauth = new Ecomkit_Vuikhoe_Lazada_OAuth( $config, $tokens ); $oauth->register();
auth_check( isset( $GLOBALS['hooks']['admin_post_ecomkit_lazada_oauth_callback'], $GLOBALS['hooks']['admin_post_nopriv_ecomkit_lazada_oauth_callback'] ), 'Callback routing missing.' );
$state = $oauth->create_flow(); $url = $oauth->authorization_url( 'test-app', $state ); parse_str( parse_url( $url, PHP_URL_QUERY ), $params );
auth_check( 'auth.lazada.com' === parse_url( $url, PHP_URL_HOST ) && '/oauth/authorize' === parse_url( $url, PHP_URL_PATH ) && 'code' === $params['response_type'] && 'true' === $params['force_auth'] && 'test-app' === $params['client_id'] && $config->callback_url() === $params['redirect_uri'] && $state === $params['state'] && ! str_contains( $url, 'test-app-secret' ), 'Authorization URL invalid.' );
auth_check( ! str_contains( json_encode( $GLOBALS['transients'] ), 'test-app-secret' ), 'State contains secret.' );
$id = $oauth->complete( $state, 'fake-authorization-code' );
auth_check( 1 === count( $GLOBALS['requests'] ) && 'fake-authorization-code' === $GLOBALS['requests'][0][1]['code'] && ! isset( $GLOBALS['requests'][0][1]['access_token'] ), 'Code exchange parameters wrong.' );
$row = $GLOBALS['wpdb']->rows[$id];
auth_check( 'ACTIVE' === $row['status'] && 'LAZADA' === $row['platform'] && 'seller-17' === $row['external_shop_id'], 'Authorization not persisted.' );
auth_check( ! str_contains( json_encode( $row ), 'fake-access-one' ) && ! str_contains( json_encode( $row ), 'fake-refresh-one' ) && ! str_contains( json_encode( $row ), 'fake-authorization-code' ), 'Sensitive plaintext persisted.' );
$plain = $encryption->decrypt( $row['credential_envelope'], 'ecomkit|lazada|vn|shop:seller-17|v1' );
auth_check( abs( $plain['access_expires_at'] - time() - 7200 ) <= 2 && abs( $plain['refresh_expires_at'] - time() - 86400 ) <= 2, 'Expiry durations not normalized.' );
auth_check( 'fake-access-one' === $tokens->ensure_usable_access_token( $id ) && 1 === count( $GLOBALS['requests'] ), 'Usable token unnecessarily refreshed.' );
expect_auth_error( 'LAZADA_OAUTH_STATE_INVALID', fn() => $oauth->complete( $state, 'fake-code' ) );
expect_auth_error( 'LAZADA_OAUTH_STATE_INVALID', fn() => $oauth->complete( null, 'fake-code' ) );
expect_auth_error( 'LAZADA_OAUTH_STATE_INVALID', fn() => $oauth->complete( str_repeat( 'a', 64 ), 'fake-code' ) );
foreach ( array( 'expires_at' => time() - 1, 'platform' => 'SHOPEE', 'user_id' => 99, 'session_hash' => 'tampered', 'fingerprint' => 'old-config', 'callback' => 'https://wrong.example/' ) as $key => $value ) {
	$bad_state = $oauth->create_flow(); $flow_key = 'ecomkit_lazada_flow_' . hash( 'sha256', $bad_state ); $GLOBALS['transients'][$flow_key][$key] = $value;
	expect_auth_error( 'LAZADA_OAUTH_STATE_INVALID', fn() => $oauth->complete( $bad_state, 'fake-code' ) );
}
auth_check( 1 === count( $GLOBALS['requests'] ), 'Invalid state performed exchange.' );
$bad_state = $oauth->create_flow(); expect_auth_error( 'LAZADA_OAUTH_CODE_MISSING', fn() => $oauth->complete( $bad_state, '' ) );
$GLOBALS['reply'] = array_merge( $fixture, array( 'access_token' => 'fake-access-two', 'refresh_token' => 'fake-refresh-two' ) );
auth_check( 'fake-access-two' === $tokens->ensure_usable_access_token( $id, true ), 'Refresh failed.' );
auth_check( 'fake-refresh-one' === $GLOBALS['requests'][1][1]['refresh_token'], 'Wrong refresh credential sent.' );
$GLOBALS['reply'] = array_merge( $fixture, array( 'access_token' => 'fake-access-three', 'refresh_token' => 'fake-refresh-three' ) );
$tokens->ensure_usable_access_token( $id, true );
auth_check( 'fake-refresh-two' === $GLOBALS['requests'][2][1]['refresh_token'], 'Rotated token not used.' );
auth_check( $id === $tokens->authorize( 'new-auth-code' ) && 1 === count( $GLOBALS['wpdb']->rows ), 'Reauth duplicated shop.' );
$safe = $tokens->safe_connections();
auth_check( ! str_contains( json_encode( $safe ), 'fake-access' ) && ! str_contains( json_encode( $safe ), 'fake-refresh' ), 'UI projection leaked token.' );
$vn = Ecomkit_Vuikhoe_Lazada_Token_Service::normalize( $fixture, 1000 ); auth_check( 8200 === $vn['access_expires_at'] && 87400 === $vn['refresh_expires_at'], 'Deterministic expiry failed.' );
$cross = $fixture; $cross['country'] = 'cb'; $cross['country_user_info'] = array( array( 'country' => 'my', 'seller_id' => 'my-shop' ), array( 'country' => 'vn', 'seller_id' => 'vn-shop' ) ); unset( $cross['country_user_info_list'] );
auth_check( 'vn-shop' === Ecomkit_Vuikhoe_Lazada_Token_Service::normalize( $cross )['external_shop_id'], 'Cross-border VN selection failed.' );
$nonvn = $fixture; $nonvn['country'] = 'my'; $nonvn['country_user_info_list'][0]['country'] = 'my'; expect_auth_error( 'LAZADA_COUNTRY_MISMATCH', fn() => Ecomkit_Vuikhoe_Lazada_Token_Service::normalize( $nonvn ) );
$noidentity = $fixture; unset( $noidentity['country_user_info_list'] ); expect_auth_error( 'LAZADA_INVALID_TOKEN_RESPONSE', fn() => Ecomkit_Vuikhoe_Lazada_Token_Service::normalize( $noidentity ) );
$noaccess = $fixture; unset( $noaccess['access_token'] ); expect_auth_error( 'LAZADA_INVALID_TOKEN_RESPONSE', fn() => Ecomkit_Vuikhoe_Lazada_Token_Service::normalize( $noaccess ) );
$zero = $fixture; $zero['refresh_expires_in'] = 0; auth_check( false === Ecomkit_Vuikhoe_Lazada_Token_Service::normalize( $zero )['refreshable'], 'Zero refresh expiry treated refreshable.' );
$GLOBALS['reply'] = array( 'code' => 'IllegalRefreshToken', 'message' => 'invalid fake-refresh-three test-app-secret', 'request_id' => 'safe-id' );
expect_auth_error( 'LAZADA_REFRESH_EXPIRED', fn() => $tokens->ensure_usable_access_token( $id, true ) );
auth_check( 'REAUTH_REQUIRED' === $tokens->safe_connections()[0]['lifecycle'] && ! str_contains( json_encode( $tokens->safe_connections() ), 'test-app-secret' ), 'Invalid refresh lifecycle/redaction failed.' );
$before = count( $GLOBALS['requests'] ); expect_auth_error( 'LAZADA_REAUTH_REQUIRED', fn() => $tokens->ensure_usable_access_token( $id, true ) ); auth_check( $before === count( $GLOBALS['requests'] ), 'Refresh failure retried.' );
foreach ( array( new AuthNetworkError(), '{bad-json', array( 'code' => '0', 'country' => 'vn' ) ) as $error_reply ) {
	$GLOBALS['reply'] = $fixture; $tokens->authorize( 'new-code' ); $GLOBALS['reply'] = $error_reply;
	try { $tokens->ensure_usable_access_token( $id, true ); throw new RuntimeException( 'Invalid refresh accepted.' ); } catch ( RuntimeException $e ) { auth_check( in_array( $e->getMessage(), array( 'LAZADA_NETWORK_ERROR', 'LAZADA_INVALID_TOKEN_RESPONSE' ), true ), 'Unexpected refresh failure.' ); }
	auth_check( 'TOKEN_ERROR' === $tokens->safe_connections()[0]['lifecycle'], 'Uncertain refresh not fail closed.' );
}
$GLOBALS['reply'] = $fixture; $tokens->authorize( 'restore-code' );
$before = count( $GLOBALS['requests'] ); $GLOBALS['wpdb']->fail_update = true;
expect_auth_error( 'LAZADA_CONNECTION_PERSIST_FAILED', fn() => $tokens->ensure_usable_access_token( $id, true ) );
auth_check( $before === count( $GLOBALS['requests'] ), 'Provider called after inflight save failure.' ); $GLOBALS['wpdb']->fail_update = false;
$saved_row = $GLOBALS['wpdb']->rows[$id];
$GLOBALS['reply'] = array( 'code' => 'unknown-provider-code', 'message' => 'safe rejection' );
expect_auth_error( 'LAZADA_TOKEN_EXCHANGE_FAILED', fn() => $tokens->authorize( 'rejected-reauth-code' ) );
auth_check( $saved_row === $GLOBALS['wpdb']->rows[$id], 'Failed reauthorization destroyed working credentials.' );
$GLOBALS['reply'] = $fixture; $GLOBALS['reply_status'] = 503;
expect_auth_error( 'LAZADA_HTTP_ERROR', fn() => $http->exchange( $config->credentials(), 'fake-code' ) ); $GLOBALS['reply_status'] = 200;
$near_expiry = $encryption->decrypt( $saved_row['credential_envelope'], 'ecomkit|lazada|vn|shop:seller-17|v1' );
$near_expiry['access_expires_at'] = time() + 100;
$GLOBALS['wpdb']->rows[$id]['credential_envelope'] = $encryption->encrypt( $near_expiry, 'ecomkit|lazada|vn|shop:seller-17|v1' );
$before = count( $GLOBALS['requests'] ); $tokens->ensure_usable_access_token( $id );
auth_check( $before + 1 === count( $GLOBALS['requests'] ), 'Automatic threshold refresh not executed.' );
$near_expiry['access_expires_at'] = time() - 1; $near_expiry['refresh_expires_at'] = time() - 1;
$GLOBALS['wpdb']->rows[$id]['credential_envelope'] = $encryption->encrypt( $near_expiry, 'ecomkit|lazada|vn|shop:seller-17|v1' );
$before = count( $GLOBALS['requests'] ); expect_auth_error( 'LAZADA_REFRESH_EXPIRED', fn() => $tokens->ensure_usable_access_token( $id ) );
auth_check( $before === count( $GLOBALS['requests'] ), 'Expired refresh sent to provider.' );
$GLOBALS['wpdb']->rows[$id]['credential_envelope'] = '{bad'; auth_check( 'TOKEN_ERROR' === $tokens->safe_connections()[0]['lifecycle'], 'Malformed connection envelope not safe.' );
$GLOBALS['authorized'] = false;
foreach ( array( 'start', 'callback', 'refresh' ) as $method ) { expect_auth_error( 'DENIED_403', fn() => $oauth->$method() ); }
$GLOBALS['authorized'] = true; expect_auth_error( 'NONCE_DENIED', fn() => $oauth->start() ); expect_auth_error( 'NONCE_DENIED', fn() => $oauth->refresh() );
echo "WP.6J.2 Lazada OAuth/lifecycle: PASS (fake transport only)\n";
