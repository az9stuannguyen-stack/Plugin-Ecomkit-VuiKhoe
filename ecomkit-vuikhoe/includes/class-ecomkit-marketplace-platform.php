<?php
defined( 'ABSPATH' ) || exit;
final class Ecomkit_Vuikhoe_Marketplace_Platform {
	public const SHOPEE = 'SHOPEE';
	public const LAZADA = 'LAZADA';
	public const TIKTOK = 'TIKTOK';
	public static function validate( string $platform ): string {
		if ( ! in_array( $platform, array( self::SHOPEE, self::LAZADA, self::TIKTOK ), true ) ) { throw new InvalidArgumentException( 'ECOMKIT_PLATFORM_UNSUPPORTED' ); }
		return $platform;
	}
}
