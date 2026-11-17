<?php

declare(strict_types=1);

namespace RomCommerce\Modules\SkuGenerator;

use RomCommerce\Settings;
use WC_Data_Exception;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Builds SKUs from the module's settings and gives them to products that have
 * none. Never replaces an existing SKU: invoicing, stock and feed integrations
 * match products by it.
 *
 * Every read uses the 'edit' context: a variation's get_sku() in the default
 * 'view' context returns its parent's SKU when its own is empty.
 */
final class Generator {

	public const BASES = array( 'id', 'slug', 'sequence' );

	public const VARIATION_MODES = array( 'attributes', 'id', 'none' );

	public const SEPARATORS = array( '-', '_', '.', '/' );

	/** Room for the "-2" suffixes under the 100 characters of WooCommerce's product lookup table. */
	private const MAX_LENGTH = 90;

	/** Collision suffixes tried before giving up on a product. */
	private const MAX_TRIES = 50;

	/** @var string */
	private $module_id;

	public function __construct( string $module_id ) {
		$this->module_id = $module_id;
	}

	/**
	 * Gives $product a SKU when it has none. The caller saves the product.
	 *
	 * @param string|null $parent_sku For a variation, its parent's SKU when the
	 *                                parent is about to be saved with it; null
	 *                                reads the saved parent.
	 */
	public function assign( WC_Product $product, ?string $parent_sku = null ): bool {
		if ( '' !== (string) $product->get_sku( 'edit' ) ) {
			return false;
		}

		$sku = $product->is_type( 'variation' ) ? $this->variation_sku( $product, $parent_sku ) : $this->product_sku( $product );
		if ( '' === $sku ) {
			return false;
		}

		try {
			$product->set_sku( $sku );
		} catch ( WC_Data_Exception $e ) {
			return false;
		}

		return true;
	}

	/**
	 * Gives the variations of $parent that have no SKU one built from
	 * $parent's, and saves them. Their parent may still be unsaved.
	 *
	 * @return int Variations given a SKU.
	 */
	public function fill_variations( WC_Product $parent ): int {
		$parent_sku = (string) $parent->get_sku( 'edit' );
		if ( '' === $parent_sku || 'none' === $this->variation_mode() || ! $parent->is_type( 'variable' ) ) {
			return 0;
		}

		$filled = 0;
		foreach ( $parent->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( $variation instanceof WC_Product && $this->assign( $variation, $parent_sku ) ) {
				$variation->save();
				++$filled;
			}
		}

		return $filled;
	}

	/**
	 * What the current settings produce, for the settings pane. Uses product
	 * 42 named "Tricou bumbac" with a red, M variation.
	 *
	 * @return array{product: string, variation: string}
	 */
	public function example(): array {
		$settings = $this->settings();
		$base     = $settings['base'];
		$number   = 'sequence' === $base ? $settings['next_number'] : 42;
		$product  = 'slug' === $base ? $settings['prefix'] . $this->letters( 'tricou-bumbac' ) : $settings['prefix'] . $this->pad( $number );

		switch ( $this->variation_mode() ) {
			case 'attributes':
				$variation = $product . $settings['separator'] . $this->letters( 'rosu' ) . $settings['separator'] . $this->letters( 'm' );
				break;
			case 'id':
				$variation = $product . $settings['separator'] . '43';
				break;
			default:
				$variation = '';
		}

		return array(
			'product'   => $product,
			'variation' => $variation,
		);
	}

	/**
	 * The module's settings with defaults filled in and unknown values reset.
	 *
	 * @return array{auto: bool, prefix: string, base: string, digits: int, next_number: int, variations: string, separator: string, uppercase: bool}
	 */
	public function settings(): array {
		$saved = Settings::get( $this->module_id );

		$base       = (string) ( $saved['base'] ?? 'id' );
		$variations = (string) ( $saved['variations'] ?? 'attributes' );
		$separator  = (string) ( $saved['separator'] ?? '-' );

		return array(
			'auto'        => (bool) ( $saved['auto'] ?? true ),
			'prefix'      => self::sanitize_prefix( (string) ( $saved['prefix'] ?? '' ) ),
			'base'        => in_array( $base, self::BASES, true ) ? $base : 'id',
			'digits'      => min( 10, max( 0, (int) ( $saved['digits'] ?? 0 ) ) ),
			'next_number' => max( 1, (int) ( $saved['next_number'] ?? 1 ) ),
			'variations'  => in_array( $variations, self::VARIATION_MODES, true ) ? $variations : 'attributes',
			'separator'   => in_array( $separator, self::SEPARATORS, true ) ? $separator : '-',
			'uppercase'   => (bool) ( $saved['uppercase'] ?? true ),
		);
	}

