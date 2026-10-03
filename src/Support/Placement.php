<?php

declare(strict_types=1);

namespace RomCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Shared plumbing for frontend widgets that let the merchant pick where they
 * render: a named-slot registry (so the real hook/priority behind each spot
 * lives in one place instead of being hardcoded per module), the wp_footer
 * relocation trick (wp_footer fires after the theme's own </footer> has
 * already closed, so a plain echo there always lands outside it — this moves
 * the element into the page's actual footer landmark once it exists in the
 * DOM, the only theme-agnostic way in without a template override), and the
 * "pick a spot" settings-field renderer. Lite-only — mirrors Support\Validators,
 * not part of the Lite<->Pro contract.
 *
 * Each slot carries a `surface` (product / cart / checkout / site) so the
 * central Placements map can group every module's live placements by the page
 * a shopper actually sees. Slots deliberately map 1:1 to a single real hook
 * each rather than sharing generic names: Free Shipping Bar's cart-page
 * placement and Legal Guarantee Notice's checkout placement land at different
 * points for good reason, so collapsing them would silently move existing UI.
 */
final class Placement {

	/**
	 * Surface ids, in the order the Placements map shows its tabs. Labels live
	 * in surfaces(), not here: a constant expression can't call __().
	 *
	 * @var array<int, string>
	 */
	private const SURFACES = array( 'product', 'cart', 'checkout', 'site' );

	/**
	 * `relocates` marks a slot whose elements are moved into the theme footer
	 * landmark client-side (see footer_relocation_script) — the only slot where
	 * two modules can genuinely occupy the same spot, hence the one that needs
	 * an explicit stacking order. `fixed` is also on wp_footer but renders in a
	 * pinned viewport corner (the FAB), so it never collides with the relocated
	 * footer content and carries no ordering.
	 *
	 * @var array<string, array{hook: string, priority?: int, surface: string, relocates?: bool}>
	 */
	private const SLOTS = array(
		'footer'               => array(
			'hook'      => 'wp_footer',
			'surface'   => 'site',
			'relocates' => true,
		),
		'fixed'                => array(
			'hook'    => 'wp_footer',
			'surface' => 'site',
		),
		'product_page_summary' => array(
			'hook'     => 'woocommerce_single_product_summary',
			'priority' => 15,
			'surface'  => 'product',
		),
		'add_to_cart'          => array(
			'hook'    => 'woocommerce_after_add_to_cart_button',
			'surface' => 'product',
		),
		'cart'                 => array(
			'hook'    => 'woocommerce_after_cart_table',
			'surface' => 'cart',
		),
		'checkout_review'      => array(
			'hook'    => 'woocommerce_checkout_before_order_review',
			'surface' => 'checkout',
		),
		'checkout_submit'      => array(
			// Above WC core's own terms-and-conditions checkbox, which core
			// hooks onto the same action at priority 10.
			'hook'     => 'woocommerce_review_order_before_submit',
			'priority' => 5,
			'surface'  => 'checkout',
		),
	);

	/** Option storing the merchant's chosen order for modules sharing the relocating footer slot. */
	private const FOOTER_ORDER_OPTION = 'romcommerce_placement_footer_order';

	/** Wires $callback to the real hook behind $slot; does nothing for an unknown slot. */
	public static function hook( string $slot, callable $callback, ?int $priority = null ): void {
		if ( ! isset( self::SLOTS[ $slot ] ) ) {
			return;
		}

		$config = self::SLOTS[ $slot ];

		add_action( $config['hook'], $callback, $priority ?? ( $config['priority'] ?? 10 ) );
	}

	/** @return array<string, string> surface id => label, in tab order. */
	public static function surfaces(): array {
		return array(
			'product'  => __( 'Product page', 'romcommerce' ),
			'cart'     => __( 'Cart', 'romcommerce' ),
			'checkout' => __( 'Checkout', 'romcommerce' ),
			'site'     => __( 'Site-wide', 'romcommerce' ),
		);
	}

	public static function surface_exists( string $surface ): bool {
		return in_array( $surface, self::SURFACES, true );
	}

