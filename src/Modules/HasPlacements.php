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
 * Placement::hook() alone covers the product page on classic and block
 * themes. The block cart and checkout need Placement::hook_block() as well,
 * opted into per module, since their markup is static there.
 *
 * Like HasSettingsUi, this may be called on a module whose boot() never ran
 * (ModuleLoader stores every registered module regardless of is_enabled());
 * implementations must not assume boot() executed. `active` reflects the
 * merchant's per-placement toggle, independent of whether the module as a
 * whole is enabled — the map decides what to show using both.
 */
interface HasPlacements {

	/**
	 * Return the list already passed through Support\Placement::resolve(), so
	 * `slot` is where the placement renders right now: the merchant's choice
	 * from `choices` if one is stored, else the declared default. `key` is the
	 * placement's stable id (the stored choice is filed under it, since `slot`
	 * changes when the merchant moves it); `choices` lists every slot the
	 * merchant may move it to, default included — omit it for a fixed spot.
	 *
	 * `priority` is optional and only needed when the module hooks its default
	 * slot at a priority other than the slot's own; the map sorts occupants of
	 * a slot by it, so it must be the priority the module actually wires.
	 * resolve() drops it once the placement moves off its default slot.
	 *
	 * @return array<int, array{key: string, slot: string, active: bool, priority?: int, choices?: array<int, string>}>
	 *         One entry per placement; `active` is its current on/off state.
	 */
	public function placements(): array;
}
