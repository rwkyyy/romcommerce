<?php
/**
 * The orders-list payment method filter on whichever order storage the run
 * uses: the HPOS list table's query args, or the legacy list's main WP_Query.
 *
 * @package RomCommerce
 */

declare(strict_types=1);

namespace RomCommerce\Tests\Integration;

use Automattic\WooCommerce\Utilities\OrderUtil;
use RomCommerce\Modules\OrderPaymentFilter\Module;
use WP_Query;

final class OrderPaymentFilterTest extends TestCase {

	public function tear_down(): void {
		unset( $_GET[ Module::QUERY_VAR ] );
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
		set_current_screen( 'front' );
		parent::tear_down();
	}

	private function order_paid_with( string $method ): int {
		$order = $this->create_order();
		$order->set_payment_method( $method );
		$order->save();

		return $order->get_id();
	}

	/** @return array<int, int> */
	private function listed_order_ids(): array {
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$args = apply_filters(
				'woocommerce_shop_order_list_table_prepare_items_query_args',
				array(
					'type'   => 'shop_order',
					'status' => array_keys( wc_get_order_statuses() ),
					'limit'  => -1,
					'return' => 'ids',
				)
			);

			return array_map( 'intval', wc_get_orders( $args ) );
		}

		set_current_screen( 'edit-shop_order' );
		$query                   = new WP_Query();
		$GLOBALS['wp_the_query'] = $query;

		return array_map(
			'intval',
			$query->query(
				array(
					'post_type'   => 'shop_order',
					'post_status' => 'any',
					'fields'      => 'ids',
				)
			)
		);
	}

	public function test_the_list_shows_only_orders_paid_with_the_chosen_method(): void {
		$this->order_paid_with( 'bacs' );
		$cod = $this->order_paid_with( 'cod' );

		$_GET[ Module::QUERY_VAR ] = 'cod';

		self::assertSame( array( $cod ), $this->listed_order_ids() );
	}

	public function test_without_a_choice_every_order_is_listed(): void {
		$this->order_paid_with( 'bacs' );
		$this->order_paid_with( 'cod' );

		self::assertCount( 2, $this->listed_order_ids() );
	}

	public function test_the_dropdown_lists_the_installed_gateways_and_keeps_the_choice(): void {
		$_GET[ Module::QUERY_VAR ] = 'cod';

		ob_start();
		do_action( 'woocommerce_order_list_table_restrict_manage_orders', 'shop_order', 'top' );
		do_action( 'woocommerce_order_list_table_restrict_manage_orders', 'shop_order_refund', 'top' );
		$html = (string) ob_get_clean();

		self::assertSame( 1, substr_count( $html, 'name="rc_payment_method"' ) );
		self::assertStringContainsString( 'value="bacs"', $html );
		self::assertMatchesRegularExpression( '/value="cod"\s+selected/', $html );
	}
}
