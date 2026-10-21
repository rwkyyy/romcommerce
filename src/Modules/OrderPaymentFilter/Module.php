<?php

declare(strict_types=1);

namespace RomCommerce\Modules\OrderPaymentFilter;

use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * A payment method dropdown on WooCommerce's orders list, next to its date
 * and customer filters, on both order storages. Admin-only: nothing runs on
 * the storefront or at checkout.
 */
final class Module implements ModuleInterface, HasSettingsUi {

	private const ID = 'order-payment-filter';

	/** Prefixed so a payment filter WooCommerce adds later under a plain name can't collide with it. */
	public const QUERY_VAR = 'rc_payment_method';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Payment Method Filter', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		// HPOS orders list (both hooks since WooCommerce 7.3).
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'render_hpos_dropdown' ), 20, 1 );
		add_filter( 'woocommerce_shop_order_list_table_prepare_items_query_args', array( $this, 'filter_hpos_query' ) );

		// Legacy post-based orders list.
		add_action( 'restrict_manage_posts', array( $this, 'render_legacy_dropdown' ), 20, 1 );
		add_action( 'pre_get_posts', array( $this, 'filter_legacy_query' ) );
	}

	/** @param mixed $order_type */
	public function render_hpos_dropdown( $order_type ): void {
		if ( 'shop_order' === $order_type ) {
			$this->render_dropdown();
		}
	}

	/** @param mixed $post_type */
	public function render_legacy_dropdown( $post_type ): void {
		if ( 'shop_order' === $post_type ) {
			$this->render_dropdown();
		}
	}

	/**
	 * @param mixed $args
	 * @return mixed
	 */
	public function filter_hpos_query( $args ) {
		$method = self::requested();
		if ( '' === $method || ! is_array( $args ) ) {
			return $args;
		}

		$args['payment_method'] = $method;

		return $args;
	}

	/**
	 * The legacy list is a plain WP_Query on shop_order posts with no
	 * WooCommerce query hook of its own, so the filter goes in as a meta query
	 * on the screen's main query. Only reached when orders live in posts.
	 *
	 * @param mixed $query
	 */
	public function filter_legacy_query( $query ): void {
		if ( ! $query instanceof WP_Query || ! is_admin() || ! $query->is_main_query() || 'shop_order' !== $query->get( 'post_type' ) ) {
			return;
		}

		$method = self::requested();
		if ( '' === $method ) {
			return;
		}

		$meta_query   = (array) $query->get( 'meta_query' );
		$meta_query[] = array(
			'key'   => '_payment_method',
			'value' => $method,
		);
		$query->set( 'meta_query', $meta_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the legacy orders list has no other way to filter by payment method.
	}

	/**
	 * Every gateway WooCommerce knows, switched on or not, since older orders
	 * keep the method they were paid with. A method whose gateway has since
	 * been removed still shows while it is the active filter, by its id.
	 *
	 * @return array<string, string> Gateway id => title.
	 */
	public static function options( string $current = '' ): array {
		$options = array();

		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
				$title = trim( wp_strip_all_tags( (string) $gateway->get_title() ) );
				if ( '' === $title ) {
					$title = trim( wp_strip_all_tags( (string) $gateway->get_method_title() ) );
				}
				$options[ (string) $id ] = '' !== $title ? $title : (string) $id;
			}
		}

		if ( '' !== $current && ! isset( $options[ $current ] ) ) {
			$options[ $current ] = $current;
		}

		return $options;
	}

	/**
	 * A GET list filter, like WooCommerce's own customer and date filters: it
	 * only narrows what the current user can already list, so no nonce.
	 */
	public static function requested(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter, see above.
		return isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
	}

	private function render_dropdown(): void {
		$current = self::requested();

		echo '<label class="screen-reader-text" for="filter-by-rc-payment-method">' . esc_html__( 'Filter by payment method', 'romcommerce' ) . '</label>';
		echo '<select name="' . esc_attr( self::QUERY_VAR ) . '" id="filter-by-rc-payment-method">';
		echo '<option value="">' . esc_html__( 'All payment methods', 'romcommerce' ) . '</option>';
		foreach ( self::options( $current ) as $id => $title ) {
			echo '<option value="' . esc_attr( $id ) . '"' . selected( $current, $id, false ) . '>' . esc_html( $title ) . '</option>';
		}
		echo '</select>';
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			Settings::set_enabled( self::ID, isset( $_POST['rc_enabled'] ) );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'Adds a payment method dropdown to the WooCommerce orders list, next to the date and customer filters, so you can see only the orders paid by card, cash on delivery, bank transfer and so on. It lists every payment method installed, including switched-off ones, because older orders keep the method they were paid with.', 'romcommerce' ) . '</p>';

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Module', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ';
		echo esc_html__( 'Enable', 'romcommerce' ) . '</label></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';
	}
}
