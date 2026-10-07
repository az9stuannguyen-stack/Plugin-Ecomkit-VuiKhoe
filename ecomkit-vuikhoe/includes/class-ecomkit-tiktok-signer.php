<?php
defined( 'ABSPATH' ) || exit;
/** TikTok-only signature. Body is the exact transmitted byte string. */
final class Ecomkit_Vuikhoe_Tiktok_Signer {
	public static function sign( string $path, array $query, string $secret, string $body = '', string $content_type = 'application/json' ): string {
		if ( '' === $secret || ! str_starts_with( $path, '/' ) || str_contains( $path, '?' ) ) { throw new InvalidArgumentException( 'TIKTOK_SIGN_INPUT_INVALID' ); }
		unset( $query['sign'], $query['access_token'] ); ksort( $query, SORT_STRING ); $input = $path;
		foreach ( $query as $name => $value ) {
			if ( ! is_string( $name ) || ( ! is_string( $value ) && ! is_int( $value ) ) ) { throw new InvalidArgumentException( 'TIKTOK_SIGN_INPUT_INVALID' ); }
			$input .= $name . (string) $value;
		}
		if ( 'multipart/form-data' !== strtolower( trim( explode( ';', $content_type )[0] ) ) ) { $input .= $body; }
		return hash_hmac( 'sha256', $secret . $input . $secret, $secret );
	}
}
