<?php

declare(strict_types=1);

namespace RomCommerce\Modules\CountiesPostcodes;

defined( 'ABSPATH' ) || exit;

/**
 * Romanian localities per county, one static JSON file per WooCommerce county
 * code in data/ro-localities/. The browser fetches only the chosen county's
 * file (a few KB, cached), never the whole country. Source: the GPL dataset by
 * Razvan Farte shipped with "States, Cities, and Places for WooCommerce"
 * (13,266 localities, București as Sector 1-6).
 */
final class Localities {

	public const DIR = 'data/ro-localities/';

	/** @return array<int, string> Empty for an unknown county code. */
	public static function for_county( string $county ): array {
		if ( 1 !== preg_match( '/^[A-Z]{1,2}$/', $county ) ) {
			return array();
		}

		$file = ROMCOMMERCE_DIR . '/' . self::DIR . $county . '.json';
		if ( ! is_readable( $file ) ) {
			return array();
		}

		$names = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local bundled file.

		return is_array( $names ) ? array_values( array_filter( $names, 'is_string' ) ) : array();
	}

	/**
	 * Case, diacritics, hyphens and repeated spaces don't count, so "bistrita"
	 * matches "Bistrița" and "Abrud Sat" matches "Abrud-Sat", the same way the
	 * checkout search matches.
	 */
	public static function contains( string $county, string $city ): bool {
		$needle = self::normalize( $city );
		if ( '' === $needle ) {
			return false;
		}

		foreach ( self::for_county( $county ) as $name ) {
			if ( self::normalize( $name ) === $needle ) {
				return true;
			}
		}

		return false;
	}

	public static function normalize( string $text ): string {
		return strtolower( trim( (string) preg_replace( '/[\s\-]+/u', ' ', remove_accents( $text ) ) ) );
	}
}
