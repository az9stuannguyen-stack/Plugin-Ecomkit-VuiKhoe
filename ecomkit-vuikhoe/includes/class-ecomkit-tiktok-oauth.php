<?php
defined( 'ABSPATH' ) || exit;
/** Admin/session-bound ROW seller authorization; codes/tokens never enter notices. */
final class Ecomkit_Vuikhoe_Tiktok_OAuth {
	public function __construct( private ?Ecomkit_Vuikhoe_Tiktok_Config $config = null, private ?Ecomkit_Vuikhoe_Tiktok_Token_Service $tokens = null, private ?Ecomkit_Vuikhoe_Tiktok_Lock $lock = null ) { $this->config ??= new Ecomkit_Vuikhoe_Tiktok_Config(); $this->tokens ??= new Ecomkit_Vuikhoe_Tiktok_Token_Service(); $this->lock ??= new Ecomkit_Vuikhoe_Tiktok_Lock(); }
	public function register(): void {
		foreach ( array( 'start', 'callback', 'refresh', 'select' ) as $action ) { add_action( 'admin_post_ecomkit_tiktok_oauth_' . $action, array( $this, $action ) ); }
		add_action( 'admin_post_nopriv_ecomkit_tiktok_oauth_callback', array( $this, 'callback' ) );
	}
	public function create_flow(): string {
		Ecomkit_Vuikhoe_Security::require_management_capability(); $this->config->credentials(); $session = wp_get_session_token();
		if ( '' === $session ) { throw new RuntimeException( 'TIKTOK_SESSION_REQUIRED' ); }
		$state = bin2hex( random_bytes( 32 ) );
		$flow = array( 'platform' => 'TIKTOK', 'user_id' => get_current_user_id(), 'session_hash' => hash( 'sha256', $session ), 'fingerprint' => $this->config->fingerprint(), 'callback' => $this->config->callback_url(), 'expires_at' => time() + 900 );
		if ( ! set_transient( 'ecomkit_tiktok_flow_' . hash( 'sha256', $state ), $flow, 900 ) ) { throw new RuntimeException( 'TIKTOK_STATE_PERSIST_FAILED' ); }
		set_transient( 'ecomkit_tiktok_waiting_' . get_current_user_id(), array( 'state_hash' => hash( 'sha256', $state ), 'session_hash' => $flow['session_hash'], 'fingerprint' => $flow['fingerprint'], 'expires_at' => $flow['expires_at'] ), 900 ); return $state;
	}
	public function authorization_url( string $state ): string { return 'https://services.tiktokshop.com/open/authorize?' . http_build_query( array( 'service_id' => $this->config->credentials()['service_id'], 'state' => $state ), '', '&', PHP_QUERY_RFC3986 ); }
	private function consume( mixed $state ): void {
		Ecomkit_Vuikhoe_Security::require_management_capability();
		if ( ! is_string( $state ) || ! preg_match( '/^[a-f0-9]{64}$/D', $state ) ) { throw new RuntimeException( 'TIKTOK_STATE_INVALID' ); }
		$this->lock->synchronized( 'state:' . $state, function () use ( $state ): void {
			$key = 'ecomkit_tiktok_flow_' . hash( 'sha256', $state ); $flow = get_transient( $key );
			if ( ! is_array( $flow ) || 'TIKTOK' !== ( $flow['platform'] ?? null ) || get_current_user_id() !== ( $flow['user_id'] ?? null ) || ! hash_equals( hash( 'sha256', wp_get_session_token() ), (string) ( $flow['session_hash'] ?? '' ) ) || ! hash_equals( $this->config->fingerprint(), (string) ( $flow['fingerprint'] ?? '' ) ) || $this->config->callback_url() !== ( $flow['callback'] ?? null ) ) { throw new RuntimeException( 'TIKTOK_STATE_INVALID' ); }
			if ( ( $flow['expires_at'] ?? 0 ) <= time() ) { delete_transient( $key ); throw new RuntimeException( 'TIKTOK_STATE_EXPIRED' ); }
			if ( ! delete_transient( $key ) ) { throw new RuntimeException( 'TIKTOK_STATE_INVALID' ); }
			$waiting = get_transient( 'ecomkit_tiktok_waiting_' . get_current_user_id() ); if ( is_array( $waiting ) && ( $waiting['state_hash'] ?? '' ) === hash( 'sha256', $state ) ) { delete_transient( 'ecomkit_tiktok_waiting_' . get_current_user_id() ); }
		} );
	}
	public function complete( mixed $state, mixed $code, mixed $error = null ): array {
		$this->consume( $state );
		if ( null !== $error ) { throw new RuntimeException( 'auth_denied' === $error ? 'TIKTOK_AUTH_DENIED' : 'TIKTOK_CALLBACK_ERROR' ); }
		if ( ! is_string( $code ) || '' === $code || 'null' === $code || strlen( $code ) > 4096 || preg_match( '/[\x00-\x20\x7f]/', $code ) ) { throw new RuntimeException( 'TIKTOK_CODE_MISSING' ); }
		return $this->tokens->authorize( $code );
	}
	public function start(): void {
		Ecomkit_Vuikhoe_Security::require_management_capability(); check_admin_referer( 'ecomkit_tiktok_oauth_start', 'ecomkit_tiktok_nonce' );
		try { $url = $this->authorization_url( $this->create_flow() ); } catch ( Throwable $error ) { $this->finish( 'error', $error ); return; }
		add_filter( 'allowed_redirect_hosts', static function ( array $hosts ): array { $hosts[] = 'services.tiktokshop.com'; return $hosts; } ); wp_safe_redirect( $url ); exit;
	}
	public function callback(): void {
		Ecomkit_Vuikhoe_Security::require_management_capability(); nocache_headers(); header( 'Referrer-Policy: no-referrer' );
		try { $result = $this->complete( $_GET['state'] ?? null, $_GET['code'] ?? null, $_GET['error'] ?? null ); } catch ( Throwable $error ) { $this->finish( 'error', $error ); return; }
		$this->finish( isset( $result['selection'] ) ? 'choose_shop' : 'connected', null, $result['selection'] ?? null );
	}
	public function refresh(): void {
		Ecomkit_Vuikhoe_Security::require_management_capability(); check_admin_referer( 'ecomkit_tiktok_oauth_refresh', 'ecomkit_tiktok_nonce' );
		try { $id = $_POST['connection_id'] ?? ''; if ( ! is_string( $id ) || ! ctype_digit( $id ) || strlen( $id ) > 9 ) { throw new RuntimeException( 'TIKTOK_CONNECTION_INVALID' ); } $this->tokens->ensure_usable_access_token( (int) $id, true ); } catch ( Throwable $error ) { $this->finish( 'error', $error ); return; } $this->finish( 'refreshed' );
	}
	public function select(): void {
		Ecomkit_Vuikhoe_Security::require_management_capability(); check_admin_referer( 'ecomkit_tiktok_oauth_select', 'ecomkit_tiktok_nonce' );
		try { $reference = $_POST['selection'] ?? null; $shop = $_POST['shop_id'] ?? null; if ( ! is_string( $reference ) || ! is_string( $shop ) ) { throw new RuntimeException( 'TIKTOK_SELECTION_INVALID' ); } $this->tokens->select_shop( $reference, $shop ); } catch ( Throwable $error ) { $this->finish( 'error', $error ); return; } $this->finish( 'connected' );
	}
	private function finish( string $notice, ?Throwable $error = null, ?string $selection = null ): void {
		$args = array( 'page' => 'ecomkit-vuikhoe-marketplace', 'tiktok_notice' => $notice );
		if ( $error ) {
			// Only internal enumerated classifications; never arbitrary exception/provider text.
			$allowed = array( 'TIKTOK_STATE_INVALID', 'TIKTOK_STATE_EXPIRED', 'TIKTOK_AUTH_DENIED', 'TIKTOK_CALLBACK_ERROR', 'TIKTOK_CODE_MISSING', 'TIKTOK_REAUTH_REQUIRED', 'TIKTOK_SCOPE_REQUIRED', 'TIKTOK_SELLER_REQUIRED', 'TIKTOK_REGION_MISMATCH', 'TIKTOK_SHOP_NOT_FOUND', 'TIKTOK_SHOPS_INVALID', 'TIKTOK_NETWORK_ERROR', 'TIKTOK_HTTP_ERROR', 'TIKTOK_PROVIDER_ERROR', 'TIKTOK_INVALID_RESPONSE', 'TIKTOK_INVALID_TOKEN_RESPONSE', 'TIKTOK_EXPIRY_INVALID', 'TIKTOK_SELECTION_INVALID', 'TIKTOK_LOCK_UNAVAILABLE', 'TIKTOK_PERSIST_FAILED' );
			$reference = bin2hex( random_bytes( 16 ) ); set_transient( 'ecomkit_tiktok_notice_' . $reference, array( 'user' => get_current_user_id(), 'code' => in_array( $error->getMessage(), $allowed, true ) ? $error->getMessage() : 'TIKTOK_AUTH_REQUIRED', 'provider' => $this->tokens->safe_diagnostic() ), 300 ); $args['tiktok_diagnostic'] = $reference;
		} if ( $selection ) { $args['tiktok_selection'] = $selection; }
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) ); exit;
	}
}
