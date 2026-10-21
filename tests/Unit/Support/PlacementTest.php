<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Support;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use RomCommerce\Support\Placement;
use RomCommerce\Tests\TestCase;

final class PlacementTest extends TestCase {

	public function test_hook_wires_the_callback_to_the_slots_registered_hook_and_default_priority(): void {
		$callback = static function (): void {};

		Functions\expect( 'add_action' )->once()->with( 'woocommerce_review_order_before_submit', $callback, 5 );

		Placement::hook( 'checkout_submit', $callback );
	}

	public function test_hook_lets_the_caller_override_the_slots_default_priority(): void {
		$callback = static function (): void {};

		Functions\expect( 'add_action' )->once()->with( 'wp_footer', $callback, 20 );

		Placement::hook( 'footer', $callback, 20 );
	}

	public function test_hook_does_nothing_for_an_unknown_slot(): void {
		Functions\expect( 'add_action' )->never();

		Placement::hook( 'not-a-real-slot', static function (): void {} );
	}

	public function test_hook_block_wires_the_slots_block_equivalent(): void {
		Filters\expectAdded( 'render_block_woocommerce/checkout-terms-block' )->once();

		self::assertTrue( Placement::hook_block( 'checkout_submit', static function (): void {} ) );
	}

	public function test_hook_block_does_nothing_for_a_slot_without_a_block_equivalent(): void {
		Filters\expectAdded( 'render_block_woocommerce/cart' )->never();

		self::assertFalse( Placement::hook_block( 'product_page_summary', static function (): void {} ) );
	}

	public function test_every_cart_and_checkout_slot_has_a_block_equivalent(): void {
		foreach ( array( 'cart', 'checkout' ) as $surface ) {
			foreach ( Placement::surface_slots( $surface ) as $slot ) {
				self::assertTrue( Placement::hook_block( $slot, static function (): void {} ), $slot );
			}
		}
	}

	public function test_footer_relocation_script_embeds_the_json_encoded_element_id(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$script = Placement::footer_relocation_script( 'romcommerce-lg-anchor' );

		self::assertStringContainsString( 'document.getElementById("romcommerce-lg-anchor")', $script );
		self::assertStringContainsString( "footer[role='contentinfo']", $script );
		self::assertStringContainsString( 'footer.appendChild(el)', $script );
	}

	public function test_footer_relocation_script_re_sorts_ordered_siblings(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$script = Placement::footer_relocation_script( 'romcommerce-sal-pictogram' );

		self::assertStringContainsString( 'data-rc-footer-order', $script );
		self::assertStringContainsString( '.sort(', $script );
	}

	public function test_surfaces_are_returned_in_tab_order(): void {
		Functions\stubTranslationFunctions();

		self::assertSame(
			array( 'product', 'cart', 'checkout', 'site' ),
			array_keys( Placement::surfaces() )
		);
	}

	public function test_surface_exists_accepts_only_known_surface_ids(): void {
		self::assertTrue( Placement::surface_exists( 'checkout' ) );
		self::assertFalse( Placement::surface_exists( 'Checkout' ) );
		self::assertFalse( Placement::surface_exists( '' ) );
	}

	public function test_slots_for_surface_lists_only_that_surfaces_slots_in_declared_order(): void {
		Functions\stubTranslationFunctions();

		self::assertSame(
			array(
				'product_top',
				'product_before_title',
				'product_after_title',
				'product_page_summary',
				'product_after_excerpt',
				'product_before_add_to_cart',
				'product_before_form',
				'product_before_button',
				'add_to_cart',
				'product_after_form',
				'product_after_add_to_cart',
				'product_meta_start',
				'product_meta_end',
				'product_after_meta',
				'product_share',
				'product_after_summary',
				'product_after_tabs',
				'product_before_related',
				'product_bottom',
			),
			array_keys( Placement::slots_for_surface( 'product' ) )
		);

		self::assertSame(
			array(
				'checkout_top',
				'checkout_before_customer',
				'checkout_after_billing',
				'checkout_before_notes',
				'checkout_after_notes',
				'checkout_after_customer',
				'checkout_review',
				'checkout_before_payment',
				'checkout_after_methods',
				'checkout_submit',
				'checkout_after_submit',
				'checkout_bottom',
			),
			Placement::surface_slots( 'checkout' )
		);

		self::assertSame(
			array(
				'footer' => 'Site footer',
				'fixed'  => 'Fixed corner',
			),
			Placement::slots_for_surface( 'site' )
		);
	}

