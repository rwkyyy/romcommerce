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
 * core lacks: 6-digit RO postcode format validation at checkout, and an
 * optional locality selector fed by the chosen county (Localities). Full
 * postcode autocomplete from a courier-sourced dataset is the separate Pro
 * module.
 */
final class Module implements ModuleInterface, HasSettingsUi {

	private const ID = 'counties-postcodes';

	/**
	 * WooCommerce's city field is priority 70. The county decides which
	 * localities are offered, so it moves just before the city; WooCommerce
	 * also turns this priority into the block checkout's field order.
	 */
	private const COUNTY_PRIORITY = 65;

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
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_checkout' ), 10, 2 );
		add_action( 'woocommerce_blocks_validate_location_address_fields', array( $this, 'validate_block_checkout' ), 10, 3 );
		add_filter( 'woocommerce_get_country_locale', array( $this, 'county_before_city' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/** @return array<string, string> RO county code => name, from WooCommerce core. */
	public static function counties(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->countries ) {
			return array();
		}

		$states = WC()->countries->get_states( 'RO' );

		return is_array( $states ) ? $states : array();
	}

	/** Postcode validation was the whole module before the locality selector, so stores saved before it keep it on. */
	private function postcodes_on(): bool {
		return (bool) ( Settings::get( self::ID )['postcodes'] ?? true );
	}

	private function cities_on(): bool {
		return ! empty( Settings::get( self::ID )['cities'] );
	}

	private function list_only(): bool {
		return 'typed' !== ( Settings::get( self::ID )['city_entry'] ?? 'list' );
	}

	/**
	 * @param array<string, mixed> $data
	 * @param \WP_Error            $errors
	 */
	public function validate_checkout( $data, $errors ): void {
		if ( $this->postcodes_on() ) {
			$this->validate_postcodes( $data, $errors );
		}

		if ( $this->cities_on() && $this->list_only() ) {
			$this->check_city( $data, 'billing', $errors );
			if ( ! empty( $data['ship_to_different_address'] ) ) {
				$this->check_city( $data, 'shipping', $errors );
			}
		}
	}

	/**
	 * @param \WP_Error            $errors
	 * @param array<string, mixed> $address
	 * @param string               $group
	 */
	public function validate_block_checkout( $errors, $address, $group ): void {
		if ( $this->postcodes_on() ) {
			$this->validate_block_address( $errors, $address, $group );
		}

		if ( ! $errors instanceof \WP_Error || ! is_array( $address ) || ! $this->cities_on() || ! $this->list_only() ) {
			return;
		}

		$message = self::city_error(
			isset( $address['country'] ) ? (string) $address['country'] : '',
			isset( $address['state'] ) ? (string) $address['state'] : '',
			isset( $address['city'] ) ? (string) $address['city'] : ''
		);

		if ( null !== $message ) {
			$errors->add( 'romcommerce_city_' . ( 'shipping' === $group ? 'shipping' : 'billing' ), $message );
		}
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
	 * @param array<string, mixed> $data
	 * @param \WP_Error            $errors
	 */
	private function check_city( array $data, string $prefix, $errors ): void {
		$message = self::city_error(
			isset( $data[ $prefix . '_country' ] ) ? (string) $data[ $prefix . '_country' ] : '',
			isset( $data[ $prefix . '_state' ] ) ? (string) $data[ $prefix . '_state' ] : '',
			isset( $data[ $prefix . '_city' ] ) ? (string) $data[ $prefix . '_city' ] : ''
		);

		if ( null !== $message ) {
			$errors->add( 'romcommerce_city_' . $prefix, $message );
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

	/**
	 * An empty city is left to WooCommerce's own required-field check, and a
	 * county with no bundled list isn't checked.
	 */
	public static function city_error( string $country, string $county, string $city ): ?string {
		if ( 'RO' !== $country || '' === trim( $city ) || array() === Localities::for_county( $county ) || Localities::contains( $county, $city ) ) {
			return null;
		}

		return __( 'Alegeți localitatea din lista județului selectat.', 'romcommerce' );
	}

	/**
	 * @param mixed $locale
	 * @return mixed
	 */
	public function county_before_city( $locale ) {
		if ( is_array( $locale ) && $this->cities_on() ) {
			$locale['RO']['state']['priority'] = self::COUNTY_PRIORITY;
		}

		return $locale;
	}

	/**
	 * The classic checkout and My account's address form get WooCommerce's
	 * own selectWoo; the block checkout's React form can't host it, so there
	 * the city field gets the browser's native suggestion list instead.
	 */
	public function enqueue_assets(): void {
		if ( ! $this->cities_on() || ! function_exists( 'is_checkout' ) ) {
			return;
		}

		$checkout = is_checkout() && ! is_order_received_page();
		if ( ! $checkout && ! is_wc_endpoint_url( 'edit-address' ) ) {
			return;
		}

		if ( $checkout && function_exists( 'has_block' ) && has_block( 'woocommerce/checkout' ) ) {
			$deps = array( 'wp-data', 'wc-blocks-data-store' );
		} else {
			$deps = array( 'jquery', 'selectWoo' );
			wp_enqueue_style( 'select2' );
		}

		wp_enqueue_script( 'romcommerce-city-select', plugins_url( 'assets/city-select.js', ROMCOMMERCE_FILE ), $deps, ROMCOMMERCE_VERSION, true );
		wp_add_inline_script(
			'romcommerce-city-select',
			'window.romcommerceCitySelect=' . wp_json_encode(
				array(
					'url'      => plugins_url( Localities::DIR, ROMCOMMERCE_FILE ),
					'version'  => ROMCOMMERCE_VERSION,
					'listOnly' => $this->list_only(),
					'i18n'     => array(
						'placeholder' => __( 'Alegeți localitatea…', 'romcommerce' ),
						'countyFirst' => __( 'Alegeți mai întâi județul', 'romcommerce' ),
						'noResults'   => __( 'Nicio localitate găsită', 'romcommerce' ),
						'searching'   => __( 'Se caută…', 'romcommerce' ),
					),
				)
			) . ';',
			'before'
		);
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// SettingsForm::verify() has confirmed the nonce and capability on the line above.
			// phpcs:disable WordPress.Security.NonceVerification.Missing
			$this->handle_save(
				isset( $_POST['rc_enabled'] ),
				isset( $_POST['rc_postcodes'] ),
				isset( $_POST['rc_cities'] ),
				( isset( $_POST['rc_city_entry'] ) && 'typed' === sanitize_key( wp_unslash( $_POST['rc_city_entry'] ) ) ) ? 'typed' : 'list'
			);
			// phpcs:enable WordPress.Security.NonceVerification.Missing
		}

		echo '<p>' . esc_html__( 'Romanian address helpers for checkout: postcode validation and a locality list for the chosen county. County data comes from WooCommerce core, so nothing is duplicated.', 'romcommerce' ) . '</p>';

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Module', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ';
		echo esc_html__( 'Enable', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Postcode validation', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_postcodes" value="1"' . checked( $this->postcodes_on(), true, false ) . '> ';
		echo esc_html__( 'Require a valid 6-digit Romanian postcode at checkout', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Locality list', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_cities" value="1"' . checked( $this->cities_on(), true, false ) . '> ';
		echo esc_html__( 'Offer the localities of the chosen county in the city field (Romania only)', 'romcommerce' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'The county field moves above the city. The classic checkout and the My account address form get a searchable list; the block checkout gets suggestions while the customer types. Only the chosen county\'s list loads (a few KB), so the checkout stays fast on slow phones. București lists Sector 1 to Sector 6.', 'romcommerce' ) . '</p></td></tr>';

		$typed = ! $this->list_only();
		echo '<tr><th scope="row">' . esc_html__( 'Localities not in the list', 'romcommerce' ) . '</th><td><fieldset>';
		echo '<label><input type="radio" name="rc_city_entry" value="list"' . checked( $typed, false, false ) . '> ' . esc_html__( 'Not allowed: the customer must pick from the list', 'romcommerce' ) . '</label><br>';
		echo '<label><input type="radio" name="rc_city_entry" value="typed"' . checked( $typed, true, false ) . '> ' . esc_html__( 'Allowed: the customer can also type a locality', 'romcommerce' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'The list has 13,266 localities and dates from 2021. If it misses a customer\'s village, "Not allowed" stops that customer at checkout.', 'romcommerce' ) . '</p>';
		echo '</fieldset></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';

		ProSection::line( __( 'Address autocomplete', 'romcommerce' ), __( 'Postcode and street autocomplete in both directions, from a Romanian address dataset, for a faster checkout.', 'romcommerce' ) );
	}

	private function handle_save( bool $enabled, bool $postcodes, bool $cities, string $city_entry ): void {
		Settings::set_enabled( self::ID, $enabled );
		Settings::update(
			self::ID,
			array(
				'postcodes'  => $postcodes,
				'cities'     => $cities,
				'city_entry' => $city_entry,
			)
		);

		echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
	}
}
