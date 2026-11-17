<?php

declare(strict_types=1);

namespace RomCommerce\Modules\CodFee;

use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;
use WC_Cart;
use WC_Tax;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a fee line to the cart when the chosen payment method is cash on
 * delivery (or another method the merchant ticks). The fee follows the
 * session's chosen payment method, so both checkouts have to resync it when
 * the shopper switches method: the classic one through update_checkout, the
 * block one through a Store API cart update (assets/cod-fee.js).
 */
final class Module implements ModuleInterface, HasSettingsUi {

	private const ID = 'cod-fee';

	private const STORE_API_NAMESPACE = 'romcommerce-cod-fee';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Cash on Delivery Fee', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_fee' ) );
		add_action( 'init', array( $this, 'register_cart_update' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * @param WC_Cart $cart
	 */
	public function add_fee( $cart ): void {
		if ( ! $cart instanceof WC_Cart || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$amount = $this->amount();
		if ( $amount <= 0 || ! in_array( (string) WC()->session->get( 'chosen_payment_method' ), $this->gateways(), true ) ) {
			return;
		}

		$free_from = $this->free_from();
		if ( $free_from > 0 && $this->order_value( $cart ) >= $free_from ) {
			return;
		}

		$taxable = $this->taxable();

		// WooCommerce fees are always net. When product prices include VAT the
		// merchant enters the fee the same way, so take the VAT back out and the
		// shopper pays exactly the amount entered.
		if ( $taxable && wc_prices_include_tax() ) {
			$amount -= array_sum( WC_Tax::calc_inclusive_tax( $amount, WC_Tax::get_rates( '', $cart->get_customer() ) ) );
		}

		$cart->add_fee( $this->label(), $amount, $taxable );
	}

	/**
	 * Products after coupons, VAT included or not as the cart shows them: the
	 * same sum WooCommerce's free shipping minimum uses.
	 */
	private function order_value( WC_Cart $cart ): float {
		$total = (float) $cart->get_displayed_subtotal() - (float) $cart->get_discount_total();
		if ( $cart->display_prices_including_tax() ) {
			$total -= (float) $cart->get_discount_tax();
		}

		return round( $total, wc_get_price_decimals() );
	}

	public function register_cart_update(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			return;
		}

		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => self::STORE_API_NAMESPACE,
				'callback'  => array( $this, 'sync_payment_method' ),
			)
		);
	}

	/**
	 * The block checkout keeps the selected payment method in the browser
	 * until the order is placed, and WooCommerce calculates the order total
	 * before it reads the method from that request. Without this the fee would
	 * be missing from the total the shopper confirms.
	 *
	 * @param mixed $data
	 */
	public function sync_payment_method( $data ): void {
		$method = is_array( $data ) && isset( $data['method'] ) && is_string( $data['method'] ) ? wc_clean( $data['method'] ) : '';
		if ( '' === $method || ! WC()->session || ! $this->is_switched_on( $method ) ) {
			return;
		}

		WC()->session->set( 'chosen_payment_method', $method );
	}

	public function enqueue_assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() || is_checkout_pay_page() ) {
			return;
		}

		if ( $this->amount() <= 0 || empty( $this->gateways() ) ) {
			return;
		}

		$blocks = function_exists( 'has_block' ) && has_block( 'woocommerce/checkout' );
		if ( $blocks && ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			return;
		}

		wp_enqueue_script( 'romcommerce-cod-fee', plugins_url( 'assets/cod-fee.js', ROMCOMMERCE_FILE ), $blocks ? array( 'wp-data', 'wc-blocks-checkout' ) : array( 'jquery' ), ROMCOMMERCE_VERSION, true );
		wp_add_inline_script(
			'romcommerce-cod-fee',
			'window.romcommerceCodFee=' . wp_json_encode(
				array(
					'blocks'    => $blocks,
					'namespace' => self::STORE_API_NAMESPACE,
					'gateways'  => $this->gateways(),
					'chosen'    => WC()->session ? (string) WC()->session->get( 'chosen_payment_method' ) : '',
				)
			) . ';',
			'before'
		);
	}

	private function amount(): float {
		return max( 0.0, (float) ( Settings::get( self::ID )['amount'] ?? 0 ) );
	}

	private function free_from(): float {
		return max( 0.0, (float) ( Settings::get( self::ID )['free_from'] ?? 0 ) );
	}

	private function label(): string {
		$label = trim( (string) ( Settings::get( self::ID )['label'] ?? '' ) );

		return '' !== $label ? $label : $this->default_label();
	}

	private function default_label(): string {
		return __( 'Cash on delivery fee', 'romcommerce' );
	}

	/** @return string[] */
	private function gateways(): array {
		$settings = Settings::get( self::ID );

		return isset( $settings['gateways'] ) && is_array( $settings['gateways'] ) ? array_values( array_map( 'strval', $settings['gateways'] ) ) : array( 'cod' );
	}

	private function taxable(): bool {
		if ( ! wc_tax_enabled() ) {
			return false;
		}

		return (bool) ( Settings::get( self::ID )['taxable'] ?? true );
	}

	private function is_switched_on( string $id ): bool {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();

		return isset( $gateways[ $id ] ) && 'yes' === $gateways[ $id ]->enabled;
	}

	/**
	 * Switched-on methods, plus any ticked method switched off since, so it
	 * can still be unticked.
	 *
	 * @return array<string, string> Gateway id => title.
	 */
	private function listed_gateways(): array {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$ticked   = $this->gateways();

		$options = array();
		foreach ( $gateways as $id => $gateway ) {
			$id = (string) $id;
			$on = 'yes' === $gateway->enabled;
			if ( ! $on && ! in_array( $id, $ticked, true ) ) {
				continue;
			}

			$title = trim( wp_strip_all_tags( (string) $gateway->get_title() ) );
			if ( '' === $title ) {
				$title = trim( wp_strip_all_tags( (string) $gateway->get_method_title() ) );
			}
			$title = '' !== $title ? $title : $id;

			/* translators: %s: payment method name. */
			$options[ $id ] = $on ? $title : sprintf( __( '%s (switched off)', 'romcommerce' ), $title );
		}

		return $options;
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			Settings::set_enabled( self::ID, isset( $_POST['rc_enabled'] ) );
			$this->handle_save(
				sanitize_text_field( wp_unslash( $_POST['rc_label'] ?? '' ) ),
				sanitize_text_field( wp_unslash( $_POST['rc_amount'] ?? '' ) ),
				sanitize_text_field( wp_unslash( $_POST['rc_free_from'] ?? '' ) ),
				isset( $_POST['rc_gateways'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['rc_gateways'] ) ) : array(),
				isset( $_POST['rc_taxable'] )
			);
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'Adds a fee to orders paid by cash on delivery. The customer sees it as its own line in the order totals as soon as they choose the payment method, before placing the order.', 'romcommerce' ) . '</p>';
		echo '<p>' . esc_html__( 'By law, a fee for a payment method can\'t be higher than what that method costs you, such as your courier\'s charge for collecting the cash. Mention the fee in your terms and on your payment information page too.', 'romcommerce' ) . '</p>';

		$symbol = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES );

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Module', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ';
		echo esc_html__( 'Enable', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row"><label for="rc-cod-fee-label">' . esc_html__( 'Name shown to customers', 'romcommerce' ) . '</label></th><td>';
		echo '<input type="text" id="rc-cod-fee-label" name="rc_label" class="regular-text" value="' . esc_attr( (string) ( Settings::get( self::ID )['label'] ?? '' ) ) . '" placeholder="' . esc_attr( $this->default_label() ) . '">';
		echo '<p class="description">' . esc_html__( 'Leave empty to use the default name, translated into the shop\'s language.', 'romcommerce' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="rc-cod-fee-amount">' . esc_html__( 'Fee', 'romcommerce' ) . '</label></th><td>';
		echo '<input type="text" id="rc-cod-fee-amount" name="rc_amount" class="small-text" inputmode="decimal" value="' . esc_attr( $this->amount() > 0 ? wc_format_localized_price( (string) $this->amount() ) : '' ) . '"> ' . esc_html( $symbol );
		if ( wc_tax_enabled() ) {
			echo '<p class="description">' . esc_html(
				wc_prices_include_tax()
					? __( 'Your product prices include VAT, so enter the fee with VAT too. The customer pays exactly this amount.', 'romcommerce' )
					: __( 'Your product prices are entered without VAT, so enter the fee without VAT too. VAT is added on top when the fee is taxed.', 'romcommerce' )
			) . '</p>';
		}
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="rc-cod-fee-free-from">' . esc_html__( 'No fee from', 'romcommerce' ) . '</label></th><td>';
		echo '<input type="text" id="rc-cod-fee-free-from" name="rc_free_from" class="small-text" inputmode="decimal" value="' . esc_attr( $this->free_from() > 0 ? wc_format_localized_price( (string) $this->free_from() ) : '' ) . '"> ' . esc_html( $symbol );
		echo '<p class="description">' . esc_html__( 'Orders whose products add up to at least this much, after coupons, pay no fee. Leave empty to always charge it.', 'romcommerce' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Payment methods charged', 'romcommerce' ) . '</th><td><fieldset>';
		$gateways = $this->listed_gateways();
		if ( empty( $gateways ) ) {
			echo '<p>' . esc_html__( 'No payment methods are switched on in WooCommerce.', 'romcommerce' ) . '</p>';
		}
		foreach ( $gateways as $id => $title ) {
			echo '<label><input type="checkbox" name="rc_gateways[]" value="' . esc_attr( $id ) . '"' . checked( in_array( $id, $this->gateways(), true ), true, false ) . '> ';
			echo esc_html( $title ) . '</label><br>';
		}
		echo '<p class="description">' . esc_html__( 'WooCommerce\'s own cash on delivery method is ticked by default. Tick any other method that collects cash on delivery, such as a courier\'s own.', 'romcommerce' ) . '</p>';
		echo '</fieldset></td></tr>';

		if ( wc_tax_enabled() ) {
			echo '<tr><th scope="row">' . esc_html__( 'VAT', 'romcommerce' ) . '</th><td>';
			echo '<label><input type="checkbox" name="rc_taxable" value="1"' . checked( $this->taxable(), true, false ) . '> ';
			echo esc_html__( 'Charge VAT on the fee, at the standard rate', 'romcommerce' ) . '</label></td></tr>';
		}

		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';
	}

	/**
	 * @param string[] $gateways
	 */
	private function handle_save( string $label, string $amount, string $free_from, array $gateways, bool $taxable ): void {
		$gateways = array_intersect( $gateways, array_keys( $this->listed_gateways() ) );

		$settings = Settings::get( self::ID );

		Settings::update(
			self::ID,
			array(
				'label'     => $label,
				'amount'    => max( 0.0, (float) wc_format_decimal( $amount ) ),
				'free_from' => max( 0.0, (float) wc_format_decimal( $free_from ) ),
				'gateways'  => array_values( array_unique( $gateways ) ),
				// The checkbox is only shown while taxes are on; keep the stored
				// choice when it isn't.
				'taxable'   => wc_tax_enabled() ? $taxable : (bool) ( $settings['taxable'] ?? true ),
			)
		);
	}
}
