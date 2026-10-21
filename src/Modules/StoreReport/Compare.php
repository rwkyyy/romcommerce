<?php

declare(strict_types=1);

namespace RomCommerce\Modules\StoreReport;

defined( 'ABSPATH' ) || exit;

/**
 * The maths behind every "compared with" figure in the report, free of
 * WordPress so it is unit-tested on its own.
 */
final class Compare {

	/**
	 * The change from $before to $now. No percentage from a base of zero or
	 * less: growth from nothing has no rate, and would print as "∞%".
	 *
	 * @return array{diff: float, percent: float|null}
	 */
	public static function change( float $now, float $before ): array {
		return array(
			'diff'    => $now - $before,
			'percent' => $before > 0 ? 100 * ( $now - $before ) / $before : null,
		);
	}

	/** $part as a percentage of $whole, 0 when there is no whole. */
	public static function share( float $part, float $whole ): float {
		return $whole > 0 ? 100 * $part / $whole : 0.0;
	}

	/** The change in a share, in percentage points. */
	public static function points( float $part, float $whole, float $before_part, float $before_whole ): float {
		return self::share( $part, $whole ) - self::share( $before_part, $before_whole );
	}

	/**
	 * Which keys entered a top list and which dropped out of it, with the
	 * rank (from 1) each dropped key had. Keys compare as strings: PHP turns
	 * a campaign called "2024" into an integer array key.
	 *
	 * @param array<int, int|string> $now    Keys listed now, best first.
	 * @param array<int, int|string> $before Keys listed before, best first.
	 * @return array{entered: array<int, int|string>, left: array<int, array{key: int|string, rank: int}>}
	 */
	public static function movement( array $now, array $before ): array {
		$now_keys    = array_map( 'strval', $now );
		$before_keys = array_map( 'strval', $before );

		$entered = array();
		foreach ( $now as $key ) {
			if ( ! in_array( (string) $key, $before_keys, true ) ) {
				$entered[] = $key;
			}
		}

		$left = array();
		foreach ( array_values( $before ) as $index => $key ) {
			if ( ! in_array( (string) $key, $now_keys, true ) ) {
				$left[] = array(
					'key'  => $key,
					'rank' => $index + 1,
				);
			}
		}

		return array(
			'entered' => $entered,
			'left'    => $left,
		);
	}
}