	/** @return array<string, string> slot id => label. */
	private static function slot_labels(): array {
		return array(
			'footer'               => __( 'Site footer', 'romcommerce' ),
			'fixed'                => __( 'Fixed corner', 'romcommerce' ),
			'product_page_summary' => __( 'After the price', 'romcommerce' ),
			'add_to_cart'          => __( 'After Add to Cart', 'romcommerce' ),
			'cart'                 => __( 'Below the cart table', 'romcommerce' ),
			'checkout_review'      => __( 'Before the order review', 'romcommerce' ),
			'checkout_submit'      => __( 'Above the Place Order button', 'romcommerce' ),
		);
	}

	/**
	 * @return array<string, string> slot id => human label for every slot on
	 *                                $surface, in the slots' declared order.
	 */
	public static function slots_for_surface( string $surface ): array {
		$slots  = array();
		$labels = self::slot_labels();

		foreach ( self::SLOTS as $id => $config ) {
			if ( $config['surface'] === $surface ) {
				$slots[ $id ] = $labels[ $id ];
			}
		}

		return $slots;
	}

	public static function slot_exists( string $slot ): bool {
		return isset( self::SLOTS[ $slot ] );
	}

	public static function slot_label( string $slot ): string {
		return self::slot_labels()[ $slot ] ?? $slot;
	}

	public static function slot_surface( string $slot ): string {
		return self::SLOTS[ $slot ]['surface'] ?? '';
	}

	public static function slot_relocates( string $slot ): bool {
		return ! empty( self::SLOTS[ $slot ]['relocates'] );
	}

	/**
	 * Merchant-chosen order (list of module ids) for the relocating footer slot.
	 * An id absent from the stored list falls to the end, preserving a sensible
	 * default before the merchant ever touches the control.
	 *
	 * @return array<int, string>
	 */
	public static function footer_order(): array {
		$stored = get_option( self::FOOTER_ORDER_OPTION, array() );

		return is_array( $stored ) ? array_values( array_map( 'strval', $stored ) ) : array();
	}

	/** @param array<int, string> $module_ids Ordered module ids sharing the footer slot. */
	public static function set_footer_order( array $module_ids ): void {
		update_option( self::FOOTER_ORDER_OPTION, array_values( array_map( 'sanitize_key', $module_ids ) ) );
	}

	/** Rank for $module_id in the footer stack (lower sorts higher); unranked ids sort last. */
	public static function footer_rank( string $module_id ): int {
		$order = self::footer_order();
		$index = array_search( $module_id, $order, true );

		return false === $index ? count( $order ) : (int) $index;
	}

	/**
	 * Relocates #$element_id into the theme footer, then re-sorts any
	 * [data-rc-footer-order] siblings ascending — so when more than one module
	 * lands in the footer, the merchant's chosen order wins regardless of which
	 * module's inline script runs first.
	 */
	public static function footer_relocation_script( string $element_id ): string {
		return '(function(){'
			. 'var el=document.getElementById(' . wp_json_encode( $element_id ) . ');if(!el){return;}'
			. 'var footer=document.querySelector("footer[role=\'contentinfo\']")||document.getElementById("colophon")||document.querySelector("footer");'
			. 'if(!footer){return;}'
			. 'footer.appendChild(el);'
			. 'var ordered=footer.querySelectorAll("[data-rc-footer-order]");'
			. 'if(ordered.length>1){Array.prototype.slice.call(ordered).sort(function(a,b){'
			. 'return (parseInt(a.getAttribute("data-rc-footer-order"),10)||0)-(parseInt(b.getAttribute("data-rc-footer-order"),10)||0);'
			. '}).forEach(function(node){footer.appendChild(node);});}'
			. '})();';
	}

	/**
	 * A shared "pick one" radio control for a placement's position — inline
	 * alignment (left/center/right/none) or a fixed viewport corner
	 * (bottom-left/bottom-right); the caller supplies the option set either way.
	 *
	 * @param array<string, string> $options value => label.
	 */
	public static function position_field( string $name, array $options, string $selected ): void {
		foreach ( $options as $value => $label ) {
			echo '<label style="margin-right:16px;"><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . checked( $selected, $value, false ) . '> ' . esc_html( $label ) . '</label>';
		}
	}
}
