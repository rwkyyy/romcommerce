<?php

declare(strict_types=1);

namespace RomCommerce\Modules\PriceHistory;

use WC_Product;
use WC_Product_Variable;
use WP_Block;

defined( 'ABSPATH' ) || exit;

/**
 * Crosses out the Omnibus reference price instead of the regular price
 * wherever WooCommerce prints a product's price HTML (product pages, shop and
 * category listings, widgets, product blocks rendered in PHP). An announced
 * reduction has to be measured against the reference (CJEU C-330/23), so a
 * crossed-out regular price of 150 next to 80, when the reference is 100,
 * shows a bigger reduction than the one that may be announced.
 *
 * The product's regular and sale prices are untouched: coupons, feeds, tax
 * and "on sale" status keep working from them. When the reference isn't above
 * the current price there is no reduction to show, so the price prints plain
 * and the sale badge is hidden.
 */
final class Display {

	/** @var bool */
	private $show_percent;

	public function __construct( bool $show_percent ) {
		$this->show_percent = $show_percent;
	}

	public function register(): void {
		// Priority 20: after WooCommerce and most themes have built the HTML at
		// the default 10, since this replaces the comparison, not just its look.
		add_filter( 'woocommerce_get_price_html', array( $this, 'price_html' ), 20, 2 );
		add_filter( 'woocommerce_sale_flash', array( $this, 'sale_flash' ), 20, 3 );
		// Block themes' product grids render their own badge without
		// woocommerce_sale_flash.
		add_filter( 'render_block_woocommerce/product-sale-badge', array( $this, 'sale_badge_block' ), 20, 3 );
	}

	/**
	 * @param mixed $html
	 * @param mixed $product
	 * @return mixed
	 */
	public function price_html( $html, $product ) {
		$comparison = $this->comparison( $product );
		if ( null === $comparison || ! $product instanceof WC_Product ) {
			return $html;
		}

		list( $reference, $price ) = $comparison;
		$suffix                    = $product->get_price_suffix();

		if ( $reference <= $price ) {
			return wc_price( $price ) . $suffix;
		}

		$out = wc_format_sale_price( wc_price( $reference ), wc_price( $price ) );
		if ( $this->show_percent ) {
			$out .= ' <span class="romcommerce-reduction">' . esc_html( '−' . self::percent( $reference, $price ) . '%' ) . '</span>';
		}

		return $out . $suffix;
	}

	/**
	 * @param mixed $html
	 * @param mixed $post
	 * @param mixed $product
	 * @return mixed
	 */
	public function sale_flash( $html, $post, $product ) {
		$comparison = $this->comparison( $product );
		if ( null === $comparison ) {
			return $html;
		}

		return $comparison[0] > $comparison[1] ? $html : '';
	}

	/**
	 * @param mixed $content
	 * @param mixed $parsed_block
	 * @param mixed $block
	 * @return mixed
	 */
	public function sale_badge_block( $content, $parsed_block, $block ) {
		// postId context in query loops; older WooCommerce versions pass a
		// productId attribute instead, or rely on the global product.
		$product_id = $block instanceof WP_Block && isset( $block->context['postId'] ) ? (int) $block->context['postId'] : 0;
		if ( 0 === $product_id && is_array( $parsed_block ) && isset( $parsed_block['attrs']['productId'] ) ) {
			$product_id = (int) $parsed_block['attrs']['productId'];
		}
		$product = $product_id > 0 ? wc_get_product( $product_id ) : ( $GLOBALS['product'] ?? null );

		return $this->sale_flash( $content, null, $product );
	}

	/**
	 * Rounded down, so the announced reduction is never larger than the real
	 * one (99.99 to 80 is 19.99%, shown as 19%).
	 */
	public static function percent( float $reference, float $price ): int {
		if ( $reference <= 0 ) {
			return 0;
		}

		return (int) floor( ( $reference - $price ) / $reference * 100 + 1e-9 );
	}

	/**
	 * Reference and current price, both as displayed (tax setting applied).
	 * Null where WooCommerce's own output stands: not on sale, wp-admin
	 * screens (merchants read their own regular/sale prices there), grouped
	 * products and variable products showing a price range, which carry no
	 * crossed-out price.
	 *
	 * @param mixed $product
	 * @return array{0: float, 1: float}|null
	 */
	private function comparison( $product ): ?array {
		if ( ! $product instanceof WC_Product || ( is_admin() && ! wp_doing_ajax() ) || ! $product->is_on_sale() ) {
			return null;
		}

		if ( $product instanceof WC_Product_Variable ) {
			return $this->variable_comparison( $product );
		}

		if ( $product->is_type( 'grouped' ) || '' === (string) $product->get_price() ) {
			return null;
		}

		$price = (float) wc_get_price_to_display( $product );

		return array( $this->display_reference( $product, $price ), $price );
	}

	/**
	 * WooCommerce only crosses out a variable product's price when every
	 * variation costs the same. Variations can have different histories, so
	 * the lowest of the reduced variations' references is used: the smallest
	 * reduction, never the largest.
	 *
	 * @return array{0: float, 1: float}|null
	 */
	private function variable_comparison( WC_Product_Variable $product ): ?array {
		$prices = $product->get_variation_prices( true );
		if ( empty( $prices['price'] ) ) {
			return null;
		}

		$amounts = array_map( 'floatval', $prices['price'] );
		if ( min( $amounts ) !== max( $amounts ) ) {
			return null;
		}

		$price     = min( $amounts );
		$reference = null;
		foreach ( $amounts as $variation_id => $amount ) {
			if ( (float) ( $prices['regular_price'][ $variation_id ] ?? 0 ) <= $amount ) {
				continue;
			}

			$variation = wc_get_product( $variation_id );
			if ( ! $variation instanceof WC_Product ) {
				continue;
			}

			$candidate = $this->display_reference( $variation, $price );
			$reference = null === $reference ? $candidate : min( $reference, $candidate );
		}

		return array( null === $reference ? $price : $reference, $price );
	}

	/**
	 * With nothing on record before the current price, the current price is
	 * the reference: the only price known to have applied.
	 */
	private function display_reference( WC_Product $product, float $display_price ): float {
		$reference = Reference::for_product( $product );

		return null === $reference ? $display_price : (float) wc_get_price_to_display( $product, array( 'price' => $reference ) );
	}
}
