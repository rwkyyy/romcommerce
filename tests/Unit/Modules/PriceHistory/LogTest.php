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

	public function test_last_price_returns_null_when_nothing_logged_yet(): void {
		$wpdb = $this->mockWpdb();
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );

		self::assertNull( Log::last_price( 42 ) );
	}

	public function test_last_price_returns_the_most_recent_value(): void {
		$wpdb = $this->mockWpdb();
		$wpdb->shouldReceive( 'get_var' )->andReturn( '24.99' );

		self::assertSame( '24.99', Log::last_price( 42 ) );
	}

	/**
	 * The Omnibus floor must include the price already in effect when the
	 * 30-day window opened, not just entries logged inside it — a price set
	 * 60 days ago and never changed since was still the effective price
	 * throughout the last 30 days (see Log::thirty_day_floor()'s docblock
	 * and docs/decisions.md, Stage 2). This is the actual rule the method
	 * exists to implement, so it is the one most worth pinning here.
	 */
	public function test_thirty_day_floor_considers_the_price_carried_into_the_window(): void {
		$wpdb = $this->mockWpdb();
		// First get_var call is the in-window MIN(), second is the carried-in price.
		$wpdb->shouldReceive( 'get_var' )->andReturn( '30.00', '19.99' );

		self::assertSame( '19.99', Log::thirty_day_floor( 42 ) );
	}

	public function test_thirty_day_floor_uses_the_in_window_minimum_when_it_is_lower(): void {
		$wpdb = $this->mockWpdb();
		$wpdb->shouldReceive( 'get_var' )->andReturn( '15.00', '19.99' );

		// min() of the two float-cast candidates, then cast back to string —
		// PHP's float-to-string drops the trailing zero (15.0 -> '15'), same
		// as the real thirty_day_floor() behaviour, not a rounding bug.
		self::assertSame( '15', Log::thirty_day_floor( 42 ) );
	}

	public function test_thirty_day_floor_falls_back_to_whichever_side_has_data(): void {
		$wpdb = $this->mockWpdb();
		$wpdb->shouldReceive( 'get_var' )->andReturn( null, '19.99' );

		self::assertSame( '19.99', Log::thirty_day_floor( 42 ) );
	}

	public function test_thirty_day_floor_is_null_when_the_product_has_no_history_at_all(): void {
		$wpdb = $this->mockWpdb();
		$wpdb->shouldReceive( 'get_var' )->andReturn( null, null );

		self::assertNull( Log::thirty_day_floor( 42 ) );
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
