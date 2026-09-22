<?php

declare(strict_types=1);

namespace RomCommerce\Modules\PriceHistory;

use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;
use WC_Product;
use WC_Product_Variable;

defined( 'ABSPATH' ) || exit;

/**
 * Price history (EU Omnibus / OUG 58/2022). Logs the product's effective
 * selling price on every change and, next to any active reduction, discloses
 * the lowest price of the prior 30 days. docs/feature-specs.md (price-history)
 * is the authoritative spec.
 */
final class Module implements ModuleInterface, HasSettingsUi {

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

		// The effective price can change on a direct save (update/new) or when
		// a scheduled sale flips it — wc_scheduled_sales() saves through CRUD,
		// so the same update hooks catch that transition too.
		add_action( 'woocommerce_update_product', array( $this, 'log_price' ), 20, 1 );
		add_action( 'woocommerce_new_product', array( $this, 'log_price' ), 20, 1 );
		add_action( 'woocommerce_update_product_variation', array( $this, 'log_price' ), 20, 1 );
		add_action( 'woocommerce_new_product_variation', array( $this, 'log_price' ), 20, 1 );

		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_product_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_product_panel' ) );

		add_action( 'woocommerce_single_product_summary', array( $this, 'render_frontend_floor' ), 11 );
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

		$last = Log::last_price( $product_id );
		if ( null !== $last && wc_format_decimal( $last, $decimals ) === $normalized ) {
			return;
		}

		Log::insert( $product_id, $normalized, gmdate( 'Y-m-d H:i:s' ) );
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

		if ( ! $product instanceof WC_Product || ! $product->is_on_sale() ) {
			return;
		}

		// Variable products display a price that changes with the selected
		// variation client-side; a correct per-variation floor there needs JS
		// reactivity, deferred for now. Simple/priced products get the static
		// disclosure, which is the dominant case.
		if ( $product->is_type( 'variable' ) ) {
			return;
		}

		$floor = Log::thirty_day_floor( (int) $product->get_id() );
		if ( null === $floor ) {
			// No recorded history yet (e.g. product predates the module and was
			// never re-saved) — fall back to the regular price as the best
			// available pre-reduction baseline.
			$floor = (string) $product->get_regular_price();
		}

		if ( '' === $floor ) {
			return;
		}

		echo '<p class="romcommerce-omnibus-floor"' . $this->emphasis_style_attr() . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- emphasis_style_attr() esc_attr()s its own value.
		echo esc_html__( 'Preț minim pe ultimele 30 de zile:', 'romcommerce' ) . ' ';
		echo wp_kses_post( wc_price( (float) $floor ) );
		echo '</p>';
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
			Settings::update(
				self::ID,
				array( 'emphasis' => in_array( $emphasis, array( 'none', 'bold', 'underline' ), true ) ? $emphasis : 'none' )
			);
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
		}

		$enabled  = $this->is_enabled();
		$emphasis = $this->emphasis();

		echo '<p>' . esc_html__( 'Records every effective price change and shows the lowest price of the last 30 days next to reduced prices, as required by the Omnibus Directive.', 'romcommerce' ) . '</p>';

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody><tr>';
		echo '<th scope="row">' . esc_html__( 'Price history', 'romcommerce' ) . '</th>';
		echo '<td><label><input type="checkbox" name="romcommerce_price_history_enabled" value="1"' . checked( $enabled, true, false ) . '> ';
		echo esc_html__( 'Log price changes and display the 30-day lowest price', 'romcommerce' ) . '</label></td>';
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
		echo '<p class="description">' . esc_html__( 'A small visual emphasis on the "lowest price of the last 30 days" line shown on the product page.', 'romcommerce' ) . '</p>';
		echo '</td></tr></tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';

		echo '<hr><p><span class="romcommerce-badge">Pro</span> ' . esc_html__( 'RomCommerce Pro adds a placement/location selector for where the 30-day floor text is shown, plus a dedicated price-history report with sparklines.', 'romcommerce' ) . '</p>';
	}
}
