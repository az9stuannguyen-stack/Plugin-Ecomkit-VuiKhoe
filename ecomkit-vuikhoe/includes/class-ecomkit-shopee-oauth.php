<?php
/** Admin OAuth start and public, state-bound callback. */
defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Shopee_OAuth {
	private const AUTH_PATH = '/api/v2/shop/auth_partner';
	private const FLOW_TTL = 600;
	private const COOKIE = 'ecomkit_shopee_oauth';
	public function __construct( private ?Ecomkit_Vuikhoe_Shopee_HTTP_Client $http = null ) { $this->http ??= new Ecomkit_Vuikhoe_Shopee_HTTP_Client(); }
	public function register(): void {
		add_action( 'admin_post_ecomkit_shopee_oauth_start', array( $this, 'start' ) );
		add_action( 'rest_api_init', function (): void { register_rest_route( 'ecomkit/v1', '/shopee/callback', array( 'methods' => 'GET', 'callback' => array( $this, 'callback' ), 'permission_callback' => '__return_true' ) ); } );
	}
	public function start(): void {
		Ecomkit_Vuikhoe_Security::require_management_capability();
		check_admin_referer( 'ecomkit_shopee_oauth_start', 'ecomkit_shopee_nonce' );
		$config_service = new Ecomkit_Vuikhoe_Shopee_Config();
		$ready = $config_service->readiness();
		if ( ! $ready['ready'] ) { $this->admin_redirect( $ready['code'] ); }
		$config = $config_service->get();
		$state = $this->new_flow_nonce();
		$callback = $config_service->callback_url();
		set_transient( 'ecomkit_shopee_flow_' . hash( 'sha256', $state ), array( 'user_id' => get_current_user_id(), 'environment' => $config['environment'], 'partner_id' => $config['partner_id'], 'fingerprint' => $config['fingerprint'], 'callback' => $callback, 'created_at' => time() ), self::FLOW_TTL );
		$this->set_cookie( $state, time() + self::FLOW_TTL );
		$timestamp = time();
		$url = $this->authorization_url( $config, $config_service->partner_key( $config ), $callback, $timestamp );
		add_filter( 'allowed_redirect_hosts', static function ( array $hosts ) use ( $config ): array { $hosts[] = (string) wp_parse_url( Ecomkit_Vuikhoe_Shopee_Environment::host( (string) $config['environment'] ), PHP_URL_HOST ); return array_unique( $hosts ); } );
		wp_safe_redirect( $url ); exit;
	}
	public function new_flow_nonce(): string { return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); }
	public function authorization_url( array $config, string $partner_key, string $callback, int $timestamp ): string {
		return add_query_arg( array( 'partner_id' => $config['partner_id'], 'timestamp' => $timestamp, 'sign' => Ecomkit_Vuikhoe_Shopee_Signer::sign( (string) $config['partner_id'], self::AUTH_PATH, $timestamp, $partner_key ), 'redirect' => $callback ), Ecomkit_Vuikhoe_Shopee_Environment::authorization_url( (string) $config['environment'] ) );
	}

	public function callback( WP_REST_Request $request ): WP_REST_Response {
		$state = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( '' === $state ) { return $this->callback_error( 'SHOPEE_OAUTH_STATE_INVALID', 400 ); }
		$key = 'ecomkit_shopee_flow_' . hash( 'sha256', $state );
		$flow = get_transient( $key );
		delete_transient( $key );
		$this->set_cookie( '', time() - 3600 );
		if ( ! is_array( $flow ) || time() - (int) ( $flow['created_at'] ?? 0 ) > self::FLOW_TTL ) { return $this->callback_error( 'SHOPEE_OAUTH_STATE_EXPIRED', 400 ); }
		$config_service = new Ecomkit_Vuikhoe_Shopee_Config();
		$config = $config_service->get();
		if ( empty( $flow['user_id'] ) || ! hash_equals( (string) ( $flow['fingerprint'] ?? '' ), (string) ( $config['fingerprint'] ?? '' ) ) || (string) $flow['callback'] !== $config_service->callback_url() ) { return $this->callback_error( 'SHOPEE_OAUTH_BINDING_INVALID', 400 ); }
		$code = $request->get_param( 'code' ); $shop_id = $request->get_param( 'shop_id' );
		if ( ! is_string( $code ) || '' === trim( $code ) ) { return $this->callback_error( 'SHOPEE_AUTH_CODE_MISSING', 400 ); }
		$shop_id = is_scalar( $shop_id ) ? (string) $shop_id : '';
		if ( ! Ecomkit_Vuikhoe_Shopee_Config::valid_partner_id( $shop_id ) ) { return $this->callback_error( 'SHOPEE_SHOP_ID_INVALID', 400 ); }
		try {
			$tokens = $this->http->exchange( $config, $config_service->partner_key( $config ), $code, $shop_id );
			( new Ecomkit_Vuikhoe_Marketplace_Connection_Service() )->upsert_shopee( $shop_id, $tokens, (string) $config['fingerprint'] );
			return $this->redirect_response( array( 'shopee_notice' => 'connected', 'shop_id' => $shop_id ) );
		} catch ( Throwable $exception ) {
			return $this->redirect_response( array( 'shopee_error' => sanitize_key( strtolower( $exception->getMessage() ) ), 'request_id' => $exception instanceof Ecomkit_Vuikhoe_Shopee_Provider_Exception ? $exception->request_id : '' ) );
		}
	}
	private function callback_error( string $code, int $status ): WP_REST_Response { return new WP_REST_Response( array( 'code' => $code, 'message' => 'Shopee OAuth callback is invalid.' ), $status ); }
	private function redirect_response( array $args ): WP_REST_Response { return new WP_REST_Response( null, 302, array( 'Location' => add_query_arg( array_merge( array( 'page' => 'ecomkit-vuikhoe-marketplace' ), $args ), admin_url( 'admin.php' ) ) ) ); }
	private function admin_redirect( string $code ): never { wp_safe_redirect( add_query_arg( array( 'page' => 'ecomkit-vuikhoe-marketplace', 'shopee_error' => strtolower( $code ) ), admin_url( 'admin.php' ) ) ); exit; }
	private function set_cookie( string $value, int $expires ): void { setcookie( self::COOKIE, $value, array( 'expires' => $expires, 'path' => (string) wp_parse_url( rest_url( 'ecomkit/v1/shopee/callback' ), PHP_URL_PATH ), 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) ); }
}
