<?php

declare(strict_types=1);

namespace RomCommerce\Modules\PriceHistory;

use RomCommerce\Admin\ProSection;
use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasPlacements;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;
use RomCommerce\Support\Placement;
use WC_Product;
use WC_Product_Variable;

defined( 'ABSPATH' ) || exit;

/**
 * Price history (EU Omnibus; in Romania HG 947/2000 art. 4^1, introduced by
 * HG 686/2022). Logs the product's effective selling price on every change
 * and, next to any active reduction, discloses the lowest price applied in
 * the 30 days before that reduction started (Log::reference_price()).
 * docs/feature-specs.md (price-history) is the authoritative spec.
 */
final class Module implements ModuleInterface, HasSettingsUi, HasPlacements {

	private const ID = 'price-history';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Price History', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		add_action( 'admin_init', array( Log::class, 'ensure_table' ) );
		( new Baseline( $this ) )->register();
		add_action( 'romcommerce/module_toggled', array( $this, 'on_module_toggled' ), 10, 2 );

		if ( $this->cross_out_reference() ) {
			( new Display( $this->show_percent() ) )->register();
		}

		// The effective price can change on a direct save (update/new) or when
		// a scheduled sale flips it — wc_scheduled_sales() saves through CRUD,
		// so the same update hooks catch that transition too.
		add_action( 'woocommerce_update_product', array( $this, 'log_price' ), 20, 1 );
		add_action( 'woocommerce_new_product', array( $this, 'log_price' ), 20, 1 );
		add_action( 'woocommerce_update_product_variation', array( $this, 'log_price' ), 20, 1 );
		add_action( 'woocommerce_new_product_variation', array( $this, 'log_price' ), 20, 1 );

		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_product_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_product_panel' ) );

		// For page builders whose product templates skip WooCommerce's summary hooks.
		add_shortcode( 'romcommerce_lowest_price', array( $this, 'shortcode' ) );

