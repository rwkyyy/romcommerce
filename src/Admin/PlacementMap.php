<?php

declare(strict_types=1);

namespace RomCommerce\Admin;

use RomCommerce\Modules\HasPlacements;
use RomCommerce\Plugin;
use RomCommerce\Support\Blocks;
use RomCommerce\Support\Elementor;
use RomCommerce\Support\Placement;

defined( 'ABSPATH' ) || exit;

/**
 * The central Placements screen: pick a storefront surface (product / cart /
 * checkout / site-wide) and see every enabled module that renders there,
 * drawn on a monochrome wireframe where the only colour is the red marquee of
 * an occupied slot. Two things are editable here: each placement's position
 * (the same Support\Placement choice its module pane edits, so the two can't
 * drift), and the footer stacking order, since that belongs to no single
 * module. Everything else deep-links to the module's own pane.
 *
 * Data comes entirely from each module's HasPlacements::placements(), the same
 * declaration that wires its live hooks, so this map cannot drift from what a
 * shopper actually sees.
 *
 * Cart and checkout are drawn as whichever stock WooCommerce layout the
 * store's page actually uses — the classic shortcode template or the block.
 * Every cart/checkout slot has a block equivalent (Placement::hook_block()),
 * drawn where it lands in the block layout. Theme or plugin overrides of
 * either layout are deliberately ignored. The product page is drawn once:
 * Placement keeps block theme product templates in the same order. With
 * Elementor active, product spots its templates never run carry an asterisk.
 */
final class PlacementMap {

	private const CAPABILITY = 'manage_woocommerce';

