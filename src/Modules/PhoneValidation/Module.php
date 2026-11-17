<?php

declare(strict_types=1);

namespace RomCommerce\Modules\PhoneValidation;

use RomCommerce\Admin\ProSection;
use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Phone validation at checkout (Lite). The browser does the full check with
 * the bundled intl-tel-input (libphonenumber data, refreshed by the monthly
 * "Bundled data" workflow): a country picker on the classic checkout's phone
 * fields, and a check on leaving the block checkout's phone field against the
 * address's own country. Validator re-checks on the server for both.
 */
final class Module implements ModuleInterface, HasSettingsUi {

	private const ID = 'phone-validation';

	private const LIB = 'assets/lib/intl-tel-input/';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Phone Validation', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_classic' ), 10, 2 );
		add_action( 'woocommerce_blocks_validate_location_address_fields', array( $this, 'validate_block_address' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'woocommerce_shipping_fields', array( $this, 'add_shipping_phone' ) );
	}

	/**
	 * A phone field in the classic checkout's delivery address (the block
	 * checkout has its own). The key is WooCommerce's own shipping_phone, so
	 * the order stores it as its shipping phone, where AWB plugins that use a
	 * delivery phone read it; without "Ship to a different address"
	 * WooCommerce copies the billing phone into it.
	 *
	 * @param array<string, array<string, mixed>>|mixed $fields
	 * @return array<string, array<string, mixed>>|mixed
	 */
	public function add_shipping_phone( $fields ) {
		$mode = $this->shipping_phone_mode();
		if ( 'off' === $mode || ! is_array( $fields ) || isset( $fields['shipping_phone'] ) ) {
			return $fields;
		}

		$fields['shipping_phone'] = array(
			'label'        => __( 'Phone', 'romcommerce' ),
			'type'         => 'tel',
			'required'     => 'required' === $mode,
			'class'        => array( 'form-row-wide' ),
			'validate'     => array( 'phone' ),
			'autocomplete' => 'tel',
			'priority'     => 100,
		);

		return $fields;
	}

	private function shipping_phone_mode(): string {
		$mode = (string) ( Settings::get( self::ID )['shipping_phone'] ?? 'off' );

		return in_array( $mode, array( 'off', 'optional', 'required' ), true ) ? $mode : 'off';
	}

	/**
	 * @param array<string, mixed> $data
	 * @param \WP_Error            $errors
	 */
	public function validate_classic( $data, $errors ): void {
		if ( ! is_array( $data ) || ! $errors instanceof \WP_Error ) {
			return;
		}

		$groups = array( 'billing' );
		if ( ! empty( $data['ship_to_different_address'] ) ) {
			$groups[] = 'shipping';
		}

		foreach ( $groups as $group ) {
			$phone   = isset( $data[ $group . '_phone' ] ) ? (string) $data[ $group . '_phone' ] : '';
			$country = isset( $data[ $group . '_country' ] ) ? (string) $data[ $group . '_country' ] : '';

			if ( ! Validator::is_valid( $phone, $country ) ) {
				$errors->add( 'romcommerce_phone_' . $group, self::invalid_message() );
			}
		}
	}

	/**
	 * @param \WP_Error            $errors
	 * @param array<string, mixed> $address
	 * @param string               $group
	 */
	public function validate_block_address( $errors, $address, $group ): void {
		if ( ! $errors instanceof \WP_Error || ! is_array( $address ) ) {
			return;
		}

		$phone   = isset( $address['phone'] ) ? (string) $address['phone'] : '';
		$country = isset( $address['country'] ) ? (string) $address['country'] : '';

		if ( ! Validator::is_valid( $phone, $country ) ) {
			$errors->add( 'romcommerce_phone_' . ( 'shipping' === $group ? 'shipping' : 'billing' ), self::invalid_message() );
		}
	}

	public function enqueue_assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) ) {
			return;
		}

		$version = $this->library_version();

		wp_enqueue_style( 'romcommerce-intl-tel-input', plugins_url( self::LIB . 'css/intlTelInput.css', ROMCOMMERCE_FILE ), array(), $version );
		// The library sizes its wrapper to the input's inline width; themes give
		// checkout inputs width:100% of the row, so the wrapper must fill it too.
		wp_add_inline_style( 'romcommerce-intl-tel-input', '.woocommerce-checkout .form-row .iti{display:block;width:100%;}' );
		wp_register_script( 'romcommerce-intl-tel-input', plugins_url( self::LIB . 'js/intlTelInputWithUtils.js', ROMCOMMERCE_FILE ), array(), $version, true );

		$deps = array( 'romcommerce-intl-tel-input' );
		if ( function_exists( 'has_block' ) && has_block( 'woocommerce/checkout' ) ) {
			$deps = array_merge( $deps, array( 'wp-data', 'wc-blocks-data-store' ) );
		} else {
			$deps[] = 'jquery';
		}

		wp_enqueue_script( 'romcommerce-phone-validation', plugins_url( 'assets/phone-validation.js', ROMCOMMERCE_FILE ), $deps, ROMCOMMERCE_VERSION, true );
		wp_add_inline_script(
			'romcommerce-phone-validation',
			'window.romcommercePhoneValidation=' . wp_json_encode(
				array(
					'initialCountry' => $this->base_country(),
					'onlyCountries'  => $this->only_countries(),
					'invalid'        => self::invalid_message(),
				)
			) . ';',
			'before'
		);
	}

	private static function invalid_message(): string {
		return __( 'Numărul de telefon introdus nu este valid.', 'romcommerce' );
	}

	private function library_version(): string {
		$file    = ROMCOMMERCE_DIR . '/' . self::LIB . 'VERSION';
		$version = is_readable( $file ) ? trim( (string) file_get_contents( $file ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local bundled file.

		return '' !== $version ? $version : ROMCOMMERCE_VERSION;
	}

	private function base_country(): string {
		$country = function_exists( 'WC' ) && WC()->countries ? (string) WC()->countries->get_base_country() : '';

		return '' !== $country ? $country : 'RO';
	}

	/**
	 * The store's selling countries when it restricts them, so the picker
	 * doesn't offer countries the checkout won't accept; empty means all.
	 *
	 * @return array<int, string>
	 */
	private function only_countries(): array {
		if ( 'specific' !== get_option( 'woocommerce_allowed_countries' ) || ! function_exists( 'WC' ) || ! WC()->countries ) {
			return array();
		}

		return array_map( 'strtolower', array_keys( WC()->countries->get_allowed_countries() ) );
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			Settings::set_enabled( self::ID, isset( $_POST['rc_enabled'] ) );
			$mode = isset( $_POST['rc_shipping_phone'] ) ? sanitize_key( wp_unslash( $_POST['rc_shipping_phone'] ) ) : 'off';
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			$settings                   = Settings::get( self::ID );
			$settings['shipping_phone'] = in_array( $mode, array( 'optional', 'required' ), true ) ? $mode : 'off';
			Settings::update( self::ID, $settings );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'Checks billing and shipping phone numbers at checkout against international numbering data, with a country picker on the classic checkout. On the block checkout the number is checked against the country of the address.', 'romcommerce' ) . '</p>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: bundled intl-tel-input version */
				__( 'Numbering data: intl-tel-input %s, bundled with the plugin and updated with RomCommerce releases. Nothing is sent to an external service.', 'romcommerce' ),
				$this->library_version()
			)
		) . '</p>';

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody><tr>';
		echo '<th scope="row">' . esc_html__( 'Module', 'romcommerce' ) . '</th>';
		echo '<td><label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ';
		echo esc_html__( 'Validate phone numbers at checkout', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row"><label for="rc-shipping-phone">' . esc_html__( 'Delivery phone', 'romcommerce' ) . '</label></th><td>';
		echo '<select id="rc-shipping-phone" name="rc_shipping_phone">';
		$modes = array(
			'off'      => __( 'Not on the classic checkout', 'romcommerce' ),
			'optional' => __( 'Optional', 'romcommerce' ),
			'required' => __( 'Required', 'romcommerce' ),
		);
		foreach ( $modes as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $this->shipping_phone_mode(), $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Adds a phone field to the delivery address on the classic checkout and in My account, checked like the billing phone. It is saved as the order\'s shipping phone, which AWB plugins that use a delivery phone read; without a different delivery address, the billing phone is used. The block checkout has its own phone field for the delivery address, set in the Checkout block.', 'romcommerce' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';

		ProSection::line( __( 'Numbering data updates', 'romcommerce' ), __( 'Keeps the phone numbering data current between plugin releases.', 'romcommerce' ) );
	}
}
