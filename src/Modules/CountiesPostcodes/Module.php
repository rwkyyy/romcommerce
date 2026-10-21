<?php

declare(strict_types=1);

namespace RomCommerce\Modules\CountiesPostcodes;

use RomCommerce\Admin\ProSection;
use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Romanian counties/postcodes base data. WooCommerce core already ships the 42
 * județe + Bucharest sectors in i18n/states/RO.php, so this module does NOT
 * duplicate them — it exposes them through a reusable accessor and adds what
 * core lacks: 6-digit RO postcode format validation at checkout. Full postcode
 * autocomplete from a courier-sourced dataset is the separate Pro module.
 */
final class Module implements ModuleInterface, HasSettingsUi {

	private const ID = 'counties-postcodes';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Counties & Postcodes', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_postcodes' ), 10, 2 );
		add_action( 'woocommerce_blocks_validate_location_address_fields', array( $this, 'validate_block_address' ), 10, 3 );
	}

	/** @return array<string, string> RO county code => name, from WooCommerce core. */
	public static function counties(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->countries ) {
			return array();
		}

		$states = WC()->countries->get_states( 'RO' );

		return is_array( $states ) ? $states : array();
	}

	/**
	 * @param array<string, mixed> $data
	 * @param \WP_Error            $errors
	 */
	public function validate_postcodes( $data, $errors ): void {
		$this->check( $data, 'billing', $errors );

		if ( ! empty( $data['ship_to_different_address'] ) ) {
			$this->check( $data, 'shipping', $errors );
		}
	}

	/**
	 * @param array<string, mixed> $data
	 * @param \WP_Error            $errors
	 */
	private function check( array $data, string $prefix, $errors ): void {
		$message = self::postcode_error(
			isset( $data[ $prefix . '_country' ] ) ? (string) $data[ $prefix . '_country' ] : '',
			isset( $data[ $prefix . '_postcode' ] ) ? (string) $data[ $prefix . '_postcode' ] : ''
		);

		if ( null !== $message ) {
			$errors->add( 'romcommerce_postcode_' . $prefix, $message );
		}
	}

	/**
	 * Block checkout counterpart of validate_postcodes(), once per address
	 * (billing and shipping each arrive as their own $group).
	 *
	 * @param \WP_Error            $errors
	 * @param array<string, mixed> $address
	 * @param string               $group
	 */
	public function validate_block_address( $errors, $address, $group ): void {
		if ( ! $errors instanceof \WP_Error || ! is_array( $address ) ) {
			return;
		}

		$message = self::postcode_error(
			isset( $address['country'] ) ? (string) $address['country'] : '',
			isset( $address['postcode'] ) ? (string) $address['postcode'] : ''
		);

		if ( null !== $message ) {
			$errors->add( 'romcommerce_postcode_' . ( 'shipping' === $group ? 'shipping' : 'billing' ), $message );
		}
	}

	public static function postcode_error( string $country, string $postcode ): ?string {
		$postcode = trim( $postcode );

		if ( 'RO' !== $country || '' === $postcode || preg_match( '/^\d{6}$/', $postcode ) ) {
			return null;
		}

		return __( 'Codul poștal din România trebuie să conțină exact 6 cifre.', 'romcommerce' );
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			Settings::set_enabled( self::ID, isset( $_POST['rc_enabled'] ) );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'Validates Romanian postal codes (6 digits) at checkout. County data comes from WooCommerce core, so nothing is duplicated.', 'romcommerce' ) . '</p>';

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody><tr>';
		echo '<th scope="row">' . esc_html__( 'Postcode validation', 'romcommerce' ) . '</th>';
		echo '<td><label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ';
		echo esc_html__( 'Require a valid 6-digit Romanian postcode at checkout', 'romcommerce' ) . '</label></td>';
		echo '</tr></tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';

		ProSection::line( __( 'Address autocomplete', 'romcommerce' ), __( 'Postcode and street autocomplete in both directions, from a Romanian address dataset, for a faster checkout.', 'romcommerce' ) );
	}
}
