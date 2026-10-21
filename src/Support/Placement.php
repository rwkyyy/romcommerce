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
 * Slot keys are stored in merchants' choices, so they never change; labels may.
 *
 * Every hook, its block theme equivalent and its Elementor behaviour is listed
 * in the public hook map (HOOK_MAP_URL), which must be updated with SLOTS.
 */
final class Placement {

	public const HOOK_MAP_URL = 'https://github.com/rwkyyy/romcommerce/wiki/Placement-hook-map';

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
	 * `block` is the same spot in the block cart/checkout: an inner block and
	 * whether to print before or after it (see hook_block()).
	 *
	 * `template_blocks` is the same spot in a block theme's single product
	 * template, wired by hook() itself: [block, before|after, order?]. Only
	 * slots that WooCommerce's block template compatibility layer misplaces
	 * have one: it replays every woocommerce_single_product_summary priority
	 * in one cluster above the short description, and
	 * woocommerce_after_single_product_summary and woocommerce_share around
	 * the Product Details block (WooCommerce 11.1,
	 * SingleProductTemplateCompatibility). The other product hooks fire
	 * there in the right place already, from inside the blocks or around them.
	 *
	 * `in_form` slots print inside the add-to-cart <form>, where themes and
	 * page builders lay the quantity and button out as a flex row (see
	 * in_form_css()). `elementor` => false marks hooks that Elementor Pro's
	 * product templates never run (see Support\Elementor). `group` sorts the
	 * product page's long list into headed sections in the dropdown.
	 *
	 * @var array<string, array{hook: string, priority?: int, surface: string, relocates?: bool, block?: array{0: string, 1: string}, template_blocks?: array<int, array{0: string, 1: string, 2?: int}>, in_form?: bool, elementor?: bool, group?: string}>
	 */
	private const SLOTS = array(
		'footer'                      => array(
			'hook'      => 'wp_footer',
			'surface'   => 'site',
			'relocates' => true,
		),
		'fixed'                       => array(
			'hook'    => 'wp_footer',
			'surface' => 'site',
		),
		'product_top'                 => array(
			// After WooCommerce's notices, printed on the same action at 10.
			'hook'     => 'woocommerce_before_single_product',
			'priority' => 20,
			'surface'  => 'product',
			'group'    => 'top',
		),
		// Product summary priorities sit between WooCommerce's own template
		// parts: title 5, rating/price 10, excerpt 20, add-to-cart form 30,
		// meta 40, sharing 50.
		'product_before_title'        => array(
			'hook'            => 'woocommerce_single_product_summary',
			'priority'        => 4,
			'surface'         => 'product',
			'template_blocks' => array( array( 'core/post-title', 'before' ) ),
			'elementor'       => false,
			'group'           => 'summary',
		),
		'product_after_title'         => array(
			'hook'            => 'woocommerce_single_product_summary',
			'priority'        => 6,
			'surface'         => 'product',
			'template_blocks' => array( array( 'core/post-title', 'after' ) ),
			'elementor'       => false,
			'group'           => 'summary',
		),
		'product_page_summary'        => array(
			'hook'            => 'woocommerce_single_product_summary',
			'priority'        => 15,
			'surface'         => 'product',
			'template_blocks' => array( array( 'woocommerce/product-price', 'after' ) ),
			'elementor'       => false,
			'group'           => 'summary',
		),
		'product_after_excerpt'       => array(
			'hook'            => 'woocommerce_single_product_summary',
			'priority'        => 25,
			'surface'         => 'product',
			'template_blocks' => array(
				array( 'core/post-excerpt', 'after' ),
				array( 'woocommerce/product-summary', 'after' ),
			),
			'elementor'       => false,
			'group'           => 'summary',
		),
		// A summary priority, so it still shows for out-of-stock products,
		// where WooCommerce prints no add-to-cart form and none of its hooks.
		'product_before_add_to_cart'  => array(
			'hook'            => 'woocommerce_single_product_summary',
			'priority'        => 29,
			'surface'         => 'product',
			'template_blocks' => array(
				array( 'woocommerce/add-to-cart-form', 'before' ),
				array( 'woocommerce/add-to-cart-with-options', 'before' ),
			),
			'elementor'       => false,
			'group'           => 'add_to_cart',
		),
		// The four add-to-cart form hooks: printed by the form template
		// itself, so they also run inside Elementor's Add to Cart widget and
		// WooCommerce's add-to-cart blocks, but only when the form shows.
		'product_before_form'         => array(
			'hook'    => 'woocommerce_before_add_to_cart_form',
			'surface' => 'product',
			'group'   => 'add_to_cart',
		),
		'product_before_button'       => array(
			'hook'    => 'woocommerce_before_add_to_cart_button',
			'surface' => 'product',
			'in_form' => true,
			'group'   => 'add_to_cart',
		),
		'add_to_cart'                 => array(
			'hook'    => 'woocommerce_after_add_to_cart_button',
			'surface' => 'product',
			'in_form' => true,
			'group'   => 'add_to_cart',
		),
		'product_after_form'          => array(
			'hook'    => 'woocommerce_after_add_to_cart_form',
			'surface' => 'product',
			'group'   => 'add_to_cart',
		),
		'product_after_add_to_cart'   => array(
			'hook'            => 'woocommerce_single_product_summary',
			'priority'        => 31,
			'surface'         => 'product',
			'template_blocks' => array(
				array( 'woocommerce/add-to-cart-form', 'after' ),
				array( 'woocommerce/add-to-cart-with-options', 'after' ),
			),
			'elementor'       => false,
			'group'           => 'add_to_cart',
		),
		'product_meta_start'          => array(
			'hook'    => 'woocommerce_product_meta_start',
			'surface' => 'product',
			'group'   => 'meta',
		),
		'product_meta_end'            => array(
			'hook'    => 'woocommerce_product_meta_end',
			'surface' => 'product',
			'group'   => 'meta',
		),
		'product_after_meta'          => array(
			'hook'            => 'woocommerce_single_product_summary',
			'priority'        => 45,
			'surface'         => 'product',
			'template_blocks' => array( array( 'woocommerce/product-meta', 'after', 45 ) ),
			'elementor'       => false,
			'group'           => 'meta',
		),
		// Order 50 on the block path keeps it after product_after_meta (45)
		// there too, where both follow the Product Meta block.
		'product_share'               => array(
			'hook'            => 'woocommerce_share',
			'surface'         => 'product',
			'template_blocks' => array( array( 'woocommerce/product-meta', 'after', 50 ) ),
			'elementor'       => false,
			'group'           => 'meta',
		),
		// After-summary priorities sit between WooCommerce's own: tabs 10,
		// upsells 15, related products 20.
		'product_after_summary'       => array(
			'hook'            => 'woocommerce_after_single_product_summary',
			'priority'        => 5,
			'surface'         => 'product',
			'template_blocks' => array( array( 'woocommerce/product-details', 'before' ) ),
			'elementor'       => false,
			'group'           => 'below',
		),
		'product_after_tabs'          => array(
			'hook'    => 'woocommerce_product_after_tabs',
			'surface' => 'product',
			'group'   => 'below',
		),
		'product_before_related'      => array(
			'hook'      => 'woocommerce_after_single_product_summary',
			'priority'  => 17,
			'surface'   => 'product',
			'elementor' => false,
			'group'     => 'below',
		),
		'product_bottom'              => array(
			'hook'    => 'woocommerce_after_single_product',
			'surface' => 'product',
			'group'   => 'below',
		),
		'cart_top'                    => array(
			// After WC core's notices, which print on the same action at 10.
			'hook'     => 'woocommerce_before_cart',
			'priority' => 20,
			'surface'  => 'cart',
			'block'    => array( 'woocommerce/cart', 'before' ),
		),
		'cart_before_table'           => array(
			'hook'    => 'woocommerce_before_cart_table',
			'surface' => 'cart',
			'block'   => array( 'woocommerce/cart-line-items-block', 'before' ),
		),
		'cart'                        => array(
			'hook'    => 'woocommerce_after_cart_table',
			'surface' => 'cart',
			'block'   => array( 'woocommerce/cart-line-items-block', 'after' ),
		),
		'cart_before_totals'          => array(
			'hook'    => 'woocommerce_before_cart_totals',
			'surface' => 'cart',
			'block'   => array( 'woocommerce/cart-order-summary-block', 'before' ),
		),
		'cart_before_checkout_button' => array(
			// WC core's Proceed to checkout button is on this action at 20.
			'hook'     => 'woocommerce_proceed_to_checkout',
			'priority' => 5,
			'surface'  => 'cart',
			'block'    => array( 'woocommerce/proceed-to-checkout-block', 'before' ),
		),
		'cart_after_checkout_button'  => array(
			'hook'    => 'woocommerce_after_cart_totals',
			'surface' => 'cart',
			'block'   => array( 'woocommerce/proceed-to-checkout-block', 'after' ),
		),
		'cart_bottom'                 => array(
			'hook'    => 'woocommerce_after_cart',
			'surface' => 'cart',
			'block'   => array( 'woocommerce/cart', 'after' ),
		),
		'checkout_top'                => array(
			// After WC core's notices, login and coupon forms, all at 10.
			'hook'     => 'woocommerce_before_checkout_form',
			'priority' => 20,
			'surface'  => 'checkout',
			'block'    => array( 'woocommerce/checkout', 'before' ),
		),
		'checkout_before_customer'    => array(
			'hook'    => 'woocommerce_checkout_before_customer_details',
			'surface' => 'checkout',
			'block'   => array( 'woocommerce/checkout-contact-information-block', 'before' ),
		),
		// The block checkout puts billing after shipping, so "below the billing
		// fields" and "below billing and shipping" are the same spot there.
		'checkout_after_billing'      => array(
			'hook'    => 'woocommerce_after_checkout_billing_form',
			'surface' => 'checkout',
			'block'   => array( 'woocommerce/checkout-billing-address-block', 'after' ),
		),
		'checkout_before_notes'       => array(
			'hook'    => 'woocommerce_before_order_notes',
			'surface' => 'checkout',
			'block'   => array( 'woocommerce/checkout-order-note-block', 'before' ),
		),
		'checkout_after_notes'        => array(
			'hook'    => 'woocommerce_after_order_notes',
			'surface' => 'checkout',
			'block'   => array( 'woocommerce/checkout-order-note-block', 'after' ),
		),
		'checkout_after_customer'     => array(
			'hook'    => 'woocommerce_checkout_after_customer_details',
			'surface' => 'checkout',
			'block'   => array( 'woocommerce/checkout-billing-address-block', 'after' ),
		),
		'checkout_review'             => array(
			'hook'    => 'woocommerce_checkout_before_order_review',
			'surface' => 'checkout',
			'block'   => array( 'woocommerce/checkout-order-summary-block', 'before' ),
		),
		'checkout_before_payment'     => array(
			'hook'    => 'woocommerce_review_order_before_payment',
			'surface' => 'checkout',
			'block'   => array( 'woocommerce/checkout-payment-block', 'before' ),
		),
		// Inside the payment box, under the payment method list. Fires only
		// when WooCommerce prints its terms section, which it does unless a
		// plugin filters woocommerce_checkout_show_terms off.
		'checkout_after_methods'      => array(
			'hook'    => 'woocommerce_checkout_before_terms_and_conditions',
			'surface' => 'checkout',
			'block'   => array( 'woocommerce/checkout-payment-block', 'after' ),
		),
		'checkout_submit'             => array(
			// WooCommerce prints its terms checkbox just before this action
			// (checkout/payment.php), so this lands between the terms and the
			// button; 5 keeps it ahead of other plugins' additions at 10.
			'hook'     => 'woocommerce_review_order_before_submit',
			'priority' => 5,
			'surface'  => 'checkout',
			'block'    => array( 'woocommerce/checkout-terms-block', 'before' ),
		),
		'checkout_after_submit'       => array(
			'hook'    => 'woocommerce_review_order_after_submit',
			'surface' => 'checkout',
			'block'   => array( 'woocommerce/checkout-actions-block', 'after' ),
		),
		'checkout_bottom'             => array(
			'hook'    => 'woocommerce_after_checkout_form',
			'surface' => 'checkout',
			'block'   => array( 'woocommerce/checkout', 'after' ),
		),
	);

