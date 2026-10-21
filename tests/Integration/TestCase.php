<?php
/**
 * Shared fixtures for integration tests.
 *
 * @package RomCommerce
 */

declare(strict_types=1);

namespace RomCommerce\Tests\Integration;

use WC_Order;
use WC_Product_Simple;
use WP_UnitTestCase;

abstract class TestCase extends WP_UnitTestCase {

	/** @var array<string, mixed> */
	private array $post_backup = array();

	public function set_up(): void {
		parent::set_up();
		$this->post_backup = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- test fixture.
	}

	public function tear_down(): void {
		$_POST = $this->post_backup;
		parent::tear_down();
	}

	protected function create_product( string $regular_price = '100', string $sale_price = '' ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'Test product' );
		$product->set_regular_price( $regular_price );
		if ( '' !== $sale_price ) {
			$product->set_sale_price( $sale_price );
		}
		$product->save();

		return $product;
	}

	/**
	 * @param array<string, string> $billing billing_* field => value, without the "billing_" prefix.
	 */
	protected function create_order( array $billing = array(), string $status = 'processing' ): WC_Order {
		$order = wc_create_order();
		$order->add_product( $this->create_product() );

		foreach ( $billing as $field => $value ) {
			$order->{'set_billing_' . $field}( $value );
		}

		$order->set_status( $status );
		$order->calculate_totals();
		$order->save();

		return $order;
	}

	/**
	 * Fires WooCommerce's checkout validation hook the way WC_Checkout does,
	 * with $posted standing in for the submitted checkout form.
	 *
	 * @param array<string, string> $posted
	 * @return array<int, string> error codes raised.
	 */
	protected function run_checkout_validation( array $posted ): array {
		$_POST  = $posted;
		$errors = new \WP_Error();

		do_action( 'woocommerce_after_checkout_validation', $posted, $errors );

		return $errors->get_error_codes();
	}
}
