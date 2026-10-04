<?php
/** Pure deterministic projection from persisted Order evidence to canonical v2. */

defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Canonical_Result_Materializer {
	public function __construct( private ?DateTimeZone $timezone = null ) {
		$this->timezone ??= new DateTimeZone( 'UTC' );
	}

	/** @param array<string,mixed> $order @param array<int,array<string,mixed>> $items @param array<string,mixed> $batch_source @return array<string,mixed> */
	public function materialize( array $order, array $items = array(), array $batch_source = array() ): array {
		$columns = Ecomkit_Vuikhoe_Canonical_Columns::all();
		if ( 24 !== count( $columns ) || 24 !== count( array_unique( array_column( $columns, 'key' ) ) ) ) {
			throw new RuntimeException( 'CANONICAL_MAPPING_CONTRACT_INVALID' );
		}
		$provider = $this->decode_provider( $order['provider_normalized_data'] ?? null );
		$matched = 'SHOPEE' === (string) ( $order['platform'] ?? '' ) && 'MATCHED' === (string) ( $order['matching_status'] ?? '' );
		$values = array_fill_keys( array_column( $columns, 'key' ), null );
		$values['eshop_order_code'] = $this->eshop_code( $order['eshop_order_code'] ?? null );
		if ( null === $values['eshop_order_code'] ) {
			$raw = $this->decode_provider( $order['raw_source_metadata'] ?? null );
			$column = $raw['column_map']['Mã đơn hàng eShop'] ?? null;
			if ( ( is_int( $column ) && $column > 0 ) || ( is_string( $column ) && ctype_digit( $column ) && (int) $column > 0 ) ) {
				$values['eshop_order_code'] = $this->eshop_code( $raw['cells'][ (string) $column ] ?? null );
			} elseif ( null === $column && $this->verified_legacy_production_row( $order, $raw, $batch_source ) ) {
				$values['eshop_order_code'] = $this->eshop_code( $raw['cells']['4'] );
			}
		}
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

	/** @param array<string,mixed> $order @param array<int,array<string,mixed>> $items @param array<string,mixed> $batch_source */
	public function fingerprint( array $order, array $items = array(), array $batch_source = array() ): string {
		$source = array(
			'version' => Ecomkit_Vuikhoe_Canonical_Columns::VERSION,
			'order_date' => $order['order_date'] ?? null, 'raw_order_code' => $order['raw_order_code'] ?? null,
			'eshop_order_code' => $order['eshop_order_code'] ?? null, 'raw_source_metadata' => $order['raw_source_metadata'] ?? null,
			'batch_source' => array_intersect_key( $batch_source, array_flip( array( 'parser_version', 'header_row', 'date_column', 'valid_rows', 'item_rows' ) ) ),
			'source_refs' => $order['source_refs'] ?? null,
			'platform' => $order['platform'] ?? null, 'matching_status' => $order['matching_status'] ?? null,
			'provider_normalized_data' => $this->decode_provider( $order['provider_normalized_data'] ?? null),
			'items' => array_map( static fn( array $item ): array => array_intersect_key( $item, array_flip( array( 'id', 'sku', 'product_name', 'quantity', 'price', 'variant' ) ) ), $items ),
		);
		return hash( 'sha256', (string) wp_json_encode( $source, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ) );
	}

	/** The fixed D-cell rule is limited to the verified eight-order Vui Khỏe workbook shape. */
	private function verified_legacy_production_row( array $order, array $raw, array $batch_source ): bool {
		if ( 'wp5a-v1' !== (string) ( $batch_source['parser_version'] ?? '' )
			|| 3 !== (int) ( $batch_source['header_row'] ?? 0 )
			|| 3 !== (int) ( $batch_source['date_column'] ?? 0 )
			|| 8 !== (int) ( $batch_source['valid_rows'] ?? 0 )
			|| 18 !== (int) ( $batch_source['item_rows'] ?? 0 )
			|| 'EXCEL' !== (string) ( $raw['source'] ?? '' )
			|| ! is_array( $raw['cells'] ?? null )
			|| 7 !== count( $raw['cells'] )
			|| ! is_string( $raw['cells']['4'] ?? null )
			|| '' === trim( $raw['cells']['4'] ) ) {
			return false;
		}
		$refs = $this->decode_provider( $order['source_refs'] ?? null );
		if ( 'EXCEL' !== (string) ( $refs['source'] ?? '' )
			|| 'DANH SÁCH ĐƠN HÀNG' !== (string) ( $refs['sheet'] ?? '' )
			|| (int) ( $refs['row'] ?? 0 ) < 4
			|| ! is_string( $raw['cells']['2'] ?? null )
			|| trim( $raw['cells']['2'] ) !== trim( (string) ( $raw['combined_identity'] ?? '' ) )
			|| ! in_array( (string) ( $order['platform'] ?? '' ), array( 'SHOPEE', 'LAZADA' ), true ) ) {
			return false;
		}
		foreach ( array( '1', '2', '3', '5', '6', '7' ) as $position ) {
			if ( ! array_key_exists( $position, $raw['cells'] ) || null === $raw['cells'][ $position ] || '' === trim( (string) $raw['cells'][ $position ] ) ) { return false; }
		}
		$identity = preg_split( '/\r\n|\r|\n/', trim( $raw['cells']['2'] ) );
		if ( ! is_array( $identity ) || 2 !== count( $identity )
			|| strtoupper( trim( $identity[0] ) ) !== (string) $order['platform']
			|| trim( $identity[1] ) !== (string) ( $order['marketplace_order_id'] ?? $order['raw_order_code'] ?? '' ) ) { return false; }
		return true;
	}

	private function eshop_code( mixed $value ): ?string {
		if ( ! is_string( $value ) ) { return null; }
		$value = trim( $value );
		return '' === $value ? null : $value;
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
