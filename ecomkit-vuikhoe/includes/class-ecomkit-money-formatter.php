<?php
/** Exact, presentation-only grouping of decimal money strings. */
defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Money_Formatter {
	public static function format_exact( int|string|null $value ): ?string {
		if ( null === $value ) { return null; }
		$text = trim( (string) $value );
		if ( ! preg_match( '/\A(-?)([0-9]+)(\.[0-9]+)?\z/D', $text, $parts ) ) { return null; }
		$whole = preg_replace( '/\B(?=(?:[0-9]{3})+(?![0-9]))/', ',', $parts[2] );
		return $parts[1] . $whole . ( $parts[3] ?? '' );
	}
}