		// Placement hooks wire on init, not here — see HasPlacements.
		add_action( 'init', array( $this, 'register_placements' ) );
	}

	/**
	 * [romcommerce_lowest_price], optionally with id="…"; without one, the
	 * product being shown.
	 *
	 * @param array<string, string>|string $atts
	 */
	public function shortcode( $atts = array() ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), (array) $atts, 'romcommerce_lowest_price' );
		$id   = absint( $atts['id'] );

		global $product;
		$target = $id > 0 ? wc_get_product( $id ) : ( $product instanceof WC_Product ? $product : wc_get_product( (int) get_the_ID() ) );

		return $target instanceof WC_Product ? $this->floor_html( $target ) : '';
	}

	public function register_placements(): void {
		foreach ( $this->placements() as $placement ) {
			Placement::hook( $placement['slot'], array( $this, 'render_frontend_floor' ), $placement['priority'] ?? null );
		}
	}

	/**
	 * Priority 11: directly under WooCommerce's price (10), ahead of the PRP
	 * line (12) and the slot's own priority (15).
	 *
	 * @return array<int, array{key: string, slot: string, active: bool, priority?: int, choices: array<int, string>}>
	 */
	public function placements(): array {
		return Placement::resolve(
			self::ID,
			array(
				array(
					'key'      => 'product',
					'slot'     => 'product_page_summary',
					'active'   => true,
					'priority' => 11,
					'choices'  => Placement::price_adjacent_slots(),
				),
			)
		);
	}

	public function log_price( int $product_id ): void {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		// Variable/grouped products have no single effective price of their own;
		// their priced children are logged individually via the variation hooks.
		if ( in_array( $product->get_type(), array( 'variable', 'grouped' ), true ) ) {
			return;
		}

		$price = $product->get_price();
		if ( '' === $price || null === $price ) {
			return;
		}

		$decimals   = wc_get_price_decimals();
		$normalized = wc_format_decimal( $price, $decimals );

		$last = Log::latest( $product_id );
		if ( null !== $last && wc_format_decimal( $last['price'], $decimals ) === $normalized ) {
			return;
		}

		Log::insert( $product_id, $normalized, gmdate( 'Y-m-d H:i:s' ) );
		Reference::forget( $product_id );
	}

	public function on_module_toggled( string $module_id, bool $enabled ): void {
		if ( self::ID === $module_id && ! $enabled ) {
			Reference::purge();
		}
	}

	/**
	 * @param array<string, array<string, mixed>> $tabs
	 * @return array<string, array<string, mixed>>
	 */
	public function add_product_tab( array $tabs ): array {
		$tabs['romcommerce_price_history'] = array(
			'label'    => __( 'Istoric preț', 'romcommerce' ),
			'target'   => 'romcommerce_price_history_panel',
			'class'    => array(),
			'priority' => 80,
		);

		return $tabs;
	}

	public function render_product_panel(): void {
		global $post;

		if ( ! $post ) {
			return;
		}

		$product = wc_get_product( $post->ID );

		echo '<div id="romcommerce_price_history_panel" class="panel woocommerce_options_panel">';
		echo '<div class="options_group" style="padding:12px 12px 0;">';
		echo '<h4 style="margin-top:0;">' . esc_html__( 'Ultimele 30 de zile', 'romcommerce' ) . '</h4>';

		if ( $product instanceof WC_Product_Variable ) {
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( ! $variation instanceof WC_Product ) {
					continue;
				}

				echo '<p style="margin-bottom:4px;"><strong>' . esc_html( $variation->get_name() ) . '</strong></p>';
				$this->render_history_table( (int) $variation_id );
			}
		} else {
			$this->render_history_table( (int) $post->ID );
		}

		echo '</div></div>';
	}

	private function render_history_table( int $product_id ): void {
		$rows = Log::recent( $product_id, 30 );

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'Nu există încă modificări de preț înregistrate.', 'romcommerce' ) . '</p>';
			return;
		}

		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		echo '<table class="widefat striped" style="margin-bottom:12px;"><thead><tr>';
		echo '<th>' . esc_html__( 'Data', 'romcommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Preț efectiv', 'romcommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$timestamp = strtotime( $row['logged_at'] . ' UTC' );

			echo '<tr>';
			echo '<td>' . esc_html( wp_date( $format, $timestamp ) ) . '</td>';
			echo '<td>' . wp_kses_post( wc_price( (float) $row['price'] ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	public function render_frontend_floor(): void {
		global $product;

		if ( $product instanceof WC_Product ) {
			echo $this->floor_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- floor_html() escapes every part it builds.
		}
	}

	/** The lowest-price line, or '' when the product isn't on sale or has no price on record. */
	private function floor_html( WC_Product $product ): string {
		if ( ! $product->is_on_sale() ) {
			return '';
		}

		// Variable products display a price that changes with the selected
		// variation client-side; a correct per-variation floor there needs JS
		// reactivity, deferred for now. Simple/priced products get the static
		// disclosure, which is the dominant case.
		if ( $product->is_type( 'variable' ) ) {
			return '';
		}

		$floor = Reference::for_product( $product );
		if ( null === $floor ) {
			// Nothing on record before the current price, e.g. a sale already
			// running when the module was switched on. The current price is the
			// only one known to have applied; the regular price would claim a
			// reduction the log can't back.
			$floor = (string) $product->get_price();
		}

		if ( '' === $floor ) {
			return '';
		}

		return '<p class="romcommerce-omnibus-floor"' . $this->emphasis_style_attr() . '>' . esc_html( $this->floor_text() ) . ' ' . wp_kses_post( wc_price( (float) $floor ) ) . '</p>';
	}

	private function default_floor_text(): string {
		return __( 'Cel mai mic preț din cele 30 de zile dinaintea acestei reduceri:', 'romcommerce' );
	}

	/** Merchant-editable label preceding the 30-day floor price. */
	private function floor_text(): string {
		$text = $this->custom_floor_text();

		return '' !== $text ? $text : $this->default_floor_text();
	}

	/**
	 * Only what the merchant typed, '' when blank. The settings field shows the
	 * default as a placeholder rather than as its value: prefilling it would
	 * save the default as custom text on the next unrelated save, freezing it
	 * against later translation and default-wording updates.
	 */
	private function custom_floor_text(): string {
		return (string) ( Settings::get( self::ID )['floor_text'] ?? '' );
	}

	/**
	 * On unless the merchant turned it off: crossing out the regular price
	 * can announce a bigger reduction than the reference allows.
	 */
	private function cross_out_reference(): bool {
		$settings = Settings::get( self::ID );

		return ! isset( $settings['cross_out_reference'] ) || (bool) $settings['cross_out_reference'];
	}

	private function show_percent(): bool {
		return ! empty( Settings::get( self::ID )['show_percent'] );
	}

	/** Small optional emphasis (bold/underline) on the 30-day floor line, merchant's choice. */
	private function emphasis(): string {
		$emphasis = (string) ( Settings::get( self::ID )['emphasis'] ?? 'none' );

		return in_array( $emphasis, array( 'none', 'bold', 'underline' ), true ) ? $emphasis : 'none';
	}

	private function emphasis_style_attr(): string {
		$css = array(
			'bold'      => 'font-weight:700;',
			'underline' => 'text-decoration:underline;',
		);

		$emphasis = $this->emphasis();
		if ( ! isset( $css[ $emphasis ] ) ) {
			return '';
		}

		return ' style="' . esc_attr( $css[ $emphasis ] ) . '"';
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			Settings::set_enabled( self::ID, isset( $_POST['romcommerce_price_history_enabled'] ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			$emphasis = sanitize_key( wp_unslash( $_POST['romcommerce_price_history_emphasis'] ?? 'none' ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			$floor_text = sanitize_text_field( wp_unslash( $_POST['romcommerce_price_history_text'] ?? '' ) );
			Settings::update(
				self::ID,
				array(
					'emphasis'            => in_array( $emphasis, array( 'none', 'bold', 'underline' ), true ) ? $emphasis : 'none',
					'floor_text'          => $floor_text,
					// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
					'cross_out_reference' => isset( $_POST['romcommerce_price_history_cross_out'] ),
					// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
					'show_percent'        => isset( $_POST['romcommerce_price_history_percent'] ),
				)
			);
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			Placement::save_choices( self::ID, $this->placements(), isset( $_POST['romcommerce_price_history_area'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['romcommerce_price_history_area'] ) ) : array() );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
		}

		$enabled  = $this->is_enabled();
		$emphasis = $this->emphasis();

		echo '<p>' . esc_html__( 'Records every effective price change and, next to a reduced price, shows the lowest price applied in the 30 days before that reduction started (the Omnibus reference price).', 'romcommerce' ) . '</p>';

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody><tr>';
		echo '<th scope="row">' . esc_html__( 'Price history', 'romcommerce' ) . '</th>';
		echo '<td><label><input type="checkbox" name="romcommerce_price_history_enabled" value="1"' . checked( $enabled, true, false ) . '> ';
		echo esc_html__( 'Log price changes and display the pre-reduction lowest price', 'romcommerce' ) . '</label></td>';
		echo '</tr><tr>';
		echo '<th scope="row">' . esc_html__( 'Emphasis', 'romcommerce' ) . '</th><td>';
		$labels = array(
			'none'      => __( 'None', 'romcommerce' ),
			'bold'      => __( 'Bold', 'romcommerce' ),
			'underline' => __( 'Underline', 'romcommerce' ),
		);
		foreach ( $labels as $value => $label ) {
			echo '<label style="margin-right:16px;"><input type="radio" name="romcommerce_price_history_emphasis" value="' . esc_attr( $value ) . '"' . checked( $emphasis, $value, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'A small visual emphasis on the "lowest price in the 30 days before this reduction" line shown on the product page.', 'romcommerce' ) . '</p>';
		echo '</td></tr><tr>';
		echo '<th scope="row">' . esc_html__( 'Floor text', 'romcommerce' ) . '</th><td>';
		echo '<input type="text" name="romcommerce_price_history_text" value="' . esc_attr( $this->custom_floor_text() ) . '" class="regular-text" placeholder="' . esc_attr( $this->default_floor_text() ) . '">';
		echo '<p class="description">' . esc_html__( 'Text shown before the price. The price itself is always appended right after it. Leave blank to use the default.', 'romcommerce' ) . '</p>';
		echo '</td></tr><tr>';
		echo '<th scope="row">' . esc_html__( 'Position', 'romcommerce' ) . '</th><td>';
		Placement::area_field( 'romcommerce_price_history_area[product]', Placement::find( $this->placements(), 'product' ) );
		echo '<p class="description">' . esc_html__( 'Where the line appears in the product summary. Only spots next to the price are offered.', 'romcommerce' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Product pages built with a page builder (Elementor, Divi and others) often skip these spots. Put the shortcode [romcommerce_lowest_price] next to the price instead, e.g. in an Elementor Shortcode widget.', 'romcommerce' ) . '</p>';
		echo '</td></tr><tr>';
		echo '<th scope="row">' . esc_html__( 'Crossed-out price', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="romcommerce_price_history_cross_out" value="1"' . checked( $this->cross_out_reference(), true, false ) . '> ';
		echo esc_html__( 'Cross out the lowest price from the 30 days before the reduction, instead of the regular price', 'romcommerce' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'On product pages, shop and category pages. An announced reduction is measured against this price, not the regular price. When it isn\'t higher than the current price, the price is shown without a crossed-out price or sale badge. The regular and sale prices you enter are not changed.', 'romcommerce' ) . '</p>';
		echo '<p><label><input type="checkbox" name="romcommerce_price_history_percent" value="1"' . checked( $this->show_percent(), true, false ) . '> ';
		echo esc_html__( 'Also show the reduction as a percentage next to the price (e.g. −20%)', 'romcommerce' ) . '</label></p>';
		echo '</td></tr></tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';

		ProSection::line( __( 'Price history report', 'romcommerce' ), __( 'A dedicated report of every product\'s price history, with sparklines.', 'romcommerce' ) );
	}
}
