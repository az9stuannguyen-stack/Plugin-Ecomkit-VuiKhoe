<?php
/** Inert boundary only. WP.6L.1 must never contact TikTok production. */
defined( 'ABSPATH' ) || exit;
final class Ecomkit_Vuikhoe_Tiktok_Http_Client {
	public const RESERVED_ERRORS = array( 'TIKTOK_AUTH_REQUIRED', 'TIKTOK_HTTP_ERROR', 'TIKTOK_PROVIDER_ERROR', 'TIKTOK_INVALID_RESPONSE', 'TIKTOK_NETWORK_ERROR' );
	public function request(): never { throw new LogicException( 'TIKTOK_CLIENT_NOT_IMPLEMENTED' ); }
}
