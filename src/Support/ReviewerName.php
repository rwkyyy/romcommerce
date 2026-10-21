<?php

declare(strict_types=1);

namespace RomCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Reviewer names as the storefront shows them: the first name in full and one
 * initial for every other part ("Maria Elena Ionescu" becomes "Maria E. I.").
 * Done server-side so the full name never reaches the page source; CSS
 * truncation only hides it. Shared by the Reviews showcase and Reviewer Name
 * Privacy so both shorten a name the same way.
 *
 * A name written surname first ("Popescu Ion") can't be told apart from one
 * written first name first, so the first word is always the one kept.
 */
final class ReviewerName {

	/**
	 * The shortened name, or '' when there is nothing safe to show (empty, or
	 * an email address typed into the name field).
	 */
	public static function shorten( string $name ): string {
		if ( self::looks_like_email( $name ) ) {
			return '';
		}

		$words = self::words( $name );
		if ( array() === $words ) {
			return '';
		}

		$shown = array( (string) array_shift( $words ) );
		foreach ( $words as $word ) {
			// \X keeps a letter written with a combining mark (S + U+0326) whole.
			if ( preg_match( '/(?=[\p{L}\p{N}])\X/u', $word, $initial ) ) {
				$shown[] = self::upper( $initial[0] ) . '.';
			}
		}

		return implode( ' ', $shown );
	}

	/**
	 * What to print for a reviewer: shortened or in full, but never an email
	 * address, and a neutral label when nothing is left.
	 */
	public static function for_display( string $name, bool $shorten = true ): string {
		if ( $shorten ) {
			$shown = self::shorten( $name );
		} else {
			$shown = self::looks_like_email( $name ) || array() === self::words( $name ) ? '' : Words::limit( $name, 0 );
		}

		return '' === $shown ? __( 'Anonymous', 'romcommerce' ) : $shown;
	}

	public static function looks_like_email( string $name ): bool {
		return false !== strpos( $name, '@' );
	}

	/**
	 * Words that hold at least one letter or digit, so stray dashes and
	 * punctuation never become the "first name" or an initial.
	 *
	 * @return array<int, string>
	 */
	private static function words( string $name ): array {
		$words = preg_split( '/[\s\p{Z}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $words ) ) {
			return array();
		}

		$kept = array();
		foreach ( $words as $word ) {
			$word = trim( $word, ',;:' );
			if ( '' !== $word && preg_match( '/[\p{L}\p{N}]/u', $word ) ) {
				$kept[] = $word;
			}
		}

		return $kept;
	}

	private static function upper( string $letter ): string {
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $letter, 'UTF-8' ) : $letter;
	}
}
