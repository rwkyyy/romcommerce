<?php

declare(strict_types=1);

namespace RomCommerce\Modules\PfPjBilling;

use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;
use RomCommerce\Support\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * PF/PJ billing fields + invoicing-plugin compatibility (Lite). Individual (PF)
 * vs company (PJ) checkout billing fields, stored in one consolidated order-meta
 * entry and exposed to the Romanian invoicing ecosystem via a legacy-key shim
 * and three plugin integrations. docs/feature-specs.md (PF/PJ) is the spec.
 */
final class Module implements ModuleInterface, HasSettingsUi {

	private const ID = 'pf-pj-billing';

	private Checkout $checkout;

	private BlockCheckout $block_checkout;

	private MetaShim $shim;

	private Integrations $integrations;

	public function __construct() {
		$this->checkout       = new Checkout();
		$this->block_checkout = new BlockCheckout();
		$this->shim           = new MetaShim();
		$this->integrations   = new Integrations();
	}

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'PF/PJ Billing Fields', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		$this->checkout->register();
		$this->block_checkout->register();
		$this->shim->register();
		$this->integrations->register();
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			Settings::set_enabled( self::ID, isset( $_POST['rc_enabled'] ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			Settings::update( self::ID, array( 'cnp_enabled' => isset( $_POST['rc_cnp_enabled'] ) ) );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
		}

		$cnp_enabled = (bool) ( Settings::get( self::ID )['cnp_enabled'] ?? false );

		echo '<p>' . esc_html__( 'Adds individual (PF) vs company (PJ) billing fields at checkout and writes the data where Romanian invoicing plugins (SmartBill, Oblio, EasySales) read it.', 'romcommerce' ) . '</p>';

		$this->render_block_checkout_notices();

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Module', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ';
		echo esc_html__( 'Enable PF/PJ billing fields', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'CNP field', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_cnp_enabled" value="1"' . checked( $cnp_enabled, true, false ) . '> ';
		echo esc_html__( 'Show the CNP field for individuals at checkout', 'romcommerce' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Off by default: a legal derogation lets the 13-zero placeholder stand in for the CNP, so the field stays hidden and the placeholder is stored under the hood. Enable only if you truly need to collect the real CNP.', 'romcommerce' ) . '</p>';
		echo '<p class="description" style="color:#8a6500;">' . esc_html__( 'The CNP is a national ID number with elevated protection under Law 190/2018 art. 4. Collect it only when necessary and state the purpose in your privacy notice.', 'romcommerce' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';
	}

	/**
	 * The block checkout takes these fields only through WooCommerce's
	 * Additional Checkout Fields API (8.9+), and shows its own Company field
	 * next to ours unless the merchant hides it in the Checkout block.
	 */
	private function render_block_checkout_notices(): void {
		if ( ! Blocks::checkout_uses_block() ) {
			return;
		}

		if ( ! Blocks::has_checkout_fields_api() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Your checkout page uses the Checkout block, which needs WooCommerce 8.9 or newer for these fields. Update WooCommerce, or switch the checkout page to the classic checkout shortcode.', 'romcommerce' ) . '</p></div>';
			return;
		}

		if ( 'hidden' !== get_option( 'woocommerce_checkout_company_field', 'optional' ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'WooCommerce\'s own Company field is also shown on the block checkout. To avoid asking for the company name twice, hide it in the Checkout block settings (Company name: Hidden).', 'romcommerce' ) . '</p></div>';
		}
	}
}
