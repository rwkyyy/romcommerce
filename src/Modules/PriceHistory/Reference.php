<?php

declare(strict_types=1);

namespace RomCommerce\Modules\PriceHistory;

use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * The Omnibus reference price of a product's current price, cached in product
 * meta. Shop and category pages need it for every product card, and working
 * it out costs three log queries; product meta is already loaded with the
 * product, so a cached card costs none.
 *
 * The cache is keyed by the price it was worked out for and only written when
 * that price is on record in the log. An anchored reference then can't change
 * until the log gets a new row, and Module::log_price() clears it whenever it
 * writes one. A price missing from the log is anchored to "now", so it is
 * never cached.
 */
final class Reference {

	public const META_KEY = '_romcommerce_omnibus_reference';

	/**
	 * Null when nothing is on record before the current price took effect.
	 */
	public static function for_product( WC_Product $product ): ?string {
		$product_id = (int) $product->get_id();
		$current    = wc_format_decimal( (string) $product->get_price(), wc_get_price_decimals() );

		$cached = get_post_meta( $product_id, self::META_KEY, true );
		if ( is_array( $cached ) && isset( $cached['price'], $cached['reference'] ) && (string) $cached['price'] === $current ) {
			return '' === $cached['reference'] ? null : (string) $cached['reference'];
		}

		$reference = Log::reference_price( $product_id, $current );

		$latest = Log::latest( $product_id );
		if ( null !== $latest && (float) $latest['price'] === (float) $current ) {
			update_post_meta(
				$product_id,
				self::META_KEY,
				array(
					'price'     => $current,
					'reference' => null === $reference ? '' : $reference,
				)
			);
		}

		return $reference;
	}

	public static function forget( int $product_id ): void {
		delete_post_meta( $product_id, self::META_KEY );
	}

	/** Every cached value; it is derived from the log and rebuilds on demand. */
	public static function purge(): void {
		delete_post_meta_by_key( self::META_KEY );
	}
}
