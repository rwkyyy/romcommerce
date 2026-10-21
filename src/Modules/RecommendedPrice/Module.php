<?php

declare(strict_types=1);

namespace RomCommerce\Modules\RecommendedPrice;

use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasPlacements;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;
use RomCommerce\Support\Placement;
use WC_Product;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Manufacturer's recommended retail price (PRP, "preț recomandat de
 * producător"): an optional per-product and per-variation field, shown on the
 * product page as a separate labelled line ("PRP: 150 lei").
 *
 * Deliberately never crossed out and never turned into a percentage. A
 * crossed-out price reads as the shop's own former price, and a reduction
 * figure next to the Omnibus one would compete with it; Price History's
 * crossed-out reference stays the only reduction shown. The line is hidden
 * when the PRP isn't above the current price, where it says nothing useful.
 * docs/feature-specs.md (recommended-price) is the authoritative spec.
 */
final class Module implements ModuleInterface, HasSettingsUi, HasPlacements {

	private const ID = 'recommended-price';

	public const META_KEY = '_romcommerce_prp';

	private const FIELD = 'romcommerce_prp';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Recommended Retail Price', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		add_action( 'woocommerce_product_options_pricing', array( $this, 'render_product_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product_field' ) );
		add_action( 'woocommerce_variation_options_pricing', array( $this, 'render_variation_field' ), 10, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( $this, 'save_variation_field' ), 10, 2 );