	/**
	 * Stage elements per scene, in document order. Scene ids are surface ids,
	 * plus `cart_block` / `checkout_block` for the block-based layouts. 'slot'
	 * entries resolve to a red marquee (occupied) or a dashed outline
	 * (available). Container types ('columns', 'box', 'zone') nest further
	 * element lists; 'label' and 'zone' captions are keys into captions(),
	 * since a constant expression can't call __().
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private const SCENES = array(
		// templates/content-single-product.php: notices, then gallery | summary,
		// then tabs, upsells and related products.
		'product'        => array(
			array(
				'type' => 'slot',
				'id'   => 'product_top',
			),
			array(
				'type'  => 'columns',
				'split' => 'pdp',
				'cols'  => array(
					array(
						array( 'type' => 'gallery' ),
					),
					// The summary column in WooCommerce's own top-to-bottom order:
					// title, price, short description, add-to-cart form, meta, sharing.
					array(
						array(
							'type' => 'slot',
							'id'   => 'product_before_title',
						),
						array(
							'type' => 'line',
							'w'    => 78,
							'big'  => true,
						),
						array(
							'type' => 'slot',
							'id'   => 'product_after_title',
						),
						array(
							'type' => 'line',
							'w'    => 28,
						),
						array(
							'type' => 'slot',
							'id'   => 'product_page_summary',
						),
						array(
							'type' => 'line',
							'w'    => 94,
						),
						array(
							'type' => 'line',
							'w'    => 82,
						),
						array(
							'type' => 'slot',
							'id'   => 'product_after_excerpt',
						),
						array(
							'type' => 'slot',
							'id'   => 'product_before_add_to_cart',
						),
						array(
							'type' => 'slot',
							'id'   => 'product_before_form',
						),
						array(
							'type'     => 'box',
							'soft'     => true,
							'children' => array(
								array(
									'type' => 'slot',
									'id'   => 'product_before_button',
								),
								array( 'type' => 'qtybutton' ),
								array(
									'type' => 'slot',
									'id'   => 'add_to_cart',
								),
							),
						),
						array(
							'type' => 'slot',
							'id'   => 'product_after_form',
						),
						array(
							'type' => 'slot',
							'id'   => 'product_after_add_to_cart',
						),
						array(
							'type' => 'slot',
							'id'   => 'product_meta_start',
						),
						array(
							'type' => 'line',
							'w'    => 44,
						),
						array(
							'type' => 'line',
							'w'    => 58,
						),
						array(
							'type' => 'slot',
							'id'   => 'product_meta_end',
						),
						array(
							'type' => 'slot',
							'id'   => 'product_after_meta',
						),
						array(
							'type' => 'slot',
							'id'   => 'product_share',
						),
					),
				),
			),
			array(
				'type' => 'slot',
				'id'   => 'product_after_summary',
			),
			array( 'type' => 'tabs' ),
			array(
				'type' => 'slot',
				'id'   => 'product_after_tabs',
			),
			array(
				'type' => 'slot',
				'id'   => 'product_before_related',
			),
			array(
				'type' => 'label',
				'text' => 'related',
			),
			array( 'type' => 'tiles' ),
			array(
				'type' => 'slot',
				'id'   => 'product_bottom',
			),
		),
		// templates/cart/cart.php: the item table, its coupon/update row, then
		// woocommerce_after_cart_table, then the right-floated totals panel.
		'cart'           => array(
			array(
				'type' => 'line',
				'w'    => 22,
				'big'  => true,
			),
			array(
				'type' => 'slot',
				'id'   => 'cart_top',
			),
			array(
				'type' => 'slot',
				'id'   => 'cart_before_table',
			),
			array(
				'type'  => 'thead',
				'cells' => 4,
			),
			array( 'type' => 'item' ),
			array( 'type' => 'item' ),
			array( 'type' => 'actions' ),
			array(
				'type' => 'slot',
				'id'   => 'cart',
			),
			array(
				'type'     => 'box',
				'half'     => true,
				'children' => array(
					array(
						'type' => 'slot',
						'id'   => 'cart_before_totals',
					),
					array(
						'type' => 'label',
						'text' => 'cart_totals',
					),
					array(
						'type' => 'totals',
						'rows' => 3,
					),
					array(
						'type' => 'slot',
						'id'   => 'cart_before_checkout_button',
					),
					array(
						'type' => 'button',
						'full' => true,
					),
					array(
						'type' => 'slot',
						'id'   => 'cart_after_checkout_button',
					),
				),
			),
			array(
				'type' => 'slot',
				'id'   => 'cart_bottom',
			),
		),
		'cart_block'     => array(
			array(
				'type' => 'line',
				'w'    => 22,
				'big'  => true,
			),
			array(
				'type' => 'slot',
				'id'   => 'cart_top',
			),
			array(
				'type'  => 'columns',
				'split' => 'main',
				'cols'  => array(
					array(
						array(
							'type'  => 'thead',
							'cells' => 2,
						),
						array(
							'type' => 'slot',
							'id'   => 'cart_before_table',
						),
						array(
							'type'    => 'item',
							'stepper' => true,
						),
						array(
							'type'    => 'item',
							'stepper' => true,
						),
						array(
							'type' => 'slot',
							'id'   => 'cart',
						),
					),
					array(
						array(
							'type'     => 'box',
							'children' => array(
								array(
									'type' => 'label',
									'text' => 'cart_totals',
								),
								array(
									'type' => 'slot',
									'id'   => 'cart_before_totals',
								),
								array( 'type' => 'disclosure' ),
								array(
									'type' => 'totals',
									'rows' => 3,
								),
								array(
									'type' => 'slot',
									'id'   => 'cart_before_checkout_button',
								),
								array(
									'type' => 'button',
									'full' => true,
								),
								array(
									'type' => 'slot',
									'id'   => 'cart_after_checkout_button',
								),
							),
						),
					),
				),
			),
			array(
				'type' => 'slot',
				'id'   => 'cart_bottom',
			),
		),
		// templates/checkout/form-checkout.php: billing | shipping side by side,
		// then "Your order", woocommerce_checkout_before_order_review, the review
		// table, and the payment box ending in the Place Order button.
		'checkout'       => array(
			array(
				'type' => 'line',
				'w'    => 26,
				'big'  => true,
			),
			array( 'type' => 'notice' ),
			array(
				'type' => 'slot',
				'id'   => 'checkout_top',
			),
			array(
				'type' => 'slot',
				'id'   => 'checkout_before_customer',
			),
			array(
				'type'  => 'columns',
				'split' => 'half',
				'cols'  => array(
					array(
						array(
							'type' => 'label',
							'text' => 'billing',
						),
						array(
							'type' => 'fields',
							'rows' => array( 2, 1, 1, 1, 2, 1 ),
						),
						array(
							'type' => 'slot',
							'id'   => 'checkout_after_billing',
						),
					),
					array(
						array(
							'type' => 'check',
							'text' => 'ship_elsewhere',
						),
						array(
							'type' => 'slot',
							'id'   => 'checkout_before_notes',
						),
						array(
							'type' => 'label',
							'text' => 'order_notes',
						),
						array( 'type' => 'textarea' ),
						array(
							'type' => 'slot',
							'id'   => 'checkout_after_notes',
						),
					),
				),
			),
			array(
				'type' => 'slot',
				'id'   => 'checkout_after_customer',
			),
			array(
				'type' => 'label',
				'text' => 'your_order',
			),
			array(
				'type' => 'slot',
				'id'   => 'checkout_review',
			),
			array(
				'type'     => 'box',
				'children' => array(
					array(
						'type'  => 'thead',
						'cells' => 2,
					),
					array(
						'type' => 'totals',
						'rows' => 4,
					),
				),
			),
			array(
				'type' => 'slot',
				'id'   => 'checkout_before_payment',
			),
			array(
				'type'     => 'box',
				'soft'     => true,
				'children' => array(
					array(
						'type' => 'radio',
						'open' => true,
					),
					array( 'type' => 'radio' ),
					array(
						'type' => 'slot',
						'id'   => 'checkout_after_methods',
					),
					array(
						'type' => 'line',
						'w'    => 86,
					),
					array( 'type' => 'check' ),
					array(
						'type' => 'slot',
						'id'   => 'checkout_submit',
					),
					array(
						'type' => 'button',
						'full' => true,
					),
					array(
						'type' => 'slot',
						'id'   => 'checkout_after_submit',
					),
				),
			),
			array(
				'type' => 'slot',
				'id'   => 'checkout_bottom',
			),
		),
		'checkout_block' => array(
			array(
				'type' => 'slot',
				'id'   => 'checkout_top',
			),
			array(
				'type'  => 'columns',
				'split' => 'main',
				'cols'  => array(
					array(
						array(
							'type' => 'slot',
							'id'   => 'checkout_before_customer',
						),
						array(
							'type' => 'label',
							'text' => 'contact',
						),
						array(
							'type' => 'fields',
							'rows' => array( 1 ),
						),
						array(
							'type' => 'label',
							'text' => 'shipping_address',
						),
						array(
							'type' => 'fields',
							'rows' => array( 2, 1, 1, 2, 1 ),
						),
						array(
							'type' => 'slot',
							'id'   => 'checkout_after_billing',
						),
						array(
							'type' => 'slot',
							'id'   => 'checkout_after_customer',
						),
						array(
							'type' => 'label',
							'text' => 'shipping_options',
						),
						array(
							'type' => 'radio',
							'card' => true,
						),
						array(
							'type' => 'radio',
							'card' => true,
						),
						array(
							'type' => 'slot',
							'id'   => 'checkout_before_payment',
						),
						array(
							'type' => 'label',
							'text' => 'payment',
						),
						array(
							'type' => 'radio',
							'card' => true,
							'open' => true,
						),
						array(
							'type' => 'radio',
							'card' => true,
						),
						array(
							'type' => 'slot',
							'id'   => 'checkout_after_methods',
						),
						array(
							'type' => 'slot',
							'id'   => 'checkout_before_notes',
						),
						array( 'type' => 'check' ),
						array(
							'type' => 'slot',
							'id'   => 'checkout_after_notes',
						),
						array(
							'type' => 'slot',
							'id'   => 'checkout_submit',
						),
						array(
							'type' => 'line',
							'w'    => 72,
						),
						array( 'type' => 'submitrow' ),
						array(
							'type' => 'slot',
							'id'   => 'checkout_after_submit',
						),
					),
					array(
						array(
							'type'     => 'box',
							'children' => array(
								array(
									'type' => 'slot',
									'id'   => 'checkout_review',
								),
								array(
									'type' => 'label',
									'text' => 'summary',
								),
								array(
									'type' => 'item',
									'mini' => true,
								),
								array(
									'type' => 'item',
									'mini' => true,
								),
								array( 'type' => 'disclosure' ),
								array(
									'type' => 'totals',
									'rows' => 3,
								),
							),
						),
					),
				),
			),
			array(
				'type' => 'slot',
				'id'   => 'checkout_bottom',
			),
		),
		'site'           => array(
			array(
				'type'     => 'zone',
				'text'     => 'header',
				'children' => array(
					array( 'type' => 'masthead' ),
				),
			),
			array(
				'type'     => 'zone',
				'text'     => 'content',
				'children' => array(
					array(
						'type' => 'line',
						'w'    => 40,
						'big'  => true,
					),
					array( 'type' => 'tiles' ),
					array(
						'type' => 'line',
						'w'    => 92,
					),
					array(
						'type' => 'line',
						'w'    => 64,
					),
				),
			),
			array(
				'type'     => 'zone',
				'text'     => 'footer',
				'children' => array(
					array( 'type' => 'footcols' ),
					array(
						'type' => 'slot',
						'id'   => 'footer',
					),
				),
			),
		),
	);

	public function render(): void {
		$this->maybe_handle_area_change();

		$map = $this->gather();

		$this->maybe_handle_reorder( $this->footer_order_ids( $map ) );

		$surface  = $this->current_surface();
		$is_block = $this->uses_block( $surface );

		echo '<div class="rc-pm">';
		$this->render_tabs( $map, $surface );
		$this->render_elementor_note( $surface );

		echo '<div class="rc-pm-body">';
		echo '<div class="rc-pm-main">';
		$this->render_layout_caption( $surface, $is_block );
		$this->render_scene( $surface, $is_block, $map );
		echo '</div>';
		$this->render_sidebar( $surface, $map );
		echo '</div>';

		echo '</div>';
	}

	/** @return array<string, string> caption key => label, for 'label' and 'zone' elements. */
	private function captions(): array {
		return array(
			'cart_totals'      => __( 'Cart totals', 'romcommerce' ),
			'billing'          => __( 'Billing details', 'romcommerce' ),
			'ship_elsewhere'   => __( 'Ship to a different address?', 'romcommerce' ),
			'order_notes'      => __( 'Order notes', 'romcommerce' ),
			'your_order'       => __( 'Your order', 'romcommerce' ),
			'contact'          => __( 'Contact information', 'romcommerce' ),
			'shipping_address' => __( 'Shipping address', 'romcommerce' ),
			'shipping_options' => __( 'Shipping options', 'romcommerce' ),
			'payment'          => __( 'Payment options', 'romcommerce' ),
			'summary'          => __( 'Order summary', 'romcommerce' ),
			'header'           => __( 'Header', 'romcommerce' ),
			'content'          => __( 'Content', 'romcommerce' ),
			'footer'           => __( 'Footer', 'romcommerce' ),
			'related'          => __( 'Related products', 'romcommerce' ),
		);
	}

