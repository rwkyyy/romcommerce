<?php

declare(strict_types=1);

namespace RomCommerce\Modules\PhoneValidation;

defined( 'ABSPATH' ) || exit;

/**
 * Server-side phone check behind the browser's full libphonenumber check
 * (intl-tel-input): catches what reaches the server without the script, at
 * the precision a small rule set allows — strict for Romania, the E.164 shape
 * for international numbers, a plain length check for other countries'
 * national formats. No second phone library on the server on purpose.
 */
final class Validator {

	public static function is_valid( string $phone, string $country ): bool {
		$number = (string) preg_replace( '/[\s().\/-]/', '', $phone );

		if ( '' === $number ) {
			return true;
		}

		if ( 0 === strpos( $number, '00' ) ) {
			$number = '+' . substr( $number, 2 );
		}

		if ( 0 === strpos( $number, '+' ) ) {
			if ( ! preg_match( '/^\+[1-9]\d{6,14}$/', $number ) ) {
				return false;
			}

			return 0 !== strpos( $number, '+40' ) || (bool) preg_match( '/^\+40[237]\d{8}$/', $number );
		}

		if ( 'RO' === strtoupper( $country ) ) {
			// Mobile 07, landline 02/03; customers sometimes drop the 0, or
			// type the country code without the + (libphonenumber accepts both).
			return (bool) preg_match( '/^(?:0|40)?[237]\d{8}$/', $number );
		}

		return (bool) preg_match( '/^\d{6,15}$/', $number );
	}
}
