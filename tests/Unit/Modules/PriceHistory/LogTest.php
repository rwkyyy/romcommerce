<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\PriceHistory;

use Mockery;
use RomCommerce\Modules\PriceHistory\Log;
use RomCommerce\Tests\TestCase;

final class LogTest extends TestCase {

	/** @return Mockery\MockInterface */
	private function mockWpdb() {
		global $wpdb;

		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( string $sql ): string {
				return $sql;
			}
		);

		return $wpdb;
	}

	public function test_table_name_is_prefixed(): void {
		$this->mockWpdb();

		self::assertSame( 'wp_romcommerce_price_history', Log::table_name() );
	}

	public function test_insert_writes_the_product_price_and_timestamp(): void {
		$wpdb = $this->mockWpdb();
		$wpdb->shouldReceive( 'insert' )
			->once()
			->with(
				'wp_romcommerce_price_history',
				array(
					'product_id' => 42,
					'price'      => '19.9900',
					'logged_at'  => '2026-09-25 00:00:00',
				),
				array( '%d', '%s', '%s' )
			);

		Log::insert( 42, '19.9900', '2026-09-25 00:00:00' );
	}

	public function test_latest_returns_null_when_nothing_logged_yet(): void {
		$wpdb = $this->mockWpdb();
		$wpdb->shouldReceive( 'get_row' )->andReturn( null );

		self::assertNull( Log::latest( 42 ) );
	}

	public function test_latest_returns_the_most_recent_row_typed(): void {
		$wpdb = $this->mockWpdb();
		$wpdb->shouldReceive( 'get_row' )->andReturn(
			array(
				'id'        => '7',
				'price'     => '24.9900',
				'logged_at' => '2026-10-04 12:00:00',
			)
		);

		self::assertSame(
			array(
				'id'        => 7,
				'price'     => '24.9900',
				'logged_at' => '2026-10-04 12:00:00',
			),
			Log::latest( 42 )
		);
	}

	/**
	 * The window is the 30 days before the anchor, and the anchor's own row is
	 * excluded through the id bound, so the reduction never enters its own
	 * reference (HG 947/2000 art. 4^1, see Log::reference_price()).
	 */
	public function test_floor_before_queries_the_30_days_before_the_anchor(): void {
		global $wpdb;

		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$prepared     = array();
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( string $sql, ...$args ) use ( &$prepared ): string {
				$prepared[] = $args;
				return $sql;
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturn( null, null );

		Log::floor_before( 42, '2026-10-04 12:00:00', 7 );

		self::assertSame( array( 42, '2026-09-04 12:00:00', '2026-10-04 12:00:00', '2026-10-04 12:00:00', 7 ), $prepared[0] );
		self::assertSame( array( 42, '2026-09-04 12:00:00' ), $prepared[1] );
	}

	/**
	 * A price set long before the window and left unchanged was still being
	 * applied throughout it, so it counts even though its row predates it.
	 */
	public function test_floor_before_considers_the_price_carried_into_the_window(): void {
		$wpdb = $this->mockWpdb();
		// First get_var call is the in-window MIN(), second is the carried-in price.
		$wpdb->shouldReceive( 'get_var' )->andReturn( '30.00', '19.99' );

		self::assertSame( '19.99', Log::floor_before( 42, '2026-10-04 12:00:00' ) );
	}

	public function test_floor_before_uses_the_in_window_minimum_when_it_is_lower(): void {
		$wpdb = $this->mockWpdb();
		$wpdb->shouldReceive( 'get_var' )->andReturn( '15.00', '19.99' );

		// min() of the float-cast candidates, cast back to string: PHP drops the
		// trailing zero (15.0 -> '15'), same as production, not a rounding bug.
		self::assertSame( '15', Log::floor_before( 42, '2026-10-04 12:00:00' ) );
	}

	public function test_floor_before_is_null_when_there_is_no_earlier_history(): void {
		$wpdb = $this->mockWpdb();
		$wpdb->shouldReceive( 'get_var' )->andReturn( null, null );

		self::assertNull( Log::floor_before( 42, '2026-10-04 12:00:00' ) );
	}

	public function test_recent_returns_the_rows_as_is(): void {
		$rows = array(
			array(
				'price'     => '19.99',
				'logged_at' => '2026-09-20 10:00:00',
			),
		);

		$wpdb = $this->mockWpdb();
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

		self::assertSame( $rows, Log::recent( 42 ) );
	}

	public function test_recent_returns_empty_array_when_wpdb_returns_something_unexpected(): void {
		$wpdb = $this->mockWpdb();
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );

		self::assertSame( array(), Log::recent( 42 ) );
	}
}
