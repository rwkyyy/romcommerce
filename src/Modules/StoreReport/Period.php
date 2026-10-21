<?php

declare(strict_types=1);

namespace RomCommerce\Modules\StoreReport;

use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * The dates a report covers, all in the store's timezone. A weekly report
 * covers the seven whole days before the day it is sent (sent Monday 08:00:
 * the previous Monday 00:00 to Sunday 23:59:59).
 *
 * Comparisons keep the weekdays lined up, so weekends compare with weekends:
 * the previous week is 7 days earlier, the same week of the previous month 4
 * weeks earlier, and the same week of the previous year 52 weeks earlier.
 */
final class Period {

	public const COMPARE_NONE  = 'none';
	public const COMPARE_WEEK  = 'week';
	public const COMPARE_MONTH = 'month';
	public const COMPARE_YEAR  = 'year';

	private const COMPARE_WEEKS = array(
		self::COMPARE_WEEK  => 1,
		self::COMPARE_MONTH => 4,
		self::COMPARE_YEAR  => 52,
	);

	public DateTimeImmutable $start;

	/** Exclusive: the first moment after the period. */
	public DateTimeImmutable $end;

	public function __construct( DateTimeImmutable $start, DateTimeImmutable $end ) {
		$this->start = $start;
		$this->end   = $end;
	}

	/** The week that ends at midnight starting the day of $now. */
	public static function last_week( DateTimeImmutable $now ): self {
		$end = $now->setTime( 0, 0 );

		return new self( $end->modify( '-7 days' ), $end );
	}

	public function compared( string $mode ): ?self {
		if ( ! isset( self::COMPARE_WEEKS[ $mode ] ) ) {
			return null;
		}

		$shift = '-' . ( 7 * self::COMPARE_WEEKS[ $mode ] ) . ' days';

		return new self( $this->start->modify( $shift ), $this->end->modify( $shift ) );
	}

	/**
	 * The next send time after $now: $weekday (1 Monday … 7 Sunday, ISO) at
	 * $hour:00 in $now's timezone.
	 */
	public static function next_send( DateTimeImmutable $now, int $weekday, int $hour ): DateTimeImmutable {
		$weekday = max( 1, min( 7, $weekday ) );
		$hour    = max( 0, min( 23, $hour ) );

		$days      = ( $weekday - (int) $now->format( 'N' ) + 7 ) % 7;
		$candidate = $now->setTime( $hour, 0 )->modify( '+' . $days . ' days' );

		return $candidate > $now ? $candidate : $candidate->modify( '+7 days' );
	}

	/** The covered days for people, e.g. "3 Oct – 9 Oct 2026", in the site's language. */
	public function label(): string {
		$last = $this->end->modify( '-1 day' );

		return wp_date( 'j M', $this->start->getTimestamp(), $this->start->getTimezone() ) . ' – ' . wp_date( 'j M Y', $last->getTimestamp(), $last->getTimezone() );
	}

	/** Stable id for "already sent for this week". */
	public function key(): string {
		return $this->start->format( 'Y-m-d' );
	}

	public static function now( ?DateTimeZone $timezone = null ): DateTimeImmutable {
		return new DateTimeImmutable( 'now', $timezone ?? wp_timezone() );
	}
}
