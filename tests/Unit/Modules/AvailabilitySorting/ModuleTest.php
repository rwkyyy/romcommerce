<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\AvailabilitySorting;

use Brain\Monkey\Functions;
use RomCommerce\Modules\AvailabilitySorting\Module;
use RomCommerce\Tests\Support\InvokesPrivateMethods;
use RomCommerce\Tests\TestCase;

final class ModuleTest extends TestCase {

	use InvokesPrivateMethods;

	private const RANK = "EXISTS (SELECT 1 FROM wp_wc_product_meta_lookup WHERE wp_wc_product_meta_lookup.product_id = wp_posts.ID AND wp_wc_product_meta_lookup.stock_status IN ('outofstock')) ASC";

	/** @var array<string, mixed> */
	private array $get_backup = array();

	protected function setUp(): void {
		parent::setUp();
		$this->get_backup = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test fixture.
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'sanitize_key' )->alias(
			static function ( $key ): string {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
			}
		);
	}

	protected function tearDown(): void {
		$_GET = $this->get_backup;
		parent::tearDown();
	}

	/**
	 * @param array<string, string> $clauses
	 * @param array<int, string>    $statuses
	 * @return array<string, string>
	 */
	private function prepend( array $clauses, array $statuses = array( 'outofstock' ) ): array {
		return Module::prepend_stock_order( $clauses, $statuses, 'wp_wc_product_meta_lookup', 'wp_posts' );
	}

	/**
	 * The store's ordering must survive as the tiebreaker inside each stock
	 * group; replacing it would silently discard the merchant's custom order.
	 */
	public function test_stock_rank_is_prepended_to_the_existing_order(): void {
		$clauses = $this->prepend( array( 'orderby' => ' wc_product_meta_lookup.min_price ASC, wc_product_meta_lookup.product_id ASC ' ) );

		self::assertSame( self::RANK . ', wc_product_meta_lookup.min_price ASC, wc_product_meta_lookup.product_id ASC', $clauses['orderby'] );
	}

	public function test_an_empty_order_becomes_the_stock_rank_alone(): void {
		self::assertSame( self::RANK, $this->prepend( array( 'orderby' => '' ) )['orderby'] );
		self::assertSame( self::RANK, $this->prepend( array() )['orderby'] );
	}

	public function test_other_clauses_are_left_untouched(): void {
		$clauses = $this->prepend(
			array(
				'join'    => ' LEFT JOIN wp_wc_product_meta_lookup wc_product_meta_lookup ON wp_posts.ID = wc_product_meta_lookup.product_id ',
				'where'   => ' AND 1=1 ',
				'orderby' => 'wp_posts.menu_order ASC',
			)
		);

		self::assertSame( ' LEFT JOIN wp_wc_product_meta_lookup wc_product_meta_lookup ON wp_posts.ID = wc_product_meta_lookup.product_id ', $clauses['join'] );
		self::assertSame( ' AND 1=1 ', $clauses['where'] );
	}

	public function test_backorder_can_be_moved_along_with_out_of_stock(): void {
		$orderby = $this->prepend( array( 'orderby' => 'wp_posts.menu_order ASC' ), array( 'outofstock', 'onbackorder' ) )['orderby'];

		self::assertStringContainsString( "stock_status IN ('outofstock', 'onbackorder')", $orderby );
	}

	public function test_statuses_outside_the_allowlist_never_reach_the_sql(): void {
		$orderby = $this->prepend( array( 'orderby' => 'x' ), array( 'outofstock', "instock') OR 1=1 -- " ) )['orderby'];
		self::assertStringContainsString( "IN ('outofstock')", $orderby );
		self::assertStringNotContainsString( 'OR 1=1', $orderby );

		self::assertSame( array( 'orderby' => 'x' ), $this->prepend( array( 'orderby' => 'x' ), array( 'instock' ) ) );
	}

	public function test_shortcode_flag_follows_the_setting_and_carries_the_statuses(): void {
		Functions\when( 'get_option' )->justReturn( array( 'backorder_last' => true ) );
		self::assertSame(
			array(
				'post_type'       => 'product',
				Module::QUERY_VAR => 'outofstock,onbackorder',
			),
			( new Module() )->flag_shortcode_query( array( 'post_type' => 'product' ) )
		);

		Functions\when( 'get_option' )->justReturn( array( 'shortcodes' => false ) );
		self::assertSame( array( 'post_type' => 'product' ), ( new Module() )->flag_shortcode_query( array( 'post_type' => 'product' ) ) );
	}

	/** @dataProvider shopperSortProvider */
	public function test_shopper_chose_sort_only_for_a_non_default_dropdown_pick( $orderby, bool $is_search, bool $expected ): void {
		$_GET = null === $orderby ? array() : array( 'orderby' => $orderby );
		Functions\when( 'get_option' )->justReturn( 'menu_order' );
		Functions\when( 'is_search' )->justReturn( $is_search );

		self::assertSame( $expected, $this->invokePrivate( new Module(), 'shopper_chose_sort' ) );
	}

	/** @return array<string, array{0: mixed, 1: bool, 2: bool}> */
	public static function shopperSortProvider(): array {
		return array(
			'no orderby param'                   => array( null, false, false ),
			'empty orderby param'                => array( '', false, false ),
			'store default picked'               => array( 'menu_order', false, false ),
			'price picked'                       => array( 'price', false, true ),
			'price-desc picked'                  => array( 'price-desc', false, true ),
			'relevance on a search'              => array( 'relevance', true, false ),
			'relevance outside a search'         => array( 'relevance', false, true ),
			'array param is not a dropdown pick' => array( array( 'price' ), false, false ),
		);
	}
}
