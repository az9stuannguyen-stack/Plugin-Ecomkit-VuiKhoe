<?php
/** Shopee access/refresh token lifecycle; tokens remain server-side only. */
defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Shopee_Token_Service {
	public const REFRESH_SKEW_SECONDS = 300;
	private const REFRESH_LIFETIME_ESTIMATE = 2592000;
	public array $last_diagnostic = array();
	public function __construct( private ?Ecomkit_Vuikhoe_Shopee_HTTP_Client $http = null, private ?Ecomkit_Vuikhoe_Credential_Encryption $encryption = null, private ?Ecomkit_Vuikhoe_Credential_Mutation_Lock $lock = null ) { $this->http ??= new Ecomkit_Vuikhoe_Shopee_HTTP_Client(); $this->encryption ??= new Ecomkit_Vuikhoe_Credential_Encryption(); $this->lock ??= new Ecomkit_Vuikhoe_Credential_Mutation_Lock(); }
	private function aad( string $shop_id ): string { return 'ecomkit|shopee|shop:' . $shop_id; }

	/** Returns plaintext only to an internal server-side caller. */
	public function ensure_usable_access_token( int $connection_id, bool $force_refresh = false ): string {
		$this->last_diagnostic = array( 'connection_id' => $connection_id, 'stage' => 'REFRESH_READINESS', 'classification' => '' ); $this->last_diagnostic['stage'] = 'REFRESH_LOCK';
		try { return $this->lock->synchronized( $connection_id, function () use ( $connection_id, $force_refresh ): string { return $this->under_lock( $connection_id, $force_refresh ); } ); }
		catch ( Throwable $exception ) { if ( 'ECOMKIT_CREDENTIAL_LOCK_UNAVAILABLE' === $exception->getMessage() ) { $this->last_diagnostic['classification'] = $exception->getMessage(); } throw $exception; }
	}
	public function mark_reauthorization_required( int $connection_id, array $diagnostic = array() ): void {
		$this->lock->synchronized( $connection_id, function () use ( $connection_id, $diagnostic ): void { global $wpdb; $table = Ecomkit_Vuikhoe_DB::table_names()['marketplace_connections']; $row = $wpdb->get_row( $wpdb->prepare( "SELECT metadata FROM $table WHERE id = %d", $connection_id ), ARRAY_A ); if ( ! is_array( $row ) ) { throw new RuntimeException( 'SHOPEE_REFRESH_CONNECTION_INVALID' ); } $metadata = json_decode( (string) ( $row['metadata'] ?? '' ), true ); $this->set_lifecycle( $connection_id, is_array( $metadata ) ? $metadata : array(), 'REAUTH_REQUIRED', $diagnostic ); } );
	}

	private function under_lock( int $connection_id, bool $force_refresh ): string {
		global $wpdb;
		$this->last_diagnostic['stage'] = 'REFRESH_CREDENTIAL_LOAD';
		$table = Ecomkit_Vuikhoe_DB::table_names()['marketplace_connections'];
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, platform, external_shop_id, status, credential_source, credential_envelope, metadata FROM $table WHERE id = %d", $connection_id ), ARRAY_A );
		if ( ! is_array( $row ) || 'SHOPEE' !== (string) ( $row['platform'] ?? '' ) ) { $this->fail( 'SHOPEE_REFRESH_CONNECTION_INVALID' ); }
		$metadata = json_decode( (string) ( $row['metadata'] ?? '' ), true ); $metadata = is_array( $metadata ) ? $metadata : array();
		if ( 'OAUTH' !== (string) ( $row['credential_source'] ?? '' ) || 'ECOMKIT' !== (string) ( $metadata['refresh_ownership'] ?? '' ) ) { $this->fail( 'SHOPEE_REFRESH_NOT_OWNED' ); }
		if ( in_array( (string) ( $metadata['credential_lifecycle'] ?? 'READY' ), array( 'REAUTH_REQUIRED', 'REFRESH_UNCERTAIN' ), true ) ) { $this->fail( 'SHOPEE_REAUTH_REQUIRED' ); }
		$config_service = new Ecomkit_Vuikhoe_Shopee_Config(); $ready = $config_service->readiness(); $config = $config_service->get();
		if ( ! $ready['ready'] ) { $this->fail( 'SHOPEE_PROVIDER_CONFIG_NOT_READY' ); }
		if ( ! hash_equals( (string) ( $metadata['provider_config_fingerprint'] ?? '' ), (string) ( $config['fingerprint'] ?? '' ) ) ) { $this->set_lifecycle( $connection_id, $metadata, 'CONFIG_CHANGED' ); $this->fail( 'SHOPEE_REFRESH_CONFIG_CHANGED' ); }
		try { $credential = $this->encryption->decrypt( (string) $row['credential_envelope'], $this->aad( (string) $row['external_shop_id'] ) ); }
		catch ( Throwable $exception ) { $this->set_lifecycle( $connection_id, $metadata, 'CREDENTIAL_DECRYPT_FAILED' ); $this->fail( 'SHOPEE_CREDENTIAL_DECRYPT_FAILED' ); }
		if ( empty( $credential['access_token'] ) || empty( $credential['refresh_token'] ) || empty( $credential['access_expires_at'] ) ) { $this->fail( 'SHOPEE_CREDENTIAL_INCOMPLETE' ); }
		$expires = strtotime( (string) $credential['access_expires_at'] . ' UTC' );
		if ( ! $force_refresh && false !== $expires && $expires > time() + self::REFRESH_SKEW_SECONDS ) { $this->last_diagnostic['stage'] = 'REFRESH_COMPLETE'; return (string) $credential['access_token']; }
		try {
			$this->last_diagnostic['stage'] = 'REFRESH_REQUEST_BUILD';
			$response = $this->http->refresh( $config, $config_service->partner_key( $config ), (string) $credential['refresh_token'], (string) $row['external_shop_id'] );
			$this->last_diagnostic = array_merge( $this->last_diagnostic, $this->http->last_diagnostic );
		} catch ( Ecomkit_Vuikhoe_Shopee_Provider_Exception $exception ) {
			$this->last_diagnostic = array_merge( $this->last_diagnostic, $exception->diagnostic, array( 'classification' => $exception->getMessage() ) );
			$lifecycle = $this->failure_lifecycle( $exception->getMessage() ); if ( '' !== $lifecycle ) { $this->set_lifecycle( $connection_id, $metadata, $lifecycle, $this->last_diagnostic ); }
			throw $exception;
		}
		$now = current_time( 'mysql', true ); $expires_at = gmdate( 'Y-m-d H:i:s', time() + (int) $response['expire_in'] );
		$new_credential = array( 'v' => 1, 'access_token' => $response['access_token'], 'refresh_token' => $response['refresh_token'], 'access_expires_at' => $expires_at, 'obtained_at' => $credential['obtained_at'] ?? $now, 'refresh_obtained_at' => $now, 'refresh_expires_at_estimate' => gmdate( 'Y-m-d H:i:s', time() + self::REFRESH_LIFETIME_ESTIMATE ), 'last_refresh_at' => $now, 'last_refresh_request_id' => (string) ( $response['request_id'] ?? '' ) );
		$this->last_diagnostic['stage'] = 'REFRESH_ENCRYPT';
		try { $envelope = $this->encryption->encrypt( $new_credential, $this->aad( (string) $row['external_shop_id'] ) ); }
		catch ( Throwable $exception ) { $this->set_lifecycle( $connection_id, $metadata, 'REFRESH_UNCERTAIN', $this->last_diagnostic ); $this->fail( 'SHOPEE_REFRESH_ENCRYPT_FAILED' ); }
		$metadata = array_merge( $metadata, array( 'credential_lifecycle' => 'READY', 'access_expires_at' => $expires_at, 'last_refresh_at' => $now, 'refresh_expires_at_estimate' => $new_credential['refresh_expires_at_estimate'], 'last_refresh_request_id' => (string) ( $response['request_id'] ?? '' ) ) );
		$this->last_diagnostic['stage'] = 'REFRESH_PERSIST';
		if ( false === $wpdb->update( $table, array( 'credential_envelope' => $envelope, 'metadata' => wp_json_encode( $metadata ), 'status' => 'ACTIVE', 'updated_at' => $now ), array( 'id' => $connection_id ) ) ) { $this->set_lifecycle( $connection_id, $metadata, 'REFRESH_UNCERTAIN', $this->last_diagnostic ); $this->fail( 'SHOPEE_REFRESH_PERSIST_FAILED' ); }
		$this->last_diagnostic['stage'] = 'REFRESH_COMPLETE'; return (string) $response['access_token'];
	}
	private function failure_lifecycle( string $classification ): string { if ( in_array( $classification, array( 'SHOPEE_REFRESH_NETWORK_ERROR', 'SHOPEE_REFRESH_HTTP_ERROR', 'SHOPEE_REFRESH_INVALID_JSON', 'SHOPEE_REFRESH_RESPONSE_INVALID', 'SHOPEE_REFRESH_IDENTITY_MISMATCH' ), true ) ) { return 'REFRESH_UNCERTAIN'; } if ( in_array( $classification, array( 'SHOPEE_REFRESH_TOKEN_INVALID', 'SHOPEE_REFRESH_REAUTH_REQUIRED' ), true ) ) { return 'REAUTH_REQUIRED'; } return ''; }
	private function set_lifecycle( int $id, array $metadata, string $state, array $diagnostic = array() ): void { global $wpdb; $this->last_diagnostic['credential_lifecycle'] = $state; $metadata['credential_lifecycle'] = $state; $metadata['last_refresh_diagnostic'] = array_intersect_key( $diagnostic, array_flip( array( 'stage', 'classification', 'provider_error', 'provider_message', 'request_id', 'http_status', 'api_path', 'duration_ms' ) ) ); $wpdb->update( Ecomkit_Vuikhoe_DB::table_names()['marketplace_connections'], array( 'metadata' => wp_json_encode( $metadata ), 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id ) ); }
	private function fail( string $classification ): never { $this->last_diagnostic['classification'] = $classification; throw new RuntimeException( $classification ); }
}
