<?php

declare(strict_types=1);

namespace RomCommerce\Modules\EuCountries;

use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;
use WC_Shipping_Zone;
use WC_Shipping_Zones;

defined( 'ABSPATH' ) || exit;

/**
 * A checklist of the EU countries against WooCommerce's selling locations,
 * shipping locations and shipping zones, with buttons that add the ticked
 * countries to each. Nothing runs on the storefront: it only edits
 * WooCommerce's own settings when the merchant presses a button, and never
 * removes a country.
 *
 * The EU shipping zone it creates goes after every existing zone, and
 * countries already in another zone are skipped: WooCommerce uses the first
 * matching zone, so a country in two zones would silently keep the first.
 */
final class Module implements ModuleInterface, HasSettingsUi {

	private const ID = 'eu-countries';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'EU Countries', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// SettingsForm::verify() has confirmed the nonce and the manage_woocommerce
			// capability on the line above; the $_POST reads live here inside that guard.
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above via SettingsForm::verify().
			$action = isset( $_POST['rc_action'] ) ? sanitize_key( wp_unslash( $_POST['rc_action'] ) ) : '';
			$ticked = isset( $_POST['rc_countries'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['rc_countries'] ) ) : array();
			$enable = isset( $_POST['rc_enabled'] );
			// phpcs:enable WordPress.Security.NonceVerification.Missing

