<?php
defined( 'ABSPATH' ) || exit;
/** Provider-scoped MySQL lock; does not touch Shopee locking conventions. */
final class Ecomkit_Vuikhoe_Lazada_Lock {
	public function synchronized( string $scope, callable $callback ): mixed {
		global $wpdb;
		$name = 'ecomkit_lazada_' . substr( hash( 'sha256', $scope ), 0, 40 );
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 2 ) ) ) { throw new RuntimeException( 'LAZADA_LOCK_UNAVAILABLE' ); }
		try { return $callback(); }
		finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); }
	}
}
