<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\StoreReport;

use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use RomCommerce\Modules\StoreReport\Period;
use RomCommerce\Modules\StoreReport\Report;
use RomCommerce\Tests\TestCase;

final class ReportTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'number_format_i18n' )->alias(
			static function ( $number, int $decimals = 0 ): string {
				return number_format( (float) $number, $decimals );
			}
		);
		Functions\when( 'wc_price' )->alias(
			static function ( $amount ): string {
				return '<span class="amount">' . number_format( (float) $amount, 2 ) . '&nbsp;<span>lei</span></span>';
			}
		);
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wc_get_order_status_name' )->alias( 'ucfirst' );
		Functions\when( 'wp_date' )->alias(
			static function ( string $format, int $timestamp, DateTimeZone $timezone ): string {
				return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone )->format( $format );
			}
		);
	}

	public function test_totals_show_the_change_and_its_percentage_with_arrow_and_sign(): void {
		self::assertStringContainsString( "▲\u{00A0}+15 (+19%)", Report::change( 96, 81, false ) );
		self::assertStringContainsString( "▼\u{00A0}−1 (−1.8%)", Report::change( 55, 56, false ) );
		self::assertStringContainsString( "▲\u{00A0}+89.11\u{00A0}lei (+31%)", Report::change( 380.56, 291.45, true ) );
		self::assertStringContainsString( "▼\u{00A0}−81 (−100%)", Report::change( 0, 81, false ) );
	}

	public function test_a_change_from_zero_has_no_percentage(): void {
		$change = Report::change( 3, 0, false );

		self::assertStringContainsString( "▲\u{00A0}+3", $change );
		self::assertStringNotContainsString( '%', $change );
		self::assertStringNotContainsString( '∞', $change );
	}

	public function test_no_change_says_so_in_grey(): void {
		self::assertStringContainsString( '= no change', Report::change( 5, 5, false ) );
		self::assertStringContainsString( '= no change', Report::change( 0, 0, true ) );
		self::assertStringContainsString( '#636363', Report::change( 5, 5, false ) );
	}

	public function test_colour_follows_whether_the_move_is_good_news(): void {
		self::assertStringContainsString( '#2e7d32', Report::change( 10, 8, false ) );
		self::assertStringContainsString( '#c62828', Report::change( 8, 10, false ) );
		self::assertStringContainsString( '#c62828', Report::change( 10, 8, false, Report::UP_BAD ) );
		self::assertStringContainsString( '#2e7d32', Report::change( 8, 10, false, Report::UP_BAD ) );
		self::assertStringContainsString( '#636363', Report::change( 10, 8, false, Report::NEUTRAL ) );
	}

	public function test_share_changes_are_in_percentage_points(): void {
		self::assertStringContainsString( "▲\u{00A0}+2.1\u{00A0}pp", Report::points( 2.08 ) );
		self::assertStringContainsString( "▼\u{00A0}−12.5\u{00A0}pp", Report::points( -12.5 ) );
		self::assertStringContainsString( '= no change', Report::points( 0.04 ) );
	}

	public function test_small_percentages_keep_a_decimal(): void {
		self::assertSame( '5.2%', Report::percent( 5.2 ) );
		self::assertSame( '34%', Report::percent( 34.4 ) );
		self::assertSame( '0.0%', Report::percent( 0.0 ) );
	}

	/** @return array<string, mixed> A Stats::result() shape. */
	private function stats_result( int $orders, array $products, array $payments, array $channels, array $campaigns = array(), int $closed = 0 ): array {
		$categories = array_map(
			static function ( array $row ): array {
				$row['name'] = 'Cat ' . $row['name'];
				return $row;
			},
			$products
		);
		usort(
			$categories,
			static function ( array $a, array $b ): int {
				return $b['total'] <=> $a['total'];
			}
		);

		return array(
			'orders'            => $orders,
			'completed'         => $orders - $closed,
			'processing'        => 0,
			'waiting'           => 0,
			'waiting_by_status' => array(),
			'closed'            => $closed,
			'paid_orders'       => $orders - $closed,
			'sales'             => 1000.0,
			'aov'               => 100.0,
			'products'          => $products,
			'categories'        => $categories,
			'payments'          => $payments,
			'channels'          => $channels,
			'campaigns'         => $campaigns,
		);
	}

	private function product( int $id, int $qty, float $total ): array {
		return array(
			'id'    => $id,
			'name'  => 'P' . $id,
			'qty'   => $qty,
			'total' => $total,
		);
	}

	private function compared(): Period {
		$timezone = new DateTimeZone( 'Europe/Bucharest' );

		return new Period( new DateTimeImmutable( '2026-09-28', $timezone ), new DateTimeImmutable( '2026-10-05', $timezone ) );
	}

	public function test_every_section_is_compared(): void {
		$now    = $this->stats_result(
			10,
			array( $this->product( 1, 9, 90 ), $this->product( 2, 8, 80 ), $this->product( 6, 7, 70 ), $this->product( 3, 6, 60 ), $this->product( 4, 5, 50 ), $this->product( 5, 1, 10 ) ),
			array(
				'Ramburs' => 6,
				'Card'    => 4,
			),
			array(
				'Direct'          => 7,
				'Organic: Google' => 3,
			),
			array( 'toamna' => 2 ),
			1
		);
		$before = $this->stats_result(
			8,
			array( $this->product( 1, 4, 40 ), $this->product( 2, 3, 30 ), $this->product( 5, 2, 20 ), $this->product( 3, 1, 10 ), $this->product( 4, 1, 5 ) ),
			array(
				'Ramburs' => 4,
				'Card'    => 4,
			),
			array(
				'Direct'                 => 4,
				'Referral: Facebook.com' => 4,
			),
			array(),
			0
		);

		$html = html_entity_decode( Report::html( $now, $this->compared(), $before, Report::SECTIONS, true ) );

		self::assertStringContainsString( 'Changes are against 28 Sep – 4 Oct 2026.', $html );
		self::assertStringContainsString( "▲\u{00A0}+2 (+25%)", $html );
		self::assertStringContainsString( 'Cancelled, refunded or failed', $html );
		self::assertStringContainsString( 'Units before', $html );
		self::assertStringContainsString( 'Sales before', $html );
		self::assertStringContainsString( 'New in the top 5', $html );
		self::assertStringContainsString( 'Dropped out of the top 5: P5 (was #3).', $html );
		self::assertStringContainsString( 'Dropped out of the top 5: Cat P5 (was #3).', $html );
		self::assertStringContainsString( "▲\u{00A0}+10.0\u{00A0}pp", $html );
		self::assertStringContainsString( "▼\u{00A0}−10.0\u{00A0}pp", $html );
		self::assertStringContainsString( 'Dropped out of the top 5: Referral: Facebook.com (was #2).', $html );
		self::assertStringContainsString( "▲\u{00A0}+20.0\u{00A0}pp", $html );
		self::assertStringNotContainsString( '(from', $html );
	}

	public function test_without_a_comparison_there_are_no_comparison_columns(): void {
		$now  = $this->stats_result( 2, array( $this->product( 1, 2, 20 ) ), array( 'Card' => 2 ), array( 'Direct' => 2 ) );
		$html = Report::html( $now, null, null, Report::SECTIONS, true );

		self::assertStringNotContainsString( 'Changes are against', $html );
		self::assertStringNotContainsString( 'before', $html );
		self::assertStringNotContainsString( 'Change', $html );
		self::assertStringNotContainsString( '▲', $html );
	}

	public function test_an_earlier_period_without_orders_is_not_compared_with(): void {
		$now    = $this->stats_result( 2, array( $this->product( 1, 2, 20 ) ), array( 'Card' => 2 ), array( 'Direct' => 2 ) );
		$before = $this->stats_result( 0, array(), array(), array() );
		$html   = Report::html( $now, $this->compared(), $before, Report::SECTIONS, true );

		self::assertStringContainsString( 'No orders in 28 Sep – 4 Oct 2026 to compare with.', $html );
		self::assertStringNotContainsString( '▲', $html );
		self::assertStringNotContainsString( '∞', $html );
	}

	public function test_a_week_without_orders_says_so_once(): void {
		$now    = $this->stats_result( 0, array(), array(), array() );
		$before = $this->stats_result( 3, array( $this->product( 1, 3, 30 ) ), array( 'Card' => 3 ), array( 'Direct' => 3 ) );
		$html   = Report::html( $now, $this->compared(), $before, Report::SECTIONS, true );

		self::assertSame( 1, substr_count( $html, 'No orders in this period.' ) );
		self::assertStringNotContainsString( 'No paid orders', $html );
		self::assertStringContainsString( "▼\u{00A0}−3 (−100%)", $html );
	}

	public function test_slices_keep_the_top_three_and_group_the_rest(): void {
		$slices = Report::slices(
			array(
				'Card'            => 50,
				'Ramburs'         => 30,
				'Transfer bancar' => 10,
				'PayPal'          => 6,
				'Voucher'         => 4,
			),
			3
		);

		self::assertSame( array( 'Card', 'Ramburs', 'Transfer bancar', '' ), array_column( $slices, 'name' ) );
		self::assertSame( array( false, false, false, true ), array_column( $slices, 'other' ) );
		self::assertSame( 10, $slices[3]['count'] );
		self::assertSame( 2, $slices[3]['methods'] );
		self::assertSame( array( 50.0, 30.0, 10.0, 10.0 ), array_column( $slices, 'percent' ) );
	}

	public function test_slice_widths_sum_to_a_full_bar(): void {
		$slices = Report::slices(
			array(
				'A' => 1,
				'B' => 1,
				'C' => 1,
			),
			3
		);

		self::assertSame( 100, array_sum( array_column( $slices, 'width' ) ) );
		self::assertSame( 33.3, $slices[0]['percent'] );
		self::assertCount( 3, $slices );
	}

	public function test_a_tiny_slice_stays_visible(): void {
		$slices = Report::slices(
			array(
				'Card'    => 998,
				'Ramburs' => 1,
				'PayPal'  => 1,
			),
			3
		);

		self::assertSame( array( 98, 1, 1 ), array_column( $slices, 'width' ) );
	}

	public function test_no_orders_means_no_slices(): void {
		self::assertSame( array(), Report::slices( array(), 3 ) );
		self::assertSame( array(), Report::slices( array( 'Card' => 0 ), 3 ) );
	}
}
