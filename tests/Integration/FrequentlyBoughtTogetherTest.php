<?php
/**
 * Frequently bought together against real WooCommerce products and cart.
 *
 * @package RomCommerce
 */

declare(strict_types=1);

namespace RomCommerce\Tests\Integration;

use RomCommerce\Modules\FrequentlyBoughtTogether\Module;
use RomCommerce\Plugin;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;

final class FrequentlyBoughtTogetherTest extends TestCase {

	private Module $module;

	public function set_up(): void {
		parent::set_up();
		$module = Plugin::instance()->modules()->get( 'frequently-bought-together' );
		self::assertInstanceOf( Module::class, $module );
		$this->module = $module;
		WC()->cart->empty_cart();
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		unset( $GLOBALS['product'] );
		parent::tear_down();
	}

	/** @return array{0: WC_Product_Variable, 1: WC_Product_Variation} */
	private function variable_product(): array {
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Mărime' );
		$attribute->set_options( array( 'S', 'M' ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$parent = new WC_Product_Variable();
		$parent->set_name( 'Tricou' );
		$parent->set_attributes( array( $attribute ) );
		$parent->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_attributes( array( 'marime' => 'M' ) );
		$variation->set_regular_price( '49' );
		$variation->save();

		return array( $parent, $variation );
	}

	public function test_saving_keeps_up_to_four_clean_picks(): void {
		$product                      = $this->create_product();
		$_POST['romcommerce_fbt_ids'] = array( '5', '0', (string) $product->get_id(), '5', '6', '7', '8', '9' );

		$this->module->save_product_field( $product );
		$product->save();

		self::assertSame( array( 5, 6, 7, 8 ), get_post_meta( $product->get_id(), Module::META_KEY, true ) );
	}

	public function test_only_buyable_picks_are_shown_with_the_current_product(): void {
		$product  = $this->create_product( '120' );
		$case     = $this->create_product( '39' );
		$sold_out = $this->create_product( '25' );
		$sold_out->set_stock_status( 'outofstock' );
		$sold_out->save();
		$product->update_meta_data( Module::META_KEY, array( $case->get_id(), $sold_out->get_id() ) );
		$product->save();

		$this->go_to( get_permalink( $product->get_id() ) );
		$GLOBALS['product'] = wc_get_product( $product->get_id() );
		$html               = get_echo( array( $this->module, 'render' ) );

		self::assertStringContainsString( 'value="' . $product->get_id() . '"', $html );
		self::assertStringContainsString( 'value="' . $case->get_id() . '"', $html );
		self::assertStringNotContainsString( 'value="' . $sold_out->get_id() . '"', $html );
		self::assertStringContainsString( '159', $html );
	}

	public function test_nothing_shows_without_buyable_picks(): void {
		$product = $this->create_product();
		$this->go_to( get_permalink( $product->get_id() ) );
		$GLOBALS['product'] = wc_get_product( $product->get_id() );

		self::assertSame( '', get_echo( array( $this->module, 'render' ) ) );
	}

	public function test_a_variable_product_waits_for_a_variation_choice(): void {
		list( $parent ) = $this->variable_product();
		$case           = $this->create_product( '39' );
		$parent->update_meta_data( Module::META_KEY, array( $case->get_id() ) );
		$parent->save();

		$this->go_to( get_permalink( $parent->get_id() ) );
		$GLOBALS['product'] = wc_get_product( $parent->get_id() );
		$html               = get_echo( array( $this->module, 'render' ) );

		self::assertStringContainsString( 'data-rc-fbt-current', $html );
		self::assertMatchesRegularExpression( '/data-rc-fbt-current><label><input type="checkbox" name="rc_fbt\[\]" value="" data-price="0" disabled>/', $html );
	}

	public function test_adding_puts_products_and_variations_in_the_cart_once_each(): void {
		$product             = $this->create_product();
		list( , $variation ) = $this->variable_product();
		$sold_out            = $this->create_product();
		$sold_out->set_stock_status( 'outofstock' );
		$sold_out->save();

		$added = $this->module->add_to_cart( array( $product->get_id(), $variation->get_id(), $product->get_id(), $sold_out->get_id() ) );

		self::assertCount( 2, $added );
		self::assertSame( 2, WC()->cart->get_cart_contents_count() );
		$variations = array_column( WC()->cart->get_cart(), 'variation_id' );
		self::assertContains( $variation->get_id(), $variations );
	}
}
