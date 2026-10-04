<?php
/** WP.6B synthetic-only, one-order Payment POST contract. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' ); define( 'ARRAY_A', 'ARRAY_A' ); define( 'ECOMKIT_CREDENTIAL_KEY', base64_encode( str_repeat( 'P', 32 ) ) );
function wp_json_encode( mixed $v, int $f = 0 ): string|false { return json_encode( $v, $f ); }
function get_option( string $k, mixed $d = false ): mixed { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( string $k, mixed $v, bool $a = false ): bool { $GLOBALS['opts'][ $k ] = $v; return true; }
function current_time( string $t, bool $g = false ): string { return gmdate( 'Y-m-d H:i:s' ); }
function rest_url( string $p ): string { return 'https://example.test/wp-json/' . $p; }
function wp_http_validate_url( string $u ): string|false { return filter_var( $u, FILTER_VALIDATE_URL ); }
function wp_parse_url( string $u, int $c = -1 ): mixed { return parse_url( $u, $c ); }
function add_query_arg( array $a, string $u ): string { return $u . '?' . http_build_query( $a ); }
function sanitize_text_field( string $v ): string { return trim( $v ); }
function sanitize_key( string $v ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $v ) ); }
final class PaymentWpError {}
function is_wp_error( mixed $v ): bool { return $v instanceof PaymentWpError; }
function wp_remote_post( string $url, array $args ): mixed { $GLOBALS['posts'][] = compact( 'url', 'args' ); return array_shift( $GLOBALS['responses'] ); }
function wp_remote_get( string $url, array $args ): mixed { $GLOBALS['gets'][] = compact( 'url', 'args' ); throw new RuntimeException( 'Unexpected GET fallback.' ); }
function wp_remote_retrieve_response_code( array $r ): int { return $r['status']; }
function wp_remote_retrieve_body( array $r ): string { return $r['body']; }
function payment_check( bool $v, string $m ): void { if ( ! $v ) { throw new RuntimeException( $m ); } }
final class PaymentWpdb {
	public string $prefix = 'pay_'; public array $order = array(); public array $connection = array(); public array $updates = array();
	public function prepare( string $q, mixed ...$a ): string { return json_encode( array( 'q' => $q, 'a' => $a ) ); }
	public function get_row( string $q, string $mode ): ?array { $p = json_decode( $q, true ); return str_contains( $p['q'], 'ecomkit_orders' ) ? ( (int) ( $p['a'][0] ?? 0 ) === (int) ( $this->order['id'] ?? 0 ) && ( ! isset( $p['a'][1] ) || (int) $p['a'][1] === (int) ( $this->order['batch_id'] ?? 0 ) ) ? $this->order : null ) : $this->connection; }
	public function get_results( string $q, string $mode ): array { if ( str_starts_with( $q, 'SHOW FULL COLUMNS' ) ) { return array_map( static fn( string $name ): array => array( 'Field' => $name, 'Null' => 'YES' ), array( 'payment_raw_data', 'payment_normalized_data', 'payment_fetched_at', 'payment_request_id' ) ); } return array(); }
	public function get_var( string $q ): int { return 1; }
	public function update( string $table, array $data, array $where ): int { if ( str_contains( $table, 'ecomkit_marketplace_connections' ) && (int) $where['id'] === (int) $this->connection['id'] ) { $this->connection = array_merge( $this->connection, $data ); return 1; } if ( ! str_contains( $table, 'ecomkit_orders' ) || (int) $where['id'] !== (int) $this->order['id'] ) { throw new RuntimeException( 'Unexpected update.' ); } $this->updates[] = $data; $this->order = array_merge( $this->order, $data ); return 1; }
}
$GLOBALS['opts'] = array(); $GLOBALS['posts'] = array(); $GLOBALS['gets'] = array(); $GLOBALS['responses'] = array(); $GLOBALS['wpdb'] = new PaymentWpdb();
foreach ( array( 'class-ecomkit-db.php', 'class-ecomkit-credential-key-resolver.php', 'class-ecomkit-credential-encryption.php', 'class-ecomkit-credential-mutation-lock.php', 'class-ecomkit-shopee-environment.php', 'class-ecomkit-shopee-signer.php', 'class-ecomkit-shopee-config.php', 'class-ecomkit-shopee-http-client.php', 'class-ecomkit-shopee-token-service.php', 'class-ecomkit-shopee-payment-normalizer.php', 'class-ecomkit-shopee-payment-service.php' ) as $file ) { require __DIR__ . '/../ecomkit-vuikhoe/includes/' . $file; }
$config = ( new Ecomkit_Vuikhoe_Shopee_Config() )->save( 'sandbox', '123', 'synthetic-partner-key' );
$crypto = new Ecomkit_Vuikhoe_Credential_Encryption();
$credential = array( 'access_token' => 'ACCESS_VALID', 'refresh_token' => 'REFRESH_VALID', 'access_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) );
$db = $GLOBALS['wpdb'];
$db->connection = array( 'id' => 9, 'platform' => 'SHOPEE', 'external_shop_id' => '456', 'status' => 'ACTIVE', 'credential_source' => 'OAUTH', 'credential_envelope' => $crypto->encrypt( $credential, 'ecomkit|shopee|shop:456' ), 'metadata' => json_encode( array( 'refresh_ownership' => 'ECOMKIT', 'credential_lifecycle' => 'READY', 'provider_config_fingerprint' => $config['fingerprint'] ) ) );
$db->order = array( 'id' => 7, 'batch_id' => 8, 'platform' => 'SHOPEE', 'matching_status' => 'MATCHED', 'marketplace_order_id' => 'TEST-SHP-001', 'eshop_order_code' => 'ĐH-TEST-001', 'connection_id' => 9, 'provider_raw_data' => 'order-detail-raw', 'provider_normalized_data' => 'order-detail-normalized', 'canonical_data' => 'canonical-v2' );
function payment_response( array $v, int $status = 200 ): array { return array( 'status' => $status, 'body' => json_encode( $v ) ); }
function payment_success( array $income ): array { return payment_response( array( 'error' => '', 'request_id' => 'req-safe', 'response' => array( 'order_sn' => 'TEST-SHP-001', 'order_income' => $income, 'buyer_user_name' => 'DO_NOT_DISPLAY' ) ) ); }
$service = new Ecomkit_Vuikhoe_Shopee_Payment_Service();
$GLOBALS['responses'][] = payment_success( array( 'escrow_amount' => 90000, 'escrow_amount_after_adjustment' => 88000, 'commission_fee' => 5000, 'service_fee' => 2000, 'seller_transaction_fee' => 1000 ) );
$result = $service->inspect_matched_order( 8, 7 ); $post = $GLOBALS['posts'][0]; parse_str( (string) parse_url( $post['url'], PHP_URL_QUERY ), $query );
payment_check( str_contains( $post['url'], '/api/v2/payment/get_escrow_detail?' ) && array( 'order_sn' => 'TEST-SHP-001' ) === json_decode( $post['args']['body'], true ) && 'application/json' === $post['args']['headers']['Content-Type'] && 15 === $post['args']['timeout'] && true === $post['args']['sslverify'] && 0 === $post['args']['redirection'], 'POST body/HTTP contract or marketplace identity failed.' );
payment_check( isset( $query['partner_id'], $query['timestamp'], $query['access_token'], $query['shop_id'], $query['sign'] ) && ! isset( $query['order_sn'] ) && 'ACCESS_VALID' === $query['access_token'] && Ecomkit_Vuikhoe_Shopee_Signer::sign_shop( '123', Ecomkit_Vuikhoe_Shopee_Payment_Service::PATH, (int) $query['timestamp'], 'ACCESS_VALID', '456', 'synthetic-partner-key' ) === $query['sign'], 'Shop signer or valid-token path failed.' );
payment_check( 90000 === $result['normalized']['escrowAmount'] && 88000 === $result['normalized']['escrowAmountAfterAdjustment'] && 5000 === $result['normalized']['commissionFee'] && 2000 === $result['normalized']['serviceFee'] && 1000 === $result['normalized']['sellerTransactionFee'], 'Financial sources collapsed or changed.' );
payment_check( null === $result['normalized']['affiliateCommissionFee'] && 'order-detail-raw' === $db->order['provider_raw_data'] && 'order-detail-normalized' === $db->order['provider_normalized_data'] && 'canonical-v2' === $db->order['canonical_data'] && 'ĐH-TEST-001' === $db->order['eshop_order_code'], 'Unrelated business evidence mutated.' );
payment_check( ! str_contains( json_encode( $db->order['payment_raw_data'] ), 'DO_NOT_DISPLAY' ) && ! str_contains( json_encode( $result ), 'ACCESS_VALID' ), 'PII or token leaked.' );
$GLOBALS['responses'][] = payment_success( array() ); $missing = $service->inspect_matched_order( 8, 7 ); payment_check( null === $missing['normalized']['commissionFee'], 'Absent fee became zero.' );
$GLOBALS['responses'][] = payment_success( array( 'commission_fee' => 0 ) ); $zero = $service->inspect_matched_order( 8, 7 ); payment_check( 0 === $zero['normalized']['commissionFee'], 'Explicit zero became NULL.' );
$before = count( $db->updates );
$cases = array(
	array( payment_response( array( 'error' => '', 'response' => array( 'order_sn' => 'TEST-SHP-002', 'order_income' => array() ) ) ), 'SHOPEE_PAYMENT_IDENTITY_MISMATCH' ),
	array( payment_response( array( 'error' => '', 'response' => array( 'order_sn' => 'TEST-SHP-001' ) ) ), 'SHOPEE_PAYMENT_INCOME_UNAVAILABLE' ),
	array( payment_response( array( 'error' => 'error_api_permission', 'request_id' => 'permission' ) ), 'MANUAL_BLOCKED_EXTERNAL_PERMISSION' ),
	array( payment_response( array( 'error' => 'error_param', 'message' => 'HTTP method not allowed', 'request_id' => 'method' ), 405 ), 'SHOPEE_PAYMENT_METHOD_CONTRACT_REJECTED' ),
	array( new PaymentWpError(), 'SHOPEE_PAYMENT_NETWORK_ERROR' ),
);
foreach ( $cases as [ $provider, $expected ] ) { $GLOBALS['responses'][] = $provider; try { $service->inspect_matched_order( 8, 7 ); throw new RuntimeException( 'Expected failure.' ); } catch ( Ecomkit_Vuikhoe_Shopee_Payment_Exception $e ) { payment_check( $expected === $e->getMessage(), 'Wrong provider failure class.' ); } }
payment_check( $before === count( $db->updates ) && array() === $GLOBALS['gets'], 'Failure persisted snapshot or triggered GET fallback.' );
$db->order['platform'] = 'LAZADA'; $calls = count( $GLOBALS['posts'] ); try { $service->inspect_matched_order( 8, 7 ); throw new RuntimeException( 'Lazada accepted.' ); } catch ( Ecomkit_Vuikhoe_Shopee_Payment_Exception $e ) { payment_check( 'SHOPEE_PAYMENT_ORDER_NOT_ELIGIBLE' === $e->getMessage() && $calls === count( $GLOBALS['posts'] ), 'Ineligible order called provider.' ); }
$db->order['platform'] = 'SHOPEE';
$credential['access_expires_at'] = gmdate( 'Y-m-d H:i:s', time() + 10 ); $db->connection['credential_envelope'] = $crypto->encrypt( $credential, 'ecomkit|shopee|shop:456' );
$GLOBALS['responses'][] = payment_response( array( 'error' => '', 'access_token' => 'ACCESS_NEW', 'refresh_token' => 'REFRESH_NEW', 'expire_in' => 3600 ) );
$GLOBALS['responses'][] = payment_success( array( 'commission_fee' => 0 ) ); $before = count( $GLOBALS['posts'] ); $service->inspect_matched_order( 8, 7 );
payment_check( 2 === count( $GLOBALS['posts'] ) - $before && str_contains( $GLOBALS['posts'][ $before + 1 ]['url'], 'access_token=ACCESS_NEW' ), 'WP.4A refresh token was not reused before one Payment call.' );
echo "WP.6B Shopee Payment checks passed.\n";
