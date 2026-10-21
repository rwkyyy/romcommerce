<?php

declare(strict_types=1);

namespace RomCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Block cart/checkout plumbing shared by every module that supports both
 * checkout types. Lite-only, like Placement and Validators.
 *
 * No build step: block fields go through WooCommerce's PHP Additional
 * Checkout Fields API, block UI through plain JS on WooCommerce's globals, and
 * static markup through render_block (see insert(), and
 * insert_in_product_template() for the block theme product page).
 */
final class Blocks {

	/**
	 * Markup queued around single product template blocks, by block name.
	 *
	 * @var array<string, array<int, array{where: string, order: int, seq: int, render: callable}>>
	 */
	private static array $product_inserts = array();

	/** Additional Checkout Fields API, stable since WooCommerce 8.9. */
	public static function has_checkout_fields_api(): bool {
		return function_exists( 'woocommerce_register_additional_checkout_field' );
	}

	public static function cart_uses_block(): bool {
		$utils = '\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils';
		if ( class_exists( $utils ) && method_exists( $utils, 'is_cart_block_default' ) ) {
			return (bool) $utils::is_cart_block_default();
		}

		return self::page_has_block( 'cart', 'woocommerce/cart' );
	}

	public static function checkout_uses_block(): bool {
		$utils = '\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils';
		if ( class_exists( $utils ) && method_exists( $utils, 'is_checkout_block_default' ) ) {
			return (bool) $utils::is_checkout_block_default();
		}

		return self::page_has_block( 'checkout', 'woocommerce/checkout' );
	}

	/**
	 * True when block checkout is in use but this WooCommerce can't take
	 * RomCommerce's checkout fields there, so the merchant needs telling.
	 */
	public static function checkout_fields_unsupported(): bool {
		return ! self::has_checkout_fields_api() && self::checkout_uses_block();
	}

	/**
	 * Prints $render's output before or after every rendered $block_name.
	 *
	 * Works inside the cart and checkout blocks too: on hydration WooCommerce
	 * re-renders markup it doesn't recognise as an inner block as-is (checked
	 * against WC 11.1, render-parent-block). It stays static, though: React
	 * owns that tree, so inline scripts in it never run and it won't follow
	 * cart changes. Anything that must react to the cart needs its own JS.
	 * The rebuild also drops some form attributes (<option value> for one), so
	 * data a script needs goes in data-* attributes.
	 *
	 * @param callable():void $render Echoes the markup, like a classic hook callback.
	 */
	public static function insert( string $block_name, string $where, callable $render, int $priority = 10 ): void {
		add_filter(
			'render_block_' . $block_name,
			static function ( $content ) use ( $where, $render ) {
				$markup = self::capture( $render );

				if ( '' === $markup ) {
					return $content;
				}

				return 'before' === $where ? $markup . $content : $content . $markup;
			},
			$priority
		);
	}

	/**
	 * Like insert(), for the single product block template: prints only around
	 * the page's own product blocks, never around the same blocks repeated
	 * for other products (a related-products collection reuses post-title and
	 * product-price), and only on block themes, where the template is blocks.
	 *
	 * Everything inserted around one block goes through one filter, in
	 * $order order on both sides: one filter per insertion would print the
	 * "before" ones in reverse, each prepending ahead of the last.
	 *
	 * @param callable():void $render Echoes the markup, like a classic hook callback.
	 */
	public static function insert_in_product_template( string $block_name, string $where, callable $render, int $order = 10 ): void {
		if ( ! isset( self::$product_inserts[ $block_name ] ) ) {
			self::$product_inserts[ $block_name ] = array();
			add_filter( 'render_block_' . $block_name, array( self::class, 'render_product_inserts' ), 10, 3 );
		}

		self::$product_inserts[ $block_name ][] = array(
			'where'  => 'before' === $where ? 'before' : 'after',
			'order'  => $order,
			'seq'    => count( self::$product_inserts[ $block_name ] ),
			'render' => $render,
		);
	}

	/**
	 * @param mixed                $content
	 * @param array<string, mixed> $parsed_block
	 * @param mixed                $instance WP_Block since WordPress 5.9.
	 * @return mixed
	 */
	public static function render_product_inserts( $content, $parsed_block = array(), $instance = null ) {
		$name    = (string) ( $parsed_block['blockName'] ?? '' );
		$inserts = self::$product_inserts[ $name ] ?? array();

		if ( array() === $inserts || ! self::is_main_product_block( $instance ) ) {
			return $content;
		}

		usort(
			$inserts,
			static function ( array $a, array $b ): int {
				return array( $a['order'], $a['seq'] ) <=> array( $b['order'], $b['seq'] );
			}
		);

		$before = '';
		$after  = '';
		foreach ( $inserts as $insert ) {
			if ( 'before' === $insert['where'] ) {
				$before .= self::capture( $insert['render'] );
			} else {
				$after .= self::capture( $insert['render'] );
			}
		}

		return $before . $content . $after;
	}

	/**
	 * WordPress gives template-level blocks the queried post as postId
	 * context, and blocks inside a product collection the collection item's,
	 * so a postId naming another product means "not this page's product".
	 * Every block targeted here declares postId context (WooCommerce 11.1
	 * block.json files), so a missing one can only be the page's own.
	 *
	 * @param mixed $instance
	 */
	private static function is_main_product_block( $instance ): bool {
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() || ! function_exists( 'is_product' ) || ! is_product() ) {
			return false;
		}

		$post_id = is_object( $instance ) && isset( $instance->context['postId'] ) ? (int) $instance->context['postId'] : 0;

		return 0 === $post_id || (int) get_queried_object_id() === $post_id;
	}

	/** @param callable():void $render */
	private static function capture( callable $render ): string {
		ob_start();
		$render();

		return (string) ob_get_clean();
	}

	private static function page_has_block( string $page, string $block_name ): bool {
		if ( ! function_exists( 'wc_get_page_id' ) || ! function_exists( 'has_block' ) ) {
			return false;
		}

		$page_id = wc_get_page_id( $page );

		return $page_id > 0 && has_block( $block_name, $page_id );
	}
}
