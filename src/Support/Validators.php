<?php

declare(strict_types=1);

namespace RomCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Pure Romanian identifier checksum validators, shared within Lite (the PF/PJ
 * billing and VAT-ID modules both use them). Algorithms ported from the
 * owner-supplied reference invoicing plugin (docs/feature-specs.md, PF/PJ
 * entry) — user-contributed source, cross-checked, not an official spec.
 *
 * These are the clearest early PHPUnit candidates in the project (CLAUDE.md);
 * kept side-effect-free and dependency-free so they can be unit-tested as-is.
 */
final class Validators {

	/** CNP — 13-digit weighted checksum + basic date-part sanity. */
	public static function cnp( string $value ): bool {
		$cnp = preg_replace( '/\D/', '', $value );
		if ( 13 !== strlen( (string) $cnp ) ) {
			return false;
		}

		// First digit encodes sex + century; 0 is never valid.
		if ( '0' === $cnp[0] ) {
			return false;
		}

		$month = (int) substr( $cnp, 3, 2 );
		$day   = (int) substr( $cnp, 5, 2 );
		if ( $month < 1 || $month > 12 || $day < 1 || $day > 31 ) {
			return false;
		}

		$weights = array( 2, 7, 9, 1, 4, 6, 3, 5, 8, 2, 7, 9 );
		$sum     = 0;
		for ( $i = 0; $i < 12; $i++ ) {
			$sum += (int) $cnp[ $i ] * $weights[ $i ];
		}

		$control = $sum % 11;
		if ( 10 === $control ) {
			$control = 1;
		}

		return $control === (int) $cnp[12];
	}

	/** CUI / CIF (also the RO VAT number) — strip the RO prefix, then the 753217532 reversed checksum. */
	public static function cui( string $value ): bool {
		$cui = self::normalize_cui( $value );

		$length = strlen( $cui );
		if ( $length < 2 || $length > 10 ) {
			return false;
		}

		$control = (int) $cui[ $length - 1 ];
		$digits  = str_split( substr( $cui, 0, $length - 1 ) );

		$digits = array_pad( $digits, -9, '0' );

		$key = array( 7, 5, 3, 2, 1, 7, 5, 3, 2 );
		$sum = 0;
		for ( $i = 0; $i < 9; $i++ ) {
			$sum += (int) $digits[ $i ] * $key[ $i ];
		}

		$computed = ( $sum * 10 ) % 11;
		if ( 10 === $computed ) {
			$computed = 0;
		}

		return $computed === $control;
	}

	/** Digits of a CUI with any leading RO and non-digits removed. */
	public static function normalize_cui( string $value ): string {
		$value = strtoupper( trim( $value ) );
		$value = preg_replace( '/^RO/', '', $value );

		return (string) preg_replace( '/\D/', '', (string) $value );
	}

	/** IBAN — generic ISO 7064 mod-97 check for any IBAN-issuing country. */
	public static function iban( string $value ): bool {
		$iban = strtoupper( (string) preg_replace( '/\s+/', '', $value ) );

		if ( ! preg_match( '/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/', $iban ) ) {
			return false;
		}

		// Move the first four chars to the end, then map letters to numbers
		// (A=10 … Z=35) and take the whole thing mod 97; valid when it equals 1.
		$rearranged = substr( $iban, 4 ) . substr( $iban, 0, 4 );

		$numeric = '';
		$length  = strlen( $rearranged );
		for ( $i = 0; $i < $length; $i++ ) {
			$char     = $rearranged[ $i ];
			$numeric .= ctype_alpha( $char ) ? (string) ( ord( $char ) - 55 ) : $char;
		}

		return 1 === self::mod97( $numeric );
	}

	/** mod 97 over an arbitrarily long numeric string, chunked to stay within int range. */
	private static function mod97( string $numeric ): int {
		$remainder = 0;
		$length    = strlen( $numeric );

		for ( $i = 0; $i < $length; $i += 7 ) {
			$block     = ( 0 === $remainder ? '' : (string) $remainder ) . substr( $numeric, $i, 7 );
			$remainder = (int) $block % 97;
		}

		return $remainder;
	}
}
