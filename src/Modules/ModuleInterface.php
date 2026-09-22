<?php

declare(strict_types=1);

namespace RomCommerce\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for any RomCommerce feature module — implemented by both Lite and Pro modules.
 *
 * Pro consumes this interface by hooking 'romcommerce/register_modules' and calling
 * ModuleLoader::register() with its own ModuleInterface implementations. That hook is
 * the only sanctioned extension point between the two plugins.
 */
interface ModuleInterface {

	public function id(): string;

	public function title(): string;

	public function is_enabled(): bool;

	/**
	 * Register hooks only — WooCommerce state is not reliably ready at
	 * plugins_loaded. Defer WC data access to init / woocommerce_init or later.
	 */
	public function boot(): void;
}
