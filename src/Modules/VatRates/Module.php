<?php

declare(strict_types=1);

namespace RomCommerce\Modules\VatRates;

use InvalidArgumentException;
use RomCommerce\Admin\ProSection;
use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * EU VAT rates import (Lite): writes the bundled EU-27 rates for the
 * countries the merchant picks into WooCommerce's tax tables, for shops
 * selling cross-border under OSS. Nothing is imported until the merchant
 * picks countries and presses Import; after a plugin update brings new
 * bundled rates, an admin notice offers to refresh the imported rows.
 */
final class Module implements ModuleInterface, HasSettingsUi {

	private const ID = 'vat-rates';

	private const EU = array( 'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK' );

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'EU VAT Rates Import', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		add_action( 'admin_notices', array( $this, 'render_update_notice' ) );
	}

	/**
	 * On RomCommerce's own screen and WooCommerce's settings only, where a
	 * merchant handling taxes will see it, rather than on every admin page.
	 */
	public function render_update_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ( false === strpos( (string) $screen->id, 'romcommerce' ) && 'woocommerce_page_wc-settings' !== $screen->id ) ) {
			return;
		}

		$bundle = $this->bundle();
		if ( null === $bundle || ! $this->importer()->has_updates( $bundle ) ) {
			return;
		}

		$url = add_query_arg(
			array(
				'page'     => 'romcommerce',
				'tab'      => 'modules',
				'category' => 'checkout-billing',
				'module'   => self::ID,
			),
			admin_url( 'admin.php' )
		);

		echo '<div class="notice notice-warning"><p>' . esc_html__( 'RomCommerce has newer EU VAT rates than the ones you imported.', 'romcommerce' ) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Review and refresh them', 'romcommerce' ) . '</a></p></div>';
	}

	public function render_settings(): void {
		$bundle   = $this->bundle();
		$importer = $this->importer();

		if ( SettingsForm::verify( self::ID ) ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above via SettingsForm::verify().
			if ( isset( $_POST['rc_import'] ) || isset( $_POST['rc_refresh'] ) ) {
				$countries = isset( $_POST['rc_refresh'] )
					? $importer->imported_countries()
					: self::selected_countries( isset( $_POST['rc_all'] ), array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['rc_countries'] ?? array() ) ) ) );
				$this->handle_import( $bundle, $countries );
			} else {
				Settings::set_enabled( self::ID, isset( $_POST['rc_enabled'] ) );
				echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing
		}

		echo '<p>' . esc_html__( 'Imports the VAT rates of the EU member states you sell to under OSS into WooCommerce\'s tax tables: the standard rate, the reduced rate (the lowest one, for countries with several), and super-reduced and parking rates where a country has them. Only rows created here are ever updated; your own tax rates are never changed.', 'romcommerce' ) . '</p>';

		if ( null === $bundle ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The bundled VAT rate file is missing or damaged. Reinstall RomCommerce.', 'romcommerce' ) . '</p></div>';
			return;
		}

		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: date the bundled rates were fetched */
				__( 'Rates bundled with this version of RomCommerce, as of %s (source: EU TEDB via vatcomply.com). RomCommerce does not contact any service; new rates arrive with plugin updates.', 'romcommerce' ),
				(string) $bundle['fetched_at']
			)
		) . '</p>';

		if ( function_exists( 'wc_tax_enabled' ) && ! wc_tax_enabled() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Taxes are turned off in WooCommerce → Settings → General. Imported rates are saved but won\'t apply at checkout until you turn taxes on.', 'romcommerce' ) . '</p></div>';
		}

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody><tr>';
		echo '<th scope="row">' . esc_html__( 'Module', 'romcommerce' ) . '</th>';
		echo '<td><label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ';
		echo esc_html__( 'Enable EU VAT rates import', 'romcommerce' ) . '</label></td>';
		echo '</tr></tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';

		if ( ! $this->is_enabled() ) {
			return;
		}

		if ( $importer->has_updates( $bundle ) ) {
			echo '<form method="post" class="notice notice-warning inline"><p>';
			SettingsForm::nonce_field( self::ID );
			echo esc_html__( 'The bundled rates differ from rates you imported earlier.', 'romcommerce' ) . ' ';
			echo '<button type="submit" name="rc_refresh" value="1" class="button button-primary">' . esc_html__( 'Refresh imported rates', 'romcommerce' ) . '</button>';
			echo '</p></form>';
		}

		$this->render_import_form( $bundle, $importer->imported_countries() );

		ProSection::line( __( 'Monthly rate refresh', 'romcommerce' ), __( 'Refreshes the imported rates every month, between plugin releases.', 'romcommerce' ) );
	}

	/**
	 * Every EU member state when "all" is ticked, otherwise the ticked ones
	 * that are member states, so a forged code can't create a stray row.
	 *
	 * @param array<int, string> $requested
	 * @return array<int, string>
	 */
	public static function selected_countries( bool $all, array $requested ): array {
		if ( $all ) {
			return self::EU;
		}

		return array_values( array_intersect( self::EU, array_map( 'strtoupper', $requested ) ) );
	}

	/**
	 * @param array<string, mixed>|null $bundle
	 * @param array<int, string>        $countries
	 */
	private function handle_import( ?array $bundle, array $countries ): void {
		if ( null === $bundle || array() === $countries ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Pick at least one country to import.', 'romcommerce' ) . '</p></div>';
			return;
		}

		$result = $this->importer()->import(
			$bundle,
			$countries,
			self::rate_name(),
			self::class_names()
		);

		echo '<div class="notice notice-success"><p>' . esc_html(
			sprintf(
				/* translators: 1: rates added, 2: rates updated, 3: rates left alone because the store already had its own */
				__( 'VAT rates imported: %1$d added, %2$d updated, %3$d left as they were (your own rates).', 'romcommerce' ),
				count( $result['add'] ),
				count( $result['update'] ),
				count( $result['kept'] )
			)
		) . '</p></div>';
	}

	/**
	 * @param array<string, mixed> $bundle
	 * @param array<int, string>   $imported
	 */
	private function render_import_form( array $bundle, array $imported ): void {
		$names = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_countries() : array();

		echo '<h3>' . esc_html__( 'Import rates', 'romcommerce' ) . '</h3>';
		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<p><label><input type="checkbox" name="rc_all" value="1"> ' . esc_html__( 'All 27 EU member states', 'romcommerce' ) . '</label></p>';
		echo '<table class="widefat striped" style="max-width:720px;"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Country', 'romcommerce' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Standard', 'romcommerce' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Reduced', 'romcommerce' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Super reduced', 'romcommerce' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Parking', 'romcommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( self::EU as $country ) {
			$rate = (array) ( $bundle['rates'][ $country ] ?? array() );
			$id   = 'rc-vat-' . strtolower( $country );

			echo '<tr><td><label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="rc_countries[]" value="' . esc_attr( $country ) . '"' . checked( in_array( $country, $imported, true ), true, false ) . '> ';
			echo esc_html( html_entity_decode( (string) ( $names[ $country ] ?? $country ), ENT_QUOTES ) ) . '</label>';
			if ( in_array( $country, $imported, true ) ) {
				echo ' <span class="description">' . esc_html__( '(imported)', 'romcommerce' ) . '</span>';
			}
			echo '</td>';

			foreach ( array_keys( Importer::TIERS ) as $tier ) {
				$value = Rates::tier( $rate, $tier );
				echo '<td>' . ( null === $value ? '&mdash;' : esc_html( wc_format_localized_decimal( (string) $value ) . '%' ) ) . '</td>';
			}
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p><button type="submit" name="rc_import" value="1" class="button button-primary">' . esc_html__( 'Import selected rates', 'romcommerce' ) . '</button></p>';
		echo '</form>';
	}

	/** The tax rate label customers see. */
	public static function rate_name(): string {
		return __( 'VAT', 'romcommerce' );
	}

	/** @return array<string, string> class slug => name, for the classes the importer may create. */
	public static function class_names(): array {
		return array(
			'reduced-rate'       => __( 'Reduced rate', 'romcommerce' ),
			'super-reduced-rate' => __( 'Super reduced rate', 'romcommerce' ),
			'parking-rate'       => __( 'Parking rate', 'romcommerce' ),
		);
	}

	/** @return array<string, mixed>|null */
	private function bundle(): ?array {
		try {
			return Rates::bundled();
		} catch ( InvalidArgumentException $exception ) {
			return null;
		}
	}

	private function importer(): Importer {
		return new Importer( new WooTaxRateStore() );
	}
}
