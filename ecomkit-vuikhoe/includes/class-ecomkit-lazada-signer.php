<?php
defined( 'ABSPATH' ) || exit;
final class Ecomkit_Vuikhoe_Lazada_Signer {
	/** Text parameters only. Reject binary/structured inputs instead of accidentally signing them. */
	public static function sign( string $path, array $parameters, string $secret ): string {
		if ( '' === $secret || 1 !== preg_match( '~^/[a-zA-Z0-9/_]+$~D', $path ) ) { throw new InvalidArgumentException( 'LAZADA_SIGNATURE_INPUT_INVALID' ); }
		unset( $parameters['sign'] );
		ksort( $parameters, SORT_STRING );
		$input = $path;
		foreach ( $parameters as $key => $value ) {
			if ( ! is_string( $key ) || 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $key ) || ( ! is_string( $value ) && ! is_int( $value ) ) || str_contains( (string) $value, "\0" ) || ! preg_match( '//u', (string) $value ) ) { throw new InvalidArgumentException( 'LAZADA_SIGNATURE_INPUT_INVALID' ); }
			$input .= $key . $value;
		}
		return strtoupper( hash_hmac( 'sha256', $input, $secret ) );
	}
}