	/**
	 * Option storing the merchant's chosen slot per placement, as
	 * module id => placement key => slot id. Absent entries keep the
	 * placement's declared default slot.
	 */
	private const CHOICES_OPTION = 'romcommerce_placement_choices';

	/** Option storing the merchant's chosen order for modules sharing the relocating footer slot. */
	private const FOOTER_ORDER_OPTION = 'romcommerce_placement_footer_order';

	/** Whether in_form_css() is already queued for this request. */
	private static bool $in_form_css_queued = false;

	/**
	 * Wires $callback to the real hook behind $slot; does nothing for an
	 * unknown slot. A product slot with `template_blocks` is also wired into
	 * the block theme's product template, and skipped when WooCommerce's
	 * compatibility layer replays its hook there in the wrong place.
	 */
	public static function hook( string $slot, callable $callback, ?int $priority = null ): void {
		if ( ! isset( self::SLOTS[ $slot ] ) ) {
			return;
		}

		$config   = self::SLOTS[ $slot ];
		$priority = $priority ?? ( $config['priority'] ?? 10 );

		if ( ! empty( $config['in_form'] ) && ! self::$in_form_css_queued ) {
			self::$in_form_css_queued = true;
			add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_in_form_css' ) );
		}

		if ( ! isset( $config['template_blocks'] ) ) {
			add_action( $config['hook'], $callback, $priority );
			return;
		}

		add_action(
			$config['hook'],
			static function () use ( $callback ): void {
				if ( self::replayed_in_block_template() ) {
					return;
				}
				$callback();
			},
			$priority
		);

		foreach ( $config['template_blocks'] as $target ) {
			Blocks::insert_in_product_template( $target[0], $target[1], $callback, $target[2] ?? $priority );
		}
	}

