<?php

declare(strict_types=1);

namespace RomCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor detection for the admin warnings around placements. Lite-only,
 * feature-detected, never a dependency.
 *
 * Why it matters: an Elementor Pro Theme Builder product template replaces
 * WooCommerce's content-single-product.php with widgets, and only some widgets
 * run WooCommerce's own hooks (checked against Elementor Pro 3.35 source and a
 * live 4.3 store): the template itself fires woocommerce_before/after_single_product,
 * the Add to Cart widget runs the add-to-cart form hooks, Product Meta runs
 * woocommerce_product_meta_start/end, Product Data Tabs runs
 * woocommerce_product_after_tabs. Nothing fires woocommerce_single_product_summary,
 * woocommerce_after_single_product_summary or woocommerce_share, so slots on
 * those hooks render nothing there (Placement marks them 'elementor' => false).
 * Elementor Pro's Cart and Checkout widgets print WooCommerce's own cart and
 * checkout shortcodes, so those surfaces are unaffected.
 */
final class Elementor {

	/** Elementor Pro's cache of Theme Builder display conditions, location => template id => conditions. */
	private const CONDITIONS_OPTION = 'elementor_pro_theme_builder_conditions';

	public static function active(): bool {
		return defined( 'ELEMENTOR_VERSION' ) || defined( 'ELEMENTOR_PRO_VERSION' );
	}

	/** Whether an Elementor Pro Theme Builder template is set to display on product pages. */
	public static function product_template_in_use(): bool {
		if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			return false;
		}

		$conditions = get_option( self::CONDITIONS_OPTION, array() );

		return is_array( $conditions ) && self::targets_products( $conditions );
	}

	/**
	 * True when any "single" template includes product pages: a product
	 * condition ("include/product", "include/product/in_product_cat/12") or
	 * Elementor's catch-alls ("include/general", "include/singular"), which
	 * cover products too.
	 *
	 * @param array<string, mixed> $conditions As stored in elementor_pro_theme_builder_conditions.
	 */
	public static function targets_products( array $conditions ): bool {
		foreach ( (array) ( $conditions['single'] ?? array() ) as $template_conditions ) {
			foreach ( (array) $template_conditions as $condition ) {
				$parts = explode( '/', (string) $condition );

				if ( 'include' !== $parts[0] || ! isset( $parts[1] ) ) {
					continue;
				}

				$scope = $parts[2] ?? '';
				if ( 'product' === $parts[1] || 'general' === $parts[1] || ( 'singular' === $parts[1] && ( '' === $scope || 'product' === $scope ) ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
