<?php
/** Pure projection of validated Shopee accounting evidence; no canonical decisions. */
defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Shopee_Payment_Normalizer {
	public static function normalize( array $response ): array {
		$income = is_array( $response['order_income'] ?? null ) ? $response['order_income'] : array();
		$fields = array(
			'escrowAmount' => 'escrow_amount', 'escrowAmountAfterAdjustment' => 'escrow_amount_after_adjustment',
			'buyerTotalAmount' => 'buyer_total_amount', 'orderOriginalPrice' => 'order_original_price',
			'orderSellingPrice' => 'order_selling_price', 'orderDiscountedPrice' => 'order_discounted_price',
			'orderSellerDiscount' => 'order_seller_discount', 'sellerDiscount' => 'seller_discount',
			'shopeeDiscount' => 'shopee_discount', 'voucherFromSeller' => 'voucher_from_seller',
			'voucherFromShopee' => 'voucher_from_shopee', 'commissionFee' => 'commission_fee',
			'serviceFee' => 'service_fee', 'sellerTransactionFee' => 'seller_transaction_fee',
			'affiliateCommissionFee' => 'order_ams_commission_fee', 'totalAdjustmentAmount' => 'total_adjustment_amount',
			'buyerPaidShippingFee' => 'buyer_paid_shipping_fee', 'estimatedShippingFee' => 'estimated_shipping_fee',
			'actualShippingFee' => 'actual_shipping_fee', 'finalShippingFee' => 'final_shipping_fee',
			'shopeeShippingRebate' => 'shopee_shipping_rebate',
		);
		$result = array( 'marketplaceOrderId' => $response['order_sn'] );
		foreach ( $fields as $target => $source ) {
			$value = $income[ $source ] ?? null;
			$result[ $target ] = is_int( $value ) || is_float( $value ) ? $value : ( is_string( $value ) && is_numeric( $value ) ? $value : null );
		}
		$result['currency'] = self::optional_text( $income['currency'] ?? $response['currency'] ?? null );
		$result['buyerPaymentMethod'] = self::optional_text( $response['buyer_payment_method'] ?? $income['buyer_payment_method'] ?? null );
		return $result;
	}
	private static function optional_text( mixed $value ): ?string { return is_string( $value ) && '' !== trim( $value ) ? trim( $value ) : null; }
}
