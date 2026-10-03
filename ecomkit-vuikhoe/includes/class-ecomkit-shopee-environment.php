<?php
/** Central Shopee environment resolver. */
defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Shopee_Environment {
	private const HOSTS = array( 'sandbox' => 'https://partner.test-stable.shopeemobile.com', 'production' => 'https://partner.shopeemobile.com' );
	public static function host( string $environment ): string {
		if ( ! isset( self::HOSTS[ $environment ] ) ) { throw new InvalidArgumentException( 'SHOPEE_ENVIRONMENT_INVALID' ); }
		return self::HOSTS[ $environment ];
	}
	public static function authorization_url( string $environment ): string { return self::host( $environment ) . '/api/v2/shop/auth_partner'; }
	public static function api_url( string $environment, string $path ): string { return self::host( $environment ) . $path; }
}
