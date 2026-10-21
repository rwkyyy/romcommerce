<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\StoreReport;

use DateTimeImmutable;
use DateTimeZone;
use RomCommerce\Modules\StoreReport\Period;
use RomCommerce\Tests\TestCase;

final class PeriodTest extends TestCase {

	private function at( string $time ): DateTimeImmutable {
		return new DateTimeImmutable( $time, new DateTimeZone( 'Europe/Bucharest' ) );
	}

	public function test_last_week_is_the_seven_whole_days_before_the_day(): void {
		$period = Period::last_week( $this->at( '2026-10-12 08:00:10' ) );

		self::assertSame( '2026-10-05 00:00:00', $period->start->format( 'Y-m-d H:i:s' ) );
		self::assertSame( '2026-10-12 00:00:00', $period->end->format( 'Y-m-d H:i:s' ) );
		self::assertSame( '2026-10-05', $period->key() );
	}

	public function test_a_week_across_the_autumn_clock_change_still_starts_and_ends_at_midnight(): void {
		$period = Period::last_week( $this->at( '2026-10-26 08:00' ) );

		self::assertSame( '2026-10-19 00:00', $period->start->format( 'Y-m-d H:i' ) );
		self::assertSame( '2026-10-26 00:00', $period->end->format( 'Y-m-d H:i' ) );
		self::assertSame( 7 * 86400 + 3600, $period->end->getTimestamp() - $period->start->getTimestamp() );
	}

	/** @return array<string, array{0: string, 1: string, 2: string}> */
	public static function comparisons(): array {
		return array(
			'previous week'               => array( Period::COMPARE_WEEK, '2026-09-28', '2026-10-05' ),
			'same week of previous month' => array( Period::COMPARE_MONTH, '2026-09-07', '2026-09-14' ),
			'same week of previous year'  => array( Period::COMPARE_YEAR, '2025-10-06', '2025-10-13' ),
		);
	}

	/** @dataProvider comparisons */
	public function test_comparisons_keep_the_weekdays_lined_up( string $mode, string $start, string $end ): void {
		$compared = Period::last_week( $this->at( '2026-10-12 08:00' ) )->compared( $mode );

		self::assertNotNull( $compared );
		self::assertSame( $start, $compared->start->format( 'Y-m-d' ) );
		self::assertSame( $end, $compared->end->format( 'Y-m-d' ) );
		self::assertSame( 'Mon', $compared->start->format( 'D' ) );
	}

	public function test_no_comparison(): void {
		self::assertNull( Period::last_week( $this->at( '2026-10-12' ) )->compared( Period::COMPARE_NONE ) );
	}

	public function test_next_send_is_the_coming_weekday_and_hour(): void {
		// 2026-10-10 is a Saturday.
		self::assertSame( '2026-10-12 08:00', Period::next_send( $this->at( '2026-10-10 15:00' ), 1, 8 )->format( 'Y-m-d H:i' ) );
		self::assertSame( '2026-10-10 18:00', Period::next_send( $this->at( '2026-10-10 15:00' ), 6, 18 )->format( 'Y-m-d H:i' ) );
		self::assertSame( '2026-10-17 08:00', Period::next_send( $this->at( '2026-10-10 08:00' ), 6, 8 )->format( 'Y-m-d H:i' ) );
		self::assertSame( '2026-10-17 07:00', Period::next_send( $this->at( '2026-10-10 15:00' ), 6, 7 )->format( 'Y-m-d H:i' ) );
	}
}
