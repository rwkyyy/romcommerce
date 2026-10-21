<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\StoreReport;

use Brain\Monkey\Functions;
use RomCommerce\Modules\StoreReport\Stats;
use RomCommerce\Tests\TestCase;

final class StatsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
	}

	/**
	 * @param array<int, array{product_id: int, name: string, qty: int, total: float, categories: array<int, string>}> $items
	 * @return array{status: string, total: float, payment: string, source_type: string, source: string, campaign: string, items: array<int, array{product_id: int, name: string, qty: int, total: float, categories: array<int, string>}>}
	 */
	private function order( string $status, float $total, string $payment = 'Ramburs', array $items = array(), string $type = 'typein', string $source = '', string $campaign = '' ): array {
		return array(
			'status'      => $status,
			'total'       => $total,
			'payment'     => $payment,
			'source_type' => $type,
			'source'      => $source,
			'campaign'    => $campaign,
			'items'       => $items,
		);
	}

	/** @return array{product_id: int, name: string, qty: int, total: float, categories: array<int, string>} */
	private function item( int $id, string $name, int $qty, float $total, array $categories = array() ): array {
		return array(
			'product_id' => $id,
			'name'       => $name,
			'qty'        => $qty,
			'total'      => $total,
			'categories' => $categories,
		);
	}

	public function test_orders_split_by_status_with_custom_statuses_counted_as_waiting(): void {
		$stats = new Stats( array( 'processing', 'completed' ) );
		$stats->add( $this->order( 'completed', 100 ) );
		$stats->add( $this->order( 'completed', 50 ) );
		$stats->add( $this->order( 'processing', 30 ) );
		$stats->add( $this->order( 'on-hold', 20 ) );
		$stats->add( $this->order( 'pending', 20 ) );
		$stats->add( $this->order( 'awaiting-pickup', 20 ) );
		$stats->add( $this->order( 'awaiting-pickup', 20 ) );
		$stats->add( $this->order( 'cancelled', 20 ) );

		$result = $stats->result();

		self::assertSame( 8, $result['orders'] );
		self::assertSame( 2, $result['completed'] );
		self::assertSame( 1, $result['processing'] );
		self::assertSame( 4, $result['waiting'] );
		self::assertSame(
			array(
				'awaiting-pickup' => 2,
				'on-hold'         => 1,
				'pending'         => 1,
			),
			$result['waiting_by_status']
		);
		self::assertSame( 1, $result['closed'] );
	}

	public function test_average_order_value_and_rankings_count_paid_orders_only(): void {
		$phones = array( 7 => 'Telefoane' );
		$cases  = array( 9 => 'Accesorii' );

		$stats = new Stats( array( 'processing', 'completed' ) );
		$stats->add( $this->order( 'completed', 300, 'Card', array( $this->item( 1, 'Telefon', 1, 250, $phones ), $this->item( 2, 'Husă', 2, 50, $cases ) ) ) );
		$stats->add( $this->order( 'processing', 100, 'Ramburs', array( $this->item( 2, 'Husă', 4, 100, $cases ) ) ) );
		$stats->add( $this->order( 'on-hold', 999, 'Transfer bancar', array( $this->item( 3, 'Tabletă', 9, 999, $phones ) ) ) );

		$result = $stats->result();

		self::assertSame( 2, $result['paid_orders'] );
		self::assertSame( 400.0, $result['sales'] );
		self::assertSame( 200.0, $result['aov'] );
		self::assertSame( array( 'Husă', 'Telefon' ), array_column( $result['products'], 'name' ) );
		self::assertSame( 6, $result['products'][0]['qty'] );
		self::assertSame( array( 'Telefoane', 'Accesorii' ), array_column( $result['categories'], 'name' ) );
		self::assertSame(
			array(
				'Card'            => 1,
				'Ramburs'         => 1,
				'Transfer bancar' => 1,
			),
			$result['payments']
		);
	}

	public function test_no_paid_orders_means_a_zero_average(): void {
		$stats = new Stats( array( 'processing', 'completed' ) );
		$stats->add( $this->order( 'pending', 50 ) );

		self::assertSame( 0.0, $stats->result()['aov'] );
	}

	public function test_rankings_are_complete_so_an_earlier_period_can_be_looked_up(): void {
		$stats = new Stats( array( 'completed' ) );
		for ( $i = 1; $i <= 7; $i++ ) {
			$stats->add( $this->order( 'completed', 10, 'Card', array( $this->item( $i, 'P' . $i, $i, 10.0 * $i, array( 100 + $i => 'C' . $i ) ) ), 'utm', 'src' . $i, 'camp' . $i ) );
		}

		$result = $stats->result();

		self::assertSame( array( 'P7', 'P6', 'P5', 'P4', 'P3', 'P2', 'P1' ), array_column( $result['products'], 'name' ) );
		self::assertSame( array( 7, 6, 5, 4, 3, 2, 1 ), array_column( $result['products'], 'id' ) );
		self::assertSame( array( 107, 106, 105, 104, 103, 102, 101 ), array_column( $result['categories'], 'id' ) );
		self::assertCount( 7, $result['channels'] );
		self::assertCount( 7, $result['campaigns'] );
	}

	public function test_count_rankings_are_most_first_then_by_name(): void {
		$stats = new Stats( array( 'completed' ) );
		$stats->add( $this->order( 'completed', 10, 'Transfer bancar' ) );
		$stats->add( $this->order( 'completed', 10, 'Card' ) );
		$stats->add( $this->order( 'completed', 10, 'Ramburs' ) );
		$stats->add( $this->order( 'completed', 10, 'Ramburs' ) );

		self::assertSame(
			array(
				'Ramburs'         => 2,
				'Card'            => 1,
				'Transfer bancar' => 1,
			),
			$stats->result()['payments']
		);
	}

	public function test_channels_follow_woocommerce_origin_labels(): void {
		self::assertSame( 'Source: Google', Stats::channel( 'utm', 'google' ) );
		self::assertSame( 'Organic: Google', Stats::channel( 'organic', 'google' ) );
		self::assertSame( 'Referral: Facebook.com', Stats::channel( 'referral', 'facebook.com' ) );
		self::assertSame( 'Direct', Stats::channel( 'typein', '(direct)' ) );
		self::assertSame( 'Unknown', Stats::channel( '', '' ) );
	}

	public function test_orders_without_a_campaign_are_not_listed_as_one(): void {
		$stats = new Stats( array( 'completed' ) );
		$stats->add( $this->order( 'completed', 10, 'Card', array(), 'utm', 'newsletter', 'black-friday' ) );
		$stats->add( $this->order( 'completed', 10 ) );

		$result = $stats->result();

		self::assertSame( array( 'black-friday' => 1 ), $result['campaigns'] );
		self::assertSame(
			array(
				'Direct'             => 1,
				'Source: Newsletter' => 1,
			),
			$result['channels']
		);
	}
}
