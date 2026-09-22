<?php

declare(strict_types=1);

namespace RomCommerce\Modules\MultiAddress;

use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Multiple delivery addresses (Lite). Customers save more than one shipping
 * address in their account and pick one at checkout. Additive to WooCommerce's
 * single native address — it never overwrites it.
 */
final class Module implements ModuleInterface, HasSettingsUi {

	private const ID = 'multi-address';

	private const REWRITE_FLAG    = 'romcommerce_multi_address_rewrites';
	private const REWRITE_VERSION = '1';

	private MyAccount $my_account;

	private Checkout $checkout;

	public function __construct() {
		$this->my_account = new MyAccount();
		$this->checkout   = new Checkout();
	}

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Multiple Delivery Addresses', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		$this->my_account->register();
		$this->checkout->register();
		add_action( 'init', array( $this, 'maybe_flush_rewrites' ), 99 );
	}

	public function maybe_flush_rewrites(): void {
		if ( get_option( self::REWRITE_FLAG ) === self::REWRITE_VERSION ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( self::REWRITE_FLAG, self::REWRITE_VERSION, false );
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			Settings::set_enabled( self::ID, isset( $_POST['rc_enabled'] ) );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'Lets logged-in customers save several delivery addresses under “Adrese de livrare” in their account and pick one at checkout.', 'romcommerce' ) . '</p>';

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody><tr>';
		echo '<th scope="row">' . esc_html__( 'Multiple addresses', 'romcommerce' ) . '</th>';
		echo '<td><label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ';
		echo esc_html__( 'Enable the saved delivery address book', 'romcommerce' ) . '</label></td>';
		echo '</tr></tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';
	}
}
