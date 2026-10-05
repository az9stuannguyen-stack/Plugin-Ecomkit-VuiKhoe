<?php
/** Persistence and readiness for per-shop OAuth connections. */
defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Marketplace_Connection_Service {
	/** Safe cross-platform lookup; never returns encrypted credentials to views. */
	public function list_platform( string $platform ): array {
		global $wpdb;
		Ecomkit_Vuikhoe_Marketplace_Platform::validate( $platform );
		$table = Ecomkit_Vuikhoe_DB::table_names()['marketplace_connections'];
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, platform, external_shop_id, status, credential_source, updated_at FROM $table WHERE platform = %s ORDER BY id ASC", $platform ), ARRAY_A );
	}
	/** Reserve an actual known account identity only; never implies authorization. */
	public function create_pending( string $platform, string $external_shop_id ): int {
		global $wpdb;
		Ecomkit_Vuikhoe_Marketplace_Platform::validate( $platform );
		if ( '' === $external_shop_id || strlen( $external_shop_id ) > 191 || preg_match( '/[\x00-\x20\x7F]/', $external_shop_id ) ) { throw new InvalidArgumentException( 'ECOMKIT_SHOP_ID_INVALID' ); }
		$table = Ecomkit_Vuikhoe_DB::table_names()['marketplace_connections'];
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE platform = %s AND external_shop_id = %s", $platform, $external_shop_id ) );
		if ( $id ) { return $id; }
		$now = current_time( 'mysql', true );
		if ( false === $wpdb->insert( $table, array( 'platform' => $platform, 'external_shop_id' => $external_shop_id, 'status' => 'PENDING_AUTH', 'credential_source' => null, 'credential_envelope' => null, 'metadata' => wp_json_encode( array( 'v' => 1, 'credential_lifecycle' => 'PENDING_AUTH' ) ), 'created_at' => $now, 'updated_at' => $now ) ) ) { throw new RuntimeException( 'ECOMKIT_CONNECTION_PERSIST_FAILED' ); }
		return (int) $wpdb->insert_id;
	}
	public function __construct( private ?Ecomkit_Vuikhoe_Credential_Encryption $encryption = null, private ?Ecomkit_Vuikhoe_Credential_Mutation_Lock $lock = null ) { $this->encryption ??= new Ecomkit_Vuikhoe_Credential_Encryption(); $this->lock ??= new Ecomkit_Vuikhoe_Credential_Mutation_Lock(); }
	private function aad( string $shop_id ): string { return 'ecomkit|shopee|shop:' . $shop_id; }

	public function upsert_shopee( string $shop_id, array $tokens, string $config_fingerprint ): int {
		global $wpdb;
		$table = Ecomkit_Vuikhoe_DB::table_names()['marketplace_connections'];
		$now = current_time( 'mysql', true );
		$expires = gmdate( 'Y-m-d H:i:s', time() + (int) $tokens['expire_in'] );
		try {
			$envelope = $this->encryption->encrypt( array( 'v' => 1, 'access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'], 'access_expires_at' => $expires, 'obtained_at' => $now, 'refresh_obtained_at' => $now, 'refresh_expires_at_estimate' => gmdate( 'Y-m-d H:i:s', time() + 2592000 ) ), $this->aad( $shop_id ) );
		} catch ( Throwable $exception ) {
			throw new RuntimeException( 'SHOPEE_TOKEN_ENCRYPT_FAILED', 0, $exception );
		}
		$metadata = wp_json_encode( array( 'refresh_ownership' => 'ECOMKIT', 'provider_config_fingerprint' => $config_fingerprint, 'credential_lifecycle' => 'READY', 'access_expires_at' => $expires, 'last_refresh_at' => null, 'refresh_expires_at_estimate' => gmdate( 'Y-m-d H:i:s', time() + 2592000 ) ) );
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE platform = %s AND external_shop_id = %s", 'SHOPEE', $shop_id ) );
		$data = array( 'platform' => 'SHOPEE', 'external_shop_id' => $shop_id, 'status' => 'ACTIVE', 'credential_source' => 'OAUTH', 'credential_envelope' => $envelope, 'metadata' => $metadata, 'updated_at' => $now );
		if ( $id ) {
			return $this->lock->synchronized( $id, function () use ( $wpdb, $table, $data, $id ): int { if ( false === $wpdb->update( $table, $data, array( 'id' => $id ) ) ) { throw new RuntimeException( 'SHOPEE_CONNECTION_PERSIST_FAILED' ); } return $id; } );
		}
		$data['created_at'] = $now;
		if ( false === $wpdb->insert( $table, $data ) ) { throw new RuntimeException( 'SHOPEE_CONNECTION_PERSIST_FAILED' ); }
		return (int) $wpdb->insert_id;
	}

	/** @return array<int,array<string,mixed>> */
	public function list_shopee( string $current_fingerprint ): array {
		global $wpdb;
		$table = Ecomkit_Vuikhoe_DB::table_names()['marketplace_connections'];
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, external_shop_id, status, credential_source, credential_envelope, metadata, updated_at FROM $table WHERE platform = %s ORDER BY id ASC", 'SHOPEE' ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		foreach ( $rows as &$row ) {
			$metadata = json_decode( (string) $row['metadata'], true ) ?: array();
			$row['access_expires_at'] = $metadata['access_expires_at'] ?? null;
			$row['last_refresh_at'] = $metadata['last_refresh_at'] ?? null;
			$row['refresh_expires_at_estimate'] = $metadata['refresh_expires_at_estimate'] ?? null;
			$row['refresh_ownership'] = $metadata['refresh_ownership'] ?? null;
			$row['credential_lifecycle'] = $metadata['credential_lifecycle'] ?? 'READY';
			$row['last_refresh_diagnostic'] = $metadata['last_refresh_diagnostic'] ?? array();
			$row['credential_ready'] = false;
			try {
				$plain = $this->encryption->decrypt( (string) $row['credential_envelope'], $this->aad( (string) $row['external_shop_id'] ) );
				$row['credential_ready'] = 'ACTIVE' === $row['status'] && 'READY' === $row['credential_lifecycle'] && 'OAUTH' === $row['credential_source'] && 'ECOMKIT' === $row['refresh_ownership'] && ! empty( $plain['access_token'] ) && ! empty( $plain['refresh_token'] ) && ! empty( $plain['access_expires_at'] ) && hash_equals( (string) ( $metadata['provider_config_fingerprint'] ?? '' ), $current_fingerprint );
			} catch ( Throwable $exception ) { $row['credential_ready'] = false; }
		}
		unset( $row );
		return $rows ?: array();
	}
}
