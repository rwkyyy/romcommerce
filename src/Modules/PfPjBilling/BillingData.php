<?php

declare(strict_types=1);

namespace RomCommerce\Modules\PfPjBilling;

use RomCommerce\Support\Validators;

defined( 'ABSPATH' ) || exit;

/**
 * Validation and the stored shape of the PF/PJ data, shared by the classic and
 * block checkouts so the two can't drift apart. Both take the submitted
 * values keyed by the classic field names (Fields::*, plus billing_company).
 */
final class BillingData {

	/**
	 * @param array<string, string> $values
	 * @return array<string, string> error code => message.
	 */
	public static function errors( array $values, bool $cnp_enabled ): array {
		$errors = array();
		$type   = $values[ Fields::PERSON_TYPE ] ?? '';

		if ( Fields::TYPE_PJ === $type ) {
			// The legal floor for a company invoice is CUI + company name; the
			// checksum itself is enforced by the VAT-ID module when enabled.
			if ( '' === ( $values[ Fields::CUI ] ?? '' ) ) {
				$errors['romcommerce_cui'] = __( 'Vă rugăm să introduceți CUI / CIF pentru persoană juridică.', 'romcommerce' );
			}

			if ( '' === ( $values['billing_company'] ?? '' ) ) {
				$errors['romcommerce_company'] = __( 'Vă rugăm să introduceți denumirea firmei.', 'romcommerce' );
			}

			$iban = $values[ Fields::IBAN ] ?? '';
			if ( '' !== $iban && ! Validators::iban( $iban ) ) {
				$errors['romcommerce_iban'] = __( 'IBAN-ul introdus nu este valid.', 'romcommerce' );
			}
		}

		// CNP is validated only when provided; an empty field falls back to the
		// placeholder derogation rather than blocking checkout.
		if ( Fields::TYPE_PF === $type && $cnp_enabled ) {
			$cnp = $values[ Fields::CNP ] ?? '';
			if ( '' !== $cnp && ! Validators::cnp( $cnp ) ) {
				$errors['romcommerce_cnp'] = __( 'CNP-ul introdus nu este valid.', 'romcommerce' );
			}
		}

		return $errors;
	}

	/**
	 * The consolidated Fields::META_KEY entry for an order.
	 *
	 * @param array<string, string> $values
	 * @return array<string, string>
	 */
	public static function for_order( array $values, bool $cnp_enabled ): array {
		$type    = Fields::TYPE_PJ === ( $values[ Fields::PERSON_TYPE ] ?? '' ) ? Fields::TYPE_PJ : Fields::TYPE_PF;
		$billing = array( 'type' => $type );

		if ( Fields::TYPE_PJ === $type ) {
			// Preserve the entered form (incl. any RO VAT-payer prefix) — invoicing
			// tools distinguish VAT-registered companies by it. Validation strips
			// RO internally for the checksum; storage keeps it.
			$billing['cui']     = strtoupper( (string) preg_replace( '/\s+/', '', $values[ Fields::CUI ] ?? '' ) );
			$billing['reg_com'] = $values[ Fields::REG_COM ] ?? '';
			$billing['bank']    = $values[ Fields::BANK ] ?? '';
			$billing['iban']    = strtoupper( (string) preg_replace( '/\s+/', '', $values[ Fields::IBAN ] ?? '' ) );

			return $billing;
		}

		$cnp            = $values[ Fields::CNP ] ?? '';
		$billing['cnp'] = ( $cnp_enabled && '' !== $cnp ) ? (string) preg_replace( '/\D/', '', $cnp ) : Fields::CNP_PLACEHOLDER;

		return $billing;
	}
}
