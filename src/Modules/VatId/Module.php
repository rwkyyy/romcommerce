<?php

declare(strict_types=1);

namespace RomCommerce\Modules\VatId;

use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Modules\PfPjBilling\Fields;
use RomCommerce\Settings;
use RomCommerce\Support\Validators;

defined( 'ABSPATH' ) || exit;

/**
 * VAT ID validation (Lite). The paired checkout check for the PF/PJ CUI/CIF —
 * Lite does offline format + checksum validation; Pro adds the live VIES lookup
 * and company-data autofill (teased render-only here). Validates the field the
 * PF/PJ module collects, so it is a companion to that module, not a standalone
 * field.
 */
final class Module implements ModuleInterface, HasSettingsUi {

	private const ID = 'vat-id-validation';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'VAT ID Validation', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		// Priority 20 so PF/PJ's presence checks (priority 10) run first; this
		// only judges the validity of a value that is actually present.
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate' ), 20, 2 );
	}

	/**
	 * @param array<string, mixed> $data
	 * @param \WP_Error            $errors
	 */
	public function validate( $data, $errors ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WooCommerce verifies its checkout nonce before this hook fires; wc_clean() sanitizes (not a WPCS-recognized sanitizer).
		if ( ! isset( $_POST[ Fields::PERSON_TYPE ] ) || Fields::TYPE_PJ !== wc_clean( wp_unslash( $_POST[ Fields::PERSON_TYPE ] ) ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WooCommerce verifies its checkout nonce before this hook fires; wc_clean() sanitizes (not a WPCS-recognized sanitizer).
		$cui = isset( $_POST[ Fields::CUI ] ) ? wc_clean( wp_unslash( $_POST[ Fields::CUI ] ) ) : '';
		if ( '' === $cui ) {
			return;
		}

		if ( ! Validators::cui( $cui ) ) {
			$errors->add( 'romcommerce_vat_id', __( 'CUI / CIF-ul introdus nu are un format valid.', 'romcommerce' ) );
		}
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			Settings::set_enabled( self::ID, isset( $_POST['rc_enabled'] ) );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'Validates the CUI/CIF format and checksum at checkout for company (PJ) orders. Works alongside the PF/PJ billing fields.', 'romcommerce' ) . '</p>';

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody><tr>';
		echo '<th scope="row">' . esc_html__( 'VAT ID validation', 'romcommerce' ) . '</th>';
		echo '<td><label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ';
		echo esc_html__( 'Reject invalid CUI/CIF at checkout', 'romcommerce' ) . '</label></td>';
		echo '</tr></tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';

		echo '<hr><p><span class="romcommerce-badge">Pro</span> ' . esc_html__( 'RomCommerce Pro adds a live VIES lookup that confirms the VAT ID is registered and can autofill the company name and address from it.', 'romcommerce' ) . '</p>';
	}
}
