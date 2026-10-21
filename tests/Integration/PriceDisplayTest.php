<?php
/**
 * Price History's crossed-out reference price, through WooCommerce's own
 * get_price_html() and sale badge.
 *
 * @package RomCommerce
 */

declare(strict_types=1);

namespace RomCommerce\Tests\Integration;

use RomCommerce\Modules\PriceHistory\Display;
use RomCommerce\Modules\PriceHistory\Log;
use RomCommerce\Modules\PriceHistory\Reference;
use WC_Product;

final class PriceDisplayTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		Log::ensure_table();
	}

	private function price_at( int $product_id, string $price, int $days_ago ): void {
		Log::insert( $product_id, wc_format_decimal( $price, 4 ), gmdate( 'Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS ) );
	}

	/** The owner's example: regular 150, a 100 promotion 20 days ago, an 80 sale today. */
	private function product_with_history(): WC_Product {
		$product = $this->create_product( '150' );
		$this->price_at( $product->get_id(), '150', 60 );
		$this->price_at( $product->get_id(), '100', 20 );
		$this->price_at( $product->get_id(), '150', 10 );
		$product->set_sale_price( '80' );
		$product->save();

		return wc_get_product( $product->get_id() );
	}

	private function text( string $html ): string {
		return html_entity_decode( wp_strip_all_tags( $html ) );
	}

	/** @return array<int, string> the amounts inside <del>. */
	private function crossed_out( string $html ): array {
		preg_match_all( '#<del[^>]*>(.*?)</del>#s', $html, $matches );

		return array_map( array( $this, 'text' ), $matches[1] );
	}

	public function test_the_reference_is_crossed_out_instead_of_the_regular_price(): void {
		$html = $this->product_with_history()->get_price_html();

		$this->assertCount( 1, $this->crossed_out( $html ) );
		$this->assertStringContainsString( '100', $this->crossed_out( $html )[0] );
		$this->assertStringContainsString( '80', $this->text( $html ) );
		$this->assertStringNotContainsString( '150', $this->text( $html ) );
	}

	public function test_the_stored_prices_are_left_alone(): void {
		$product = $this->product_with_history();

		$this->assertSame( '150', $product->get_regular_price() );
		$this->assertSame( '80', $product->get_sale_price() );
		$this->assertTrue( $product->is_on_sale() );
	}

	public function test_without_history_before_the_sale_nothing_is_crossed_out_and_the_badge_is_hidden(): void {
		$product = wc_get_product( $this->create_product( '150', '80' )->get_id() );

		$html = $product->get_price_html();
		$this->assertSame( array(), $this->crossed_out( $html ) );
		$this->assertStringNotContainsString( '150', $this->text( $html ) );
		$this->assertSame( '', apply_filters( 'woocommerce_sale_flash', '<span class="onsale">Sale!</span>', get_post( $product->get_id() ), $product ) );
	}

	public function test_a_real_reduction_keeps_its_sale_badge(): void {
		$product = $this->product_with_history();

		$this->assertSame( '<span class="onsale">Sale!</span>', apply_filters( 'woocommerce_sale_flash', '<span class="onsale">Sale!</span>', get_post( $product->get_id() ), $product ) );
	}

	public function test_a_product_not_on_sale_is_untouched(): void {
		$product = wc_get_product( $this->create_product( '150' )->get_id() );

		$this->assertSame( wc_price( 150 ), $product->get_price_html() );
	}

	public function test_the_optional_percentage_is_measured_from_the_reference(): void {
		$product = $this->product_with_history();

		$html = ( new Display( true ) )->price_html( '', $product );

		$this->assertStringContainsString( '−20%', $this->text( $html ) );
	}

	public function test_the_percentage_is_rounded_down(): void {
		$this->assertSame( 19, Display::percent( 99.99, 80.0 ) );
		$this->assertSame( 20, Display::percent( 100.0, 80.0 ) );
	}

	public function test_the_cached_reference_follows_a_new_price(): void {
		$product = $this->product_with_history();
		$this->assertSame( '100', Reference::for_product( $product ) );
		$this->assertIsArray( get_post_meta( $product->get_id(), Reference::META_KEY, true ) );

		$product->set_sale_price( '' );
		$product->save();
		$product = wc_get_product( $product->get_id() );

		$this->assertSame( '', get_post_meta( $product->get_id(), Reference::META_KEY, true ) );
		$this->assertSame( '80', Reference::for_product( $product ), 'Back at 150: the lowest price in the 30 days before was the 80 sale.' );
	}

	public function test_a_variable_product_with_one_price_crosses_out_the_lowest_variation_reference(): void {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Variabil' );
		$parent->save();

		$references = array( '100', '120' );
		foreach ( $references as $reference ) {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_regular_price( '150' );
			$variation->save();
			$this->price_at( $variation->get_id(), $reference, 20 );
			$this->price_at( $variation->get_id(), '150', 10 );
			$variation->set_sale_price( '80' );
			$variation->save();
		}
		\WC_Product_Variable::sync( $parent->get_id() );
		wc_delete_product_transients( $parent->get_id() );

		$crossed = $this->crossed_out( wc_get_product( $parent->get_id() )->get_price_html() );

		$this->assertCount( 1, $crossed );
		$this->assertStringContainsString( '100', $crossed[0] );
	}

	/**
	 * Calls the filter WordPress applies to the block's output rather than
	 * rendering WooCommerce's block, whose markup and context differ between
	 * the WooCommerce versions this suite runs on.
	 */
	public function test_the_block_theme_sale_badge_follows_the_same_rule(): void {
		$no_history = $this->create_product( '150', '80' );
		$reduced    = $this->product_with_history();
		$badge      = '<span class="wc-block-components-product-sale-badge__text">Sale</span>';
		$filter     = 'render_block_woocommerce/product-sale-badge';

		$parsed = static function ( int $product_id ): array {
			return array(
				'blockName' => 'woocommerce/product-sale-badge',
				'attrs'     => array( 'productId' => $product_id ),
			);
		};

		$this->assertSame( '', apply_filters( $filter, $badge, $parsed( $no_history->get_id() ), null ) );
		$this->assertSame( $badge, apply_filters( $filter, $badge, $parsed( $reduced->get_id() ), null ) );

		$GLOBALS['product'] = wc_get_product( $no_history->get_id() );
		$this->assertSame( '', apply_filters( $filter, $badge, array( 'attrs' => array() ), null ) );

		$type = \WP_Block_Type_Registry::get_instance()->get_registered( 'woocommerce/product-sale-badge' );
		if ( $type && in_array( 'postId', (array) $type->uses_context, true ) ) {
			$block = new \WP_Block( array( 'blockName' => 'woocommerce/product-sale-badge' ), array( 'postId' => $reduced->get_id() ) );
			$this->assertSame( $badge, apply_filters( $filter, $badge, array( 'attrs' => array() ), $block ), 'Query-loop context wins over the global product.' );
		}
	}
}
