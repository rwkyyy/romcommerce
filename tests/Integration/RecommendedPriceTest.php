<?php
/**
 * The recommended retail price (PRP) line on product pages and variations.
 *
 * @package RomCommerce
 */

declare(strict_types=1);

namespace RomCommerce\Tests\Integration;

use RomCommerce\Modules\RecommendedPrice\Module;
use WC_Product;

final class RecommendedPriceTest extends TestCase {

	private function with_prp( WC_Product $product, string $prp ): WC_Product {
		( new Module() )->store( $product, $prp );
		$product->save();

		return wc_get_product( $product->get_id() );
	}

	private function text( string $html ): string {
		return html_entity_decode( wp_strip_all_tags( $html ) );
	}

	public function test_a_prp_above_the_price_is_shown_as_a_plain_labelled_line(): void {
		$line = ( new Module() )->line( $this->with_prp( $this->create_product( '120' ), '150' ) );

		$this->assertStringContainsString( 'class="romcommerce-prp"', $line );
		$this->assertStringContainsString( '<abbr', $line );
		$this->assertStringContainsString( '150', $this->text( $line ) );
		$this->assertStringNotContainsString( '<del', $line );
		$this->assertStringNotContainsString( '%', $this->text( $line ) );
	}

	public function test_a_prp_not_above_the_price_is_hidden(): void {
		$this->assertSame( '', ( new Module() )->line( $this->with_prp( $this->create_product( '150' ), '150' ) ) );
		$this->assertSame( '', ( new Module() )->line( $this->with_prp( $this->create_product( '150' ), '120' ) ) );
	}

	public function test_blank_zero_or_invalid_input_removes_the_prp(): void {
		$product = $this->with_prp( $this->create_product( '100' ), '150' );

		foreach ( array( '', '0', 'abc', '-5' ) as $input ) {
			$product = $this->with_prp( $this->with_prp( $product, '150' ), $input );
			$this->assertSame( '', $product->get_meta( Module::META_KEY ), "Input: '{$input}'" );
		}
	}

	public function test_a_variation_carries_its_line_in_the_price_html_woocommerce_swaps_in(): void {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Variabil' );
		$parent->save();
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '100' );
		$variation->save();
		$variation = $this->with_prp( $variation, '130' );

		$data = wc_get_product( $parent->get_id() )->get_available_variation( $variation );

		$this->assertStringContainsString( 'romcommerce-prp', $data['price_html'] );
		$this->assertStringContainsString( '130', $this->text( $data['price_html'] ) );
	}

	public function test_the_product_page_renders_the_line_after_the_price(): void {
		$GLOBALS['product'] = $this->with_prp( $this->create_product( '100' ), '130' );

		ob_start();
		do_action( 'woocommerce_single_product_summary' );
		$summary = (string) ob_get_clean();

		$this->assertStringContainsString( 'romcommerce-prp', $summary );
		$this->assertLessThan( strpos( $summary, 'romcommerce-prp' ), strpos( $summary, 'class="price"' ) );
	}
}
