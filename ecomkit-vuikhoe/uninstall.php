<?php
/**
 * Ecomkit uninstall safety foundation.
 *
 * Operational tables and settings are intentionally preserved. A future,
 * explicit opt-in removal setting may add destructive cleanup, but it must
 * remain disabled by default and require a separate reviewed migration.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Deliberately non-destructive in WP.1.

