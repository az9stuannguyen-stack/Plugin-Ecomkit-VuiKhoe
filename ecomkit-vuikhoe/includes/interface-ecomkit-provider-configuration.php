<?php
defined( 'ABSPATH' ) || exit;
/** Configuration projection only; Order/Financial clients belong to later stages. */
interface Ecomkit_Vuikhoe_Provider_Configuration {
	public function platform(): string;
	public function safe_state(): array;
}
