<?php
/** Pure deterministic projection from persisted Order evidence to canonical v7. */

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
		$rationals = array(); $formula_states = array(); $source_metadata = array();
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
		foreach ( array( 'product_price_vat_8', 'affiliate_fee_vuikhoe', 'discount_vuikhoe' ) as $key ) {
			$values[ $key ] = $this->internal_money( $order[ $key ] ?? null );
		}
		$source_metadata['productPrice'] = array( 'source' => null === $values['product_price_vat_8'] ? 'NULL_NO_VERIFIED_SOURCE' : 'EXCEL' );
		$values['vat_issued_date'] = $this->vat_date( $order['vat_issued_date'] ?? null );
		$values['note'] = $this->scalar( $order['note'] ?? null );
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
			$payment = $this->decode_provider( $order['payment_normalized_data'] ?? null );
			if ( (string) ( $payment['marketplaceOrderId'] ?? '' ) === (string) ( $order['marketplace_order_id'] ?? '' ) ) {
				$provider_price = $this->internal_money( $payment['orderSellingPrice'] ?? null );
				if ( null !== $provider_price ) {
					if ( null === $values['product_price_vat_8'] ) {
						$values['product_price_vat_8'] = $provider_price;
						$source_metadata['productPrice']['source'] = 'SHOPEE_OPENAPI_PAYMENT/order_selling_price';
					} elseif ( Ecomkit_Vuikhoe_Exact_Financial_Math::compare( $values['product_price_vat_8'], $provider_price ) !== 0 ) {
						$source_metadata['productPrice']['discrepancy'] = array( 'excel' => $values['product_price_vat_8'], 'provider' => $provider_price );
					}
				}
				$values['fixed_platform_fee'] = $this->payment_number( $payment['commissionFee'] ?? null );
				$raw_payment = $this->decode_provider( $order['payment_raw_data'] ?? null );
				$raw_income = (string) ( $raw_payment['order_sn'] ?? '' ) === (string) ( $order['marketplace_order_id'] ?? '' ) && is_array( $raw_payment['order_income'] ?? null ) ? $raw_payment['order_income'] : array();
				// Old Payment snapshots lack the normalized PiShip key; use only the identity-checked persisted raw field.
				$piship = $this->payment_number( $payment['shippingSellerProtectionFeeAmount'] ?? $raw_income['shipping_seller_protection_fee_amount'] ?? null );
				$provider_service = $this->exact_payment_number( $payment['serviceFee'] ?? null );
				$discount = $values['discount_vuikhoe'];
				$source_metadata['serviceFeeReclassification'] = array( 'version' => 'v1', 'providerServiceFee' => $provider_service, 'shippingSellerProtectionFeeAmount' => $piship, 'vuiKhoeDiscount' => $discount, 'canonicalServiceFee' => null, 'status' => 'MISSING_OPERANDS' );
				$missing_service = array();
				if ( null === $provider_service ) { $missing_service[] = 'providerServiceFee'; }
				if ( null === $piship || null === $this->exact_payment_number( $piship ) ) { $missing_service[] = 'shippingSellerProtectionFeeAmount'; }
				if ( null === $discount ) { $missing_service[] = 'discount_vuikhoe'; }
				if ( $missing_service ) { $formula_states['service_platform_fee'] = array( 'status' => 'MISSING_OPERANDS', 'fields' => $missing_service ); }
				else {
					$corrected = Ecomkit_Vuikhoe_Exact_Financial_Math::subtract( Ecomkit_Vuikhoe_Exact_Financial_Math::add( $provider_service, $piship ), $discount );
					$source_metadata['serviceFeeReclassification']['calculatedServiceFee'] = $corrected;
					if ( Ecomkit_Vuikhoe_Exact_Financial_Math::compare( $corrected, '0' ) < 0 ) {
						$source_metadata['serviceFeeReclassification']['status'] = 'SERVICE_FEE_NEGATIVE_REVIEW';
						$formula_states['service_platform_fee'] = array( 'status' => 'SERVICE_FEE_NEGATIVE_REVIEW', 'fields' => array() );
					} else {
						$values['service_platform_fee'] = $corrected;
						$source_metadata['serviceFeeReclassification']['canonicalServiceFee'] = $corrected;
						$source_metadata['serviceFeeReclassification']['status'] = 'READY';
						$formula_states['service_platform_fee'] = array( 'status' => 'READY', 'fields' => array() );
					}
				}
				$values['transaction_platform_fee'] = $this->payment_number( $payment['sellerTransactionFee'] ?? null );
				$values['total_amount_to_collect'] = $this->payment_number( $payment['escrowAmountAfterAdjustment'] ?? null );
				$values['total_amount_to_collect'] ??= $this->payment_number( $payment['escrowAmount'] ?? null );
			}
		}
		$math = Ecomkit_Vuikhoe_Exact_Financial_Math::class;
		foreach ( Ecomkit_Vuikhoe_Canonical_Columns::legacy_formula_contract() as $target => $contract ) {
			$required = $contract['numerator'];
			if ( null !== $contract['denominator'] ) { $required[] = $contract['denominator']; }
			if ( null !== $contract['subtract_from'] ) { $required[] = $contract['subtract_from']; }
			$missing = array_values( array_filter( $required, static fn( string $key ): bool => ! ( is_int( $values[ $key ] ?? null ) || ( is_string( $values[ $key ] ?? null ) && 1 === preg_match( '/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/D', $values[ $key ] ) ) ) ) );
			if ( $missing ) { $formula_states[ $target ] = array( 'status' => 'MISSING_OPERANDS', 'fields' => $missing ); continue; }
			if ( null !== $contract['denominator'] && $math::is_zero( $values[ $contract['denominator'] ] ) ) { $formula_states[ $target ] = array( 'status' => 'ZERO_DIVISOR', 'fields' => array( $contract['denominator'] ) ); continue; }
			$sum = '0';
			foreach ( $contract['numerator'] as $key ) { $sum = $math::add( $sum, $values[ $key ] ); }
			if ( null !== $contract['denominator'] ) {
				$rationals[ $target ] = $math::ratio( $sum, $values[ $contract['denominator'] ] );
				$values[ $target ] = $rationals[ $target ]['ratio'];
			} elseif ( null === $values[ $target ] ) {
				$values[ $target ] = $math::subtract( $values[ $contract['subtract_from'] ], $sum );
			}
			$formula_states[ $target ] = array( 'status' => 'READY', 'fields' => array() );
		}
		foreach ( $values as $value ) {
			if ( null !== $value && ! is_scalar( $value ) ) { throw new RuntimeException( 'CANONICAL_SOURCE_INVALID' ); }
		}
		return array( 'version' => Ecomkit_Vuikhoe_Canonical_Columns::VERSION, 'columns' => $values, 'rational' => $rationals, 'formula_state' => $formula_states, 'source_metadata' => $source_metadata );
	}

	/** @param array<string,mixed> $order @param array<int,array<string,mixed>> $items @param array<string,mixed> $batch_source */
	public function fingerprint( array $order, array $items = array(), array $batch_source = array() ): string {
		$source = array(
			'version' => Ecomkit_Vuikhoe_Canonical_Columns::VERSION,
			'order_date' => $order['order_date'] ?? null, 'raw_order_code' => $order['raw_order_code'] ?? null,
			'eshop_order_code' => $order['eshop_order_code'] ?? null, 'raw_source_metadata' => $order['raw_source_metadata'] ?? null,
			'product_price_vat_8' => $order['product_price_vat_8'] ?? null, 'affiliate_fee_vuikhoe' => $order['affiliate_fee_vuikhoe'] ?? null,
			'discount_vuikhoe' => $order['discount_vuikhoe'] ?? null, 'vat_issued_date' => $order['vat_issued_date'] ?? null, 'note' => $order['note'] ?? null,
			'batch_source' => array_intersect_key( $batch_source, array_flip( array( 'parser_version', 'header_row', 'date_column', 'valid_rows', 'item_rows' ) ) ),
			'source_refs' => $order['source_refs'] ?? null,
			'platform' => $order['platform'] ?? null, 'matching_status' => $order['matching_status'] ?? null,
			'provider_normalized_data' => $this->decode_provider( $order['provider_normalized_data'] ?? null),
			'payment_normalized_data' => $this->decode_provider( $order['payment_normalized_data'] ?? null ),
			'payment_raw_data' => $this->decode_provider( $order['payment_raw_data'] ?? null ),
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

	private function payment_number( mixed $value ): int|float|string|null {
		if ( is_int( $value ) || is_float( $value ) ) { return $value; }
		return is_string( $value ) && is_numeric( $value ) ? $value : null;
	}

	private function exact_payment_number( mixed $value ): ?string {
		if ( ! is_int( $value ) && ! is_string( $value ) ) { return null; }
		try { return Ecomkit_Vuikhoe_Exact_Financial_Math::normalize( $value ); }
		catch ( InvalidArgumentException ) { return null; }
	}

	private function internal_money( mixed $value ): ?string {
		if ( is_int( $value ) ) { $value = (string) $value; }
		return is_string( $value ) && 1 === preg_match( '/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]{1,4})?$/D', $value ) ? $value : null;
	}

	private function vat_date( mixed $value ): ?string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} 00:00:00$/D', $value ) ) { return null; }
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		return false !== $date && $date->format( 'Y-m-d H:i:s' ) === $value ? substr( $value, 0, 10 ) : null;
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
