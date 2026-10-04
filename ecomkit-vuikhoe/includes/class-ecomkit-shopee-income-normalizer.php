<?php
/** Pure, source-only Shopee Income record normalization. */
defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Shopee_Income_Normalizer {
	public const SOURCE_KEYS = array( 'order_sn', 'description', 'status', 'currency', 'payment_method', 'estimated_escrow_amount', 'estimated_payout_time', 'to_release_amount', 'creation_date', 'released_amount', 'actual_payout_time' );

	public static function normalize( array $item, string $bucket ): array {
		$map = array(
			'marketplaceOrderId' => 'order_sn', 'providerStatus' => 'status', 'currency' => 'currency',
			'paymentMethod' => 'payment_method', 'description' => 'description',
			'estimatedEscrowAmount' => 'estimated_escrow_amount', 'releasedAmount' => 'released_amount',
			'toReleaseAmount' => 'to_release_amount',
		);
		$result = array( 'incomeBucket' => $bucket );
		foreach ( $map as $target => $source ) { $result[ $target ] = array_key_exists( $source, $item ) && ( is_scalar( $item[ $source ] ) || null === $item[ $source ] ) ? $item[ $source ] : null; }
		foreach ( array( 'estimatedPayoutTime' => 'estimated_payout_time', 'actualPayoutTime' => 'actual_payout_time', 'creationDate' => 'creation_date' ) as $target => $source ) {
			$value = $item[ $source ] ?? null;
			$result[ $target ] = ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) && (int) $value > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $value ) : null;
		}
		return $result;
	}

	public static function minimized_raw( array $item ): array {
		return array_intersect_key( $item, array_flip( self::SOURCE_KEYS ) );
	}
}
