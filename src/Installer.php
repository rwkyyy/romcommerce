<?php

declare(strict_types=1);

namespace RomCommerce;

use RomCommerce\Modules\PriceHistory\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Activation-time install work for Lite's own modules. This is the one place
 * allowed to reference Lite modules directly (the boundary rule concerns Pro,
 * not Lite's own internals). The module boot paths also self-heal at runtime —
 * the price-history table via a version-guarded admin_init check, rewrites via
 * a flush flag — because the project's file-deploy workflow never fires this
 * activation hook; this covers the ordinary WordPress.org install path.
 */
final class Installer {

	public static function activate(): void {
		Log::ensure_table();

		// Flush so module-registered rewrite endpoints (e.g. the multi-address
		// My Account endpoint) resolve on the WordPress.org install path; modules
		// also self-heal at runtime via their own version-guarded flush flags.
		flush_rewrite_rules( false );
	}
}
