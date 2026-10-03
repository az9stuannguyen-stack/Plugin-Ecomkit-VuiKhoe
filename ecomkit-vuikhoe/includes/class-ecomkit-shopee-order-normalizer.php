<?php
/** Pure, provider-neutral projection of one validated Shopee Order Detail object. */

defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Shopee_Order_Normalizer {
	/** @param array<string,mixed> $order @return array<string,mixed> */
	public static function normalize( array $order ): array {
		$order_sn = isset( $order['order_sn'] ) && is_string( $order['order_sn'] ) ? $order['order_sn'] : '';
		if ( '' === $order_sn ) {
			throw new InvalidArgumentException( 'SHOPEE_RECON_PROVIDER_IDENTITY_MISMATCH' );
		}

		$address = is_array( $order['recipient_address'] ?? null ) ? $order['recipient_address'] : array();
		$items = array();
		foreach ( is_array( $order['item_list'] ?? null ) ? $order['item_list'] : array() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$items[] = array(
				'itemId'            => self::source_value( $item, 'item_id' ),
				'itemName'          => self::source_value( $item, 'item_name' ),
				'itemSku'           => self::source_value( $item, 'item_sku' ),
				'modelId'           => self::source_value( $item, 'model_id' ),
				'modelName'         => self::source_value( $item, 'model_name' ),
				'modelSku'          => self::source_value( $item, 'model_sku' ),
				'quantityPurchased' => self::source_value( $item, 'model_quantity_purchased' ),
				'originalPrice'     => self::source_value( $item, 'model_original_price' ),
				'discountedPrice'   => self::source_value( $item, 'model_discounted_price' ),
			);
		}

		return array(
			'platform'              => 'SHOPEE',
			'marketplaceOrderId'    => $order_sn,
			'rawOrderCode'          => $order_sn,
			'providerStatus'        => self::source_value( $order, 'order_status' ),
			'providerCreatedAt'     => self::provider_time( $order['create_time'] ?? null ),
			'providerUpdatedAt'     => self::provider_time( $order['update_time'] ?? null ),
			'buyerUsername'         => self::source_value( $order, 'buyer_username' ),
			'recipientName'         => self::source_value( $address, 'name' ),
			'recipientPhone'        => self::source_value( $address, 'phone' ),
			'recipientFullAddress'  => self::source_value( $address, 'full_address' ),
			'recipientRegion'       => self::source_value( $address, 'region' ),
			'recipientState'        => self::source_value( $address, 'state' ),
			'recipientCity'         => self::source_value( $address, 'city' ),
			'recipientDistrict'     => self::source_value( $address, 'district' ),
			'recipientZipcode'      => self::source_value( $address, 'zipcode' ),
			'items'                 => $items,
			'totalAmount'           => self::source_value( $order, 'total_amount' ),
			'estimatedShippingFee'  => self::source_value( $order, 'estimated_shipping_fee' ),
			'actualShippingFee'     => self::source_value( $order, 'actual_shipping_fee' ),
			'escrowAmount'          => self::source_value( $order, 'escrow_amount' ),
		);
	}

	/** @param array<string,mixed> $source */
	private static function source_value( array $source, string $key ): mixed {
		return array_key_exists( $key, $source ) && ( is_scalar( $source[ $key ] ) || null === $source[ $key ] ) ? $source[ $key ] : null;
	}

	private static function provider_time( mixed $value ): ?string {
		if ( ! is_int( $value ) && ! ( is_string( $value ) && 1 === preg_match( '/^[0-9]+$/', $value ) ) ) {
			return null;
		}
		$timestamp = (int) $value;
		return $timestamp > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ) : null;
	}
}
