<?php

declare(strict_types=1);

namespace RomCommerce\Modules\PfPjBilling;

use RomCommerce\Settings;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Renders, validates and persists the PF/PJ billing fields at classic checkout.
 * Fields are rendered manually (not registered into the WC checkout fields
 * array) so WooCommerce does not auto-scatter them into individual postmeta —
 * everything is written to one consolidated meta entry in save().
 */
final class Checkout {

	private const MODULE_ID = 'pf-pj-billing';

	public function register(): void {
		// Fires right after the "Billing details" heading, before WooCommerce's
		// own field loop — puts the PF/PJ selector at the top of the billing
		// form rather than tacked on after the native fields.
		add_action( 'woocommerce_before_checkout_billing_form', array( $this, 'render_fields' ) );
		// billing_company is rendered inside our own PJ group instead (see
		// render_fields()), so it must not also render in its native position.
		add_filter( 'woocommerce_billing_fields', array( $this, 'remove_native_company_field' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * 'woocommerce_billing_fields' is not checkout-exclusive — WC_Countries::
	 * get_address_fields() applies the same filter for the My Account "Edit
	 * billing address" screen. Only strip the native field on checkout itself,
	 * so that account screen keeps its own native Company field untouched.
	 *
	 * @param array<string, array<string, mixed>> $fields
	 * @return array<string, array<string, mixed>>
	 */
	public function remove_native_company_field( array $fields ): array {
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			unset( $fields['billing_company'] );
		}

		return $fields;
	}

	public function enqueue_assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		wp_register_script( 'romcommerce-pf-pj-billing', false, array(), ROMCOMMERCE_VERSION, true );
		wp_enqueue_script( 'romcommerce-pf-pj-billing' );
		wp_add_inline_script( 'romcommerce-pf-pj-billing', $this->toggle_script_js() );
	}

	public function cnp_enabled(): bool {
		return (bool) ( Settings::get( self::MODULE_ID )['cnp_enabled'] ?? false );
	}

	public function render_fields(): void {
		$type = $this->posted( Fields::PERSON_TYPE );
		if ( '' === $type ) {
			$type = Fields::TYPE_PF;
		}

		echo '<div class="romcommerce-billing-fields">';

		woocommerce_form_field(
			Fields::PERSON_TYPE,
			array(
				'type'    => 'select',
				'label'   => __( 'Tip client', 'romcommerce' ),
				'class'   => array( 'form-row-wide' ),
				'options' => array(
					Fields::TYPE_PF => __( 'Persoană fizică', 'romcommerce' ),
					Fields::TYPE_PJ => __( 'Persoană juridică', 'romcommerce' ),
				),
			),
			$type
		);

		echo '<div id="romcommerce-pj-fields">';
		woocommerce_form_field(
			'billing_company',
			array(
				'type'     => 'text',
				'label'    => __( 'Denumire firmă', 'romcommerce' ),
				'class'    => array( 'form-row-wide' ),
				'required' => true,
			),
			$this->posted( 'billing_company' )
		);
		woocommerce_form_field(
			Fields::CUI,
			array(
				'type'     => 'text',
				'label'    => __( 'CUI / CIF', 'romcommerce' ),
				'class'    => array( 'form-row-wide' ),
				'required' => true,
			),
			$this->posted( Fields::CUI )
		);
		woocommerce_form_field(
			Fields::REG_COM,
			array(
				'type'  => 'text',
				'label' => __( 'Nr. Reg. Com. (opțional)', 'romcommerce' ),
				'class' => array( 'form-row-wide' ),
			),
			$this->posted( Fields::REG_COM )
		);
		woocommerce_form_field(
			Fields::BANK,
			array(
				'type'  => 'text',
				'label' => __( 'Bancă (opțional)', 'romcommerce' ),
				'class' => array( 'form-row-first' ),
			),
			$this->posted( Fields::BANK )
		);
		woocommerce_form_field(
			Fields::IBAN,
			array(
				'type'  => 'text',
				'label' => __( 'IBAN (opțional)', 'romcommerce' ),
				'class' => array( 'form-row-last' ),
			),
			$this->posted( Fields::IBAN )
		);
		echo '</div>';

		if ( $this->cnp_enabled() ) {
			echo '<div id="romcommerce-cnp-field">';
			woocommerce_form_field(
				Fields::CNP,
				array(
					'type'        => 'text',
					'label'       => __( 'CNP', 'romcommerce' ),
					'class'       => array( 'form-row-wide' ),
					'placeholder' => Fields::CNP_PLACEHOLDER,
				),
				$this->posted( Fields::CNP )
			);
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $data
	 * @param \WP_Error            $errors
	 */
	public function validate( $data, $errors ): void {
		foreach ( BillingData::errors( $this->posted_values(), $this->cnp_enabled() ) as $code => $message ) {
			$errors->add( $code, $message );
		}
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $data is required by the woocommerce_checkout_create_order hook signature; unused here.
	public function save( WC_Order $order, array $data ): void {
		// billing_company is unregistered from WC's native checkout fields (see
		// remove_native_company_field()), so WC never writes it to the order on
		// its own — set it here from our own rendered field.
		$order->set_billing_company( $this->posted( 'billing_company' ) );
		$order->update_meta_data( Fields::META_KEY, BillingData::for_order( $this->posted_values(), $this->cnp_enabled() ) );
	}

	/** @return array<string, string> */
	private function posted_values(): array {
		$values = array();
		foreach ( array( Fields::PERSON_TYPE, 'billing_company', Fields::CUI, Fields::REG_COM, Fields::BANK, Fields::IBAN, Fields::CNP ) as $key ) {
			$values[ $key ] = $this->posted( $key );
		}

		return $values;
	}

	private function posted( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WooCommerce verifies its checkout nonce before these hooks fire; wc_clean() sanitizes (not a WPCS-recognized sanitizer).
		return isset( $_POST[ $key ] ) ? wc_clean( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	private function toggle_script_js(): string {
		return '(function(){'
			. 'var sel=document.getElementById(' . wp_json_encode( Fields::PERSON_TYPE ) . ');if(!sel){return;}'
			. 'var pj=document.getElementById("romcommerce-pj-fields");'
			. 'var cnp=document.getElementById("romcommerce-cnp-field");'
			. 'function sync(){var isPj=sel.value===' . wp_json_encode( Fields::TYPE_PJ ) . ';'
			. 'if(pj){pj.style.display=isPj?"":"none";}if(cnp){cnp.style.display=isPj?"none":"";}}'
			. 'sel.addEventListener("change",sync);sync();'
			. '})();';
	}
}
