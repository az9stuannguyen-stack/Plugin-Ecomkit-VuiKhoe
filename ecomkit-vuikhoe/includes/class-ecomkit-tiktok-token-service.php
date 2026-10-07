<?php
defined( 'ABSPATH' ) || exit;
/** Shop-bound encrypted seller credentials. No Order or finance operations. */
final class Ecomkit_Vuikhoe_Tiktok_Token_Service {
	public function __construct( private ?Ecomkit_Vuikhoe_Tiktok_Http_Client $http = null, private ?Ecomkit_Vuikhoe_Tiktok_Config $config = null, private ?Ecomkit_Vuikhoe_Credential_Encryption $encryption = null, private ?Ecomkit_Vuikhoe_Tiktok_Lock $lock = null ) {
		$this->http ??= new Ecomkit_Vuikhoe_Tiktok_Http_Client(); $this->config ??= new Ecomkit_Vuikhoe_Tiktok_Config(); $this->encryption ??= new Ecomkit_Vuikhoe_Credential_Encryption(); $this->lock ??= new Ecomkit_Vuikhoe_Tiktok_Lock();
	}
	public static function normalize( array $data, ?int $now = null ): array {
		$now ??= time(); $out = array();
		foreach ( array( 'access_token', 'refresh_token', 'open_id' ) as $field ) {
			$value = $data[$field] ?? null;
			if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 8192 || preg_match( '/[\x00-\x20\x7f]/', $value ) ) { throw new RuntimeException( 'TIKTOK_INVALID_TOKEN_RESPONSE' ); } $out[$field] = $value;
		}
		if ( 0 !== ( $data['user_type'] ?? null ) ) { throw new RuntimeException( 'TIKTOK_SELLER_REQUIRED' ); }
		$scopes = $data['granted_scopes'] ?? null;
		if ( ! is_array( $scopes ) || ! array_is_list( $scopes ) || count( $scopes ) > 100 ) { throw new RuntimeException( 'TIKTOK_SCOPE_REQUIRED' ); }
		foreach ( $scopes as $scope ) { if ( ! is_string( $scope ) || ! preg_match( '/^[a-zA-Z0-9_.]{1,128}$/D', $scope ) ) { throw new RuntimeException( 'TIKTOK_SCOPE_REQUIRED' ); } }
		if ( ! in_array( 'seller.authorization.info', $scopes, true ) ) { throw new RuntimeException( 'TIKTOK_SCOPE_REQUIRED' ); }
		$out['user_type'] = 0; $out['granted_scopes'] = $scopes;
		foreach ( array( 'access_token_expire_in', 'refresh_token_expire_in' ) as $field ) {
			$value = $data[$field] ?? null;
			if ( ! ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^[0-9]{10}$/D', $value ) ) ) || $value <= $now || $value > 9999999999 ) { throw new RuntimeException( 'TIKTOK_EXPIRY_INVALID' ); }
			$out[$field] = (int) $value; // Official absolute Unix seconds, never a duration.
		} return $out;
	}
	public static function normalize_shops( array $data ): array {
		$shops = $data['shops'] ?? null;
		if ( is_array( $shops ) && array() === $shops ) { throw new RuntimeException( 'TIKTOK_SHOP_NOT_FOUND' ); }
		if ( ! is_array( $shops ) || ! array_is_list( $shops ) || count( $shops ) > 100 ) { throw new RuntimeException( 'TIKTOK_SHOPS_INVALID' ); }
		$out = array(); $ids = array();
		foreach ( $shops as $shop ) {
			if ( ! is_array( $shop ) ) { throw new RuntimeException( 'TIKTOK_SHOPS_INVALID' ); } $safe = array();
			foreach ( array( 'id', 'cipher', 'region', 'seller_type' ) as $field ) {
				$value = $shop[$field] ?? null;
				if ( ! is_string( $value ) || '' === $value || strlen( $value ) > ( 'id' === $field ? 191 : 1024 ) || preg_match( '/[\x00-\x20\x7f]/', $value ) ) { throw new RuntimeException( 'TIKTOK_SHOPS_INVALID' ); } $safe[$field] = $value;
			}
			if ( ! preg_match( '/^[A-Z]{2}$/D', $safe['region'] ) || ! in_array( $safe['seller_type'], array( 'LOCAL', 'CROSS_BORDER' ), true ) || in_array( $safe['id'], $ids, true ) ) { throw new RuntimeException( 'TIKTOK_SHOPS_INVALID' ); }
			foreach ( array( 'name', 'code' ) as $field ) { if ( isset( $shop[$field] ) && ( ! is_string( $shop[$field] ) || strlen( $shop[$field] ) > 512 || preg_match( '/[\x00-\x1f\x7f]/', $shop[$field] ) ) ) { throw new RuntimeException( 'TIKTOK_SHOPS_INVALID' ); } $safe[$field] = $shop[$field] ?? ''; }
			$ids[] = $safe['id']; $out[] = $safe;
		} return $out;
	}
	public function authorize( string $code ): array {
		$fingerprint = $this->config->fingerprint(); $app = $this->config->credentials();
		$tokens = self::normalize( $this->http->exchange( $app, $code ) ); $shops = self::normalize_shops( $this->http->shops( $app, $tokens['access_token'] ) );
		$this->assert_config( $fingerprint );
		if ( 1 === count( $shops ) ) { return array( 'connection_id' => $this->persist( $tokens, $shops[0], $fingerprint ) ); }
		$reference = bin2hex( random_bytes( 32 ) ); $context = $this->context( $fingerprint );
		$context['envelope'] = $this->encryption->encrypt( array( 'tokens' => $tokens, 'shops' => $shops ), 'ecomkit|tiktok|selection:' . $reference );
		if ( ! set_transient( 'ecomkit_tiktok_selection_' . $reference, $context, 900 ) ) { throw new RuntimeException( 'TIKTOK_STATE_PERSIST_FAILED' ); }
		return array( 'selection' => $reference );
	}
	private function context( string $fingerprint ): array { return array( 'user' => get_current_user_id(), 'session' => hash( 'sha256', wp_get_session_token() ), 'fingerprint' => $fingerprint, 'expires' => time() + 900 ); }
	private function selection( string $reference ): array {
		Ecomkit_Vuikhoe_Security::require_management_capability();
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $reference ) ) { throw new RuntimeException( 'TIKTOK_SELECTION_INVALID' ); }
		$row = get_transient( 'ecomkit_tiktok_selection_' . $reference ); $context = $this->context( $this->config->fingerprint() );
		if ( ! is_array( $row ) || ( $row['expires'] ?? 0 ) <= time() || ( $row['user'] ?? null ) !== $context['user'] || ! hash_equals( $context['session'], (string) ( $row['session'] ?? '' ) ) || ! hash_equals( $context['fingerprint'], (string) ( $row['fingerprint'] ?? '' ) ) ) { throw new RuntimeException( 'TIKTOK_SELECTION_INVALID' ); }
		return array( $row, $this->encryption->decrypt( $row['envelope'], 'ecomkit|tiktok|selection:' . $reference ) );
	}
	public function safe_selection( string $reference ): array { [, $plain] = $this->selection( $reference ); return array_map( static fn( array $shop ): array => array_intersect_key( $shop, array_flip( array( 'id', 'name', 'region', 'seller_type' ) ) ), $plain['shops'] ); }
	public function select_shop( string $reference, string $id ): int {
		return $this->lock->synchronized( 'selection:' . $reference, function () use ( $reference, $id ): int {
			[$context, $plain] = $this->selection( $reference ); $tokens = self::normalize( $plain['tokens'] );
			foreach ( $plain['shops'] as $shop ) { if ( $shop['id'] === $id ) { $result = $this->persist( $tokens, $shop, $context['fingerprint'] ); delete_transient( 'ecomkit_tiktok_selection_' . $reference ); return $result; } }
			throw new RuntimeException( 'TIKTOK_SELECTION_INVALID' );
		} );
	}
	private function assert_config( string $fingerprint ): void { if ( ! hash_equals( $fingerprint, $this->config->fingerprint() ) ) { throw new RuntimeException( 'TIKTOK_REAUTH_REQUIRED' ); } }
	private function aad( string $shop ): string { return 'ecomkit|tiktok|vn|shop:' . $shop . '|v1'; }
	private function persist( array $tokens, array $shop, string $fingerprint ): int {
		$tokens = self::normalize( $tokens );
		if ( 'VN' !== $shop['region'] ) { throw new RuntimeException( 'TIKTOK_REGION_MISMATCH' ); }
		return $this->lock->synchronized( 'shop:' . $shop['id'], function () use ( $tokens, $shop, $fingerprint ): int {
			global $wpdb; $this->assert_config( $fingerprint ); $table = Ecomkit_Vuikhoe_DB::table_names()['marketplace_connections'];
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE platform = %s AND external_shop_id = %s", 'TIKTOK', $shop['id'] ) );
			$data = array( 'platform' => 'TIKTOK', 'external_shop_id' => $shop['id'], 'status' => 'ACTIVE', 'credential_source' => 'OAUTH', 'credential_envelope' => $this->encryption->encrypt( array( 'v' => 1, 'tokens' => $tokens, 'shop' => $shop ), $this->aad( $shop['id'] ) ), 'metadata' => wp_json_encode( array( 'v' => 1, 'provider_config_fingerprint' => $fingerprint, 'credential_lifecycle' => 'READY', 'refresh_ownership' => 'ECOMKIT' ) ), 'updated_at' => current_time( 'mysql', true ) );
			if ( $id ) { if ( false === $wpdb->update( $table, $data, array( 'id' => $id, 'platform' => 'TIKTOK' ) ) ) { throw new RuntimeException( 'TIKTOK_PERSIST_FAILED' ); } }
			else { $data['created_at'] = $data['updated_at']; if ( false === $wpdb->insert( $table, $data ) ) { throw new RuntimeException( 'TIKTOK_PERSIST_FAILED' ); } $id = (int) $wpdb->insert_id; }
			return $id;
		} );
	}
	private function row( int $id ): array { global $wpdb; $table = Ecomkit_Vuikhoe_DB::table_names()['marketplace_connections']; $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d AND platform = %s", $id, 'TIKTOK' ), ARRAY_A ); if ( ! is_array( $row ) ) { throw new RuntimeException( 'TIKTOK_CONNECTION_INVALID' ); } return $row; }
	private function plain( array $row ): array {
		$plain = $this->encryption->decrypt( (string) $row['credential_envelope'], $this->aad( $row['external_shop_id'] ) );
		if ( 1 !== ( $plain['v'] ?? null ) || ( $plain['shop']['id'] ?? null ) !== $row['external_shop_id'] || 'VN' !== ( $plain['shop']['region'] ?? null ) || ! is_string( $plain['shop']['cipher'] ?? null ) || '' === $plain['shop']['cipher'] ) { throw new RuntimeException( 'TIKTOK_CONNECTION_INVALID' ); }
		self::normalize( $plain['tokens'], 0 ); return $plain;
	}
	private function lifecycle( array $row, array $plain ): string {
		$meta = json_decode( (string) $row['metadata'], true ) ?: array();
		if ( 'ACTIVE' !== $row['status'] || 'OAUTH' !== $row['credential_source'] || ! hash_equals( $this->config->fingerprint(), (string) ( $meta['provider_config_fingerprint'] ?? '' ) ) || $plain['tokens']['refresh_token_expire_in'] <= time() ) { return 'REAUTH_REQUIRED'; }
		if ( 'READY' !== ( $meta['credential_lifecycle'] ?? '' ) ) { return 'INVALID'; }
		$expiry = $plain['tokens']['access_token_expire_in']; return $expiry <= time() ? 'EXPIRED' : ( $expiry <= time() + 600 ? 'REFRESH_SOON' : 'READY' );
	}
	public function ensure_usable_access_token( int $id, bool $force = false ): string {
		$observed = $this->row( $id );
		return $this->lock->synchronized( 'shop:' . $observed['external_shop_id'], function () use ( $id, $force, $observed ): string {
			global $wpdb; $row = $this->row( $id ); $plain = $this->plain( $row ); $state = $this->lifecycle( $row, $plain );
			if ( in_array( $state, array( 'REAUTH_REQUIRED', 'INVALID' ), true ) ) { throw new RuntimeException( 'TIKTOK_REAUTH_REQUIRED' ); }
			if ( 'READY' === $state && ( ! $force || $observed['credential_envelope'] !== $row['credential_envelope'] ) ) { return $plain['tokens']['access_token']; }
			$table = Ecomkit_Vuikhoe_DB::table_names()['marketplace_connections']; $meta = json_decode( $row['metadata'], true ); $meta['credential_lifecycle'] = 'REFRESH_INFLIGHT';
			if ( false === $wpdb->update( $table, array( 'metadata' => wp_json_encode( $meta ) ), array( 'id' => $id, 'platform' => 'TIKTOK' ) ) ) { throw new RuntimeException( 'TIKTOK_PERSIST_FAILED' ); }
			// A crash/uncertain rotating refresh fails closed; the previous encrypted pair remains intact.
			$new = self::normalize( $this->http->refresh( $this->config->credentials(), $plain['tokens']['refresh_token'] ) );
			if ( $new['open_id'] !== $plain['tokens']['open_id'] ) { throw new RuntimeException( 'TIKTOK_IDENTITY_MISMATCH' ); }
			$this->assert_config( $meta['provider_config_fingerprint'] ); $plain['tokens'] = $new; $meta['credential_lifecycle'] = 'READY'; $meta['last_refresh_at'] = current_time( 'mysql', true );
			$data = array( 'credential_envelope' => $this->encryption->encrypt( $plain, $this->aad( $row['external_shop_id'] ) ), 'metadata' => wp_json_encode( $meta ), 'updated_at' => current_time( 'mysql', true ) );
			if ( false === $wpdb->update( $table, $data, array( 'id' => $id, 'platform' => 'TIKTOK' ) ) ) { throw new RuntimeException( 'TIKTOK_PERSIST_FAILED' ); } return $new['access_token'];
		} );
	}
	public function safe_connections(): array {
		Ecomkit_Vuikhoe_Security::require_management_capability(); $out = array();
		foreach ( ( new Ecomkit_Vuikhoe_Marketplace_Connection_Service() )->list_platform( 'TIKTOK' ) as $item ) {
			$safe = array( 'id' => (int) $item['id'], 'shop_id' => (string) $item['external_shop_id'], 'lifecycle' => 'INVALID', 'name' => '' );
			try { $row = $this->row( $safe['id'] ); $plain = $this->plain( $row ); $safe['lifecycle'] = $this->lifecycle( $row, $plain ); $safe['name'] = $plain['shop']['name']; $safe['region'] = $plain['shop']['region']; $safe['granted_scopes'] = $plain['tokens']['granted_scopes']; $safe['access_expires_at'] = $plain['tokens']['access_token_expire_in']; $safe['refresh_expires_at'] = $plain['tokens']['refresh_token_expire_in']; } catch ( Throwable ) {}
			$out[] = $safe;
		} return $out;
	}
	public function safe_diagnostic(): array { return $this->http->last_diagnostic; }
}