	/**
	 * WooCommerce's block template compatibility layer fires the classic
	 * product hooks from a render_block filter; nothing else on a block theme
	 * product page does. Inside the blocks themselves (the add-to-cart form,
	 * the legacy Product Details tabs) hooks run from render callbacks, before
	 * any render_block filter, so this stays false there.
	 */
	private static function replayed_in_block_template(): bool {
		return function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() && doing_filter( 'render_block' );
	}

	public static function enqueue_in_form_css(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		wp_register_style( 'romcommerce-placement', false, array(), ROMCOMMERCE_VERSION );
		wp_enqueue_style( 'romcommerce-placement' );
		wp_add_inline_style( 'romcommerce-placement', self::in_form_css() );
	}

	/**
	 * Gives every RomCommerce element printed inside the add-to-cart form a
	 * full-width line of its own. Elementor's Add to Cart widget makes the
	 * form (Inline layout) or its quantity-and-button holder (Auto, Stacked)
	 * a nowrap flex row, as do WooCommerce's variation wrapper and many
	 * themes, so a widget printed there became a squeezed column beside the
	 * button. !important because those rules carry up to six classes of
	 * specificity (Elementor Pro 3.35/4.3); TM Extra Product Options forces
	 * the same flex-wrap on the same forms for the same reason. Without :has()
	 * support (browsers before 2023) the old squeezed layout remains.
	 */
	public static function in_form_css(): string {
		$containers = ':is(form.cart,.woocommerce-variation-add-to-cart,.e-atc-qty-button-holder)';
		$ours       = '[class^="romcommerce-"],[class*=" romcommerce-"]';

		return $containers . ':has(>:is(' . $ours . ')){flex-wrap:wrap!important;}'
			. $containers . '>:is(' . $ours . '){flex:0 0 100%!important;max-width:100%;box-sizing:border-box;}';
	}

