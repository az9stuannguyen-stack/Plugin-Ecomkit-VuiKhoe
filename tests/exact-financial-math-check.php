<?php
/** WP.6H exact decimal and rational regression, no provider calls. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/wordpress-placeholder/' );
require __DIR__ . '/../ecomkit-vuikhoe/vendor/autoload.php';
function exact_check( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }
$math = Ecomkit_Vuikhoe_Exact_Financial_Math::class;
exact_check( '83854' === $math::add( $math::add( $math::add( $math::add( '50265', '3000' ), '18481' ), '0' ), '12108' ), 'Exact sample A sum failed.' );
exact_check( '224146' === $math::subtract( '308000', '83854' ) && '227095' === $math::subtract( '312000', '84905' ), 'Legacy receivable subtraction failed.' );
exact_check( '99000.25' === $math::subtract( '100000.50', '1000.25' ) && '1000.35' === $math::add( '1000.25', '0.10' ), 'Decimal money arithmetic rounded.' );
exact_check( '-1000.25' === $math::subtract( '0', '1000.25' ) && '-999.75' === $math::add( '-1000.25', '0.50' ), 'Signed decimal arithmetic failed.' );
exact_check( '1000000000000000000000000000000' === $math::add( '999999999999999999999999999999', '1' ), 'Large integer carry used native overflow.' );
foreach ( range( -20, 20 ) as $a ) { foreach ( range( -20, 20 ) as $b ) { exact_check( (string) ( $a + $b ) === $math::add( (string) $a, (string) $b ) && (string) ( $a - $b ) === $math::subtract( (string) $a, (string) $b ) && ( $a <=> $b ) === $math::compare( (string) $a, (string) $b ), 'Signed integer exhaustive check failed.' ); } }
exact_check( $math::compare( '-1000.25', '-999.75' ) < 0 && $math::compare( '1000.250', '1000.25' ) === 0 && $math::is_zero( '-0.0000' ), 'Compare/zero failed.' );
$third = $math::ratio( '1', '3' );
exact_check( '1' === $third['numerator'] && '3' === $third['denominator'] && '0.333333333333333333' === $third['ratio'] && ! $third['decimal_exact'], 'Repeating 1/3 lost exact rational evidence.' );
exact_check( '33.333333333333…%' === $math::format_percent( $third ), 'Percent display rounded or lost truncation marker.' );
$ratio = $math::ratio( '393', '10000' );
exact_check( '0.0393' === $ratio['ratio'] && $ratio['decimal_exact'] && '3.93%' === $math::format_percent( $ratio ), 'Ratio was multiplied by 100 twice.' );
exact_check( '-12.5%' === $math::format_percent( $math::ratio( '-1', '8' ) ), 'Signed percent display failed.' );
foreach ( array( array( '1234', '100000', '1.23%' ), array( '1235', '100000', '1.24%' ), array( '1236', '100000', '1.24%' ), array( '0', '1', '0.00%' ), array( '1', '1', '100.00%' ), array( '17160', '312000', '5.50%' ), array( '75900', '312000', '24.33%' ), array( '93060', '312000', '29.83%' ), array( '-1235', '100000', '-1.24%' ) ) as [$numerator, $denominator, $display] ) {
	exact_check( $display === $math::format_percent_two_decimals( $math::ratio( $numerator, $denominator ) ), 'Two-decimal HALF-UP percentage failed: ' . $display );
}
exact_check( null === $math::format_percent_two_decimals( array() ), 'Missing percentage did not remain NULL.' );
try { $math::ratio( '1', '0' ); throw new RuntimeException( 'Zero divisor was accepted.' ); } catch ( InvalidArgumentException $exception ) { exact_check( 'EXACT_DECIMAL_ZERO_DIVISOR' === $exception->getMessage(), 'Zero divisor classification changed.' ); }
try { $math::add( '1.1e3', '1' ); throw new RuntimeException( 'Scientific notation was accepted.' ); } catch ( InvalidArgumentException ) {}
echo "WP.6H exact financial math: PASS\n";
