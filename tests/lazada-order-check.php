<?php
/** Synthetic only. Reuse OAuth fake WordPress/DB/transport harness and real lifecycle. */
declare(strict_types=1);
require __DIR__ . '/lazada-oauth-check.php';
$GLOBALS['reply'] = $fixture; $GLOBALS['reply_status'] = 200; $tokens->authorize( 'order-test-restore' );
$GLOBALS['seller_calls'] = array(); $GLOBALS['seller_status'] = 200;
function synthetic_order( int $id ): array {
	return array( 'order_id' => (string) $id, 'created_at' => '2026-10-01 10:00:00 +0700', 'updated_at' => '2026-10-02 10:00:00 +0700', 'statuses' => array( 'delivered', 'canceled' ), 'price' => '312000.01', 'voucher' => '50000.00', 'shipping_fee' => '2700', 'address_shipping' => array( 'first_name' => 'Synthetic Example', 'address1' => '***', 'address3' => 'Synthetic Region', 'address5' => null ) );
}
$GLOBALS['seller_reply'] = static function( string $path, array $p ): array {
	$offset = (int) $p['offset']; $size = min( 100, 237 - $offset ); $orders = array(); for ( $i = 0; $i < $size; ++$i ) { $orders[] = synthetic_order( 100000 + $offset + $i ); }
	return array( 'code' => '0', 'data' => array( 'orders' => $orders, 'count' => count( $orders ), 'countTotal' => 237 ), 'request_id' => 'synthetic-request' );
};
$seller_transport = static function( string $url, array $options ) use ( $config ): mixed {
	parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $p ); $path = substr( (string) parse_url( $url, PHP_URL_PATH ), strlen( '/rest' ) );
	$GLOBALS['seller_calls'][] = array( 'path' => $path, 'params' => $p );
	auth_check( parse_url( $url, PHP_URL_SCHEME ) === 'https' && parse_url( $url, PHP_URL_HOST ) === 'api.lazada.vn' && in_array( $path, array( '/orders/get', '/order/get', '/order/items/get' ), true ), 'Wrong seller base/path.' );
	auth_check( 'GET' === $options['method'] && ! isset( $options['body'] ) && ! isset( $options['headers'] ) && ! isset( $p['app_secret'] ), 'Read request must be GET query only.' );
	auth_check( $options['sslverify'] && 0 === $options['redirection'] && 20 === $options['timeout'] && 2097152 === $options['limit_response_size'], 'Seller transport safety missing.' );
	auth_check( isset( $p['app_key'], $p['access_token'], $p['timestamp'], $p['sign_method'], $p['sign'] ) && 'sha256' === $p['sign_method'] && $p['sign'] === Ecomkit_Vuikhoe_Lazada_Signer::sign( $path, $p, $config->credentials()['app_secret'] ), 'Order signature wrong.' );
	auth_check( (string) parse_url( $url, PHP_URL_QUERY ) === http_build_query( $p, '', '&', PHP_QUERY_RFC3986 ), 'Sent encoding differs from signed values.' );
	if ( '/orders/get' === $path ) { auth_check( isset( $p['created_after'], $p['status'], $p['limit'], $p['offset'] ) && 'all' === $p['status'] && (int) $p['limit'] <= 100 && (int) $p['offset'] <= 5000, 'List GET parameters lost.' ); }
	else { auth_check( is_string( $p['order_id'] ?? null ), 'Direct GET string ID lost.' ); }
	$r = is_callable( $GLOBALS['seller_reply'] ) ? ( $GLOBALS['seller_reply'] )( $path, $p ) : $GLOBALS['seller_reply'];
	if ( $r instanceof AuthNetworkError ) { return $r; }
	return array( 'status' => $GLOBALS['seller_status'], 'body' => is_string( $r ) ? $r : json_encode( $r ) );
};
$client = new Ecomkit_Vuikhoe_Lazada_Order_Client( $id, $tokens, $config, $seller_transport );
auth_check( ( new ReflectionProperty( Ecomkit_Vuikhoe_Lazada_Order_Client::class, 'transport' ) )->getValue( new Ecomkit_Vuikhoe_Lazada_Order_Client( $id, $tokens, $config ) ) === 'wp_remote_get', 'Default HTTP function is not GET.' );
$query = new Ecomkit_Vuikhoe_Lazada_Order_Query( new DateTimeImmutable( '2026-09-24T00:00:00+07:00' ), 'all' );
auth_check( '2026-09-24T00:00:00+07:00' === $query->parameters()['created_after'], 'Timezone serialization changed.' );
auth_check( '2026-09-23T17:00:00+00:00' === Ecomkit_Vuikhoe_Lazada_Order_Query::date( $query->created_after->setTimezone( new DateTimeZone( 'UTC' ) ) ), 'UTC serialization wrong.' );
$all = $client->paginate( $query );
auth_check( 237 === count( $all['orders'] ) && 3 === $all['pageCount'] && $all['paginationComplete'] && array( '0', '100', '200' ) === array_column( array_column( $GLOBALS['seller_calls'], 'params' ), 'offset' ), '100/100/37 pagination failed.' );
auth_check( array( 'delivered', 'canceled' ) === $all['orders'][0]['rawStatuses'] && '312000.01' === $all['orders'][0]['price'] && '50000.00' === $all['orders'][0]['voucher'] && '2700' === $all['orders'][0]['shippingFee'], 'DTO status or price changed.' );
auth_check( $id === $all['orders'][0]['connectionId'] && ! isset( $all['orders'][0]['canonical_data'] ), 'Shop context lost or canonical mapped.' );
$GLOBALS['seller_reply'] = array( 'code' => '0', 'data' => array( 'orders' => array(), 'countTotal' => 0 ) ); $before = count( $GLOBALS['seller_calls'] );
auth_check( 0 === count( $client->paginate( $query )['orders'] ) && $before + 1 === count( $GLOBALS['seller_calls'] ), 'Empty page not stopped.' );
$hundred = array(); for ( $i=0; $i<100; ++$i ) { $hundred[] = synthetic_order( 200000+$i ); }
$GLOBALS['seller_reply'] = array( 'code'=>'0', 'data'=>array( 'orders'=>$hundred ) );
expect_auth_error( 'LAZADA_ORDER_PAGINATION_ERROR', fn()=> $client->paginate( $query ) );
$before = count( $GLOBALS['seller_calls'] ); expect_auth_error( 'LAZADA_ORDER_WINDOW_TOO_LARGE', fn()=> $client->paginate( $query->at_offset(5000) ) );
auth_check( $before+1 === count( $GLOBALS['seller_calls'] ) && '5000' === end($GLOBALS['seller_calls'])['params']['offset'], 'Requested beyond max offset.' );
expect_auth_error( 'LAZADA_ORDER_PAGINATION_ERROR', fn()=> $client->paginate( $query, 1 ) );
$GLOBALS['seller_reply'] = array( 'code'=>'0', 'data'=>array( 'orders'=>array(), 'countTotal'=>10 ) );
expect_auth_error( 'LAZADA_ORDER_PAGINATION_ERROR', fn()=> $client->paginate($query) );
$GLOBALS['seller_reply'] = array( 'code'=>'0', 'data'=>array( 'orders'=>array(synthetic_order(1)), 'countTotal'=>'bad' ) );
expect_auth_error( 'LAZADA_ORDER_PAGINATION_ERROR', fn()=> $client->get_orders($query) );
$single = synthetic_order(123); unset($single['address_shipping']);
$GLOBALS['seller_reply'] = array('code'=>'0','data'=>$single); $order = $client->get_order('123');
auth_check( null === $order['addressShipping'] && '123' === $order['providerOrderId'] && '/order/get' === end($GLOBALS['seller_calls'])['path'], 'Missing PII rejected or wrong GetOrder route.' );
$GLOBALS['seller_reply'] = '{"code":"0","data":{"order_id":123,"price":312000.01234567890123456789,"voucher":0,"shipping_fee":2700}}';
auth_check( '312000.01234567890123456789' === $client->get_order('123')['price'], 'JSON decimal was converted to float.' );
$items = array(); foreach( array('delivered','canceled') as $i=>$status ) { $items[] = array('order_id'=>'123','order_item_id'=>(string)(500+$i),'sku'=>'same-sku','name'=>'Synthetic item','status'=>$status,'paid_price'=>'12.50','item_price'=>'15'); }
$GLOBALS['seller_reply'] = array('code'=>'0','data'=>$items); $actual = $client->get_order_items('123');
auth_check( 2 === count($actual) && '500' === $actual[0]['orderItemId'] && '501' === $actual[1]['orderItemId'] && '/order/items/get' === end($GLOBALS['seller_calls'])['path'], 'Same SKU items collapsed.' );
$GLOBALS['seller_reply'] = array('code'=>'0','data'=>array()); expect_auth_error('LAZADA_ORDER_NOT_FOUND', fn()=> $client->get_order('123'));
foreach( array('{invalid', '{0:0,"code":0,"data":{"order_id":123}}', array('code'=>'0'), array('code'=>'0','data'=>'wrong')) as $r ) { $GLOBALS['seller_reply']=$r; expect_auth_error('LAZADA_ORDER_INVALID_RESPONSE', fn()=> $client->get_order('123')); }
$GLOBALS['seller_reply'] = new AuthNetworkError(); expect_auth_error('LAZADA_ORDER_NETWORK_ERROR', fn()=> $client->get_order('123'));
$GLOBALS['seller_status']=503; $GLOBALS['seller_reply']=array('code'=>'15','message'=>'error'); expect_auth_error('LAZADA_ORDER_HTTP_ERROR', fn()=> $client->get_order('123')); $GLOBALS['seller_status']=200;
$GLOBALS['seller_reply']=array('code'=>'15','message'=>'fake-access-one test-app-secret access_token=leak sign=leak https://unsafe.example/?refresh_token=leak','request_id'=>'synthetic-safe-id');
try { $client->get_order('123'); throw new RuntimeException('Expected provider failure'); } catch(Ecomkit_Vuikhoe_Lazada_Provider_Exception $e) { auth_check('LAZADA_ORDER_PROVIDER_ERROR'===$e->getMessage() && !str_contains(json_encode($e->diagnostic),'fake-access-one') && !str_contains(json_encode($e->diagnostic),'test-app-secret') && !str_contains(json_encode($e->diagnostic),'unsafe.example'), 'Provider diagnostics leaked credentials.'); }
// Real lifecycle + fake refresh: seller request must use rotated access token.
$GLOBALS['reply']=$fixture; $tokens->authorize('restore-near-expiry'); $row=$GLOBALS['wpdb']->rows[$id];
$near=$encryption->decrypt($row['credential_envelope'],'ecomkit|lazada|vn|shop:seller-17|v1'); $near['access_expires_at']=time()+10;
$GLOBALS['wpdb']->rows[$id]['credential_envelope']=$encryption->encrypt($near,'ecomkit|lazada|vn|shop:seller-17|v1');
$GLOBALS['reply']=array_merge($fixture,array('access_token'=>'fake-access-new-for-order','refresh_token'=>'fake-refresh-new-for-order'));
$GLOBALS['seller_reply']=array('code'=>'0','data'=>$single); $client->get_order('123');
auth_check('fake-access-new-for-order'===end($GLOBALS['seller_calls'])['params']['access_token'], 'Seller request used old access token.');
$meta=json_decode($GLOBALS['wpdb']->rows[$id]['metadata'],true); $meta['credential_lifecycle']='REAUTH_REQUIRED'; $GLOBALS['wpdb']->rows[$id]['metadata']=json_encode($meta);
$before=count($GLOBALS['seller_calls']); expect_auth_error('LAZADA_ORDER_AUTH_REQUIRED',fn()=> $client->get_order('123')); auth_check($before===count($GLOBALS['seller_calls']), 'Reauth-required still called seller API.');
$GLOBALS['reply']=$fixture; $tokens->authorize('restore-validation-tests');
$before=count($GLOBALS['seller_calls']);
foreach( array( array(0,0), array(101,0), array(100,-1), array(100,5001) ) as [$limit,$offset] ) { try { new Ecomkit_Vuikhoe_Lazada_Order_Query($query->created_after,null,$limit,$offset); throw new RuntimeException('Invalid query accepted'); } catch(InvalidArgumentException $e) { auth_check('LAZADA_ORDER_QUERY_INVALID'===$e->getMessage(),'Wrong query error'); } }
expect_auth_error('LAZADA_ORDER_INVALID_RESPONSE', fn()=> $client->get_order('123 OR 1=1'));
auth_check($before===count($GLOBALS['seller_calls']), 'Invalid query/ID sent to provider.');
$GLOBALS['seller_reply']=array('code'=>'0','data'=>array('orders'=>$hundred,'count'=>99));
expect_auth_error('LAZADA_ORDER_PAGINATION_ERROR', fn()=> $client->get_orders($query));
$oversize=$hundred; $oversize[]=synthetic_order(999999); $GLOBALS['seller_reply']=array('code'=>'0','data'=>array('orders'=>$oversize));
expect_auth_error('LAZADA_ORDER_PAGINATION_ERROR', fn()=> $client->get_orders($query));
$GLOBALS['seller_reply']=array('code'=>'0','data'=>array($items[0],$items[0])); expect_auth_error('LAZADA_ORDER_INVALID_RESPONSE',fn()=> $client->get_order_items('123'));
$bad_item=$items[0]; $bad_item['order_id']='999'; $GLOBALS['seller_reply']=array('code'=>'0','data'=>array($bad_item)); expect_auth_error('LAZADA_ORDER_INVALID_RESPONSE',fn()=> $client->get_order_items('123'));
$GLOBALS['seller_reply']='{"code":"0","data":{"order_id":987654321098765432109876543210,"price":0,"statuses":[]}}';
$large=$client->get_order('987654321098765432109876543210'); auth_check('987654321098765432109876543210'===$large['providerOrderId'] && '0'===$large['price'],'Large ID or explicit zero corrupted.');
// The requested sample remains a string in synthetic GET fixtures; no live request.
$snapshot = array( $GLOBALS['wpdb']->rows, $GLOBALS['options'], $GLOBALS['transients'] );
$sample_id = '532935709720247';
$GLOBALS['seller_reply'] = static function( string $path, array $p ) use ( $sample_id ): array {
	$order = synthetic_order( 123 ); $order['order_id'] = $sample_id;
	if ( '/orders/get' === $path ) { return array( 'code' => '0', 'data' => array( 'orders' => array( $order ), 'count' => 1, 'countTotal' => 1 ) ); }
	auth_check( $p['order_id'] === $sample_id, 'Requested sample ID changed.' );
	return array( 'code' => '0', 'data' => '/order/get' === $path ? $order : array( array( 'order_id' => $sample_id, 'order_item_id' => '501', 'status' => 'delivered' ), array( 'order_id' => $sample_id, 'order_item_id' => '502', 'status' => 'delivered' ) ) );
};
$client->get_order( $sample_id ); $evidence = array( $client->last_evidence() );
auth_check( count( $client->get_order_items( $sample_id ) ) === 2, 'GET collapsed distinct items.' ); $evidence[] = $client->last_evidence();
$client->get_orders( new Ecomkit_Vuikhoe_Lazada_Order_Query( new DateTimeImmutable( '2026-10-03T00:00:00+07:00' ), 'all' ) ); $evidence[] = $client->last_evidence();
foreach ( $evidence as $entry ) { auth_check( $entry['diagnostic']['http_method'] === 'GET' && !str_contains( json_encode( $entry ), 'access_token' ) && !str_contains( json_encode( $entry ), 'fake-access' ) && !str_contains( json_encode( $entry ), 'test-app-secret' ) && !str_contains( json_encode( $entry ), 'https:' ), 'Safe GET evidence leaked URL/credentials.' ); }
$GLOBALS['seller_reply'] = static function( string $path, array $p ): array { return array( 'code' => '15', 'message' => 'https://api.lazada.vn/rest' . $path . '?' . http_build_query( $p ) . ' test-app-secret refresh_token=hidden', 'request_id' => 'safe-request' ); };
try { $client->get_order( $sample_id ); throw new RuntimeException( 'Expected provider error' ); }
catch ( Ecomkit_Vuikhoe_Lazada_Provider_Exception $e ) { auth_check( $e->getMessage() === 'LAZADA_ORDER_PROVIDER_ERROR' && $e->diagnostic['provider_code'] === '15' && $e->diagnostic['request_id'] === 'safe-request' && !str_contains( json_encode( $e->diagnostic ), end( $GLOBALS['seller_calls'] )['params']['sign'] ) && !str_contains( json_encode( $e->diagnostic ), 'fake-access' ) && !str_contains( json_encode( $e->diagnostic ), 'https:' ) && !str_contains( json_encode( $e->diagnostic ), 'test-app-secret' ), 'Provider URL echo leaked credentials.' ); }
$GLOBALS['seller_reply'] = static function(): never { throw new RuntimeException( 'credential-bearing transport exception must not escape' ); };
expect_auth_error( 'LAZADA_ORDER_NETWORK_ERROR', fn() => $client->get_order( $sample_id ) );
auth_check( $snapshot === array( $GLOBALS['wpdb']->rows, $GLOBALS['options'], $GLOBALS['transients'] ), 'GET reads persisted business data.' );
echo "WP.6J.3B.3 Lazada Order GET transport: PASS (zero real provider calls)\n";
