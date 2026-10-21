<?php

declare(strict_types=1);

namespace RomCommerce\Modules\FreeShippingBar;

use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasPlacements;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;
use RomCommerce\Support\Placement;
use WC_Shipping_Zones;

defined( 'ABSPATH' ) || exit;

/**
 * "Spend X more for free shipping" progress indicator, shown under the
 * Add-to-Cart button, on the cart, and on the checkout — each placement
 * individually toggleable so it never double-renders against a theme that
 * already has one. The threshold is read automatically from the WooCommerce
 * free-shipping method matching the cart's destination zone, with a per-country
 * manual override for shops whose free shipping isn't a core WC method.
 */
final class Module implements ModuleInterface, HasSettingsUi, HasPlacements {

	private const ID = 'free-shipping-bar';

	private const SHAPES = array( 'rounded', 'square' );

	/** The thickest choice, nearest the 10px bar stores had before thickness was a setting. */
	private const DEFAULT_THICKNESS = 5;

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Free Shipping Bar', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		// Placement hooks wire on init, not here — see HasPlacements.
		add_action( 'init', array( $this, 'register_placements' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_block_script' ) );
	}

	public function register_placements(): void {
		foreach ( $this->placements() as $placement ) {
			if ( $placement['active'] ) {
				Placement::hook( $placement['slot'], array( $this, 'render' ) );
				Placement::hook_block( $placement['slot'], array( $this, 'render' ) );
			}
		}
	}

	/** @return array<int, array{key: string, slot: string, active: bool, choices: array<int, string>}> */
	public function placements(): array {
		return Placement::resolve(
			self::ID,
			array(
				array(
					'key'     => 'product',
					'slot'    => 'add_to_cart',
					'active'  => $this->placement( 'product' ),
					'choices' => Placement::surface_slots( 'product' ),
				),
				array(
					'key'     => 'cart',
					'slot'    => 'cart',
					'active'  => $this->placement( 'cart' ),
					'choices' => Placement::surface_slots( 'cart' ),
				),
				array(
					'key'     => 'checkout',
					'slot'    => 'checkout_review',
					'active'  => $this->placement( 'checkout' ),
					'choices' => Placement::surface_slots( 'checkout' ),
				),
			)
		);
	}

	public function render(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$threshold = $this->threshold();
		if ( $threshold <= 0 ) {
			// Fail-soft: no resolvable free-shipping threshold means no bar,
			// never an error or an empty widget.
			return;
		}

		$total   = $this->cart_total();
		$percent = min( 100, (int) floor( ( $total / $threshold ) * 100 ) );

		// The threshold rides along in minor units so block_script_js() can
		// redraw the bar from the block cart's own totals.
		$minor = (int) round( $threshold * pow( 10, wc_get_price_decimals() ) );
		echo '<div class="romcommerce-fsb" data-rc-threshold="' . esc_attr( (string) $minor ) . '">';

		if ( $total >= $threshold ) {
			echo '<p class="romcommerce-fsb-message">' . esc_html__( 'Felicitări! Beneficiați de livrare gratuită.', 'romcommerce' ) . '</p>';
		} else {
			$remaining = wp_strip_all_tags( wc_price( $threshold - $total ) );
			echo '<p class="romcommerce-fsb-message">' . esc_html(
				sprintf(
					/* translators: %s: formatted remaining amount */
					__( 'Mai adăugați %s pentru livrare gratuită.', 'romcommerce' ),
					$remaining
				)
			) . '</p>';
		}

		$radius = 'square' === $this->shape() ? '0' : '999px';
		$height = (string) $this->thickness() . 'px';

		echo '<div class="romcommerce-fsb-track" style="background:' . esc_attr( $this->inactive_color() ) . ';border-radius:' . esc_attr( $radius ) . ';height:' . esc_attr( $height ) . ';overflow:hidden;">';
		echo '<div class="romcommerce-fsb-fill" style="height:' . esc_attr( $height ) . ';border-radius:' . esc_attr( $radius ) . ';background:' . esc_attr( $this->active_color() ) . ';width:' . esc_attr( (string) $percent ) . '%;"></div>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * On the block cart and checkout the bar is server-rendered once, then the
	 * cart changes in the browser without a page load; this keeps it current
	 * from WooCommerce's cart data store.
	 */
	public function enqueue_block_script(): void {
		if ( ! has_block( 'woocommerce/cart' ) && ! has_block( 'woocommerce/checkout' ) ) {
			return;
		}

		wp_enqueue_script(
			'romcommerce-free-shipping-bar',
			plugins_url( 'assets/free-shipping-bar-blocks.js', ROMCOMMERCE_FILE ),
			array( 'wp-data', 'wc-blocks-data-store', 'wc-settings', 'wc-price-format' ),
			ROMCOMMERCE_VERSION,
			true
		);
		wp_add_inline_script(
			'romcommerce-free-shipping-bar',
			'window.romcommerceFreeShippingBar=' . wp_json_encode(
				array(
					'inclTax' => 'incl' === get_option( 'woocommerce_tax_display_cart' ),
					/* translators: %s: formatted remaining amount */
					'left'    => __( 'Mai adăugați %s pentru livrare gratuită.', 'romcommerce' ),
					'done'    => __( 'Felicitări! Beneficiați de livrare gratuită.', 'romcommerce' ),
				)
			) . ';',
			'before'
		);
	}

	/** The amount the free-shipping threshold is compared against, mirroring WC's own calculation closely enough for an indicator. */
	private function cart_total(): float {
		$total  = (float) WC()->cart->get_displayed_subtotal();
		$total -= (float) WC()->cart->get_discount_total();

		return max( 0.0, $total );
	}

	private function threshold(): float {
		$override = $this->country_override();
		if ( null !== $override ) {
			return $override;
		}

		return $this->auto_threshold();
	}

	/** Per-country manual override for the customer's current shipping country, if configured. */
	private function country_override(): ?float {
		$overrides = $this->overrides();
		if ( empty( $overrides ) ) {
			return null;
		}

		$country = WC()->customer ? WC()->customer->get_shipping_country() : '';
		if ( '' === $country ) {
			$country = WC()->customer ? WC()->customer->get_billing_country() : '';
		}

		return isset( $overrides[ $country ] ) ? (float) $overrides[ $country ] : null;
	}

	/** Reads min_amount from the free-shipping method in the zone matching the cart destination. */
	private function auto_threshold(): float {
		$packages = WC()->cart->get_shipping_packages();
		$package  = is_array( $packages ) ? reset( $packages ) : false;
		if ( false === $package ) {
			return 0.0;
		}

		$zone    = WC_Shipping_Zones::get_zone_matching_package( $package );
		$methods = $zone->get_shipping_methods( true );

		foreach ( $methods as $method ) {
			if ( 'free_shipping' !== $method->id ) {
				continue;
			}

			$requires = isset( $method->requires ) ? (string) $method->requires : '';
			if ( ! in_array( $requires, array( 'min_amount', 'either', 'both' ), true ) ) {
				continue;
			}

			$min = isset( $method->min_amount ) ? (float) $method->min_amount : 0.0;
			if ( $min > 0 ) {
				return $min;
			}
		}

		return 0.0;
	}

	/** @return array<string, float> */
	private function overrides(): array {
		$stored = Settings::get( self::ID )['overrides'] ?? array();

		return is_array( $stored ) ? $stored : array();
	}

	private function placement( string $key ): bool {
		$defaults = array(
			'product'  => true,
			'cart'     => true,
			'checkout' => true,
		);

		return (bool) ( Settings::get( self::ID )[ 'placement_' . $key ] ?? $defaults[ $key ] );
	}

	private function active_color(): string {
		$color = (string) ( Settings::get( self::ID )['active_color'] ?? '#303f9f' );

		return '' !== $color ? $color : '#303f9f';
	}

	private function inactive_color(): string {
		$color = (string) ( Settings::get( self::ID )['inactive_color'] ?? '#eceff1' );

		return '' !== $color ? $color : '#eceff1';
	}

	/**
	 * Square or fully rounded: a radius in between barely shows on a bar a
	 * few pixels thick. Stores saved before this setting existed keep square
	 * corners if they had set the old corner radius to 0.
	 */
	private function shape(): string {
		$settings = Settings::get( self::ID );
		$shape    = (string) ( $settings['shape'] ?? '' );
		if ( in_array( $shape, self::SHAPES, true ) ) {
			return $shape;
		}

		return isset( $settings['border_radius'] ) && 0 === (int) $settings['border_radius'] ? 'square' : 'rounded';
	}

	private function thickness(): int {
		$thickness = (int) ( Settings::get( self::ID )['thickness'] ?? self::DEFAULT_THICKNESS );

		return max( 1, min( 5, $thickness ) );
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// SettingsForm::verify() has confirmed the nonce and the manage_woocommerce
			// capability on the line above; the $_POST reads live here inside that guard
			// (not in the handlers) so the authorization check is local to the input.
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above via SettingsForm::verify().
			$shape = sanitize_key( wp_unslash( $_POST['rc_shape'] ?? '' ) );
			$this->handle_save(
				isset( $_POST['rc_enabled'] ),
				array(
					'placement_product'  => isset( $_POST['rc_placement_product'] ),
					'placement_cart'     => isset( $_POST['rc_placement_cart'] ),
					'placement_checkout' => isset( $_POST['rc_placement_checkout'] ),
					'active_color'       => $this->sanitize_color( sanitize_text_field( wp_unslash( $_POST['rc_active_color'] ?? '' ) ), '#303f9f' ),
					'inactive_color'     => $this->sanitize_color( sanitize_text_field( wp_unslash( $_POST['rc_inactive_color'] ?? '' ) ), '#eceff1' ),
					'shape'              => in_array( $shape, self::SHAPES, true ) ? $shape : 'rounded',
					'thickness'          => max( 1, min( 5, absint( wp_unslash( $_POST['rc_thickness'] ?? self::DEFAULT_THICKNESS ) ) ) ),
					'overrides'          => $this->parse_overrides( sanitize_textarea_field( wp_unslash( $_POST['rc_overrides'] ?? '' ) ) ),
				)
			);
			Placement::save_choices( self::ID, $this->placements(), isset( $_POST['rc_area'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['rc_area'] ) ) : array() );
			// phpcs:enable WordPress.Security.NonceVerification.Missing
		}

		echo '<p>' . esc_html__( 'Shows how much more the customer needs to spend to qualify for free shipping. The threshold is read from your WooCommerce free-shipping method automatically.', 'romcommerce' ) . '</p>';

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Module', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ';
		echo esc_html__( 'Enable', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Placements', 'romcommerce' ) . '</th><td>';
		$placements = $this->placements();
		$labels     = array(
			'product'  => __( 'Product page', 'romcommerce' ),
			'cart'     => __( 'Cart page', 'romcommerce' ),
			'checkout' => __( 'Checkout page', 'romcommerce' ),
		);
		foreach ( $labels as $key => $label ) {
			echo '<p class="rc-area-row"><label><input type="checkbox" name="rc_placement_' . esc_attr( $key ) . '" value="1"' . checked( $this->placement( $key ), true, false ) . '> ' . esc_html( $label ) . '</label> ';
			Placement::area_field( 'rc_area[' . $key . ']', Placement::find( $placements, $key ) );
			echo '</p>';
		}
		echo '<p class="description">' . esc_html__( 'Toggle off any placement your theme already provides to avoid a double indicator.', 'romcommerce' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Colours', 'romcommerce' ) . '</th><td>';
		echo '<label style="display:inline-block;margin-right:24px;">' . esc_html__( 'Active (filled)', 'romcommerce' ) . '<br>';
		echo '<input type="color" name="rc_active_color" value="' . esc_attr( $this->active_color() ) . '"></label>';
		echo '<label style="display:inline-block;">' . esc_html__( 'Inactive (track)', 'romcommerce' ) . '<br>';
		echo '<input type="color" name="rc_inactive_color" value="' . esc_attr( $this->inactive_color() ) . '"></label>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="rc-fsb-shape">' . esc_html__( 'Bar shape', 'romcommerce' ) . '</label></th><td>';
		echo '<select name="rc_shape" id="rc-fsb-shape">';
		foreach (
			array(
				'rounded' => __( 'Rounded', 'romcommerce' ),
				'square'  => __( 'Square', 'romcommerce' ),
			) as $value => $label
		) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $this->shape(), $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="rc-fsb-thickness">' . esc_html__( 'Bar thickness', 'romcommerce' ) . '</label></th><td>';
		echo '<input type="number" id="rc-fsb-thickness" name="rc_thickness" value="' . esc_attr( (string) $this->thickness() ) . '" min="1" max="5" step="1" class="small-text"> px</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Manual thresholds', 'romcommerce' ) . '</th><td>';
		echo '<textarea name="rc_overrides" rows="4" class="large-text" placeholder="RO:200&#10;HU:250">' . esc_textarea( $this->overrides_text() ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'One per line as COUNTRY:AMOUNT (e.g. RO:200). Overrides the auto-detected threshold for that country. Use it when free shipping is not a WooCommerce core method.', 'romcommerce' ) . '</p></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';
	}

	private function overrides_text(): string {
		$lines = array();
		foreach ( $this->overrides() as $country => $amount ) {
			$lines[] = $country . ':' . $amount;
		}

		return implode( "\n", $lines );
	}

	/**
	 * @param bool                 $enabled  Module enable flag.
	 * @param array<string, mixed> $settings Values already read and sanitized under the
	 *                                        verified nonce in render_settings().
	 */
	private function handle_save( bool $enabled, array $settings ): void {
		Settings::set_enabled( self::ID, $enabled );
		Settings::update( self::ID, $settings );

		echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
	}

	private function sanitize_color( string $color, string $default ): string {
		$color = trim( $color );

		return preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color ) ? $color : $default;
	}

	/** @return array<string, float> */
	private function parse_overrides( string $raw ): array {
		$lines = preg_split( '/\r\n|\r|\n/', $raw );

		$overrides = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}

			list( $country, $amount ) = explode( ':', $line, 2 );
			$country                  = strtoupper( trim( $country ) );
			$amount                   = (float) wc_format_decimal( trim( $amount ) );

			if ( '' !== $country && $amount > 0 ) {
				$overrides[ $country ] = $amount;
			}
		}

		return $overrides;
	}
}
