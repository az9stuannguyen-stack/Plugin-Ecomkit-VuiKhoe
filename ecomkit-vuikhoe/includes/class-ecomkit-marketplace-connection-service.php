<?php
/** Persistence and readiness for per-shop OAuth connections. */
defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Marketplace_Connection_Service {
	public function __construct( private ?Ecomkit_Vuikhoe_Credential_Encryption $encryption = null ) { $this->encryption ??= new Ecomkit_Vuikhoe_Credential_Encryption(); }
	private function aad( string $shop_id ): string { return 'ecomkit|shopee|shop:' . $shop_id; }

	public function upsert_shopee( string $shop_id, array $tokens, string $config_fingerprint ): int {
		global $wpdb;
		$table = Ecomkit_Vuikhoe_DB::table_names()['marketplace_connections'];
		$now = current_time( 'mysql', true );
		$expires = gmdate( 'Y-m-d H:i:s', time() + (int) $tokens['expire_in'] );
		$envelope = $this->encryption->encrypt( array( 'v' => 1, 'access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'], 'access_expires_at' => $expires, 'obtained_at' => $now ), $this->aad( $shop_id ) );
		$metadata = wp_json_encode( array( 'refresh_ownership' => 'ECOMKIT', 'provider_config_fingerprint' => $config_fingerprint, 'access_expires_at' => $expires ) );
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE platform = %s AND external_shop_id = %s", 'SHOPEE', $shop_id ) );
		$data = array( 'platform' => 'SHOPEE', 'external_shop_id' => $shop_id, 'status' => 'ACTIVE', 'credential_source' => 'OAUTH', 'credential_envelope' => $envelope, 'metadata' => $metadata, 'updated_at' => $now );
		if ( $id ) {
			if ( false === $wpdb->update( $table, $data, array( 'id' => $id ) ) ) { throw new RuntimeException( 'SHOPEE_CONNECTION_PERSIST_FAILED' ); }
			return $id;
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
			$row['refresh_ownership'] = $metadata['refresh_ownership'] ?? null;
			$row['credential_ready'] = false;
			try {
				$plain = $this->encryption->decrypt( (string) $row['credential_envelope'], $this->aad( (string) $row['external_shop_id'] ) );
				$row['credential_ready'] = 'ACTIVE' === $row['status'] && 'OAUTH' === $row['credential_source'] && 'ECOMKIT' === $row['refresh_ownership'] && ! empty( $plain['access_token'] ) && ! empty( $plain['refresh_token'] ) && ! empty( $plain['access_expires_at'] ) && hash_equals( (string) ( $metadata['provider_config_fingerprint'] ?? '' ), $current_fingerprint );
			} catch ( Throwable $exception ) { $row['credential_ready'] = false; }
		}
		unset( $row );
		return $rows ?: array();
	}
}
