<?php

declare(strict_types=1);

namespace RomCommerce\Modules\ClearCart;

use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One-click "empty cart" action. Renders a link in the cart-actions area (and
 * via a [romcommerce_clear_cart] shortcode) that clears the whole cart in one
 * click, with an optional confirmation prompt. Classic cart only; the block
 * cart is deferred with the rest of the Blocks track.
 */
final class Module implements ModuleInterface, HasSettingsUi {

	private const ID = 'clear-cart';

	private const NONCE_ACTION = 'romcommerce_clear_cart';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Clear Cart', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		// template_redirect: the cart is fully loaded and no output has been
		// sent yet, so empty_cart() + a redirect are both safe here.
		add_action( 'template_redirect', array( $this, 'handle_clear' ) );

		if ( $this->show_on_cart() ) {
			add_action( 'woocommerce_cart_actions', array( $this, 'render_button' ) );
		}

		add_shortcode( 'romcommerce_clear_cart', array( $this, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets(): void {
		if ( ! $this->confirm() ) {
			return;
		}

		wp_register_script( 'romcommerce-clear-cart', false, array(), ROMCOMMERCE_VERSION, true );
		wp_enqueue_script( 'romcommerce-clear-cart' );
		wp_add_inline_script( 'romcommerce-clear-cart', $this->confirm_script_js() );
	}

	public function handle_clear(): void {
		if ( ! isset( $_GET['rc_clear_cart'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_GET['rc_clear_cart'] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}

	public function render_button(): void {
		echo $this->button_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts in button_html()
	}

	/** @param array<string, mixed>|string $atts */
	public function shortcode( $atts ): string {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return '';
		}

		return $this->button_html();
	}

	private function button_html(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return '';
		}

		$url   = add_query_arg( 'rc_clear_cart', wp_create_nonce( self::NONCE_ACTION ), wc_get_cart_url() );
		$attrs = 'class="button romcommerce-clear-cart"';

		$html = '<a href="' . esc_url( $url ) . '" ' . $attrs;
		if ( $this->confirm() ) {
			$html .= ' data-rc-confirm="' . esc_attr__( 'Sigur doriți să goliți coșul?', 'romcommerce' ) . '"';
		}
		$html .= '>' . esc_html__( 'Golește coșul', 'romcommerce' ) . '</a>';

		return $html;
	}

	private function confirm_script_js(): string {
		return 'document.addEventListener("click",function(e){var b=e.target.closest?e.target.closest(".romcommerce-clear-cart[data-rc-confirm]"):null;'
			. 'if(b&&!window.confirm(b.getAttribute("data-rc-confirm"))){e.preventDefault();}});';
	}

	private function show_on_cart(): bool {
		return (bool) ( Settings::get( self::ID )['show_on_cart'] ?? true );
	}

	private function confirm(): bool {
		return (bool) ( Settings::get( self::ID )['confirm'] ?? true );
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			Settings::set_enabled( self::ID, isset( $_POST['rc_enabled'] ) );
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			Settings::update(
				self::ID,
				array(
					'show_on_cart' => isset( $_POST['rc_show_on_cart'] ),
					'confirm'      => isset( $_POST['rc_confirm'] ),
				)
			);
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'Adds a one-click button that empties the whole cart. Also available anywhere via the [romcommerce_clear_cart] shortcode.', 'romcommerce' ) . '</p>';

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Module', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ';
		echo esc_html__( 'Enable', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Cart page button', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_show_on_cart" value="1"' . checked( $this->show_on_cart(), true, false ) . '> ';
		echo esc_html__( 'Show the button in the cart actions area', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Confirmation', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_confirm" value="1"' . checked( $this->confirm(), true, false ) . '> ';
		echo esc_html__( 'Ask the customer to confirm before emptying the cart', 'romcommerce' ) . '</label></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';
	}
}
