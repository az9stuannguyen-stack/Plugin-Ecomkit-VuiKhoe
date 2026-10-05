<?php
/** Exact signed decimal arithmetic; no optional PHP extension or binary float. */
defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Exact_Financial_Math {
	public static function normalize( int|string $value ): string {
		$text = trim( (string) $value );
		if ( 1 !== preg_match( '/\A([+-]?)([0-9]+)(?:\.([0-9]+))?\z/D', $text, $match ) ) { throw new InvalidArgumentException( 'EXACT_DECIMAL_INVALID' ); }
		$whole = ltrim( $match[2], '0' );
		$whole = '' === $whole ? '0' : $whole;
		$fraction = rtrim( $match[3] ?? '', '0' );
		$sign = '-' === $match[1] && ( '0' !== $whole || '' !== $fraction ) ? '-' : '';
		return $sign . $whole . ( '' === $fraction ? '' : '.' . $fraction );
	}

	public static function add( int|string $left, int|string $right ): string {
		[$a, $b, $scale] = self::aligned( $left, $right );
		$negative_a = str_starts_with( $a, '-' ); $negative_b = str_starts_with( $b, '-' );
		$a = ltrim( $a, '-' ); $b = ltrim( $b, '-' );
		if ( $negative_a === $negative_b ) { $digits = self::add_unsigned( $a, $b ); $negative = $negative_a; }
		else {
			$comparison = self::compare_unsigned( $a, $b );
			$digits = $comparison >= 0 ? self::subtract_unsigned( $a, $b ) : self::subtract_unsigned( $b, $a );
			$negative = $comparison >= 0 ? $negative_a : $negative_b;
		}
		return self::unscale( ( $negative && '0' !== $digits ? '-' : '' ) . $digits, $scale );
	}

	public static function subtract( int|string $left, int|string $right ): string {
		$right = self::normalize( $right );
		return self::add( $left, str_starts_with( $right, '-' ) ? substr( $right, 1 ) : '-' . $right );
	}

	public static function compare( int|string $left, int|string $right ): int {
		[$a, $b] = self::aligned( $left, $right );
		$negative_a = str_starts_with( $a, '-' ); $negative_b = str_starts_with( $b, '-' );
		if ( $negative_a !== $negative_b ) { return $negative_a ? -1 : 1; }
		$comparison = self::compare_unsigned( ltrim( $a, '-' ), ltrim( $b, '-' ) );
		return $negative_a ? -$comparison : $comparison;
	}

	public static function is_zero( int|string $value ): bool { return '0' === self::normalize( $value ); }

	/** Decimal prefix is truncated, never rounded; exact rational remains in numerator/denominator. */
	public static function ratio( int|string $numerator, int|string $denominator, int $digits = 18 ): array {
		if ( $digits < 0 || $digits > 100 ) { throw new InvalidArgumentException( 'EXACT_DECIMAL_PRECISION_INVALID' ); }
		[$a, $b] = self::aligned( $numerator, $denominator );
		if ( '0' === ltrim( $b, '-' ) ) { throw new InvalidArgumentException( 'EXACT_DECIMAL_ZERO_DIVISOR' ); }
		$negative = str_starts_with( $a, '-' ) !== str_starts_with( $b, '-' );
		$a = ltrim( $a, '-' ); $b = ltrim( $b, '-' );
		[$whole, $remainder] = self::divide_unsigned( $a, $b );
		$fraction = '';
		for ( $index = 0; $index < $digits && '0' !== $remainder; $index++ ) {
			[$digit, $remainder] = self::divide_unsigned( $remainder . '0', $b );
			$fraction .= $digit;
		}
		$fraction = rtrim( $fraction, '0' );
		$decimal = ( $negative && ( '0' !== $whole || '' !== $fraction || '0' !== $remainder ) ? '-' : '' ) . $whole . ( '' === $fraction ? '' : '.' . $fraction );
		return array( 'ratio' => $decimal, 'numerator' => self::normalize( $numerator ), 'denominator' => self::normalize( $denominator ), 'decimal_exact' => '0' === $remainder, 'decimal_digits' => $digits );
	}

	/** Display only: exact ratio × 100, then truncate at fixed decimal places. */
	public static function format_percent( array $rational, int $digits = 12 ): ?string {
		if ( ! isset( $rational['numerator'], $rational['denominator'] ) ) { return null; }
		try {
			$percent = self::ratio( self::shift_hundred( self::normalize( $rational['numerator'] ) ), self::normalize( $rational['denominator'] ), $digits );
			return $percent['ratio'] . ( $percent['decimal_exact'] ? '' : '…' ) . '%';
		} catch ( InvalidArgumentException ) { return null; }
	}

	private static function shift_hundred( string $value ): string {
		$negative = str_starts_with( $value, '-' ); $parts = explode( '.', ltrim( $value, '-' ), 2 );
		$fraction = $parts[1] ?? '';
		$digits = $parts[0] . $fraction . '00';
		return self::unscale( ( $negative ? '-' : '' ) . $digits, strlen( $fraction ) );
	}

	private static function aligned( int|string $left, int|string $right ): array {
		$left = self::normalize( $left ); $right = self::normalize( $right );
		$a = explode( '.', $left, 2 ); $b = explode( '.', $right, 2 );
		$scale = max( strlen( $a[1] ?? '' ), strlen( $b[1] ?? '' ) );
		return array( self::scaled( $left, $scale ), self::scaled( $right, $scale ), $scale );
	}

	private static function scaled( string $value, int $scale ): string {
		$negative = str_starts_with( $value, '-' ); $parts = explode( '.', ltrim( $value, '-' ), 2 );
		$digits = self::strip_zeroes( $parts[0] . str_pad( $parts[1] ?? '', $scale, '0' ) );
		return $negative && '0' !== $digits ? '-' . $digits : $digits;
	}

	private static function unscale( string $digits, int $scale ): string {
		$negative = str_starts_with( $digits, '-' ); $digits = str_pad( ltrim( $digits, '-' ), $scale + 1, '0', STR_PAD_LEFT );
		$whole = 0 === $scale ? $digits : substr( $digits, 0, -$scale );
		$fraction = 0 === $scale ? '' : rtrim( substr( $digits, -$scale ), '0' );
		return self::normalize( ( $negative ? '-' : '' ) . $whole . ( '' === $fraction ? '' : '.' . $fraction ) );
	}

	private static function strip_zeroes( string $digits ): string { $digits = ltrim( $digits, '0' ); return '' === $digits ? '0' : $digits; }
	private static function compare_unsigned( string $a, string $b ): int { $a = self::strip_zeroes( $a ); $b = self::strip_zeroes( $b ); return strlen( $a ) <=> strlen( $b ) ?: strcmp( $a, $b ) <=> 0; }
	private static function add_unsigned( string $a, string $b ): string {
		$a = strrev( $a ); $b = strrev( $b ); $carry = 0; $result = '';
		for ( $index = 0, $size = max( strlen( $a ), strlen( $b ) ); $index < $size || $carry; $index++ ) { $sum = (int) ( $a[ $index ] ?? 0 ) + (int) ( $b[ $index ] ?? 0 ) + $carry; $result .= (string) ( $sum % 10 ); $carry = intdiv( $sum, 10 ); }
		return self::strip_zeroes( strrev( $result ) );
	}
	private static function subtract_unsigned( string $a, string $b ): string {
		$a = strrev( $a ); $b = strrev( $b ); $borrow = 0; $result = '';
		for ( $index = 0, $size = strlen( $a ); $index < $size; $index++ ) { $digit = (int) $a[ $index ] - (int) ( $b[ $index ] ?? 0 ) - $borrow; $borrow = $digit < 0 ? 1 : 0; $result .= (string) ( $digit < 0 ? $digit + 10 : $digit ); }
		return self::strip_zeroes( strrev( $result ) );
	}
	private static function divide_unsigned( string $a, string $b ): array {
		$a = self::strip_zeroes( $a ); $b = self::strip_zeroes( $b ); $remainder = '0'; $quotient = '';
		for ( $index = 0, $length = strlen( $a ); $index < $length; $index++ ) {
			$remainder = self::strip_zeroes( $remainder . $a[ $index ] ); $digit = 0;
			while ( self::compare_unsigned( $remainder, $b ) >= 0 ) { $remainder = self::subtract_unsigned( $remainder, $b ); $digit++; }
			$quotient .= (string) $digit;
		}
		return array( self::strip_zeroes( $quotient ), $remainder );
	}
}
