<?php

declare(strict_types=1);

namespace RomCommerce\Support;

defined( 'ABSPATH' ) || exit;

final class Words {

	/**
	 * $text cut to at most $max whole words, never inside a word, ending in an
	 * ellipsis when anything was left out. $max of 0 keeps the whole text.
	 * Whitespace runs collapse to single spaces either way.
	 */
	public static function limit( string $text, int $max ): string {
		$collapsed = preg_replace( '/[\s\p{Z}]+/u', ' ', $text );
		if ( null === $collapsed ) {
			// Not valid UTF-8: fall back to ASCII whitespace, which can't split a multibyte character.
			$collapsed = (string) preg_replace( '/\s+/', ' ', $text );
		}

		$collapsed = trim( $collapsed );
		if ( $max <= 0 || '' === $collapsed ) {
			return $collapsed;
		}

		$words = explode( ' ', $collapsed );
		if ( count( $words ) <= $max ) {
			return $collapsed;
		}

		$kept    = implode( ' ', array_slice( $words, 0, $max ) );
		$trimmed = preg_replace( '/[\s,;:.!?\x{2013}\x{2014}\x{2026}-]+$/u', '', $kept );

		return ( null === $trimmed || '' === $trimmed ? $kept : $trimmed ) . "\u{2026}";
	}
}