	/** Whether the store's cart or checkout is the WooCommerce block rather than the classic shortcode. */
	private function uses_block( string $surface ): bool {
		if ( 'cart' === $surface ) {
			return Blocks::cart_uses_block();
		}

		return 'checkout' === $surface && Blocks::checkout_uses_block();
	}

	private function scene_id( string $surface, bool $is_block ): string {
		return $is_block ? $surface . '_block' : $surface;
	}

	private function render_layout_caption( string $surface, bool $is_block ): void {
		$captions = array(
			'cart'           => __( 'Your cart page uses the classic cart (shortcode)', 'romcommerce' ),
			'cart_block'     => __( 'Your cart page uses the Cart block', 'romcommerce' ),
			'checkout'       => __( 'Your checkout page uses the classic checkout (shortcode)', 'romcommerce' ),
			'checkout_block' => __( 'Your checkout page uses the Checkout block', 'romcommerce' ),
		);

		$scene = $this->scene_id( $surface, $is_block );
		if ( ! isset( $captions[ $scene ] ) ) {
			return;
		}

		echo '<p class="rc-pm-layout' . ( $is_block ? ' is-block' : '' ) . '">' . esc_html( $captions[ $scene ] ) . '</p>';
	}

	/**
	 * Every enabled, placement-aware module's active placements, grouped
	 * surface => slot => occupants, each slot's occupants in render order.
	 *
	 * @return array<string, array<string, array<int, array{id: string, title: string, category: string, priority: int, key: string, slot: string, choices: array<int, string>}>>>
	 */
	private function gather(): array {
		$by_surface = array();

		foreach ( Plugin::instance()->modules()->all() as $id => $module ) {
			if ( ! $module instanceof HasPlacements || ! $module->is_enabled() ) {
				continue;
			}

			$meta = ModuleRegistry::get( $id );

			foreach ( $module->placements() as $placement ) {
				if ( empty( $placement['active'] ) ) {
					continue;
				}

				$slot = (string) $placement['slot'];
				if ( ! Placement::slot_exists( $slot ) ) {
					continue;
				}

				$by_surface[ Placement::slot_surface( $slot ) ][ $slot ][] = array(
					'id'       => $id,
					'title'    => $module->title(),
					'category' => null !== $meta ? $meta['category'] : '',
					'priority' => (int) ( $placement['priority'] ?? Placement::slot_priority( $slot ) ),
					'key'      => (string) ( $placement['key'] ?? '' ),
					'slot'     => $slot,
					'choices'  => array_values( (array) ( $placement['choices'] ?? array() ) ),
				);
			}
		}

		// Registration order is not render order: modules sharing a slot at
		// different hook priorities must appear in the order a shopper sees them.
		// The index tiebreak keeps equal priorities stable on PHP 7.4.
		foreach ( $by_surface as $surface => $slots ) {
			foreach ( $slots as $slot => $occupants ) {
				$keyed = array();
				foreach ( $occupants as $index => $occupant ) {
					$keyed[] = array( $occupant['priority'], $index, $occupant );
				}
				sort( $keyed );
				$by_surface[ $surface ][ $slot ] = array_column( $keyed, 2 );
			}
		}

		return $by_surface;
	}