	/** Whether Elementor Pro's product templates ever run the hook behind $slot. */
	public static function slot_runs_in_elementor( string $slot ): bool {
		return self::SLOTS[ $slot ]['elementor'] ?? true;
	}

	/**
	 * Wires $callback to the slot's spot in the block cart/checkout, if it has
	 * one. Separate from hook() so each module opts in once its output works
	 * there: block output is static (see Blocks::insert()), so anything that
	 * follows the cart live needs its own JS first. A slot inside an inner
	 * block the merchant removed from the page simply renders nothing.
	 */
	public static function hook_block( string $slot, callable $callback ): bool {
		if ( ! isset( self::SLOTS[ $slot ]['block'] ) ) {
			return false;
		}

		list( $block_name, $where ) = self::SLOTS[ $slot ]['block'];
		Blocks::insert( $block_name, $where, $callback );

		return true;
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
			'footer'                      => __( 'Site footer', 'romcommerce' ),
			'fixed'                       => __( 'Fixed corner', 'romcommerce' ),
			'product_top'                 => __( 'Top of the product page', 'romcommerce' ),
			'product_before_title'        => __( 'Before the title', 'romcommerce' ),
			'product_after_title'         => __( 'After the title', 'romcommerce' ),
			'product_page_summary'        => __( 'After the price', 'romcommerce' ),
			'product_after_excerpt'       => __( 'After the short description', 'romcommerce' ),
			'product_before_add_to_cart'  => __( 'Before the Add to Cart area', 'romcommerce' ),
			'product_before_form'         => __( 'Above the Add to Cart form', 'romcommerce' ),
			'product_before_button'       => __( 'Above the quantity and button', 'romcommerce' ),
			'add_to_cart'                 => __( 'Below the Add to Cart button', 'romcommerce' ),
			'product_after_form'          => __( 'Below the Add to Cart form', 'romcommerce' ),
			'product_after_add_to_cart'   => __( 'After the Add to Cart area', 'romcommerce' ),
			'product_meta_start'          => __( 'Start of SKU, categories and tags', 'romcommerce' ),
			'product_meta_end'            => __( 'End of SKU, categories and tags', 'romcommerce' ),
			'product_after_meta'          => __( 'After SKU, categories and tags', 'romcommerce' ),
			'product_share'               => __( 'Sharing area', 'romcommerce' ),
			'product_after_summary'       => __( 'Below the summary, above the tabs', 'romcommerce' ),
			'product_after_tabs'          => __( 'Below the tabs', 'romcommerce' ),
			'product_before_related'      => __( 'Above related products', 'romcommerce' ),
			'product_bottom'              => __( 'Bottom of the product page', 'romcommerce' ),
			'cart_top'                    => __( 'Top of the cart page', 'romcommerce' ),
			'cart_before_table'           => __( 'Above the cart table', 'romcommerce' ),
			'cart'                        => __( 'Below the cart table', 'romcommerce' ),
			'cart_before_totals'          => __( 'Above Cart totals', 'romcommerce' ),
			'cart_before_checkout_button' => __( 'Above the Proceed to checkout button', 'romcommerce' ),
			'cart_after_checkout_button'  => __( 'Below the Proceed to checkout button', 'romcommerce' ),
			'cart_bottom'                 => __( 'Bottom of the cart page', 'romcommerce' ),
			'checkout_top'                => __( 'Top of the checkout page', 'romcommerce' ),
			'checkout_before_customer'    => __( 'Above the billing details', 'romcommerce' ),
			'checkout_after_billing'      => __( 'Below the billing fields', 'romcommerce' ),
			'checkout_before_notes'       => __( 'Above the order notes', 'romcommerce' ),
			'checkout_after_notes'        => __( 'Below the order notes', 'romcommerce' ),
			'checkout_after_customer'     => __( 'Below the billing and shipping details', 'romcommerce' ),
			'checkout_review'             => __( 'Before the order review', 'romcommerce' ),
			'checkout_before_payment'     => __( 'Above the payment methods', 'romcommerce' ),
			'checkout_after_methods'      => __( 'Below the payment methods', 'romcommerce' ),
			'checkout_submit'             => __( 'Above the Place Order button', 'romcommerce' ),
			'checkout_after_submit'       => __( 'Below the Place Order button', 'romcommerce' ),
			'checkout_bottom'             => __( 'Bottom of the checkout page', 'romcommerce' ),
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

	/** @return array<int, string> Every slot id on $surface, in page order — a module's "anywhere on this page" choice list. */
	public static function surface_slots( string $surface ): array {
		$slots = array();
		foreach ( self::SLOTS as $id => $config ) {
			if ( $config['surface'] === $surface ) {
				$slots[] = $id;
			}
		}

		return $slots;
	}

	/**
	 * Product slots that still read as part of the price block. Price History's
	 * 30-day line and the RRP line are only meaningful next to the price they
	 * qualify, so they're offered these rather than the whole product page.
	 *
	 * @return array<int, string>
	 */
	public static function price_adjacent_slots(): array {
		return array( 'product_after_title', 'product_page_summary', 'product_after_excerpt' );
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

	public static function slot_priority( string $slot ): int {
		return self::SLOTS[ $slot ]['priority'] ?? 10;
	}

	public static function slot_relocates( string $slot ): bool {
		return ! empty( self::SLOTS[ $slot ]['relocates'] );
	}

	public static function slot_group( string $slot ): string {
		return self::SLOTS[ $slot ]['group'] ?? '';
	}

	/** @return array<string, string> group id => heading, for the dropdown's optgroups. */
	private static function group_labels(): array {
		return array(
			'top'         => __( 'Above the product', 'romcommerce' ),
			'summary'     => __( 'Product summary', 'romcommerce' ),
			'add_to_cart' => __( 'Add to Cart', 'romcommerce' ),
			'meta'        => __( 'Meta and sharing', 'romcommerce' ),
			'below'       => __( 'Below the summary', 'romcommerce' ),
		);
	}

	/**
	 * Swaps each placement's declared default slot for the merchant's stored
	 * choice, when that choice is one the placement allows. A moved placement
	 * loses its `priority`: that value is tuned against the default slot's
	 * neighbours (Price History's 11 = right under the price) and means
	 * nothing on another hook, so the new slot's own priority applies.
	 *
	 * @param array<int, array<string, mixed>> $placements As declared by HasPlacements::placements().
	 * @return array<int, array<string, mixed>>
	 */
	public static function resolve( string $module_id, array $placements ): array {
		$stored = self::choices()[ $module_id ] ?? array();

		foreach ( $placements as $index => $placement ) {
			$key    = (string) ( $placement['key'] ?? '' );
			$chosen = isset( $stored[ $key ] ) ? (string) $stored[ $key ] : '';

			if ( '' === $chosen || $chosen === $placement['slot'] || ! in_array( $chosen, (array) ( $placement['choices'] ?? array() ), true ) ) {
				continue;
			}

			$placements[ $index ]['slot'] = $chosen;
			unset( $placements[ $index ]['priority'] );
		}

		return $placements;
	}

	/**
	 * Stores the merchant's slot choices for $module_id. Keys not present in
	 * $requested are left alone, and a slot outside the placement's
	 * `choices` is ignored, so a forged value can never wire an arbitrary hook.
	 *
	 * @param array<int, array<string, mixed>> $placements The module's placements().
	 * @param array<string, string>            $requested  placement key => slot id.
	 */
	public static function save_choices( string $module_id, array $placements, array $requested ): void {
		$all = self::choices();

		foreach ( $placements as $placement ) {
			$key = (string) ( $placement['key'] ?? '' );
			if ( '' === $key || ! isset( $requested[ $key ] ) ) {
				continue;
			}

			$slot = sanitize_key( (string) $requested[ $key ] );
			if ( in_array( $slot, (array) ( $placement['choices'] ?? array() ), true ) ) {
				$all[ $module_id ][ $key ] = $slot;
			}
		}

		update_option( self::CHOICES_OPTION, $all );
	}

	/** @return array<string, array<string, string>> */
	private static function choices(): array {
		$stored = get_option( self::CHOICES_OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * The "where on the page" dropdown for one placement, shared by module
	 * panes and the Placements map so both offer the same list. With
	 * Elementor active, spots its product templates never run get an
	 * asterisk and a note says what it means.
	 *
	 * @param array<string, mixed> $placement One resolved entry from placements().
	 */
	public static function area_field( string $name, array $placement, string $id = '' ): void {
		$mark   = Elementor::active();
		$groups = self::group_labels();
		$open   = '';

		echo '<select name="' . esc_attr( $name ) . '"' . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '>';
		foreach ( (array) ( $placement['choices'] ?? array() ) as $slot ) {
			$slot  = (string) $slot;
			$group = self::slot_group( $slot );

			if ( $group !== $open ) {
				echo '' !== $open ? '</optgroup>' : '';
				echo '' !== $group ? '<optgroup label="' . esc_attr( $groups[ $group ] ?? $group ) . '">' : '';
				$open = $group;
			}

			$label = self::slot_label( $slot ) . ( $mark && ! self::slot_runs_in_elementor( $slot ) ? ' *' : '' );
			echo '<option value="' . esc_attr( $slot ) . '"' . selected( (string) $placement['slot'], $slot, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '' !== $open ? '</optgroup>' : '';
		echo '</select>';

		$note = $mark ? self::elementor_note( $placement ) : '';
		if ( '' !== $note ) {
			$warning = ! self::slot_runs_in_elementor( (string) $placement['slot'] );
			echo ' <span class="rc-area-elementor' . ( $warning ? ' is-warning' : '' ) . '">' . esc_html( $note ) . '</span>';
		}
	}

	/**
	 * What the asterisks in a placement's dropdown mean, or '' when none of
	 * its choices has one; worded as a warning when the current choice has.
	 *
	 * @param array<string, mixed> $placement One resolved entry from placements().
	 */
	public static function elementor_note( array $placement ): string {
		if ( ! self::slot_runs_in_elementor( (string) ( $placement['slot'] ?? '' ) ) ) {
			return __( '* Your current choice doesn\'t show on product pages built with Elementor Pro.', 'romcommerce' );
		}

		foreach ( (array) ( $placement['choices'] ?? array() ) as $slot ) {
			if ( ! self::slot_runs_in_elementor( (string) $slot ) ) {
				return __( '* Doesn\'t show on product pages built with Elementor Pro.', 'romcommerce' );
			}
		}

		return '';
	}

	/**
	 * The resolved placement with key $key, for panes that render one area
	 * field per placement.
	 *
	 * @param array<int, array<string, mixed>> $placements
	 * @return array<string, mixed>
	 */
	public static function find( array $placements, string $key ): array {
		foreach ( $placements as $placement ) {
			if ( ( $placement['key'] ?? '' ) === $key ) {
				return $placement;
			}
		}

		return array();
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
