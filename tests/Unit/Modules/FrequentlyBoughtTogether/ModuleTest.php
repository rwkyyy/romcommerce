<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\FrequentlyBoughtTogether;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use RomCommerce\Modules\FrequentlyBoughtTogether\Module;
use RomCommerce\Tests\TestCase;

final class ModuleTest extends TestCase {

	public function test_picks_are_unique_positive_not_the_product_itself_and_capped_at_four(): void {
		self::assertSame( array( 7, 3, 9, 4 ), Module::clean_ids( array( 7, 0, 3, 7, 12, 9, 4, 5 ), 12 ) );
	}

	public function test_the_add_to_cart_limit_leaves_room_for_the_product_itself(): void {
		self::assertSame( array( 1, 2, 3, 4, 5 ), Module::clean_ids( array( 1, 2, 3, 4, 5, 6 ), 0, 5 ) );
	}

	public function test_the_bundle_is_never_offered_inside_the_add_to_cart_form_or_the_meta_line(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$choices = ( new Module() )->placements()[0]['choices'];

		foreach ( array( 'product_before_button', 'add_to_cart', 'product_meta_start', 'product_meta_end' ) as $excluded ) {
			self::assertNotContains( $excluded, $choices );
		}
		foreach ( array( 'product_after_summary', 'product_page_summary', 'product_after_form', 'product_bottom' ) as $kept ) {
			self::assertContains( $kept, $choices );
		}
	}

	/**
	 * The parfumuri-refan.ro report (2026-10-14): an Elementor Pro product
	 * template never fires the chosen summary hooks, so the bundle never showed.
	 */
	public function test_the_bottom_of_the_product_page_is_wired_as_a_fallback(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$module = new Module();

		Actions\expectAdded( 'woocommerce_after_single_product' )->once()->with( array( $module, 'render' ), 99 );

		$module->register_placements();
	}

	public function test_no_fallback_is_added_when_the_merchant_switched_it_off(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $name ) {
				return 'romcommerce_frequently_bought_together' === $name ? array( 'fallback' => false ) : array();
			}
		);
		$module = new Module();

		Actions\expectAdded( 'woocommerce_after_single_product' )->never();

		$module->register_placements();
	}

	public function test_no_fallback_is_added_when_the_bundle_already_sits_at_the_bottom(): void {
		Functions\when( 'get_option' )->justReturn( array( 'frequently-bought-together' => array( 'product' => 'product_bottom' ) ) );
		$module = new Module();

		Actions\expectAdded( 'woocommerce_after_single_product' )->once()->with( array( $module, 'render' ), 10 );

		$module->register_placements();
	}

	public function test_the_shortcode_prints_nothing_without_a_product(): void {
		Functions\when( 'shortcode_atts' )->alias(
			static function ( array $defaults, array $atts ): array {
				return array_merge( $defaults, $atts );
			}
		);
		Functions\when( 'absint' )->alias( 'abs' );
		Functions\when( 'get_the_ID' )->justReturn( 0 );
		Functions\when( 'wc_get_product' )->justReturn( false );

		self::assertSame( '', ( new Module() )->shortcode( array() ) );
	}
}