	public function test_slot_metadata_accessors(): void {
		Functions\stubTranslationFunctions();

		self::assertTrue( Placement::slot_exists( 'checkout_submit' ) );
		self::assertFalse( Placement::slot_exists( 'nope' ) );

		self::assertSame( 'checkout', Placement::slot_surface( 'checkout_submit' ) );
		self::assertSame( 'Above the Place Order button', Placement::slot_label( 'checkout_submit' ) );

		self::assertTrue( Placement::slot_relocates( 'footer' ) );
		self::assertFalse( Placement::slot_relocates( 'fixed' ) );
	}

	public function test_footer_rank_follows_the_stored_order_and_sends_unranked_ids_last(): void {
		Functions\when( 'get_option' )->justReturn( array( 'legal-guarantee-notice', 'sal-pictogram' ) );

		self::assertSame( 0, Placement::footer_rank( 'legal-guarantee-notice' ) );
		self::assertSame( 1, Placement::footer_rank( 'sal-pictogram' ) );
		self::assertSame( 2, Placement::footer_rank( 'not-in-the-list' ) );
	}

	public function test_set_footer_order_persists_sanitized_keys(): void {
		Functions\expect( 'sanitize_key' )->andReturnUsing(
			static function ( string $value ): string {
				return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) );
			}
		);

		Functions\expect( 'update_option' )->once()->with(
			'romcommerce_placement_footer_order',
			array( 'sal-pictogram', 'legal-guarantee-notice' )
		);

		Placement::set_footer_order( array( 'sal-pictogram', 'legal-guarantee-notice' ) );
	}

	public function test_surface_slots_matches_slots_for_surface_without_needing_labels(): void {
		Functions\stubTranslationFunctions();

		self::assertSame( array_keys( Placement::slots_for_surface( 'cart' ) ), Placement::surface_slots( 'cart' ) );
	}

	public function test_every_price_adjacent_slot_is_a_product_slot(): void {
		foreach ( Placement::price_adjacent_slots() as $slot ) {
			self::assertSame( 'product', Placement::slot_surface( $slot ), $slot );
		}
	}

	public function test_resolve_keeps_the_default_when_nothing_is_stored(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$declared = array( $this->pricePlacement() );

		self::assertSame( $declared, Placement::resolve( 'price-history', $declared ) );
	}

	public function test_resolve_moves_to_an_allowed_choice_and_drops_the_default_slots_priority(): void {
		Functions\when( 'get_option' )->justReturn( array( 'price-history' => array( 'product' => 'product_after_title' ) ) );

		$resolved = Placement::resolve( 'price-history', array( $this->pricePlacement() ) );

		self::assertSame( 'product_after_title', $resolved[0]['slot'] );
		self::assertArrayNotHasKey( 'priority', $resolved[0] );
	}

	public function test_resolve_ignores_a_stored_slot_outside_the_placements_choices(): void {
		Functions\when( 'get_option' )->justReturn( array( 'price-history' => array( 'product' => 'checkout_bottom' ) ) );

		$resolved = Placement::resolve( 'price-history', array( $this->pricePlacement() ) );

		self::assertSame( 'product_page_summary', $resolved[0]['slot'] );
		self::assertSame( 11, $resolved[0]['priority'] );
	}

	public function test_save_choices_stores_only_allowed_slots_and_keeps_other_modules(): void {
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array( 'free-shipping-bar' => array( 'cart' => 'cart_bottom' ) ) );

		Functions\expect( 'update_option' )->once()->with(
			'romcommerce_placement_choices',
			array(
				'free-shipping-bar' => array( 'cart' => 'cart_bottom' ),
				'price-history'     => array( 'product' => 'product_after_excerpt' ),
			)
		);

		Placement::save_choices(
			'price-history',
			array(
				$this->pricePlacement(),
				array(
					'key'     => 'other',
					'slot'    => 'product_page_summary',
					'active'  => true,
					'choices' => array( 'product_page_summary' ),
				),
			),
			array(
				'product' => 'product_after_excerpt',
				'other'   => 'wp_head',
			)
		);
	}

	/**
	 * Merchants' stored choices name these keys (0.6–0.8), so each must keep
	 * its hook and priority: changing either would move a placement on update.
	 */
	public function test_slot_keys_already_in_use_keep_their_hook_and_priority(): void {
		$expected = array(
			'footer'                      => array( 'wp_footer', 10 ),
			'fixed'                       => array( 'wp_footer', 10 ),
			'product_before_title'        => array( 'woocommerce_single_product_summary', 4 ),
			'product_after_title'         => array( 'woocommerce_single_product_summary', 6 ),
			'product_page_summary'        => array( 'woocommerce_single_product_summary', 15 ),
			'product_after_excerpt'       => array( 'woocommerce_single_product_summary', 25 ),
			'product_before_add_to_cart'  => array( 'woocommerce_single_product_summary', 29 ),
			'add_to_cart'                 => array( 'woocommerce_after_add_to_cart_button', 10 ),
			'product_after_add_to_cart'   => array( 'woocommerce_single_product_summary', 31 ),
			'product_after_meta'          => array( 'woocommerce_single_product_summary', 45 ),
			'product_after_summary'       => array( 'woocommerce_after_single_product_summary', 5 ),
			'cart_top'                    => array( 'woocommerce_before_cart', 20 ),
			'cart_before_table'           => array( 'woocommerce_before_cart_table', 10 ),
			'cart'                        => array( 'woocommerce_after_cart_table', 10 ),
			'cart_before_totals'          => array( 'woocommerce_before_cart_totals', 10 ),
			'cart_before_checkout_button' => array( 'woocommerce_proceed_to_checkout', 5 ),
			'cart_bottom'                 => array( 'woocommerce_after_cart', 10 ),
			'checkout_top'                => array( 'woocommerce_before_checkout_form', 20 ),
			'checkout_before_customer'    => array( 'woocommerce_checkout_before_customer_details', 10 ),
			'checkout_after_customer'     => array( 'woocommerce_checkout_after_customer_details', 10 ),
			'checkout_review'             => array( 'woocommerce_checkout_before_order_review', 10 ),
			'checkout_before_payment'     => array( 'woocommerce_review_order_before_payment', 10 ),
			'checkout_submit'             => array( 'woocommerce_review_order_before_submit', 5 ),
			'checkout_after_submit'       => array( 'woocommerce_review_order_after_submit', 10 ),
			'checkout_bottom'             => array( 'woocommerce_after_checkout_form', 10 ),
		);

		$slots = $this->slots();
		foreach ( $expected as $slot => list( $hook, $priority ) ) {
			self::assertArrayHasKey( $slot, $slots, $slot );
			self::assertSame( $hook, $slots[ $slot ]['hook'], $slot );
			self::assertSame( $priority, Placement::slot_priority( $slot ), $slot );
		}
	}

	/**
	 * WooCommerce's block template compatibility layer replays these hooks in
	 * the wrong place (summary hooks clustered above the short description;
	 * after-summary and share around Product Details), so their slots need
	 * their own block path. product_before_related is the exception: the layer
	 * fires it after Product Details, above the related products, as labelled.
	 */
	public function test_slots_on_hooks_the_block_compatibility_layer_misplaces_have_a_block_path(): void {
		$misplaced = array( 'woocommerce_single_product_summary', 'woocommerce_after_single_product_summary', 'woocommerce_share' );

		foreach ( $this->slots() as $slot => $config ) {
			if ( ! in_array( $config['hook'], $misplaced, true ) || 'product_before_related' === $slot ) {
				continue;
			}

			self::assertNotEmpty( $config['template_blocks'] ?? array(), $slot );
		}
	}

	public function test_elementor_pro_product_templates_run_only_the_document_and_widget_hooks(): void {
		$runs = array();
		foreach ( Placement::surface_slots( 'product' ) as $slot ) {
			if ( Placement::slot_runs_in_elementor( $slot ) ) {
				$runs[] = $slot;
			}
		}

		self::assertSame(
			array( 'product_top', 'product_before_form', 'product_before_button', 'add_to_cart', 'product_after_form', 'product_meta_start', 'product_meta_end', 'product_after_tabs', 'product_bottom' ),
			$runs
		);

		foreach ( array( 'cart', 'checkout', 'site' ) as $surface ) {
			foreach ( Placement::surface_slots( $surface ) as $slot ) {
				self::assertTrue( Placement::slot_runs_in_elementor( $slot ), $slot );
			}
		}
	}

	public function test_hook_wires_a_template_block_slot_to_its_block_and_skips_the_compatibility_replay(): void {
		$calls    = 0;
		$callback = static function () use ( &$calls ): void {
			++$calls;
		};
		$wired    = null;

		Functions\expect( 'add_action' )->once()->with( 'woocommerce_single_product_summary', \Mockery::type( 'Closure' ), 4 )->andReturnUsing(
			static function ( $hook, $closure ) use ( &$wired ): void {
				$wired = $closure;
			}
		);

		Placement::hook( 'product_before_title', $callback );

		Functions\when( 'wp_is_block_theme' )->justReturn( true );
		Functions\when( 'doing_filter' )->justReturn( true );
		$wired();
		self::assertSame( 0, $calls, 'replayed by the block template compatibility layer' );

		Functions\when( 'doing_filter' )->justReturn( false );
		$wired();
		self::assertSame( 1, $calls, 'fired by a classic template' );
	}

	public function test_an_in_form_slot_queues_the_layout_css_once(): void {
		( new \ReflectionProperty( Placement::class, 'in_form_css_queued' ) )->setValue( null, false );

		Actions\expectAdded( 'wp_enqueue_scripts' )->once()->with( array( Placement::class, 'enqueue_in_form_css' ) );

		Placement::hook( 'add_to_cart', static function (): void {} );
		Placement::hook( 'product_before_button', static function (): void {} );
	}

	public function test_in_form_css_gives_our_elements_a_full_line_in_flex_add_to_cart_layouts(): void {
		$css = Placement::in_form_css();

		self::assertStringContainsString( ':has(', $css );
		self::assertStringContainsString( 'flex-wrap:wrap!important', $css );
		self::assertStringContainsString( 'flex:0 0 100%!important', $css );
		foreach ( array( 'form.cart', '.woocommerce-variation-add-to-cart', '.e-atc-qty-button-holder' ) as $container ) {
			self::assertStringContainsString( $container, $css );
		}
	}

	public function test_elementor_note_warns_when_the_current_choice_never_shows_there(): void {
		Functions\stubTranslationFunctions();

		$placement = array(
			'slot'    => 'product_page_summary',
			'choices' => array( 'product_page_summary', 'product_after_form' ),
		);
		self::assertStringStartsWith( '* Your current choice', Placement::elementor_note( $placement ) );

		$placement['slot'] = 'product_after_form';
		self::assertStringStartsWith( '* Doesn', Placement::elementor_note( $placement ) );

		self::assertSame(
			'',
			Placement::elementor_note(
				array(
					'slot'    => 'cart',
					'choices' => Placement::surface_slots( 'cart' ),
				)
			)
		);
	}

	public function test_area_field_groups_product_choices_under_headings_in_page_order(): void {
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'selected' )->alias(
			static function ( $a, $b ): string {
				return (string) $a === (string) $b ? ' selected=\'selected\'' : '';
			}
		);

		ob_start();
		Placement::area_field(
			'rc_area[product]',
			array(
				'slot'    => 'add_to_cart',
				'choices' => array( 'product_after_excerpt', 'product_before_form', 'add_to_cart', 'product_bottom' ),
			)
		);
		$html = (string) ob_get_clean();

		self::assertSame( 3, substr_count( $html, '<optgroup' ) );
		self::assertSame( 3, substr_count( $html, '</optgroup>' ) );
		self::assertMatchesRegularExpression( '/label="Product summary">.*After the short description.*<\/optgroup><optgroup label="Add to Cart">.*Above the Add to Cart form.*Below the Add to Cart button.*<\/optgroup><optgroup label="Below the summary">.*Bottom of the product page/s', $html );
		self::assertStringContainsString( 'value="add_to_cart" selected', $html );
		self::assertStringNotContainsString( '*', $html, 'no Elementor marks without Elementor' );
	}

	public function test_area_field_leaves_cart_choices_ungrouped(): void {
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'selected' )->justReturn( '' );

		ob_start();
		Placement::area_field(
			'rc_area[cart]',
			array(
				'slot'    => 'cart',
				'choices' => Placement::surface_slots( 'cart' ),
			)
		);

		self::assertStringNotContainsString( '<optgroup', (string) ob_get_clean() );
	}

	/**
	 * Own process: ELEMENTOR_VERSION can't be undefined again for later tests.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_area_field_marks_spots_elementor_never_runs_when_elementor_is_active(): void {
		define( 'ELEMENTOR_VERSION', '4.3.4' );
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'selected' )->justReturn( '' );

		ob_start();
		Placement::area_field(
			'rc_area[product]',
			array(
				'slot'    => 'product_after_meta',
				'choices' => array( 'product_after_meta', 'product_after_form' ),
			)
		);
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'After SKU, categories and tags *</option>', $html );
		self::assertStringContainsString( 'Below the Add to Cart form</option>', $html );
		self::assertStringContainsString( 'rc-area-elementor is-warning', $html );
	}

	/** @return array<string, array<string, mixed>> */
	private function slots(): array {
		return ( new \ReflectionClassConstant( Placement::class, 'SLOTS' ) )->getValue();
	}

	/** @return array<string, mixed> */
	private function pricePlacement(): array {
		return array(
			'key'      => 'product',
			'slot'     => 'product_page_summary',
			'active'   => true,
			'priority' => 11,
			'choices'  => Placement::price_adjacent_slots(),
		);
	}
}
