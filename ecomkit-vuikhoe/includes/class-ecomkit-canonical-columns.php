<?php
/** Canonical 24-column business contract shared by Result and future export. */

defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Canonical_Columns {
	public const VERSION = 'v9';
	private const MONEY_KEYS = array( 'amount_collected', 'difference_amount', 'product_price_vat_8', 'total_amount_to_collect', 'affiliate_fee_vuikhoe', 'discount_vuikhoe', 'fixed_platform_fee', 'service_platform_fee', 'transaction_platform_fee' );

	public static function is_money( string $key ): bool { return in_array( $key, self::MONEY_KEYS, true ); }

	/**
	 * Verified legacy workbook formulas. Metadata only: no source for all internal
	 * Approved legacy formulas, evaluated by the exact decimal-string engine.
	 * Ratios are fractions (0.0393 = 3.93%), never multiplied by 100 here.
	 *
	 * @return array<string,array{numerator:array<int,string>,denominator:?string,subtract_from:?string}>
	 */
	public static function legacy_formula_contract(): array {
		$platform_fees = array( 'fixed_platform_fee', 'service_platform_fee', 'transaction_platform_fee' );
		$all_costs = array_merge( $platform_fees, array( 'affiliate_fee_vuikhoe', 'discount_vuikhoe' ) );
		return array(
			'total_cost_percent' => array( 'numerator' => $all_costs, 'denominator' => 'product_price_vat_8', 'subtract_from' => null ),
			'total_amount_to_collect' => array( 'numerator' => $all_costs, 'denominator' => null, 'subtract_from' => 'product_price_vat_8' ),
			'discount_percent_vuikhoe' => array( 'numerator' => array( 'affiliate_fee_vuikhoe', 'discount_vuikhoe' ), 'denominator' => 'product_price_vat_8', 'subtract_from' => null ),
			'platform_cost_percent' => array( 'numerator' => $platform_fees, 'denominator' => 'product_price_vat_8', 'subtract_from' => null ),
		);
	}

	/** A source gate, not a calculation. Missing is never treated as zero. */
	public static function legacy_operands_ready( array $values, string $target ): bool {
		$contract = self::legacy_formula_contract()[ $target ] ?? null;
		if ( null === $contract ) { return false; }
		$keys = $contract['numerator'];
		if ( null !== $contract['denominator'] ) { $keys[] = $contract['denominator']; }
		if ( null !== $contract['subtract_from'] ) { $keys[] = $contract['subtract_from']; }
		foreach ( $keys as $key ) {
			$value = $values[ $key ] ?? null;
			if ( ! is_int( $value ) && ! ( is_string( $value ) && 1 === preg_match( '/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $value ) ) ) {
				return false;
			}
		}
		$denominator = $contract['denominator'];
		return null === $denominator || 1 !== preg_match( '/^-?0+(?:\.0+)?$/', (string) $values[ $denominator ] );
	}

	/** @return array<int,array{index:int,key:string,label:string,source:string}> */
	public static function all(): array {
		$rows = array(
			array( 'order_date', 'Ngày Lên Đơn', 'EXCEL_THEN_SHOPEE_FALLBACK' ),
			array( 'eshop_order_code', 'Mã đơn ESHOP', 'EXCEL_AVAILABLE' ),
			array( 'raw_order_code', 'Mã đơn sàn', 'EXCEL_THEN_SHOPEE_FALLBACK' ),
			array( 'sales_channel', 'Kênh Bán Hàng', 'EXCEL_THEN_SHOPEE_FALLBACK' ),
			array( 'order_status', 'Trạng Thái Đơn Hàng', 'SHOPEE_ORDER_DETAIL_AVAILABLE' ),
			array( 'customer_name', 'Tên Khách Hàng', 'SHOPEE_ORDER_DETAIL_AVAILABLE' ),
			array( 'phone', 'SĐT', 'SHOPEE_ORDER_DETAIL_AVAILABLE' ),
			array( 'address', 'Địa Chỉ', 'SHOPEE_ORDER_DETAIL_AVAILABLE' ),
			array( 'province_city', 'Tỉnh/TP', 'SHOPEE_ORDER_DETAIL_AVAILABLE' ),
			array( 'vat_issued_date', 'Ngày Xuất VAT', 'INTERNAL_EXCEL' ),
			array( 'note', 'Ghi Chú', 'INTERNAL_EXCEL' ),
			array( 'amount_collected', 'Đã Thu Tiền', 'FUTURE_PAYMENT_ESCROW' ),
			array( 'receivable_status', 'Trạng Thái Công Nợ', 'FUTURE_PAYMENT_ESCROW' ),
			array( 'difference_amount', 'Chênh lệch', 'UNRESOLVED_BROKEN_LEGACY_REFERENCE' ),
			array( 'product_price_vat_8', 'Giá SP (VAT 8%)', 'EXCEL_THEN_SHOPEE_PAYMENT_FALLBACK' ),
			array( 'total_cost_percent', '% Tổng Chi Phí', 'DERIVED_LEGACY_FORMULA' ),
			array( 'total_amount_to_collect', 'Tổng Tiền Sẽ Thu', 'SHOPEE_PAYMENT_ESCROW' ),
			array( 'affiliate_fee_vuikhoe', 'Phí Affiliate (Vui Khỏe)', 'EXCEL_THEN_SHOPEE_PAYMENT_FALLBACK' ),
			array( 'discount_vuikhoe', 'Chiết Khấu (Vui Khỏe)', 'EXCEL_THEN_SHOPEE_PAYMENT_DERIVED_FALLBACK' ),
			array( 'discount_percent_vuikhoe', '% Chiết Khấu Vui Khỏe', 'DERIVED_LEGACY_FORMULA' ),
			array( 'fixed_platform_fee', 'Phí Cố Định (TMĐT)', 'SHOPEE_PAYMENT_ESCROW' ),
			array( 'service_platform_fee', 'Phí dịch vụ (TMĐT)', 'SHOPEE_PAYMENT_PLUS_INTERNAL_RECLASSIFICATION' ),
			array( 'transaction_platform_fee', 'Phí Giao Dịch (TMĐT)', 'SHOPEE_PAYMENT_ESCROW' ),
			array( 'platform_cost_percent', '% Chi Phí Sàn TMĐT', 'DERIVED_LEGACY_FORMULA' ),
		);
		return array_map(
			static fn( array $row, int $index ): array => array( 'index' => $index + 1, 'key' => $row[0], 'label' => $row[1], 'source' => $row[2] ),
			$rows,
			array_keys( $rows )
		);
	}
}
