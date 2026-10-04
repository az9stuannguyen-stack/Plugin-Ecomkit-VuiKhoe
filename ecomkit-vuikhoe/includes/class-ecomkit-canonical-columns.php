<?php
/** Canonical 24-column business contract shared by Result and future export. */

defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Canonical_Columns {
	public const VERSION = 'v1';

	/** @return array<int,array{index:int,key:string,label:string,source:string}> */
	public static function all(): array {
		$rows = array(
			array( 'order_date', 'Ngày Lên Đơn', 'EXCEL > SHOPEE_ORDER_DETAIL' ),
			array( 'eshop_order_code', 'Mã đơn ESHOP', 'UNMAPPED' ),
			array( 'raw_order_code', 'Mã đơn sàn', 'EXCEL > SHOPEE_ORDER_DETAIL' ),
			array( 'sales_channel', 'Kênh Bán Hàng', 'EXCEL > SHOPEE_ORDER_DETAIL' ),
			array( 'order_status', 'Trạng Thái Đơn Hàng', 'SHOPEE_ORDER_DETAIL' ),
			array( 'customer_name', 'Tên Khách Hàng', 'SHOPEE_ORDER_DETAIL' ),
			array( 'phone', 'SĐT', 'SHOPEE_ORDER_DETAIL' ),
			array( 'address', 'Địa Chỉ', 'SHOPEE_ORDER_DETAIL' ),
			array( 'province_city', 'Tỉnh/TP', 'SHOPEE_ORDER_DETAIL' ),
			array( 'vat_issued_date', 'Ngày Xuất VAT', 'UNMAPPED' ),
			array( 'note', 'Ghi Chú', 'UNMAPPED' ),
			array( 'amount_collected', 'Đã Thu Tiền', 'FUTURE_PAYMENT_ESCROW' ),
			array( 'receivable_status', 'Trạng Thái Công Nợ', 'FUTURE_PAYMENT_ESCROW' ),
			array( 'difference_amount', 'Chênh lệch', 'FUTURE_PAYMENT_ESCROW' ),
			array( 'product_price_vat_8', 'Giá SP (VAT 8%)', 'UNMAPPED' ),
			array( 'total_cost_percent', '% Tổng Chi Phí', 'FUTURE_PAYMENT_ESCROW' ),
			array( 'total_amount_to_collect', 'Tổng Tiền Sẽ Thu', 'FUTURE_PAYMENT_ESCROW' ),
			array( 'affiliate_fee_vuikhoe', 'Phí Affiliate (Vui Khỏe)', 'UNMAPPED' ),
			array( 'discount_vuikhoe', 'Chiết Khấu (Vui Khỏe)', 'UNMAPPED' ),
			array( 'discount_percent_vuikhoe', '% Chiết Khấu Vui Khỏe', 'UNMAPPED' ),
			array( 'fixed_platform_fee', 'Phí Cố Định (TMĐT)', 'FUTURE_PAYMENT_ESCROW' ),
			array( 'service_platform_fee', 'Phí dịch vụ (TMĐT)', 'FUTURE_PAYMENT_ESCROW' ),
			array( 'transaction_platform_fee', 'Phí Giao Dịch (TMĐT)', 'FUTURE_PAYMENT_ESCROW' ),
			array( 'platform_cost_percent', '% Chi Phí Sàn TMĐT', 'FUTURE_PAYMENT_ESCROW' ),
		);
		return array_map(
			static fn( array $row, int $index ): array => array( 'index' => $index + 1, 'key' => $row[0], 'label' => $row[1], 'source' => $row[2] ),
			$rows,
			array_keys( $rows )
		);
	}
}
