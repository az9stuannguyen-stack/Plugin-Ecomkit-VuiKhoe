<?php
/** Pure deterministic projection from persisted Order evidence to canonical v1. */

defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Canonical_Result_Materializer {
	public function __construct( private ?DateTimeZone $timezone = null ) {
		$this->timezone ??= new DateTimeZone( 'UTC' );
	}

	/** @param array<string,mixed> $order @param array<int,array<string,mixed>> $items @return array<string,mixed> */
	public function materialize( array $order, array $items = array() ): array {
		$columns = Ecomkit_Vuikhoe_Canonical_Columns::all();
		if ( 24 !== count( $columns ) || 24 !== count( array_unique( array_column( $columns, 'key' ) ) ) ) {
			throw new RuntimeException( 'CANONICAL_MAPPING_CONTRACT_INVALID' );
		}
		$provider = $this->decode_provider( $order['provider_normalized_data'] ?? null );
		$matched = 'SHOPEE' === (string) ( $order['platform'] ?? '' ) && 'MATCHED' === (string) ( $order['matching_status'] ?? '' );
		$values = array_fill_keys( array_column( $columns, 'key' ), null );
		$values['order_date'] = $this->date_value( $order['order_date'] ?? null, 'UTC' );
		if ( null === $values['order_date'] && $matched ) {
			$values['order_date'] = $this->date_value( $provider['providerCreatedAt'] ?? null, 'ISO' );
		}
		$values['raw_order_code'] = $this->scalar( $order['raw_order_code'] ?? $order['marketplace_order_id'] ?? null );
		$values['sales_channel'] = $this->scalar( $order['platform'] ?? null );
		if ( $matched ) {
			$values['raw_order_code'] ??= $this->scalar( $provider['rawOrderCode'] ?? $provider['marketplaceOrderId'] ?? null );
			$values['sales_channel'] ??= $this->scalar( $provider['platform'] ?? null );
			$values['order_status'] = $this->scalar( $provider['providerStatus'] ?? null );
			$values['customer_name'] = $this->scalar( $provider['recipientName'] ?? null );
			$values['phone'] = $this->scalar( $provider['recipientPhone'] ?? null );
			$values['address'] = $this->scalar( $provider['recipientFullAddress'] ?? null );
			foreach ( array( 'recipientState', 'recipientCity', 'recipientRegion' ) as $key ) {
				$value = $this->scalar( $provider[ $key ] ?? null );
				if ( null !== $value ) { $values['province_city'] = $value; break; }
			}
		}
		foreach ( $values as $value ) {
			if ( null !== $value && ! is_scalar( $value ) ) { throw new RuntimeException( 'CANONICAL_SOURCE_INVALID' ); }
		}
		return array( 'version' => Ecomkit_Vuikhoe_Canonical_Columns::VERSION, 'columns' => $values );
	}

	/** @param array<string,mixed> $order @param array<int,array<string,mixed>> $items */
	public function fingerprint( array $order, array $items = array() ): string {
		$source = array(
			'version' => Ecomkit_Vuikhoe_Canonical_Columns::VERSION,
			'order_date' => $order['order_date'] ?? null, 'raw_order_code' => $order['raw_order_code'] ?? null,
			'platform' => $order['platform'] ?? null, 'matching_status' => $order['matching_status'] ?? null,
			'provider_normalized_data' => $this->decode_provider( $order['provider_normalized_data'] ?? null),
			'items' => array_map( static fn( array $item ): array => array_intersect_key( $item, array_flip( array( 'id', 'sku', 'product_name', 'quantity', 'price', 'variant' ) ) ), $items ),
		);
		return hash( 'sha256', (string) wp_json_encode( $source, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ) );
	}

	/** @return array<string,mixed> */
	private function decode_provider( mixed $value ): array {
		if ( is_array( $value ) ) { return $value; }
		if ( ! is_string( $value ) || '' === $value ) { return array(); }
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private function scalar( mixed $value ): mixed {
		if ( null === $value || ! is_scalar( $value ) ) { return null; }
		return is_string( $value ) && '' === trim( $value ) ? null : $value;
	}

	private function date_value( mixed $value, string $kind ): ?string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) { return null; }
		try {
			$date = 'UTC' === $kind ? DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) ) : new DateTimeImmutable( $value );
			$errors = 'UTC' === $kind ? DateTimeImmutable::getLastErrors() : false;
			if ( false === $date || ( false !== $errors && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) { return null; }
			return $date->setTimezone( $this->timezone )->format( 'Y-m-d' );
		} catch ( Throwable ) { return null; }
	}
}
