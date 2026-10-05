<?php
defined( 'ABSPATH' ) || exit;
/** In-memory provider DTOs only; PII belongs to these records, NEVER diagnostics/logs. */
final class Ecomkit_Vuikhoe_Lazada_Order_Normalizer {
	public static function id( mixed $value ): string {
		if ( is_int( $value ) ) { $value = (string) $value; }
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[0-9]{1,40}$/D', $value ) || 0 === preg_match( '/[1-9]/', $value ) ) { throw new RuntimeException( 'LAZADA_ORDER_INVALID_RESPONSE' ); }
		return $value;
	}
	private static function text( mixed $value ): ?string {
		if ( null === $value ) { return null; }
		if ( ! is_string( $value ) || strlen( $value ) > 8192 || ! preg_match( '//u', $value ) ) { throw new RuntimeException( 'LAZADA_ORDER_INVALID_RESPONSE' ); }
		return $value;
	}
	private static function money( mixed $value ): ?string {
		if ( null === $value ) { return null; }
		if ( is_int( $value ) ) { $value = (string) $value; }
		if ( ! is_string( $value ) || 1 !== preg_match( '/^-?[0-9]{1,40}(?:\.[0-9]{1,20})?$/D', $value ) ) { throw new RuntimeException( 'LAZADA_ORDER_INVALID_RESPONSE' ); }
		return $value; // No float, scaling, rounding or financial derivation.
	}
	public static function order( array $raw, int $connection ): array {
		$statuses = $raw['statuses'] ?? array();
		if ( ! is_array( $statuses ) || ! array_is_list( $statuses ) ) { throw new RuntimeException( 'LAZADA_ORDER_INVALID_RESPONSE' ); }
		foreach ( $statuses as $status ) { if ( ! is_string( $status ) ) { throw new RuntimeException( 'LAZADA_ORDER_INVALID_RESPONSE' ); } }
		$address = $raw['address_shipping'] ?? null;
		if ( null !== $address && ! is_array( $address ) ) { throw new RuntimeException( 'LAZADA_ORDER_INVALID_RESPONSE' ); }
		$safe_address = null;
		if ( null !== $address ) { $safe_address = array(); foreach ( array( 'first_name', 'last_name', 'phone', 'phone2', 'country', 'address1', 'address2', 'address3', 'address4', 'address5' ) as $field ) { if ( array_key_exists( $field, $address ) ) { $safe_address[$field] = self::text( $address[$field] ); } } }
		return array( 'providerOrderId' => self::id( $raw['order_id'] ?? null ), 'connectionId' => $connection, 'createdAt' => self::text( $raw['created_at'] ?? null ), 'updatedAt' => self::text( $raw['updated_at'] ?? null ), 'rawStatuses' => $statuses, 'addressShipping' => $safe_address, 'price' => self::money( $raw['price'] ?? null ), 'voucher' => self::money( $raw['voucher'] ?? null ), 'shippingFee' => self::money( $raw['shipping_fee'] ?? null ), 'rawSafeMetadata' => array( 'platform' => 'LAZADA' ) );
	}
	public static function item( array $raw, int $connection, string $order ): array {
		$id = self::id( $raw['order_id'] ?? null );
		if ( $order !== $id ) { throw new RuntimeException( 'LAZADA_ORDER_INVALID_RESPONSE' ); }
		return array( 'orderItemId' => self::id( $raw['order_item_id'] ?? null ), 'providerOrderId' => $id, 'connectionId' => $connection, 'sku' => self::text( $raw['sku'] ?? null ), 'status' => self::text( $raw['status'] ?? null ), 'name' => self::text( $raw['name'] ?? null ), 'paidPrice' => self::money( $raw['paid_price'] ?? null ), 'itemPrice' => self::money( $raw['item_price'] ?? null ), 'rawSafeMetadata' => array( 'platform' => 'LAZADA' ) );
	}
}