	private function render_tabs( array $map, string $active ): void {
		echo '<div class="rc-pm-tabs">';

		foreach ( Placement::surfaces() as $sid => $label ) {
			$count = $this->surface_count( $map, $sid );
			$cls   = 'rc-pm-tab' . ( $sid === $active ? ' is-active' : '' );

			echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( $this->surface_url( $sid ) ) . '">';
			echo esc_html( $label );
			echo ' <span class="rc-pm-count">' . esc_html( (string) $count ) . '</span>';
			echo '</a>';
		}

		echo '<a class="rc-pm-hookmap" href="' . esc_url( Placement::HOOK_MAP_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Hook map', 'romcommerce' ) . '</a>';
		echo '</div>';
	}

	/**
	 * Explains the asterisks drawn on the product surface when Elementor is
	 * active: Elementor Pro's product templates don't run those hooks.
	 */
	private function render_elementor_note( string $surface ): void {
		if ( 'product' !== $surface || ! Elementor::active() ) {
			return;
		}

		echo '<p class="rc-pm-elementor">' . esc_html__( '* Not shown on product pages built with Elementor Pro: its product templates don\'t run these WooCommerce hooks. The Add to Cart, meta and tabs spots need the matching Elementor widget in the template.', 'romcommerce' ) . '</p>';
	}

	/** The asterisk for a slot Elementor Pro's product templates never run, while Elementor is active. */
	private function elementor_mark( string $slot ): string {
		return Elementor::active() && ! Placement::slot_runs_in_elementor( $slot ) ? ' *' : '';
	}

	private function render_scene( string $surface, bool $is_block, array $map ): void {
		$scene = $this->scene_id( $surface, $is_block );

		echo '<div class="rc-pm-scene is-' . esc_attr( str_replace( '_', '-', $scene ) ) . '">';
		echo '<div class="rc-pm-bar"></div>';

		echo '<div class="rc-pm-stage">';
		foreach ( self::SCENES[ $scene ] ?? array() as $element ) {
			$this->render_element( $surface, $element, $map );
		}
		echo '</div>';

		if ( 'site' === $surface && ! empty( $map['site']['fixed'] ) ) {
			$title = $map['site']['fixed'][0]['title'];
			echo '<div class="rc-pm-fab" title="' . esc_attr( $title ) . '">' . esc_html__( 'FAB', 'romcommerce' ) . '</div>';
		}

		echo '</div>';
	}

	/** @param array<string, mixed> $element */
	private function render_element( string $surface, array $element, array $map ): void {
		$type = (string) ( $element['type'] ?? '' );

		switch ( $type ) {
			case 'line':
				$cls = 'rc-pm-line' . ( ! empty( $element['big'] ) ? ' is-big' : '' );
				echo '<div class="' . esc_attr( $cls ) . '" style="width:' . esc_attr( (string) ( $element['w'] ?? 100 ) ) . '%;"></div>';
				return;

			case 'label':
				echo '<div class="rc-pm-label">' . esc_html( $this->captions()[ (string) $element['text'] ] ?? '' ) . '</div>';
				return;

			case 'gallery':
				echo '<div class="rc-pm-gallery">';
				echo '<div class="rc-pm-img is-main"></div>';
				echo '<div class="rc-pm-thumbs">' . str_repeat( '<div class="rc-pm-thumb"></div>', 4 ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static markup.
				echo '</div>';
				return;

			case 'columns':
				echo '<div class="rc-pm-cols is-' . esc_attr( (string) ( $element['split'] ?? 'half' ) ) . '">';
				foreach ( (array) ( $element['cols'] ?? array() ) as $col ) {
					echo '<div class="rc-pm-col">';
					$this->render_children( $surface, (array) $col, $map );
					echo '</div>';
				}
				echo '</div>';
				return;

			case 'box':
				$cls = 'rc-pm-box' . ( ! empty( $element['half'] ) ? ' is-half' : '' ) . ( ! empty( $element['soft'] ) ? ' is-soft' : '' );
				echo '<div class="' . esc_attr( $cls ) . '">';
				$this->render_children( $surface, (array) ( $element['children'] ?? array() ), $map );
				echo '</div>';
				return;

			case 'zone':
				echo '<div class="rc-pm-zone">';
				echo '<span class="rc-pm-zonelabel">' . esc_html( $this->captions()[ (string) $element['text'] ] ?? '' ) . '</span>';
				$this->render_children( $surface, (array) ( $element['children'] ?? array() ), $map );
				echo '</div>';
				return;

			case 'thead':
				echo '<div class="rc-pm-thead">' . str_repeat( '<span></span>', max( 1, (int) ( $element['cells'] ?? 2 ) ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static markup.
				return;

			case 'item':
				$this->render_item( ! empty( $element['stepper'] ), ! empty( $element['mini'] ) );
				return;

			case 'actions':
				echo '<div class="rc-pm-actions"><span class="rc-pm-input is-coupon"></span><span class="rc-pm-btn is-ghost"></span><span class="rc-pm-btn is-ghost is-push"></span></div>';
				return;

			case 'totals':
				$rows = max( 1, (int) ( $element['rows'] ?? 3 ) );
				echo '<div class="rc-pm-totals">';
				for ( $i = 1; $i <= $rows; $i++ ) {
					echo '<div class="rc-pm-totalrow' . ( $i === $rows ? ' is-grand' : '' ) . '"><span></span><span></span></div>';
				}
				echo '</div>';
				return;

			case 'fields':
				echo '<div class="rc-pm-fields">';
				foreach ( (array) ( $element['rows'] ?? array( 1 ) ) as $per_row ) {
					echo '<div class="rc-pm-fieldrow">' . str_repeat( '<span class="rc-pm-input"></span>', max( 1, (int) $per_row ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static markup.
				}
				echo '</div>';
				return;

			case 'textarea':
				echo '<span class="rc-pm-input is-textarea"></span>';
				return;

			case 'check':
				$caption = isset( $element['text'] ) ? ( $this->captions()[ (string) $element['text'] ] ?? '' ) : '';
				echo '<div class="rc-pm-check"><span class="rc-pm-box-glyph"></span>';
				echo '' !== $caption ? '<span class="rc-pm-label is-inline">' . esc_html( $caption ) . '</span>' : '<span class="rc-pm-line" style="width:60%;"></span>';
				echo '</div>';
				return;

			case 'radio':
				$cls = 'rc-pm-radio' . ( ! empty( $element['card'] ) ? ' is-card' : '' ) . ( ! empty( $element['open'] ) ? ' is-open' : '' );
				echo '<div class="' . esc_attr( $cls ) . '"><div class="rc-pm-radiohead"><span class="rc-pm-dot"></span><span class="rc-pm-line" style="width:45%;"></span></div>';
				if ( ! empty( $element['open'] ) ) {
					echo '<div class="rc-pm-radiobody"><span class="rc-pm-line" style="width:80%;"></span></div>';
				}
				echo '</div>';
				return;

			case 'notice':
				echo '<div class="rc-pm-notice"><span class="rc-pm-line" style="width:46%;"></span></div>';
				return;

			case 'disclosure':
				echo '<div class="rc-pm-disclosure"><span class="rc-pm-line" style="width:50%;"></span><span class="rc-pm-chevron"></span></div>';
				return;

			case 'submitrow':
				echo '<div class="rc-pm-submitrow"><span class="rc-pm-line" style="width:24%;"></span><span class="rc-pm-btn"></span></div>';
				return;

			case 'tabs':
				echo '<div class="rc-pm-tabstrip">' . str_repeat( '<span></span>', 3 ) . '</div><div class="rc-pm-line" style="width:90%;"></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static markup.
				return;

			case 'masthead':
				echo '<div class="rc-pm-masthead"><span class="rc-pm-logo"></span><span class="rc-pm-nav">' . str_repeat( '<span></span>', 4 ) . '</span><span class="rc-pm-carticon"></span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static markup.
				return;

			case 'tiles':
				echo '<div class="rc-pm-tiles">' . str_repeat( '<span class="rc-pm-img"></span>', 4 ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static markup.
				return;

			case 'footcols':
				echo '<div class="rc-pm-footcols">' . str_repeat( '<div><span class="rc-pm-line" style="width:60%;"></span><span class="rc-pm-line" style="width:80%;"></span><span class="rc-pm-line" style="width:50%;"></span></div>', 3 ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static markup.
				return;

			case 'qtybutton':
				echo '<div class="rc-pm-qtyrow"><span class="rc-pm-input rc-pm-qty"></span><span class="rc-pm-btn"></span></div>';
				return;

			case 'button':
				$cls = 'rc-pm-btn';
				if ( 'right' === ( $element['align'] ?? '' ) ) {
					$cls .= ' is-right';
				}
				if ( ! empty( $element['full'] ) ) {
					$cls .= ' is-full';
				}
				echo '<div class="' . esc_attr( $cls ) . '"></div>';
				return;

			case 'slot':
				$slot = (string) $element['id'];
				if ( 'footer' === $slot ) {
					$this->render_footer_band( $map );
					return;
				}
				$this->render_slot_band( $slot, $map[ $surface ][ $slot ] ?? array() );
				return;
		}
	}

	/** @param array<int, mixed> $children */
	private function render_children( string $surface, array $children, array $map ): void {
		foreach ( $children as $child ) {
			$this->render_element( $surface, (array) $child, $map );
		}
	}

	/**
	 * A cart line item. Classic: remove ×, thumbnail, name, price, quantity
	 * box, subtotal across one table row. Block: thumbnail, name/price stack
	 * with a −/+ stepper, total on the right. Mini: the order-summary row.
	 */
	private function render_item( bool $stepper, bool $mini ): void {
		$cls = 'rc-pm-item' . ( $stepper ? ' is-block' : '' ) . ( $mini ? ' is-mini' : '' );

		echo '<div class="' . esc_attr( $cls ) . '">';
		if ( ! $stepper && ! $mini ) {
			echo '<span class="rc-pm-remove">&times;</span>';
		}
		echo '<span class="rc-pm-img rc-pm-itemthumb"></span>';
		echo '<span class="rc-pm-itemtext"><span class="rc-pm-line" style="width:80%;"></span>';
		if ( $stepper ) {
			echo '<span class="rc-pm-line" style="width:30%;"></span><span class="rc-pm-stepper"><span>&minus;</span><span>1</span><span>+</span></span>';
		}
		echo '</span>';
		if ( ! $stepper && ! $mini ) {
			echo '<span class="rc-pm-line rc-pm-itemcell"></span><span class="rc-pm-input rc-pm-qty"></span>';
		}
		echo '<span class="rc-pm-line rc-pm-itemcell"></span>';
		echo '</div>';
	}

	/** @param array<int, array{id: string, title: string, category: string}> $occupants */
	private function render_slot_band( string $slot, array $occupants ): void {
		if ( array() === $occupants ) {
			echo '<div class="rc-pm-slot is-available">';
			echo '<span class="rc-pm-availlabel">' . esc_html( Placement::slot_label( $slot ) . $this->elementor_mark( $slot ) ) . '</span>';
			echo '</div>';
			return;
		}

		$this->render_occupied_band( $occupants, $this->elementor_mark( $slot ) );
	}

	private function render_footer_band( array $map ): void {
		$occupants = $this->order_footer_occupants( $map['site']['footer'] ?? array() );

		if ( array() === $occupants ) {
			echo '<div class="rc-pm-slot is-available"><span class="rc-pm-availlabel">' . esc_html__( 'Footer — open', 'romcommerce' ) . '</span></div>';
			return;
		}

		$this->render_occupied_band( $occupants );
	}

	/**
	 * Occupants of a shared slot stay on one line, numbered in the order a
	 * shopper sees them and split by a plain separator, so two modules on the
	 * same spot never read as one.
	 *
	 * @param array<int, array{id: string, title: string, category: string}> $occupants In render order.
	 * @param string                                                        $mark      Elementor asterisk, if any.
	 */
	private function render_occupied_band( array $occupants, string $mark = '' ): void {
		$shared = count( $occupants ) > 1;

		echo '<div class="rc-pm-slot is-occupied">';

		foreach ( $occupants as $index => $occupant ) {
			if ( $index > 0 ) {
				echo '<span class="rc-pm-sep" aria-hidden="true">|</span>';
			}
			echo '<span class="rc-pm-who">';
			if ( $shared ) {
				echo '<span class="rc-pm-rank">' . esc_html( (string) ( $index + 1 ) ) . '.</span>';
			}
			echo esc_html( $occupant['title'] ) . '</span>';
		}

		if ( '' !== $mark ) {
			echo '<span class="rc-pm-mark">' . esc_html( trim( $mark ) ) . '</span>';
		}

		echo '</div>';
	}

	private function render_sidebar( string $surface, array $map ): void {
		$slots = Placement::slots_for_surface( $surface );

		echo '<div class="rc-pm-side">';

		$footer_occupants = 'site' === $surface ? $this->order_footer_occupants( $map['site']['footer'] ?? array() ) : array();
		if ( count( $footer_occupants ) > 1 ) {
			$this->render_order_control( $footer_occupants );
		}

		$count = $this->surface_count( $map, $surface );
		echo '<h3 class="rc-pm-sidehead">' . esc_html(
			sprintf(
				/* translators: %d: number of modules rendering on this surface */
				_n( '%d module here', '%d modules here', $count, 'romcommerce' ),
				$count
			)
		) . '</h3>';

		if ( 0 === $count ) {
			echo '<p class="rc-pm-empty">' . esc_html__( 'No active module renders on this surface yet.', 'romcommerce' ) . '</p>';
			echo '</div>';
			return;
		}

		foreach ( $slots as $slot => $slot_label ) {
			$occupants = $map[ $surface ][ $slot ] ?? array();
			if ( 'footer' === $slot ) {
				$occupants = $this->order_footer_occupants( $occupants );
			}

			foreach ( $occupants as $occupant ) {
				$this->render_side_row( $occupant, $slot_label );
			}
		}

		echo '</div>';
	}

	/**
	 * @param array{id: string, title: string, category: string, key: string, slot: string, choices: array<int, string>} $occupant
	 */
	private function render_side_row( array $occupant, string $slot_label ): void {
		$pill = '<span class="rc-pm-pill">' . esc_html__( 'On', 'romcommerce' ) . '</span>';

		echo '<div class="rc-pm-modrow">';
		echo '<div class="rc-pm-modtop"><span class="rc-pm-modname">' . esc_html( $occupant['title'] ) . '</span>' . $pill . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $pill is built from escaped parts above.

		if ( count( $occupant['choices'] ) > 1 && '' !== $occupant['key'] ) {
			$field_id = 'rc-area-' . $occupant['id'] . '-' . $occupant['key'];

			echo '<form method="post" class="rc-pm-areaform">';
			wp_nonce_field( 'romcommerce_placement_area', 'rc_area_nonce' );
			echo '<input type="hidden" name="rc_area_module" value="' . esc_attr( $occupant['id'] ) . '">';
			echo '<label class="screen-reader-text" for="' . esc_attr( $field_id ) . '">' . esc_html__( 'Position', 'romcommerce' ) . '</label>';
			Placement::area_field( 'rc_area[' . $occupant['key'] . ']', $occupant, $field_id );
			echo '<button type="submit" class="button button-small">' . esc_html__( 'Move', 'romcommerce' ) . '</button>';
			echo '</form>';
		} else {
			echo '<p class="rc-pm-where">' . esc_html( $slot_label . $this->elementor_mark( $occupant['slot'] ) ) . '</p>';
		}

		echo '<a class="rc-pm-gear" href="' . esc_url( $this->module_url( $occupant['category'], $occupant['id'] ) ) . '">' . esc_html__( 'Open settings →', 'romcommerce' ) . '</a>';
		echo '</div>';
	}

	/**
	 * Applies a position change from a sidebar row. The requested slot is
	 * checked against the module's own declared choices in save_choices(), so
	 * this can only move a placement to a spot its module allows.
	 */
	private function maybe_handle_area_change(): void {
		if ( ! isset( $_POST['rc_area_module'], $_POST['rc_area_nonce'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rc_area_nonce'] ) ), 'romcommerce_placement_area' ) ) {
			return;
		}

		$module_id = sanitize_key( wp_unslash( $_POST['rc_area_module'] ) );
		$module    = Plugin::instance()->modules()->get( $module_id );
		if ( ! $module instanceof HasPlacements ) {
			return;
		}

		$requested = isset( $_POST['rc_area'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['rc_area'] ) ) : array();
		Placement::save_choices( $module_id, $module->placements(), $requested );

		echo '<div class="notice notice-success"><p>' . esc_html__( 'Position saved.', 'romcommerce' ) . '</p></div>';
	}

	/** @param array<int, array{id: string, title: string, category: string}> $occupants */
	private function render_order_control( array $occupants ): void {
		echo '<div class="rc-pm-order">';
		echo '<p class="rc-pm-orderhead">' . esc_html__( 'Footer — 2+ modules share this spot', 'romcommerce' ) . '</p>';
		echo '<p class="rc-pm-orderhelp">' . esc_html__( 'Both relocate into your theme footer. Set who sits on top — otherwise their order is undefined.', 'romcommerce' ) . '</p>';

		$last = count( $occupants ) - 1;
		foreach ( $occupants as $index => $occupant ) {
			echo '<div class="rc-pm-orderrow">';
			echo '<span class="rc-pm-ordername">' . esc_html( (string) ( $index + 1 ) ) . '. ' . esc_html( $occupant['title'] ) . '</span>';
			echo '<span class="rc-pm-orderarrows">';
			$this->render_move_button( $occupant['id'], 'up', 0 === $index );
			$this->render_move_button( $occupant['id'], 'down', $index === $last );
			echo '</span>';
			echo '</div>';
		}

		echo '</div>';
	}

	private function render_move_button( string $module_id, string $dir, bool $disabled ): void {
		$arrow = 'up' === $dir ? '&uarr;' : '&darr;';

		echo '<form method="post" class="rc-pm-moveform">';
		wp_nonce_field( 'romcommerce_footer_order', 'rc_footer_nonce' );
		echo '<input type="hidden" name="rc_footer_move" value="' . esc_attr( $module_id ) . '">';
		echo '<input type="hidden" name="rc_footer_dir" value="' . esc_attr( $dir ) . '">';
		echo '<button type="submit" class="rc-pm-move"' . ( $disabled ? ' disabled' : '' ) . '>' . $arrow . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $arrow is a static HTML entity literal.
		echo '</form>';
	}

	/**
	 * Applies an up/down swap to the footer stacking order. Reads $_POST here,
	 * local to the verified nonce + capability check, per the project's
	 * handler-is-POST-free convention.
	 *
	 * @param array<int, string> $ordered_ids Footer occupants in current display order.
	 */
	private function maybe_handle_reorder( array $ordered_ids ): void {
		if ( ! isset( $_POST['rc_footer_move'], $_POST['rc_footer_nonce'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rc_footer_nonce'] ) ), 'romcommerce_footer_order' ) ) {
			return;
		}

		$move = sanitize_key( wp_unslash( $_POST['rc_footer_move'] ) );
		$dir  = isset( $_POST['rc_footer_dir'] ) && 'down' === sanitize_key( wp_unslash( $_POST['rc_footer_dir'] ) ) ? 1 : -1;

		$index = array_search( $move, $ordered_ids, true );
		if ( false === $index ) {
			return;
		}

		$target = (int) $index + $dir;
		if ( $target < 0 || $target >= count( $ordered_ids ) ) {
			return;
		}

		$from = (int) $index;
		$swap = $ordered_ids[ $from ];

		$ordered_ids[ $from ]   = $ordered_ids[ $target ];
		$ordered_ids[ $target ] = $swap;

		Placement::set_footer_order( $ordered_ids );
	}

	/**
	 * @param array<int, array{id: string, title: string, category: string}> $occupants
	 * @return array<int, array{id: string, title: string, category: string}> same list, sorted by footer rank (ties broken by id for 7.4's unstable usort).
	 */
	private function order_footer_occupants( array $occupants ): array {
		usort(
			$occupants,
			static function ( array $a, array $b ): int {
				$rank = Placement::footer_rank( $a['id'] ) - Placement::footer_rank( $b['id'] );

				return 0 !== $rank ? $rank : strcmp( $a['id'], $b['id'] );
			}
		);

		return $occupants;
	}

	/** @return array<int, string> Footer occupant ids in display order. */
	private function footer_order_ids( array $map ): array {
		$ids = array();
		foreach ( $this->order_footer_occupants( $map['site']['footer'] ?? array() ) as $occupant ) {
			$ids[] = $occupant['id'];
		}

		return $ids;
	}

	private function surface_count( array $map, string $surface ): int {
		$count = 0;
		foreach ( $map[ $surface ] ?? array() as $occupants ) {
			$count += count( $occupants );
		}

		return $count;
	}

	private function current_surface(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- read-only nav state; the AJAX path is nonce-verified in Admin\Ajax::handle() before this runs.
		$surface = isset( $_REQUEST['surface'] ) ? sanitize_key( wp_unslash( $_REQUEST['surface'] ) ) : '';

		return Placement::surface_exists( $surface ) ? $surface : 'product';
	}

	private function surface_url( string $surface ): string {
		return add_query_arg(
			array(
				'page'    => 'romcommerce',
				'tab'     => 'placements',
				'surface' => $surface,
			),
			admin_url( 'admin.php' )
		);
	}

	private function module_url( string $category, string $module_id ): string {
		return add_query_arg(
			array(
				'page'     => 'romcommerce',
				'tab'      => 'modules',
				'category' => $category,
				'module'   => $module_id,
			),
			admin_url( 'admin.php' )
		);
	}
}