	public function variation_mode(): string {
		return $this->settings()['variations'];
	}

	public static function sanitize_prefix( string $prefix ): string {
		return substr( (string) preg_replace( '#[^A-Za-z0-9._/-]#', '', $prefix ), 0, 20 );
	}

	private function product_sku( WC_Product $product ): string {
		$settings = $this->settings();

		if ( 'sequence' === $settings['base'] ) {
			return $this->next_in_sequence( $product->get_id() );
		}

		$base = '';
		if ( 'slug' === $settings['base'] ) {
			// Drafts have no slug yet, so their name stands in for it.
			$slug = (string) $product->get_slug( 'edit' );
			$base = $this->letters( '' !== $slug ? urldecode( $slug ) : (string) $product->get_name( 'edit' ) );
		}

		if ( '' === $base ) {
			if ( 0 === $product->get_id() ) {
				return '';
			}
			$base = $this->pad( $product->get_id() );
		}

		return $this->unique( $product->get_id(), $settings['prefix'] . $base );
	}

	private function variation_sku( WC_Product $variation, ?string $parent_sku ): string {
		$settings = $this->settings();
		if ( 'none' === $settings['variations'] ) {
			return '';
		}

		if ( null === $parent_sku ) {
			$parent     = wc_get_product( $variation->get_parent_id() );
			$parent_sku = $parent instanceof WC_Product ? (string) $parent->get_sku( 'edit' ) : '';
		}

		// Without a parent SKU there is nothing to build on; the parent's own
		// save fills its variations in once it has one.
		if ( '' === $parent_sku ) {
			return '';
		}

		$part = '';
		if ( 'attributes' === $settings['variations'] ) {
			$values = array();
			foreach ( $variation->get_attributes( 'edit' ) as $value ) {
				$value = $this->letters( urldecode( (string) $value ) );
				if ( '' !== $value ) {
					$values[] = $value;
				}
			}
			$part = implode( $settings['separator'], $values );
		}

		// "Any" for every attribute leaves nothing to tell variations apart.
		if ( '' === $part ) {
			if ( 0 === $variation->get_id() ) {
				return '';
			}
			$part = (string) $variation->get_id();
		}

		return $this->unique( $variation->get_id(), $parent_sku . $settings['separator'] . $part );
	}

	/**
	 * The first unused number from the stored counter, which then moves past
	 * it. Numbers taken by hand-entered SKUs are skipped.
	 */
	private function next_in_sequence( int $product_id ): string {
		$settings = $this->settings();
		$number   = $settings['next_number'];

		for ( $tries = 0; $tries < self::MAX_TRIES; $tries++, $number++ ) {
			$sku = $settings['prefix'] . $this->pad( $number );
			if ( wc_product_has_unique_sku( $product_id, $sku ) ) {
				$saved                = Settings::get( $this->module_id );
				$saved['next_number'] = $number + 1;
				Settings::update( $this->module_id, $saved );

				return $sku;
			}
		}

		return '';
	}

	private function unique( int $product_id, string $sku ): string {
		$sku       = substr( $sku, 0, self::MAX_LENGTH );
		$separator = $this->settings()['separator'];

		for ( $tries = 1; $tries <= self::MAX_TRIES; $tries++ ) {
			$candidate = 1 === $tries ? $sku : $sku . $separator . $tries;
			if ( wc_product_has_unique_sku( $product_id, $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/** "Roșu închis" becomes "ROSU-INCHIS" (or "rosu-inchis" with uppercase off). */
	private function letters( string $text ): string {
		$text = trim( (string) preg_replace( '/[^A-Za-z0-9]+/', '-', remove_accents( $text ) ), '-' );

		return $this->settings()['uppercase'] ? strtoupper( $text ) : strtolower( $text );
	}

	private function pad( int $number ): string {
		return str_pad( (string) $number, $this->settings()['digits'], '0', STR_PAD_LEFT );
	}
}
