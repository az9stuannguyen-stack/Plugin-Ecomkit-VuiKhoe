<?php
/** Conservative Shopee business labels for spreadsheet copy only. */

defined( 'ABSPATH' ) || exit;

final class Ecomkit_Vuikhoe_Shopee_Business_Status {
	/** @return array{label:string,diagnostic:?string} */
	public static function resolve( string $platform, string $provider_status ): array {
		if ( 'SHOPEE' !== $platform || '' === $provider_status ) { return array( 'label' => $provider_status, 'diagnostic' => null ); }
		$labels = array(
			'COMPLETED' => 'Giao Hàng Thành Công',
			'SHIPPED' => 'Đang Giao Hàng',
		);
		return isset( $labels[ $provider_status ] )
			? array( 'label' => $labels[ $provider_status ], 'diagnostic' => null )
			: array( 'label' => $provider_status, 'diagnostic' => 'UNMAPPED_SHOPEE_BUSINESS_STATUS' );
	}
}
