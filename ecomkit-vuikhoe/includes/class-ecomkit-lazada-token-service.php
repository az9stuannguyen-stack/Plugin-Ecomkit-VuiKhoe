<?php
defined( 'ABSPATH' ) || exit;
/** Lazada token lifecycle in the existing generic marketplace_connections table. */
final class Ecomkit_Vuikhoe_Lazada_Token_Service {
	public const REFRESH_THRESHOLD = 1800;
	public function __construct( private ?Ecomkit_Vuikhoe_Lazada_HTTP_Client $http = null, private ?Ecomkit_Vuikhoe_Lazada_Config $config = null, private ?Ecomkit_Vuikhoe_Credential_Encryption $encryption = null, private ?Ecomkit_Vuikhoe_Lazada_Lock $lock = null ) {
		$this->http ??= new Ecomkit_Vuikhoe_Lazada_HTTP_Client(); $this->config ??= new Ecomkit_Vuikhoe_Lazada_Config(); $this->encryption ??= new Ecomkit_Vuikhoe_Credential_Encryption(); $this->lock ??= new Ecomkit_Vuikhoe_Lazada_Lock();
	}
	private static function aad( string $shop ): string { return 'ecomkit|lazada|vn|shop:' . $shop . '|v1'; }
	private static function table(): string { return Ecomkit_Vuikhoe_DB::table_names()['marketplace_connections']; }
	public static function normalize( array $data, ?int $now = null ): array {
		$now ??= time();
		foreach ( array( 'access_token', 'refresh_token' ) as $field ) {
			if ( 'refresh_token' === $field && ! isset( $data[$field] ) && 0 === self::duration( $data['refresh_expires_in'] ?? null, true ) ) { $data[$field] = ''; }
			if ( ! is_string( $data[$field] ?? null ) || strlen( $data[$field] ) > 8192 || ( '' === $data[$field] && 'access_token' === $field ) || preg_match( '/[\x00-\x20\x7F]/', $data[$field] ) ) { throw new RuntimeException( 'LAZADA_INVALID_TOKEN_RESPONSE' ); }
		}
		$access_duration = self::duration( $data['expires_in'] ?? null, false );
		$refresh_duration = isset( $data['refresh_expires_in'] ) ? self::duration( $data['refresh_expires_in'], true ) : null;
		if ( null !== $refresh_duration && $refresh_duration > 0 && '' === $data['refresh_token'] ) { throw new RuntimeException( 'LAZADA_INVALID_TOKEN_RESPONSE' ); }
		$entries = $data['country_user_info_list'] ?? $data['country_user_info'] ?? array();
		if ( ! is_array( $entries ) ) { throw new RuntimeException( 'LAZADA_INVALID_TOKEN_RESPONSE' ); }
		if ( isset( $entries['country'] ) ) { $entries = array( $entries ); }
		$root_country = is_string( $data['country'] ?? null ) ? strtolower( $data['country'] ) : '';
		if ( ! in_array( $root_country, array( '', 'vn', 'cb' ), true ) && count( $entries ) < 2 ) { throw new RuntimeException( 'LAZADA_COUNTRY_MISMATCH' ); }
		$identities = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || 'vn' !== ( $entry['country'] ?? null ) ) { continue; }
			foreach ( array( 'seller_id', 'user_id', 'short_code' ) as $field ) {
				$value = $entry[$field] ?? null;
				if ( is_int( $value ) ) { $value = (string) $value; }
				if ( is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,191}$/D', $value ) ) { $identities[$value] = array( 'external_shop_id' => $value, 'identity_field' => $field, 'identity_path' => ( isset( $data['country_user_info_list'] ) ? 'country_user_info_list' : 'country_user_info' ) . '[country=vn].' . $field ); break; }
			}
		}
		if ( 1 !== count( $identities ) ) { throw new RuntimeException( empty( $identities ) && 'vn' !== $root_country ? 'LAZADA_COUNTRY_MISMATCH' : 'LAZADA_INVALID_TOKEN_RESPONSE' ); }
		$identity = array_values( $identities )[0];
		return array_merge( $identity, array( 'v' => 1, 'country' => 'vn', 'access_token' => $data['access_token'], 'refresh_token' => $data['refresh_token'], 'obtained_at' => $now, 'access_expires_at' => $now + $access_duration, 'refresh_expires_at' => null === $refresh_duration || 0 === $refresh_duration ? null : $now + $refresh_duration, 'refreshable' => null !== $refresh_duration && $refresh_duration > 0, 'refresh_expires_in' => $refresh_duration, 'account_id' => is_string( $data['account_id'] ?? null ) ? $data['account_id'] : null ) );
	}
	private static function duration( mixed $value, bool $zero ): int {
		if ( ( ! is_int( $value ) && ! is_string( $value ) ) || 1 !== preg_match( '/^[0-9]{1,10}$/D', (string) $value ) || (int) $value < ( $zero ? 0 : 1 ) || (int) $value > 2147483647 ) { throw new RuntimeException( 'LAZADA_INVALID_TOKEN_RESPONSE' ); }
		return (int) $value;
	}
	public function authorize( string $code ): int {
		$app = $this->config->credentials(); $fingerprint = $this->config->fingerprint();
		$result = $this->http->exchange( $app, $code );
		try { $tokens = self::normalize( $result['data'] ); } catch ( RuntimeException $e ) { throw new Ecomkit_Vuikhoe_Lazada_Provider_Exception( $e->getMessage(), $result['diagnostic'] ); }
		$tokens['app_key'] = $app['app_key'];
		return $this->lock->synchronized( 'shop:' . $tokens['external_shop_id'], function () use ( $tokens, $fingerprint ): int {
			if ( ! hash_equals( $fingerprint, $this->config->fingerprint() ) ) { throw new RuntimeException( 'LAZADA_CONFIG_CHANGED' ); }
			global $wpdb;
			$table = self::table();
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE platform = %s AND external_shop_id = %s", 'LAZADA', $tokens['external_shop_id'] ) );
			$data = $this->stored_pair( $tokens, $fingerprint );
			if ( $id ) { if ( false === $wpdb->update( $table, $data, array( 'id' => $id, 'platform' => 'LAZADA' ) ) ) { throw new RuntimeException( 'LAZADA_CONNECTION_PERSIST_FAILED' ); } return $id; }
			$data['platform'] = 'LAZADA'; $data['external_shop_id'] = $tokens['external_shop_id']; $data['created_at'] = current_time( 'mysql', true );
			if ( false === $wpdb->insert( $table, $data ) ) { throw new RuntimeException( 'LAZADA_CONNECTION_PERSIST_FAILED' ); }
			return (int) $wpdb->insert_id;
		} );
	}
	private function row( int $id ): array {
		global $wpdb; $table = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d AND platform = %s", $id, 'LAZADA' ), ARRAY_A );
		if ( ! is_array( $row ) ) { throw new RuntimeException( 'LAZADA_NOT_CONNECTED' ); }
		return $row;
	}
	private function plain( array $row ): array {
		$tokens = $this->encryption->decrypt( (string) ( $row['credential_envelope'] ?? '' ), self::aad( (string) $row['external_shop_id'] ) );
		if ( 1 !== ( $tokens['v'] ?? null ) || 'vn' !== ( $tokens['country'] ?? null ) || (string) $row['external_shop_id'] !== ( $tokens['external_shop_id'] ?? null ) || ! is_string( $tokens['access_token'] ?? null ) || '' === $tokens['access_token'] || ! is_int( $tokens['access_expires_at'] ?? null ) ) { throw new RuntimeException( 'LAZADA_INVALID_TOKEN_RESPONSE' ); }
		return $tokens;
	}
	private function stored_pair( array $tokens, string $fingerprint ): array {
		$metadata = array( 'v' => 1, 'country' => 'vn', 'identity_path' => $tokens['identity_path'], 'config_fingerprint' => $fingerprint, 'credential_lifecycle' => 'READY', 'access_expires_at' => $tokens['access_expires_at'], 'refresh_expires_at' => $tokens['refresh_expires_at'], 'refreshable' => $tokens['refreshable'] );
		$json = wp_json_encode( $metadata ); if ( ! is_string( $json ) ) { throw new RuntimeException( 'LAZADA_CONNECTION_PERSIST_FAILED' ); }
		return array( 'status' => 'ACTIVE', 'credential_source' => 'OAUTH', 'credential_envelope' => $this->encryption->encrypt( $tokens, self::aad( $tokens['external_shop_id'] ) ), 'metadata' => $json, 'updated_at' => current_time( 'mysql', true ) );
	}
	private function mark( array $row, string $lifecycle, array $diagnostic = array() ): void {
		global $wpdb;
		$metadata = json_decode( (string) $row['metadata'], true ) ?: array();
		$metadata['credential_lifecycle'] = $lifecycle; $metadata['last_diagnostic'] = $diagnostic;
		$json = wp_json_encode( $metadata );
		if ( ! is_string( $json ) || false === $wpdb->update( self::table(), array( 'metadata' => $json, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $row['id'], 'platform' => 'LAZADA' ) ) ) { throw new RuntimeException( 'LAZADA_CONNECTION_PERSIST_FAILED' ); }
	}
	public function ensure_usable_access_token( int $id, bool $force_refresh = false ): string {
		$row = $this->row( $id );
		return $this->lock->synchronized( 'shop:' . $row['external_shop_id'], function () use ( $id, $force_refresh ): string {
			$row = $this->row( $id ); $meta = json_decode( (string) $row['metadata'], true ) ?: array();
			if ( 'ACTIVE' !== $row['status'] || 'READY' !== ( $meta['credential_lifecycle'] ?? '' ) ) { throw new RuntimeException( 'LAZADA_REAUTH_REQUIRED' ); }
			$app = $this->config->credentials(); $fingerprint = $this->config->fingerprint();
			if ( ! hash_equals( (string) ( $meta['config_fingerprint'] ?? '' ), $fingerprint ) ) { $this->mark( $row, 'REAUTH_REQUIRED' ); throw new RuntimeException( 'LAZADA_REAUTH_REQUIRED' ); }
			$tokens = $this->plain( $row ); $now = time();
			if ( ! $force_refresh && $tokens['access_expires_at'] > $now + self::REFRESH_THRESHOLD ) { return $tokens['access_token']; }
			if ( empty( $tokens['refreshable'] ) || ! is_int( $tokens['refresh_expires_at'] ?? null ) || $tokens['refresh_expires_at'] <= $now || empty( $tokens['refresh_token'] ) ) {
				if ( ! $force_refresh && $tokens['access_expires_at'] > $now ) { return $tokens['access_token']; }
				$this->mark( $row, 'REAUTH_REQUIRED' ); throw new RuntimeException( 'LAZADA_REFRESH_EXPIRED' );
			}
			$this->mark( $row, 'REFRESH_INFLIGHT' ); // Fail closed if execution/persistence is interrupted.
			try {
				$result = $this->http->refresh( $app, $tokens['refresh_token'] );
				$new = self::normalize( $result['data'] );
				if ( $new['external_shop_id'] !== $tokens['external_shop_id'] || $new['identity_field'] !== $tokens['identity_field'] ) { throw new RuntimeException( 'LAZADA_COUNTRY_MISMATCH' ); }
				$new['app_key'] = $app['app_key'];
				// Refresh rotates tokens, never extends the previously known refresh expiry.
				if ( null !== $new['refresh_expires_at'] ) { $new['refresh_expires_at'] = min( $tokens['refresh_expires_at'], $new['refresh_expires_at'] ); }
				if ( ! hash_equals( $fingerprint, $this->config->fingerprint() ) ) { throw new RuntimeException( 'LAZADA_CONFIG_CHANGED' ); }
				global $wpdb;
				if ( false === $wpdb->update( self::table(), $this->stored_pair( $new, $fingerprint ), array( 'id' => $id, 'platform' => 'LAZADA' ) ) ) { throw new RuntimeException( 'LAZADA_CONNECTION_PERSIST_FAILED' ); }
				return $new['access_token'];
			} catch ( Throwable $e ) {
				$diag = $e instanceof Ecomkit_Vuikhoe_Lazada_Provider_Exception ? $e->diagnostic : array( 'stage' => 'REFRESH_VALIDATE_OR_PERSIST', 'classification' => 'LAZADA_REFRESH_FAILED' );
				$this->mark( $row, 'LAZADA_REFRESH_EXPIRED' === $e->getMessage() ? 'REAUTH_REQUIRED' : 'TOKEN_ERROR', $diag );
				throw $e;
			}
		} );
	}
	/** Safe read-only UI; never refreshes or returns token/envelope. */
	public function safe_connections(): array {
		$rows = ( new Ecomkit_Vuikhoe_Marketplace_Connection_Service() )->list_platform( 'LAZADA' ); $safe = array();
		foreach ( $rows as $summary ) {
			$entry = array( 'id' => (int) $summary['id'], 'shop' => (string) $summary['external_shop_id'], 'status' => (string) $summary['status'], 'lifecycle' => 'NOT_CONNECTED', 'access_expires_at' => null, 'refresh_expires_at' => null, 'identity_path' => '', 'diagnostic' => array() );
			try {
				$row = $this->row( (int) $summary['id'] ); $meta = json_decode( (string) $row['metadata'], true ) ?: array(); $tokens = $this->plain( $row );
				$entry['lifecycle'] = (string) ( $meta['credential_lifecycle'] ?? 'TOKEN_ERROR' );
				if ( ! hash_equals( (string) ( $meta['config_fingerprint'] ?? '' ), $this->config->fingerprint() ) ) { $entry['lifecycle'] = 'REAUTH_REQUIRED'; }
				elseif ( 'READY' === $entry['lifecycle'] && $tokens['access_expires_at'] <= time() + self::REFRESH_THRESHOLD ) { $entry['lifecycle'] = ! empty( $tokens['refreshable'] ) && ( $tokens['refresh_expires_at'] ?? 0 ) > time() ? 'REFRESH_NEEDED' : ( $tokens['access_expires_at'] > time() ? 'READY' : 'REAUTH_REQUIRED' ); }
				$entry['access_expires_at'] = $tokens['access_expires_at']; $entry['refresh_expires_at'] = $tokens['refresh_expires_at']; $entry['identity_path'] = (string) ( $meta['identity_path'] ?? '' ); $entry['diagnostic'] = (array) ( $meta['last_diagnostic'] ?? array() );
			} catch ( Throwable ) { if ( 'ACTIVE' === $entry['status'] ) { $entry['lifecycle'] = 'TOKEN_ERROR'; } }
			$safe[] = $entry;
		}
		return $safe;
	}
}
