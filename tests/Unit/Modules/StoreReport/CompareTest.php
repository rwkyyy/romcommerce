<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\StoreReport;

use RomCommerce\Modules\StoreReport\Compare;
use RomCommerce\Tests\TestCase;

final class CompareTest extends TestCase {

	public function test_change_gives_the_difference_and_the_percentage(): void {
		self::assertSame(
			array(
				'diff'    => 15.0,
				'percent' => 18.75,
			),
			Compare::change( 95, 80 )
		);
		self::assertSame( -100.0, Compare::change( 0, 81 )['percent'] );
		self::assertSame( 0.0, Compare::change( 12, 12 )['percent'] );
	}

	public function test_change_from_nothing_has_no_percentage(): void {
		self::assertSame(
			array(
				'diff'    => 3.0,
				'percent' => null,
			),
			Compare::change( 3, 0 )
		);
		self::assertNull( Compare::change( 0, 0 )['percent'] );
		self::assertNull( Compare::change( 50, -20 )['percent'] );
	}

	public function test_share_of_nothing_is_zero(): void {
		self::assertSame( 25.0, Compare::share( 2, 8 ) );
		self::assertSame( 0.0, Compare::share( 0, 0 ) );
		self::assertSame( 0.0, Compare::share( 3, 0 ) );
	}

	public function test_points_are_the_difference_between_two_shares(): void {
		self::assertEqualsWithDelta( 10.0, Compare::points( 30, 100, 10, 50 ), 0.0001 );
		self::assertEqualsWithDelta( -5.0, Compare::points( 15, 100, 20, 100 ), 0.0001 );
		self::assertEqualsWithDelta( 0.0, Compare::points( 1, 4, 25, 100 ), 0.0001 );
	}

	public function test_points_against_a_period_without_orders_start_from_zero(): void {
		self::assertEqualsWithDelta( 40.0, Compare::points( 4, 10, 0, 0 ), 0.0001 );
		self::assertEqualsWithDelta( -30.0, Compare::points( 0, 0, 3, 10 ), 0.0001 );
	}

	public function test_movement_names_new_entries_and_the_ranks_of_those_that_dropped_out(): void {
		$moves = Compare::movement( array( 4, 1, 9, 2, 7 ), array( 1, 2, 3, 4, 5 ) );

		self::assertSame( array( 9, 7 ), $moves['entered'] );
		self::assertSame(
			array(
				array(
					'key'  => 3,
					'rank' => 3,
				),
				array(
					'key'  => 5,
					'rank' => 5,
				),
			),
			$moves['left']
		);
	}

	public function test_movement_without_changes_is_empty(): void {
		self::assertSame(
			array(
				'entered' => array(),
				'left'    => array(),
			),
			Compare::movement( array( 'Direct', 'Organic: Google' ), array( 'Organic: Google', 'Direct' ) )
		);
	}

	public function test_movement_matches_numeric_names_that_php_turned_into_integer_keys(): void {
		$moves = Compare::movement( array_keys( array( '2024' => 3 ) ), array( '2024', 'black-friday' ) );

		self::assertSame( array(), $moves['entered'] );
		self::assertSame( array( 'black-friday' ), array_column( $moves['left'], 'key' ) );
		self::assertSame( array( 2 ), array_column( $moves['left'], 'rank' ) );
	}
}
