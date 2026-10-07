<?php
defined( 'ABSPATH' ) || exit;
/** Existing named-lock pattern, isolated to TikTok state/shop mutations. */
final class Ecomkit_Vuikhoe_Tiktok_Lock {
	public function synchronized( string $scope, callable $callback ): mixed {
		global $wpdb; $name = 'ecomkit_tiktok_' . substr( hash( 'sha256', $scope ), 0, 40 );
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 2 ) ) ) { throw new RuntimeException( 'TIKTOK_LOCK_UNAVAILABLE' ); }
		try { return $callback(); } finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); }
	}
}
