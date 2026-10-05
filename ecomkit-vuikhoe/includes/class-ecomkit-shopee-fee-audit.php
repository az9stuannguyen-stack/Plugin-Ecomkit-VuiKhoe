<?php
/** Read-only, allowlisted projection of persisted Shopee Payment evidence. */
defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Shopee_Fee_Audit {
	private const MAX_ORDERS = 10;
	private const MAX_JSON_BYTES = 2097152;
	private const MAX_PATHS = 1000;
	private const FINANCIAL_KEY = '/(?:fee|commission|service|transaction|income|escrow|amount|discount|voucher|rebate|shipping|campaign|program|ams|affiliate|adjustment|tax|seller|price|cost|charge|subtotal)/i';
	private const BLOCKED_KEY = '/(?:name|buyer|recipient|phone|mobile|email|address|zipcode|postal|token|sign|signature|partner_key|credential|secret|authorization|customer|user|account|card|bank|tracking|password|cookie|session|passport|identity|personal|(?:^|_)id$)/i';
	private const NORMALIZED_NUMBERS = array(
		'escrowAmount', 'escrowAmountAfterAdjustment', 'buyerTotalAmount', 'orderOriginalPrice',
		'orderSellingPrice', 'orderDiscountedPrice', 'orderSellerDiscount', 'sellerDiscount',
		'shopeeDiscount', 'voucherFromSeller', 'voucherFromShopee', 'commissionFee',
		'serviceFee', 'sellerTransactionFee', 'affiliateCommissionFee', 'totalAdjustmentAmount',
		'buyerPaidShippingFee', 'estimatedShippingFee', 'actualShippingFee', 'finalShippingFee',
		'shopeeShippingRebate',
	);

	/** @return array<int,string> */
	public static function parse_order_ids( string $input ): array {
		$parts = preg_split( '/[\r\n,]+/', $input );
		$ids = array_values( array_unique( array_filter( array_map( 'trim', is_array( $parts ) ? $parts : array() ), static fn( string $id ): bool => '' !== $id ) ) );
		if ( ! $ids || count( $ids ) > self::MAX_ORDERS ) { throw new InvalidArgumentException( 'PAYMENT_AUDIT_IDS_INVALID' ); }
		foreach ( $ids as $id ) { if ( 1 !== preg_match( '/\A[A-Za-z0-9]{1,64}\z/D', $id ) ) { throw new InvalidArgumentException( 'PAYMENT_AUDIT_IDS_INVALID' ); } }
		return $ids;
	}

	/** Only SELECTs the five permitted persisted Payment fields for exact IDs in one Batch. */
	public function inspect_batch( int $batch_id, array $ids ): array {
		if ( $batch_id < 1 || ! $ids || count( $ids ) > self::MAX_ORDERS ) { throw new InvalidArgumentException( 'PAYMENT_AUDIT_IDS_INVALID' ); }
		global $wpdb;
		$table = Ecomkit_Vuikhoe_DB::table_names()['orders'];
		$orders = array();
		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) || 1 !== preg_match( '/\A[A-Za-z0-9]{1,64}\z/D', $id ) ) { throw new InvalidArgumentException( 'PAYMENT_AUDIT_IDS_INVALID' ); }
			$query = $wpdb->prepare( "SELECT marketplace_order_id, payment_raw_data, payment_normalized_data, payment_fetched_at, payment_request_id FROM $table WHERE batch_id = %d AND platform = %s AND marketplace_order_id = %s LIMIT 2", $batch_id, 'SHOPEE', $id );
			$rows = $wpdb->get_results( $query, ARRAY_A );
			if ( ! is_array( $rows ) || 1 !== count( $rows ) || ! hash_equals( $id, (string) ( $rows[0]['marketplace_order_id'] ?? '' ) ) ) { throw new RuntimeException( 'PAYMENT_AUDIT_ORDER_NOT_UNIQUE' ); }
			$orders[] = self::project( $rows[0] );
		}
		return array( 'plugin_version' => ECOMKIT_VUIKHOE_VERSION, 'orders' => $orders );
	}

	/** Pure safe projection; raw JSON and unknown normalized keys never leave this method. */
	public static function project( array $row ): array {
		$id = $row['marketplace_order_id'] ?? null;
		if ( ! is_string( $id ) || 1 !== preg_match( '/\A[A-Za-z0-9]{1,64}\z/D', $id ) ) { throw new RuntimeException( 'PAYMENT_AUDIT_ORDER_INVALID' ); }
		$raw = self::decode( $row['payment_raw_data'] ?? null );
		$normalized = self::decode( $row['payment_normalized_data'] ?? null );
		if ( ! hash_equals( $id, (string) ( $raw['order_sn'] ?? '' ) ) || ! hash_equals( $id, (string) ( $normalized['marketplaceOrderId'] ?? '' ) ) ) { throw new RuntimeException( 'PAYMENT_AUDIT_IDENTITY_MISMATCH' ); }
		$paths = array();
		self::collect_paths( $raw, 'payment_raw_data', false, $paths, 0 );
		$matches = array_values( array_filter( $paths, static fn( array $item ): bool => self::equals_5700( $item['value'] ) ) );
		$financial = array();
		foreach ( self::NORMALIZED_NUMBERS as $key ) { $financial[ $key ] = self::safe_number( $normalized[ $key ] ?? null ); }
		$financial['buyerPaymentMethod'] = self::safe_payment_method( $normalized['buyerPaymentMethod'] ?? null );
		$financial['currency'] = self::safe_code( $normalized['currency'] ?? null, '/\A[A-Z]{3}\z/D' );
		$date = $row['payment_fetched_at'] ?? null;
		$request = $row['payment_request_id'] ?? null;
		return array(
			'marketplace_order_id' => $id,
			'payment_fetched_at' => is_string( $date ) && 1 === preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D', $date ) ? $date : null,
			'payment_request_id' => self::safe_code( $request, '/\A[A-Za-z0-9_-]{1,191}\z/D' ),
			'normalized_financial' => $financial,
			'financial_paths' => $paths,
			'exact_5700_matches' => $matches,
		);
	}

	private static function decode( mixed $json ): array {
		if ( ! is_string( $json ) || '' === $json || strlen( $json ) > self::MAX_JSON_BYTES ) { throw new RuntimeException( 'PAYMENT_AUDIT_SNAPSHOT_MISSING_OR_LARGE' ); }
		$data = json_decode( $json, true, 32 );
		if ( ! is_array( $data ) || JSON_ERROR_NONE !== json_last_error() ) { throw new RuntimeException( 'PAYMENT_AUDIT_SNAPSHOT_INVALID' ); }
		return $data;
	}

	private static function collect_paths( array $node, string $path, bool $financial_parent, array &$paths, int $depth ): void {
		if ( $depth > 24 ) { throw new RuntimeException( 'PAYMENT_AUDIT_SNAPSHOT_TOO_DEEP' ); }
		foreach ( $node as $key => $value ) {
			$segment = (string) $key;
			if ( is_string( $key ) && ( 1 !== preg_match( '/\A[A-Za-z0-9_]{1,80}\z/D', $segment ) || preg_match( self::BLOCKED_KEY, $segment ) ) ) { continue; }
			$child = $path . ( is_int( $key ) ? '[' . $key . ']' : '.' . $segment );
			$financial = $financial_parent || ( is_string( $key ) && 1 === preg_match( self::FINANCIAL_KEY, $segment ) && 'order_income' !== $segment );
			if ( is_array( $value ) ) { self::collect_paths( $value, $child, $financial, $paths, $depth + 1 ); continue; }
			if ( ! $financial || ( is_string( $key ) && preg_match( '/\A(?:id|count|time|timestamp|date|code|index)\z/iD', $segment ) ) ) { continue; }
			$safe = self::safe_number( $value );
			if ( null === $safe ) { continue; }
			if ( count( $paths ) >= self::MAX_PATHS ) { throw new RuntimeException( 'PAYMENT_AUDIT_TOO_MANY_PATHS' ); }
			$paths[] = array( 'path' => $child, 'type' => get_debug_type( $value ), 'value' => $safe );
		}
	}

	private static function safe_number( mixed $value ): int|float|string|null {
		if ( is_int( $value ) ) { return $value; }
		if ( is_float( $value ) ) { return is_finite( $value ) ? $value : null; }
		return is_string( $value ) && strlen( $value ) <= 64 && 1 === preg_match( '/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/D', $value ) ? $value : null;
	}

	private static function safe_code( mixed $value, string $pattern ): ?string { return is_string( $value ) && 1 === preg_match( $pattern, $value ) && ! preg_match( self::BLOCKED_KEY, $value ) ? $value : null; }
	private static function safe_payment_method( mixed $value ): ?string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[A-Za-z0-9 _.-]{1,64}\z/D', $value ) || preg_match( '/[0-9]{5,}/', $value ) || preg_match( '/(?:token|secret|password|phone|email|address|recipient|customer|access|refresh)/i', $value ) ) { return null; }
		return $value;
	}
	private static function equals_5700( int|float|string $value ): bool {
		if ( is_int( $value ) ) { return 5700 === $value; }
		if ( is_float( $value ) ) { return 5700.0 === $value; }
		try { return 0 === Ecomkit_Vuikhoe_Exact_Financial_Math::compare( $value, '5700' ); }
		catch ( InvalidArgumentException ) { return false; }
	}
}
