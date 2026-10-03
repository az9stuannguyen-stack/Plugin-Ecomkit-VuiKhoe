<?php
/** Serializes mutations of one MarketplaceConnection credential pair. */
defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Credential_Mutation_Lock {
	private const WAIT_SECONDS = 2;
	public function synchronized( int $connection_id, callable $callback ): mixed {
		global $wpdb;
		if ( $connection_id < 1 ) { throw new RuntimeException( 'ECOMKIT_CREDENTIAL_LOCK_INVALID' ); }
		$name = 'ecomkit_shopee_credential_' . $connection_id;
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::WAIT_SECONDS ) );
		if ( 1 !== (int) $acquired ) { throw new RuntimeException( 'ECOMKIT_CREDENTIAL_LOCK_UNAVAILABLE' ); }
		try { return $callback(); }
		finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); }
	}
}
