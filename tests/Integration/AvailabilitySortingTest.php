<?php
/**
 * Availability Sorting: the reordering is pure SQL layered on top of
 * WooCommerce's own sort clauses, so it is checked against the real lookup
 * table and WC's real price/popularity sorts rather than stand-ins.
 *
 * @package RomCommerce
 */

declare(strict_types=1);

namespace RomCommerce\Tests\Integration;

use RomCommerce\Modules\AvailabilitySorting\Module;

final class AvailabilitySortingTest extends TestCase {

	/** @var array<string, int> */
	private array $ids = array();

	public function set_up(): void {
		parent::set_up();
		delete_option( 'romcommerce_availability_sorting' );

		$this->ids = array(
			'cheap_out'     => $this->product( 'cheap_out', '10', 'outofstock', 1, 5 ),
			'mid_in'        => $this->product( 'mid_in', '20', 'instock', 2, 1 ),
			'mid_backorder' => $this->product( 'mid_backorder', '25', 'onbackorder', 3, 9 ),
			'dear_in'       => $this->product( 'dear_in', '30', 'instock', 4, 3 ),
		);
	}

	private function product( string $name, string $price, string $stock_status, int $menu_order, int $sales ): int {
		$product = $this->create_product( $price );
		$product->set_name( $name );
		$product->set_stock_status( $stock_status );
		$product->set_menu_order( $menu_order );
		$product->set_total_sales( $sales );
		$product->save();

		return $product->get_id();
	}

	/**
	 * Runs the query the way WooCommerce does for a catalog sort: its own
	 * ordering args (which add its posts_clauses callbacks), then the cleanup.
	 *
	 * @param mixed $flag value for Module::QUERY_VAR, or null for an unflagged query.
	 * @return array<int, string> product names in result order.
	 */
	private function names( string $orderby, string $order = '', $flag = true ): array {
		$ordering = WC()->query->get_catalog_ordering_args( $orderby, $order );

		$args = array(
			'post_type'      => 'product',
			'post__in'       => array_values( $this->ids ),
			'posts_per_page' => -1,
			'orderby'        => $ordering['orderby'],
			'order'          => $ordering['order'],
		);
		if ( null !== $flag ) {
			$args[ Module::QUERY_VAR ] = $flag;
		}

		$query = new \WP_Query( $args );
		WC()->query->remove_ordering_args();

		return array_map(
			static function ( \WP_Post $post ): string {
				return $post->post_title;
			},
			$query->posts
		);
	}

	public function test_unflagged_queries_keep_woocommerce_order(): void {
		$this->assertSame( array( 'cheap_out', 'mid_in', 'mid_backorder', 'dear_in' ), $this->names( 'price', 'ASC', null ) );
	}

	/**
	 * WooCommerce's price sort replaces the whole ORDER BY and joins the lookup
	 * table under its own alias; the stock rank has to survive both, and the
	 * price order has to survive inside each group.
	 */
	public function test_out_of_stock_moves_last_under_price_sorting(): void {
		$this->assertSame( array( 'mid_in', 'mid_backorder', 'dear_in', 'cheap_out' ), $this->names( 'price', 'ASC' ) );
		$this->assertSame( array( 'dear_in', 'mid_backorder', 'mid_in', 'cheap_out' ), $this->names( 'price', 'DESC' ) );
	}

	public function test_out_of_stock_moves_last_under_custom_menu_order(): void {
		$this->assertSame( array( 'mid_in', 'mid_backorder', 'dear_in', 'cheap_out' ), $this->names( 'menu_order' ) );
	}

	public function test_out_of_stock_moves_last_under_popularity(): void {
		$this->assertSame( array( 'mid_backorder', 'dear_in', 'mid_in', 'cheap_out' ), $this->names( 'popularity' ) );
	}

	public function test_backorder_option_moves_backorders_with_out_of_stock_in_sort_order(): void {
		update_option( 'romcommerce_availability_sorting', array( 'backorder_last' => true ) );

		$this->assertSame( array( 'mid_in', 'dear_in', 'cheap_out', 'mid_backorder' ), $this->names( 'price', 'ASC' ) );
	}

	public function test_shortcode_queries_are_flagged_only_when_enabled(): void {
		$args = apply_filters( 'woocommerce_shortcode_products_query', array( 'post_type' => 'product' ), array(), 'products' );
		$this->assertSame( 'outofstock', $args[ Module::QUERY_VAR ] ?? null );

		update_option( 'romcommerce_availability_sorting', array( 'shortcodes' => false ) );
		$args = apply_filters( 'woocommerce_shortcode_products_query', array( 'post_type' => 'product' ), array(), 'products' );
		$this->assertArrayNotHasKey( Module::QUERY_VAR, $args );
	}

	public function test_a_flagged_query_still_paginates_across_the_stock_groups(): void {
		$ordering = WC()->query->get_catalog_ordering_args( 'price', 'ASC' );
		$pages    = array();

		foreach ( array( 1, 2 ) as $page ) {
			$query   = new \WP_Query(
				array(
					'post_type'       => 'product',
					'post__in'        => array_values( $this->ids ),
					'posts_per_page'  => 2,
					'paged'           => $page,
					'orderby'         => $ordering['orderby'],
					'order'           => $ordering['order'],
					Module::QUERY_VAR => true,
				)
			);
			$pages[] = wp_list_pluck( $query->posts, 'post_title' );
		}
		WC()->query->remove_ordering_args();

		$this->assertSame( array( array( 'mid_in', 'mid_backorder' ), array( 'dear_in', 'cheap_out' ) ), $pages );
	}

	private function catalog_query_flagged( ?string $orderby ): bool {
		$backup = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test fixture.
		if ( null !== $orderby ) {
			$_GET['orderby'] = $orderby;
		}

		$query = new \WP_Query();
		\RomCommerce\Plugin::instance()->modules()->get( 'availability-sorting' )->flag_catalog_query( $query );
		$_GET = $backup;

		return (bool) $query->get( Module::QUERY_VAR );
	}

	public function test_by_default_a_shoppers_sort_choice_is_followed_exactly(): void {
		$this->assertTrue( $this->catalog_query_flagged( null ), 'The store default sort still moves out-of-stock last.' );
		$this->assertTrue( $this->catalog_query_flagged( 'menu_order' ), 'Picking the store default is not a different choice.' );
		$this->assertFalse( $this->catalog_query_flagged( 'price' ) );
	}

	public function test_keep_last_option_moves_out_of_stock_under_a_shoppers_sort_too(): void {
		update_option( 'romcommerce_availability_sorting', array( 'shopper_sort' => Module::SORT_ALWAYS ) );

		$this->assertTrue( $this->catalog_query_flagged( 'price' ) );
	}
}