			if ( '' === $action ) {
				Settings::set_enabled( self::ID, $enable );
				echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
			} elseif ( $this->is_enabled() ) {
				$this->run_action( $action, array_values( array_intersect( $this->eu_countries(), array_map( 'strtoupper', $ticked ) ) ) );
			}
		}

		$eu       = $this->eu_countries();
		$names    = WC()->countries->get_countries();
		$selling  = array_keys( WC()->countries->get_allowed_countries() );
		$shipping = array_keys( WC()->countries->get_shipping_countries() );
		$zones    = $this->zones_by_country();

		echo '<p>' . esc_html__( 'Checks the EU countries against WooCommerce\'s selling and shipping locations and its shipping zones. Tick the countries you want and add them with the buttons below. Nothing is removed, and nothing changes until you press a button.', 'romcommerce' ) . '</p>';

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Module', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ';
		echo esc_html__( 'Enable', 'romcommerce' ) . '</label></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );

		$in_selling  = count( array_intersect( $eu, $selling ) );
		$in_shipping = count( array_intersect( $eu, $shipping ) );
		$in_zone     = count( array_intersect( $eu, array_keys( $zones ) ) );
		$total       = count( $eu );

		echo '<div class="rc-cat-head"><span class="rc-tile"></span><h3>' . esc_html__( 'EU countries', 'romcommerce' ) . '</h3><span class="rc-rule"></span>';
		echo '<span class="rc-count">' . esc_html(
			sprintf(
				/* translators: 1: EU countries you sell to, 2: EU countries you ship to, 3: EU countries in a shipping zone, 4: number of EU countries */
				__( 'Selling %1$d/%4$d · Shipping %2$d/%4$d · Zone %3$d/%4$d', 'romcommerce' ),
				$in_selling,
				$in_shipping,
				$in_zone,
				$total
			)
		) . '</span></div>';

		$toggle_all = "var c=this.checked;this.closest('table').querySelectorAll('input[name=\"rc_countries[]\"]').forEach(function(i){i.checked=c;});";

		echo '<table class="widefat striped" style="max-width:52em;"><thead><tr>';
		echo '<td class="check-column"><input type="checkbox" aria-label="' . esc_attr__( 'Tick all countries', 'romcommerce' ) . '" onclick="' . esc_attr( $toggle_all ) . '"></td>';
		echo '<th scope="col">' . esc_html__( 'Country', 'romcommerce' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Selling locations', 'romcommerce' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Shipping locations', 'romcommerce' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Shipping zone', 'romcommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $eu as $code ) {
			$sells   = in_array( $code, $selling, true );
			$ships   = in_array( $code, $shipping, true );
			$zone    = isset( $zones[ $code ] ) ? implode( ', ', array_values( $zones[ $code ] ) ) : '';
			$missing = ! $sells || ! $ships || '' === $zone;
			$name    = $names[ $code ] ?? $code;

			echo '<tr>';
			echo '<th scope="row" class="check-column"><input type="checkbox" name="rc_countries[]" value="' . esc_attr( $code ) . '"' . checked( $missing, true, false ) . ' aria-label="' . esc_attr( $name ) . '"></th>';
			echo '<td>' . esc_html( $name ) . '</td>';
			echo '<td>' . $this->status( $sells ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- status() escapes its own output.
			echo '<td>' . $this->status( $ships ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- status() escapes its own output.
			echo '<td>' . ( '' !== $zone ? esc_html( $zone ) : $this->status( false ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- status() escapes its own output.
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Countries missing from at least one column are ticked for you.', 'romcommerce' ) . '</p>';

		if ( ! $this->is_enabled() ) {
			echo '<p class="description">' . esc_html__( 'Enable the module and save to use the buttons.', 'romcommerce' ) . '</p>';
			echo '</form>';
			return;
		}

		echo '<p style="display:flex;flex-wrap:wrap;gap:8px;">';
		echo '<button type="submit" name="rc_action" value="selling" class="button">' . esc_html__( 'Add ticked countries to selling locations', 'romcommerce' ) . '</button>';
		echo '<button type="submit" name="rc_action" value="shipping" class="button">' . esc_html__( 'Add ticked countries to shipping locations', 'romcommerce' ) . '</button>';
		echo '<button type="submit" name="rc_action" value="zone" class="button">' . esc_html__( 'Add ticked countries to the EU shipping zone', 'romcommerce' ) . '</button>';
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'The EU shipping zone is created the first time, after your other zones, with no shipping methods: add them in WooCommerce, Settings, Shipping. Countries already in another zone are left there.', 'romcommerce' ) . '</p>';
		echo '</form>';
	}

	/** @param array<int, string> $codes Ticked EU country codes. */
	private function run_action( string $action, array $codes ): void {
		if ( array() === $codes ) {
			$this->notice( 'warning', __( 'Tick at least one country first.', 'romcommerce' ) );
			return;
		}

		switch ( $action ) {
			case 'selling':
				$this->add_to_selling( $codes );
				return;
			case 'shipping':
				$this->add_to_shipping( $codes );
				return;
			case 'zone':
				$this->add_to_zone( $codes );
				return;
		}
	}

	/** @param array<int, string> $codes */
	private function add_to_selling( array $codes ): void {
		$mode = (string) get_option( 'woocommerce_allowed_countries', 'all' );

		if ( 'all' === $mode ) {
			$this->notice( 'info', __( 'Your store already sells to every country.', 'romcommerce' ) );
			return;
		}

		if ( 'all_except' === $mode ) {
			$except = (array) get_option( 'woocommerce_all_except_countries', array() );
			update_option( 'woocommerce_all_except_countries', array_values( array_diff( $except, $codes ) ) );
		} else {
			$specific = (array) get_option( 'woocommerce_specific_allowed_countries', array() );
			update_option( 'woocommerce_specific_allowed_countries', array_values( array_unique( array_merge( $specific, $codes ) ) ) );
		}

		$this->notice( 'success', __( 'The ticked countries are now in your selling locations.', 'romcommerce' ) );
	}

	/** @param array<int, string> $codes */
	private function add_to_shipping( array $codes ): void {
		$mode = (string) get_option( 'woocommerce_ship_to_countries', '' );

		if ( 'disabled' === $mode ) {
			$this->notice( 'warning', __( 'Shipping is switched off in WooCommerce, Settings, General, so there is no shipping list to add to.', 'romcommerce' ) );
			return;
		}

		if ( 'all' === $mode ) {
			$this->notice( 'info', __( 'Your store already ships to every country.', 'romcommerce' ) );
			return;
		}

		// Changing "ship to all countries you sell to" into a fixed list would
		// stop later selling locations from being shipped to, so it stays.
		if ( 'specific' !== $mode ) {
			$this->notice( 'info', __( 'Your shipping locations follow your selling locations, so add the countries to selling locations instead.', 'romcommerce' ) );
			return;
		}

		$specific = (array) get_option( 'woocommerce_specific_ship_to_countries', array() );
		update_option( 'woocommerce_specific_ship_to_countries', array_values( array_unique( array_merge( $specific, $codes ) ) ) );

		$this->notice( 'success', __( 'The ticked countries are now in your shipping locations.', 'romcommerce' ) );
	}

	/** @param array<int, string> $codes */
	private function add_to_zone( array $codes ): void {
		$zone_id = $this->own_zone_id();
		$zones   = $this->zones_by_country();
		$names   = WC()->countries->get_countries();

		$zone = $zone_id > 0 ? new WC_Shipping_Zone( $zone_id ) : $this->new_zone();

		$added   = 0;
		$skipped = array();
		foreach ( $codes as $code ) {
			if ( isset( $zones[ $code ][ $zone_id ] ) ) {
				continue;
			}
			if ( isset( $zones[ $code ] ) ) {
				$skipped[] = $names[ $code ] ?? $code;
				continue;
			}
			$zone->add_location( $code, 'country' );
			++$added;
		}

		if ( $added > 0 ) {
			$zone->save();
			$settings            = Settings::get( self::ID );
			$settings['zone_id'] = $zone->get_id();
			Settings::update( self::ID, $settings );
		}

		$message = sprintf(
			/* translators: 1: number of countries added, 2: shipping zone name */
			_n( '%1$d country added to the "%2$s" shipping zone.', '%1$d countries added to the "%2$s" shipping zone.', $added, 'romcommerce' ),
			$added,
			$zone->get_zone_name()
		);
		if ( array() !== $skipped ) {
			/* translators: %s: comma-separated country names */
			$message .= ' ' . sprintf( __( 'Left in their own zone: %s.', 'romcommerce' ), implode( ', ', $skipped ) );
		}

		$this->notice( $added > 0 ? 'success' : 'info', $message );

		if ( $added > 0 ) {
			$url = add_query_arg(
				array(
					'page'    => 'wc-settings',
					'tab'     => 'shipping',
					'zone_id' => $zone->get_id(),
				),
				admin_url( 'admin.php' )
			);
			echo '<p><a class="button button-secondary" href="' . esc_url( $url ) . '">' . esc_html__( 'Add shipping methods to the zone', 'romcommerce' ) . '</a></p>';
		}
	}

	private function new_zone(): WC_Shipping_Zone {
		$last = 0;
		foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
			$last = max( $last, (int) ( $zone['zone_order'] ?? 0 ) );
		}

		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( __( 'European Union', 'romcommerce' ) );
		$zone->set_zone_order( $last + 1 );

		return $zone;
	}

	/** The zone this module created, or 0 once it is deleted. */
	private function own_zone_id(): int {
		$zone_id = (int) ( Settings::get( self::ID )['zone_id'] ?? 0 );
		if ( $zone_id < 1 ) {
			return 0;
		}

		foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
			if ( (int) ( $zone['id'] ?? 0 ) === $zone_id ) {
				return $zone_id;
			}
		}

		return 0;
	}

	/**
	 * Every country a shipping zone covers as a whole (by country or by
	 * continent), with the zones covering it. Zones limited to a region or
	 * postcode don't count: part of the country still has no zone.
	 *
	 * @return array<string, array<int, string>> country code => zone ID => zone name.
	 */
	private function zones_by_country(): array {
		$continents = WC()->countries->get_continents();
		$map        = array();

		foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
			foreach ( (array) ( $zone['zone_locations'] ?? array() ) as $location ) {
				$type  = is_object( $location ) ? (string) $location->type : '';
				$code  = is_object( $location ) ? (string) $location->code : '';
				$codes = array();
				if ( 'country' === $type ) {
					$codes = array( $code );
				} elseif ( 'continent' === $type && isset( $continents[ $code ]['countries'] ) ) {
					$codes = (array) $continents[ $code ]['countries'];
				}

				foreach ( $codes as $country ) {
					$map[ (string) $country ][ (int) $zone['id'] ] = (string) $zone['zone_name'];
				}
			}
		}

		return $map;
	}

	/** @return array<int, string> */
	private function eu_countries(): array {
		return array_values( array_map( 'strval', WC()->countries->get_european_union_countries() ) );
	}

	private function status( bool $present ): string {
		return $present
			? '<span style="color:#007017;font-weight:600;">&#10003;</span><span class="screen-reader-text">' . esc_html__( 'Listed', 'romcommerce' ) . '</span>'
			: '<span style="color:#b32d2e;">' . esc_html__( 'Missing', 'romcommerce' ) . '</span>';
	}

	private function notice( string $type, string $message ): void {
		echo '<div class="notice notice-' . esc_attr( $type ) . ' inline"><p>' . esc_html( $message ) . '</p></div>';
	}
}