		add_filter( 'woocommerce_available_variation', array( $this, 'add_variation_line' ), 10, 3 );
		// For page builders whose product templates skip WooCommerce's summary hooks.
		add_shortcode( 'romcommerce_recommended_price', array( $this, 'shortcode' ) );
		// Placement hooks wire on init, not here — see HasPlacements.
		add_action( 'init', array( $this, 'register_placements' ) );
	}

	public function register_placements(): void {
		foreach ( $this->placements() as $placement ) {
			Placement::hook( $placement['slot'], array( $this, 'render_product_line' ), $placement['priority'] ?? null );
		}
	}

	/**
	 * Priority 12: under the price (10) and Price History's 30-day line (11).
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
					'priority' => 12,
					'choices'  => Placement::price_adjacent_slots(),
				),
			)
		);
	}

	public function render_product_field(): void {
		global $thepostid;

		woocommerce_wp_text_input(
			array(
				'id'          => self::FIELD,
				'value'       => wc_format_localized_price( (string) get_post_meta( (int) $thepostid, self::META_KEY, true ) ),
				'label'       => $this->field_label(),
				'data_type'   => 'price',
				'desc_tip'    => true,
				'description' => $this->field_help(),
			)
		);
	}

	/**
	 * WooCommerce verifies its own woocommerce_meta_nonce and the edit_product
	 * capability before firing this hook, so its $_POST is the checked form.
	 */
	public function save_product_field( WC_Product $product ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by WooCommerce before woocommerce_admin_process_product_object fires; see the docblock.
		$this->store( $product, isset( $_POST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) : '' );
	}

	/**
	 * @param int     $loop
	 * @param mixed   $variation_data
	 * @param WP_Post $variation
	 */
	public function render_variation_field( $loop, $variation_data, $variation ): void {
		if ( ! $variation instanceof WP_Post ) {
			return;
		}

		woocommerce_wp_text_input(
			array(
				'id'            => self::FIELD . '_' . (int) $loop,
				'name'          => self::FIELD . '[' . (int) $loop . ']',
				'value'         => wc_format_localized_price( (string) get_post_meta( $variation->ID, self::META_KEY, true ) ),
				'label'         => $this->field_label(),
				'data_type'     => 'price',
				'wrapper_class' => 'form-row form-row-full',
				'desc_tip'      => true,
				'description'   => $this->field_help(),
			)
		);
	}

	/**
	 * Same nonce/capability guarantee as save_product_field(), for both the
	 * product form and the AJAX "Save changes" in the variations panel.
	 *
	 * @param WC_Product $variation
	 * @param int        $index
	 */
	public function save_variation_field( $variation, $index ): void {
		if ( ! $variation instanceof WC_Product ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified by WooCommerce before woocommerce_admin_process_variation_object fires (see save_product_field()); the one value used is sanitized below.
		$values = isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : array();

		$this->store( $variation, isset( $values[ $index ] ) ? sanitize_text_field( (string) $values[ $index ] ) : '' );
	}

	/** Blank, zero or not a number removes the PRP. */
	public function store( WC_Product $product, string $input ): void {
		$value = wc_format_decimal( $input );

		if ( '' === $value || ! is_numeric( $value ) || (float) $value <= 0 ) {
			$product->delete_meta_data( self::META_KEY );
			return;
		}

		$product->update_meta_data( self::META_KEY, $value );
	}

	/**
	 * [romcommerce_recommended_price], optionally with id="…"; without one,
	 * the product being shown. Variable products show the line per variation
	 * through WooCommerce's own price swap, so the shortcode prints nothing
	 * for them.
	 *
	 * @param array<string, string>|string $atts
	 */
	public function shortcode( $atts = array() ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), (array) $atts, 'romcommerce_recommended_price' );
		$id   = absint( $atts['id'] );

		global $product;
		$target = $id > 0 ? wc_get_product( $id ) : ( $product instanceof WC_Product ? $product : wc_get_product( (int) get_the_ID() ) );

		if ( ! $target instanceof WC_Product || $target->is_type( array( 'variable', 'grouped' ) ) ) {
			return '';
		}

		return $this->line( $target );
	}

	public function render_product_line(): void {
		global $product;

		// Variable products show the line per variation, once one is chosen.
		if ( ! $product instanceof WC_Product || $product->is_type( array( 'variable', 'grouped' ) ) ) {
			return;
		}

		echo $this->line( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- line() escapes every part it builds.
	}

	/**
	 * Appended to the variation's price HTML, which WooCommerce's own script
	 * swaps in when the shopper picks a variation; no script of ours needed.
	 *
	 * @param mixed $data
	 * @param mixed $product
	 * @param mixed $variation
	 * @return mixed
	 */
	public function add_variation_line( $data, $product, $variation ) {
		if ( ! is_array( $data ) || ! $variation instanceof WC_Product ) {
			return $data;
		}

		$line = $this->line( $variation );
		if ( '' !== $line ) {
			$data['price_html'] = ( isset( $data['price_html'] ) ? (string) $data['price_html'] : '' ) . $line;
		}

		return $data;
	}

	/** '' when there is no PRP above the current price. */
	public function line( WC_Product $product ): string {
		$prp = (string) $product->get_meta( self::META_KEY );
		if ( '' === $prp || (float) $prp <= 0 || '' === (string) $product->get_price() ) {
			return '';
		}

		$prp_display   = (float) wc_get_price_to_display( $product, array( 'price' => $prp ) );
		$price_display = (float) wc_get_price_to_display( $product );
		if ( $prp_display <= $price_display ) {
			return '';
		}

		return '<p class="romcommerce-prp">' . $this->label_html() . ' ' . wp_kses_post( wc_price( $prp_display ) ) . '</p>';
	}

	private function label_html(): string {
		$custom = $this->custom_label();
		if ( '' !== $custom ) {
			return esc_html( $custom );
		}

		return '<abbr title="' . esc_attr__( 'Recommended retail price', 'romcommerce' ) . '">' . esc_html__( 'RRP', 'romcommerce' ) . '</abbr>:';
	}

	/**
	 * Only what the merchant typed, '' when blank; same placeholder-not-value
	 * reasoning as Price History's floor text.
	 */
	private function custom_label(): string {
		return (string) ( Settings::get( self::ID )['label'] ?? '' );
	}

	private function field_label(): string {
		/* translators: %s: currency symbol, e.g. lei. */
		return sprintf( __( 'RRP (%s)', 'romcommerce' ), get_woocommerce_currency_symbol() );
	}

	private function field_help(): string {
		return __( 'Recommended retail price set by the manufacturer. Shown on the product page as a separate line when it is above the current price. Leave blank if the manufacturer hasn\'t set one.', 'romcommerce' );
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			Settings::set_enabled( self::ID, isset( $_POST['romcommerce_prp_enabled'] ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			Settings::update( self::ID, array( 'label' => sanitize_text_field( wp_unslash( $_POST['romcommerce_prp_label'] ?? '' ) ) ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			Placement::save_choices( self::ID, $this->placements(), isset( $_POST['romcommerce_prp_area'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['romcommerce_prp_area'] ) ) : array() );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'Adds a recommended retail price field to products and variations. When it is above the current price, the product page shows it as a separate line, e.g. "RRP: 150 lei". It is never crossed out and no percentage is calculated from it, so it can\'t be mistaken for a reduction.', 'romcommerce' ) . '</p>';
		echo '<p>' . esc_html__( 'Only enter a price the manufacturer actually recommends and that is in use on the market. An invented recommended price can mislead customers.', 'romcommerce' ) . '</p>';

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody><tr>';
		echo '<th scope="row">' . esc_html__( 'Recommended retail price', 'romcommerce' ) . '</th>';
		echo '<td><label><input type="checkbox" name="romcommerce_prp_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ';
		echo esc_html__( 'Add the field to products and show it on the product page', 'romcommerce' ) . '</label></td>';
		echo '</tr><tr>';
		echo '<th scope="row"><label for="romcommerce_prp_label">' . esc_html__( 'Label', 'romcommerce' ) . '</label></th><td>';
		echo '<input type="text" id="romcommerce_prp_label" name="romcommerce_prp_label" value="' . esc_attr( $this->custom_label() ) . '" class="regular-text" placeholder="' . esc_attr__( 'RRP', 'romcommerce' ) . ':">';
		echo '<p class="description">' . esc_html__( 'Text shown before the price. Leave blank to use the default, which also explains the abbreviation when hovered.', 'romcommerce' ) . '</p>';
		echo '</td></tr><tr>';
		echo '<th scope="row">' . esc_html__( 'Position', 'romcommerce' ) . '</th><td>';
		Placement::area_field( 'romcommerce_prp_area[product]', Placement::find( $this->placements(), 'product' ) );
		echo '<p class="description">' . esc_html__( 'Where the line appears in the product summary. Only spots next to the price are offered.', 'romcommerce' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Product pages built with a page builder (Elementor, Divi and others) often skip these spots. Put the shortcode [romcommerce_recommended_price] next to the price instead, e.g. in an Elementor Shortcode widget.', 'romcommerce' ) . '</p>';
		echo '</td></tr></tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';
	}
}
