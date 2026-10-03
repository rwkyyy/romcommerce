<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Support;

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
				'product_page_summary' => 'After the price',
				'add_to_cart'          => 'After Add to Cart',
			),
			Placement::slots_for_surface( 'product' )
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
}
