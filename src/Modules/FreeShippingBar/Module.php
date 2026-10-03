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
	}

	public function register_placements(): void {
		foreach ( $this->placements() as $placement ) {
			if ( $placement['active'] ) {
				Placement::hook( $placement['slot'], array( $this, 'render' ) );
			}
		}
	}

	/** @return array<int, array{slot: string, active: bool}> */
	public function placements(): array {
		return array(
			array(
				'slot'   => 'add_to_cart',
				'active' => $this->placement( 'product' ),
			),
			array(
				'slot'   => 'cart',
				'active' => $this->placement( 'cart' ),
			),
			array(
				'slot'   => 'checkout_review',
				'active' => $this->placement( 'checkout' ),
			),
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

		echo '<div class="romcommerce-fsb">';

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

		$radius = (string) $this->border_radius() . 'px';

		echo '<div class="romcommerce-fsb-track" style="background:' . esc_attr( $this->inactive_color() ) . ';border-radius:' . esc_attr( $radius ) . ';height:10px;overflow:hidden;">';
		echo '<div class="romcommerce-fsb-fill" style="height:10px;border-radius:' . esc_attr( $radius ) . ';background:' . esc_attr( $this->active_color() ) . ';width:' . esc_attr( (string) $percent ) . '%;"></div>';
		echo '</div>';
		echo '</div>';
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

	/** Clamped 0-30px, square through fully rounded. */
	private function border_radius(): int {
		$radius = (int) ( Settings::get( self::ID )['border_radius'] ?? 6 );

		return max( 0, min( 30, $radius ) );
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// SettingsForm::verify() has confirmed the nonce and the manage_woocommerce
			// capability on the line above; the $_POST reads live here inside that guard
			// (not in the handlers) so the authorization check is local to the input.
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above via SettingsForm::verify().
			$this->handle_save(
				isset( $_POST['rc_enabled'] ),
				array(
					'placement_product'  => isset( $_POST['rc_placement_product'] ),
					'placement_cart'     => isset( $_POST['rc_placement_cart'] ),
					'placement_checkout' => isset( $_POST['rc_placement_checkout'] ),
					'active_color'       => $this->sanitize_color( sanitize_text_field( wp_unslash( $_POST['rc_active_color'] ?? '' ) ), '#303f9f' ),
					'inactive_color'     => $this->sanitize_color( sanitize_text_field( wp_unslash( $_POST['rc_inactive_color'] ?? '' ) ), '#eceff1' ),
					'border_radius'      => max( 0, min( 30, absint( wp_unslash( $_POST['rc_border_radius'] ?? 6 ) ) ) ),
					'overrides'          => $this->parse_overrides( sanitize_textarea_field( wp_unslash( $_POST['rc_overrides'] ?? '' ) ) ),
				)
			);
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
		echo '<label><input type="checkbox" name="rc_placement_product" value="1"' . checked( $this->placement( 'product' ), true, false ) . '> ';
		echo esc_html__( 'Under the Add to Cart button', 'romcommerce' ) . '</label><br>';
		echo '<label><input type="checkbox" name="rc_placement_cart" value="1"' . checked( $this->placement( 'cart' ), true, false ) . '> ';
		echo esc_html__( 'On the cart page', 'romcommerce' ) . '</label><br>';
		echo '<label><input type="checkbox" name="rc_placement_checkout" value="1"' . checked( $this->placement( 'checkout' ), true, false ) . '> ';
		echo esc_html__( 'On the checkout page', 'romcommerce' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Toggle off any placement your theme already provides to avoid a double indicator.', 'romcommerce' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Colours', 'romcommerce' ) . '</th><td>';
		echo '<label style="display:inline-block;margin-right:24px;">' . esc_html__( 'Active (filled)', 'romcommerce' ) . '<br>';
		echo '<input type="color" name="rc_active_color" value="' . esc_attr( $this->active_color() ) . '"></label>';
		echo '<label style="display:inline-block;">' . esc_html__( 'Inactive (track)', 'romcommerce' ) . '<br>';
		echo '<input type="color" name="rc_inactive_color" value="' . esc_attr( $this->inactive_color() ) . '"></label>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Corner radius', 'romcommerce' ) . '</th><td>';
		echo '<input type="number" name="rc_border_radius" value="' . esc_attr( (string) $this->border_radius() ) . '" min="0" max="30" step="1" class="small-text"> px';
		echo '<p class="description">' . esc_html__( '0 for square corners, up to 30 for a fully rounded bar.', 'romcommerce' ) . '</p></td></tr>';

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
