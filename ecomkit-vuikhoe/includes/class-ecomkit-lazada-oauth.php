<?php
defined( 'ABSPATH' ) || exit;
/** Logged-in admin callback with state bound to initiating user/session/config. */
final class Ecomkit_Vuikhoe_Lazada_OAuth {
	public const FLOW_TTL = 900;
	public function __construct( private ?Ecomkit_Vuikhoe_Lazada_Config $config = null, private ?Ecomkit_Vuikhoe_Lazada_Token_Service $tokens = null, private ?Ecomkit_Vuikhoe_Lazada_Lock $lock = null ) {
		$this->config ??= new Ecomkit_Vuikhoe_Lazada_Config(); $this->tokens ??= new Ecomkit_Vuikhoe_Lazada_Token_Service(); $this->lock ??= new Ecomkit_Vuikhoe_Lazada_Lock();
	}
	public function register(): void {
		add_action( 'admin_post_ecomkit_lazada_oauth_start', array( $this, 'start' ) );
		add_action( 'admin_post_ecomkit_lazada_oauth_callback', array( $this, 'callback' ) );
		add_action( 'admin_post_nopriv_ecomkit_lazada_oauth_callback', array( $this, 'callback' ) );
		add_action( 'admin_post_ecomkit_lazada_refresh', array( $this, 'refresh' ) );
	}
	public function authorization_url( string $app_key, string $state ): string {
		return Ecomkit_Vuikhoe_Lazada_Config::AUTHORIZATION_URL . '?' . http_build_query( array( 'response_type' => 'code', 'force_auth' => 'true', 'client_id' => $app_key, 'redirect_uri' => $this->config->callback_url(), 'state' => $state ), '', '&', PHP_QUERY_RFC3986 );
	}
	public function create_flow(): string {
		Ecomkit_Vuikhoe_Security::require_management_capability();
		$this->config->credentials();
		$callback = $this->config->callback_url();
		if ( 'https' !== wp_parse_url( $callback, PHP_URL_SCHEME ) || ! wp_http_validate_url( $callback ) || '' === wp_get_session_token() ) { throw new RuntimeException( 'LAZADA_CALLBACK_CONFIG_INVALID' ); }
		$state = bin2hex( random_bytes( 32 ) );
		$flow = array( 'platform' => 'LAZADA', 'user_id' => get_current_user_id(), 'session_hash' => hash( 'sha256', wp_get_session_token() ), 'fingerprint' => $this->config->fingerprint(), 'callback' => $callback, 'issued_at' => time(), 'expires_at' => time() + self::FLOW_TTL );
		if ( ! set_transient( 'ecomkit_lazada_flow_' . hash( 'sha256', $state ), $flow, self::FLOW_TTL ) ) { throw new RuntimeException( 'LAZADA_STATE_PERSIST_FAILED' ); }
		return $state;
	}
	/** Atomic consume is serialized before token exchange. No authorization code stored. */
	public function consume_flow( mixed $state ): void {
		if ( ! is_string( $state ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $state ) ) { throw new RuntimeException( 'LAZADA_OAUTH_STATE_INVALID' ); }
		$this->lock->synchronized( 'state:' . $state, function () use ( $state ): void {
			$key = 'ecomkit_lazada_flow_' . hash( 'sha256', $state ); $flow = get_transient( $key );
			if ( ! is_array( $flow ) ) { throw new RuntimeException( 'LAZADA_OAUTH_STATE_INVALID' ); }
			if ( ! delete_transient( $key ) ) { throw new RuntimeException( 'LAZADA_OAUTH_STATE_INVALID' ); }
			if ( ! current_user_can( Ecomkit_Vuikhoe_Security::MANAGEMENT_CAPABILITY ) || 'LAZADA' !== ( $flow['platform'] ?? '' ) || (int) ( $flow['user_id'] ?? 0 ) !== get_current_user_id() || time() >= (int) ( $flow['expires_at'] ?? 0 ) || (int) ( $flow['issued_at'] ?? PHP_INT_MAX ) > time() || ! hash_equals( (string) ( $flow['session_hash'] ?? '' ), hash( 'sha256', wp_get_session_token() ) ) || ! hash_equals( (string) ( $flow['fingerprint'] ?? '' ), $this->config->fingerprint() ) || $this->config->callback_url() !== ( $flow['callback'] ?? '' ) ) { throw new RuntimeException( 'LAZADA_OAUTH_STATE_INVALID' ); }
		} );
	}
	public function complete( mixed $state, mixed $code ): int {
		Ecomkit_Vuikhoe_Security::require_management_capability();
		$this->consume_flow( $state );
		if ( ! is_string( $code ) || '' === trim( $code ) || strlen( $code ) > 4096 || preg_match( '/[\x00-\x20\x7F]/', $code ) ) { throw new RuntimeException( 'LAZADA_OAUTH_CODE_MISSING' ); }
		return $this->tokens->authorize( $code );
	}
	public function start(): void {
		Ecomkit_Vuikhoe_Security::require_management_capability(); check_admin_referer( 'ecomkit_lazada_oauth_start', 'ecomkit_lazada_nonce' );
		try { $app = $this->config->credentials(); $state = $this->create_flow(); $url = $this->authorization_url( $app['app_key'], $state ); }
		catch ( Throwable $e ) { $this->finish( 'failed', $e, 'OAUTH_START' ); }
		add_filter( 'allowed_redirect_hosts', static function ( array $hosts ): array { $hosts[] = 'auth.lazada.com'; return array_unique( $hosts ); } );
		wp_safe_redirect( $url ); exit;
	}
	public function callback(): void {
		Ecomkit_Vuikhoe_Security::require_management_capability();
		nocache_headers(); header( 'Referrer-Policy: no-referrer' );
		try { $this->complete( wp_unslash( $_GET['state'] ?? null ), wp_unslash( $_GET['code'] ?? null ) ); }
		catch ( Throwable $e ) { $this->finish( 'failed', $e, 'OAUTH_CALLBACK' ); }
		$this->finish( 'connected' );
	}
	public function refresh(): void {
		Ecomkit_Vuikhoe_Security::require_management_capability(); check_admin_referer( 'ecomkit_lazada_refresh', 'ecomkit_lazada_nonce' );
		try { $this->tokens->ensure_usable_access_token( absint( wp_unslash( $_POST['connection_id'] ?? 0 ) ), true ); }
		catch ( Throwable $e ) { $this->finish( 'failed', $e, 'TOKEN_REFRESH' ); }
		$this->finish( 'refreshed' );
	}
	private function finish( string $notice, ?Throwable $error = null, string $stage = '' ): never {
		$args = array( 'page' => 'ecomkit-vuikhoe-marketplace', 'lazada_notice' => $notice );
		if ( $error ) {
			$allowed = array( 'LAZADA_OAUTH_STATE_INVALID', 'LAZADA_OAUTH_CODE_MISSING', 'LAZADA_TOKEN_EXCHANGE_FAILED', 'LAZADA_INVALID_APP_CREDENTIALS', 'LAZADA_REFRESH_FAILED', 'LAZADA_REFRESH_EXPIRED', 'LAZADA_COUNTRY_MISMATCH', 'LAZADA_INVALID_TOKEN_RESPONSE', 'LAZADA_NETWORK_ERROR', 'LAZADA_HTTP_ERROR', 'LAZADA_CONFIG_UNAVAILABLE', 'LAZADA_CONFIG_INVALID', 'LAZADA_CALLBACK_CONFIG_INVALID', 'LAZADA_CONFIG_CHANGED', 'LAZADA_CONNECTION_PERSIST_FAILED', 'LAZADA_REAUTH_REQUIRED', 'LAZADA_LOCK_UNAVAILABLE', 'LAZADA_STATE_PERSIST_FAILED' );
			$classification = in_array( $error->getMessage(), $allowed, true ) ? $error->getMessage() : 'LAZADA_TOKEN_EXCHANGE_FAILED';
			$diagnostic = array_merge( array( 'stage' => $stage, 'classification' => $classification, 'callback' => $this->config->callback_url() ), $error instanceof Ecomkit_Vuikhoe_Lazada_Provider_Exception ? $error->diagnostic : array() );
			$reference = bin2hex( random_bytes( 16 ) );
			set_transient( 'ecomkit_lazada_diag_' . $reference, array( 'user_id' => get_current_user_id(), 'diagnostic' => $diagnostic ), self::FLOW_TTL );
			$args['lazada_diag'] = $reference;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) ); exit;
	}
}
