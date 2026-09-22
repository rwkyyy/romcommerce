<?php

declare(strict_types=1);

namespace RomCommerce\Modules\HideVoucher;

use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Hide the coupon/voucher field at checkout (and optionally on the cart) —
 * stops customers leaving to hunt for a code, a common cart-abandonment driver.
 * Classic checkout only; the block checkout is deferred with the rest of the
 * Blocks track.
 */
final class Module implements ModuleInterface, HasSettingsUi {

	private const ID = 'hide-voucher';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Hide Voucher at Checkout', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		// On init: WooCommerce's template hooks are already registered by then,
		// so the coupon form action exists to be removed.
		add_action( 'init', array( $this, 'remove_checkout_coupon_form' ) );

		if ( $this->hide_on_cart() ) {
			add_filter( 'woocommerce_coupons_enabled', array( $this, 'disable_cart_coupon_field' ) );
		}
	}

	public function remove_checkout_coupon_form(): void {
		if ( ! $this->hide_on_checkout() ) {
			return;
		}

		remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form', 10 );
	}

	/**
	 * @param bool $enabled
	 * @return bool
	 */
	public function disable_cart_coupon_field( $enabled ) {
		return is_cart() ? false : $enabled;
	}

	private function hide_on_checkout(): bool {
		$settings = Settings::get( self::ID );

		return (bool) ( $settings['hide_checkout'] ?? true );
	}

	private function hide_on_cart(): bool {
		return (bool) ( Settings::get( self::ID )['hide_cart'] ?? false );
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			Settings::set_enabled( self::ID, isset( $_POST['rc_enabled'] ) );
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			Settings::update(
				self::ID,
				array(
					'hide_checkout' => isset( $_POST['rc_hide_checkout'] ),
					'hide_cart'     => isset( $_POST['rc_hide_cart'] ),
				)
			);
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'Hides the coupon field so customers do not leave checkout to search for a code.', 'romcommerce' ) . '</p>';

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Module', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ';
		echo esc_html__( 'Enable', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Hide on checkout', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_hide_checkout" value="1"' . checked( $this->hide_on_checkout(), true, false ) . '> ';
		echo esc_html__( 'Remove the coupon field from the checkout page', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Hide on cart', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_hide_cart" value="1"' . checked( $this->hide_on_cart(), true, false ) . '> ';
		echo esc_html__( 'Also remove the coupon field from the cart page', 'romcommerce' ) . '</label></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';
	}
}
