<?php
/** Shopee Open Platform V2 public request signer. */
defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Shopee_Signer {
	public static function sign( string $partner_id, string $path, int $timestamp, string $partner_key ): string {
		return hash_hmac( 'sha256', $partner_id . $path . (string) $timestamp, $partner_key );
	}
	public static function sign_shop( string $partner_id, string $path, int $timestamp, string $access_token, string $shop_id, string $partner_key ): string {
		return hash_hmac( 'sha256', $partner_id . $path . (string) $timestamp . $access_token . $shop_id, $partner_key );
	}
}
