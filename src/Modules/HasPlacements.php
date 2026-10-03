<?php

declare(strict_types=1);

namespace RomCommerce\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Optional contract for a module that renders on the storefront at one or more
 * Support\Placement slots. Checked via instanceof, not folded into
 * ModuleInterface, because most modules don't render a frontend widget.
 *
 * The declaration is the single source of truth: a module wires its live hooks
 * by looping its own placements(), and the central Placements map reads the
 * same list to draw where everything lands — so the map can never drift from
 * what actually renders.
 *
 * That wiring happens on init, not inline in boot(): placements() may call
 * WP/WC APIs that aren't ready at plugins_loaded — get_permalink(), for one,
 * dereferences the not-yet-instantiated $wp_rewrite and fatals there — so each
 * module hooks its placement wiring onto init via a register_placements()
 * method rather than looping placements() straight from boot().
 *
 * Like HasSettingsUi, this may be called on a module whose boot() never ran
 * (ModuleLoader stores every registered module regardless of is_enabled());
 * implementations must not assume boot() executed. `active` reflects the
 * merchant's per-placement toggle, independent of whether the module as a
 * whole is enabled — the map decides what to show using both.
 */
interface HasPlacements {

	/**
	 * @return array<int, array{slot: string, active: bool}> One entry per slot
	 *         this module can occupy; `active` is its current on/off state.
	 */
	public function placements(): array;
}
