<?php

declare(strict_types=1);

namespace RomCommerce\Modules\StoreReport;

defined( 'ABSPATH' ) || exit;

/**
 * Adds up a period's orders into the report's numbers. Fed one order at a
 * time (see Collector), so a large week never sits in memory at once.
 *
 * - Every order placed in the period counts towards the total, whatever its
 *   status now.
 * - "On hold / waiting" holds every status that is neither completed,
 *   processing, nor cancelled / refunded / failed: on hold, pending payment
 *   and any custom status (owner, 2026-10-10).
 * - Sales, the average order value and the top products and categories count
 *   paid orders only (wc_get_is_paid_statuses()), net of refunds.
 *
 * Rankings come back complete, not cut to the report's top rows, so a row in
 * this week's top list can be found in the earlier period whatever its rank
 * was there.
 */
final class Stats {

	private const CLOSED = array( 'cancelled', 'refunded', 'failed' );

	/** @var array<int, string> */
	private array $paid_statuses;

	private int $orders = 0;

	private int $completed = 0;

	private int $processing = 0;

	private int $closed = 0;

	/** @var array<string, int> */
	private array $waiting = array();

	private int $paid = 0;

	private float $sales = 0.0;

	/** @var array<int, array{name: string, qty: int, total: float}> */
	private array $products = array();

	/** @var array<int, array{name: string, qty: int, total: float}> */
	private array $categories = array();

	/** @var array<string, int> */
	private array $payments = array();

	/** @var array<string, int> */
	private array $channels = array();

	/** @var array<string, int> */
	private array $campaigns = array();

	/** @param array<int, string> $paid_statuses Status slugs without "wc-". */
	public function __construct( array $paid_statuses ) {
		$this->paid_statuses = $paid_statuses;
	}

	/**
	 * @param array{status: string, total: float, payment: string, source_type: string, source: string, campaign: string, items: array<int, array{product_id: int, name: string, qty: int, total: float, categories: array<int, string>}>} $order
	 */
	public function add( array $order ): void {
		++$this->orders;
		$status = $order['status'];

		if ( 'completed' === $status ) {
			++$this->completed;
		} elseif ( 'processing' === $status ) {
			++$this->processing;
		} elseif ( in_array( $status, self::CLOSED, true ) ) {
			++$this->closed;
		} else {
			$this->waiting[ $status ] = ( $this->waiting[ $status ] ?? 0 ) + 1;
		}

		$payment                    = '' !== $order['payment'] ? $order['payment'] : __( 'No payment method', 'romcommerce' );
		$this->payments[ $payment ] = ( $this->payments[ $payment ] ?? 0 ) + 1;

		$channel                    = self::channel( $order['source_type'], $order['source'] );
		$this->channels[ $channel ] = ( $this->channels[ $channel ] ?? 0 ) + 1;

		if ( '' !== $order['campaign'] ) {
			$this->campaigns[ $order['campaign'] ] = ( $this->campaigns[ $order['campaign'] ] ?? 0 ) + 1;
		}

		if ( ! in_array( $status, $this->paid_statuses, true ) ) {
			return;
		}

		++$this->paid;
		$this->sales += $order['total'];

		foreach ( $order['items'] as $item ) {
			$this->tally( $this->products, $item['product_id'], $item['name'], $item['qty'], $item['total'] );
			foreach ( $item['categories'] as $term_id => $name ) {
				$this->tally( $this->categories, (int) $term_id, $name, $item['qty'], $item['total'] );
			}
		}
	}

	/**
	 * @return array{orders: int, completed: int, processing: int, waiting: int, waiting_by_status: array<string, int>, closed: int, paid_orders: int, sales: float, aov: float, products: array<int, array{id: int, name: string, qty: int, total: float}>, categories: array<int, array{id: int, name: string, qty: int, total: float}>, payments: array<string, int>, channels: array<string, int>, campaigns: array<string, int>}
	 */
	public function result(): array {
		arsort( $this->waiting );

		return array(
			'orders'            => $this->orders,
			'completed'         => $this->completed,
			'processing'        => $this->processing,
			'waiting'           => array_sum( $this->waiting ),
			'waiting_by_status' => $this->waiting,
			'closed'            => $this->closed,
			'paid_orders'       => $this->paid,
			'sales'             => round( $this->sales, 2 ),
			'aov'               => $this->paid > 0 ? round( $this->sales / $this->paid, 2 ) : 0.0,
			'products'          => self::ranked( $this->products, 'qty' ),
			'categories'        => self::ranked( $this->categories, 'total' ),
			'payments'          => self::ranked_counts( $this->payments ),
			'channels'          => self::ranked_counts( $this->channels ),
			'campaigns'         => self::ranked_counts( $this->campaigns ),
		);
	}

	/**
	 * The order's channel the way WooCommerce labels its origin: "Source:
	 * google" for UTM-tagged visits, "Organic: google", "Referral: facebook.com",
	 * or Direct / Web admin / Mobile app / Point of sale / Unknown.
	 */
	public static function channel( string $type, string $source ): string {
		$source = ucfirst( trim( $source, '()' ) );

		switch ( $type ) {
			case 'utm':
				/* translators: %s: traffic source, e.g. google */
				return sprintf( __( 'Source: %s', 'romcommerce' ), $source );
			case 'organic':
				/* translators: %s: search engine, e.g. Google */
				return sprintf( __( 'Organic: %s', 'romcommerce' ), $source );
			case 'referral':
				/* translators: %s: referring site, e.g. facebook.com */
				return sprintf( __( 'Referral: %s', 'romcommerce' ), $source );
			case 'typein':
				return __( 'Direct', 'romcommerce' );
			case 'admin':
				return __( 'Web admin', 'romcommerce' );
			case 'mobile_app':
				return __( 'Mobile app', 'romcommerce' );
			case 'pos':
				return __( 'Point of sale', 'romcommerce' );
			default:
				return __( 'Unknown', 'romcommerce' );
		}
	}

	/** @param array<int, array{name: string, qty: int, total: float}> $table */
	private function tally( array &$table, int $id, string $name, int $qty, float $total ): void {
		if ( ! isset( $table[ $id ] ) ) {
			$table[ $id ] = array(
				'name'  => $name,
				'qty'   => 0,
				'total' => 0.0,
			);
		}

		$table[ $id ]['qty']   += $qty;
		$table[ $id ]['total'] += $total;
	}

	/**
	 * Every row, best first by $key, the other measure breaking ties.
	 *
	 * @param array<int, array{name: string, qty: int, total: float}> $table
	 * @return array<int, array{id: int, name: string, qty: int, total: float}>
	 */
	private static function ranked( array $table, string $key ): array {
		$other = 'qty' === $key ? 'total' : 'qty';
		$rows  = array();
		foreach ( $table as $id => $row ) {
			$rows[] = array(
				'id'    => (int) $id,
				'name'  => $row['name'],
				'qty'   => $row['qty'],
				'total' => round( $row['total'], 2 ),
			);
		}

		usort(
			$rows,
			static function ( array $a, array $b ) use ( $key, $other ): int {
				return array( $b[ $key ], $b[ $other ], $a['name'] ) <=> array( $a[ $key ], $a[ $other ], $b['name'] );
			}
		);

		return $rows;
	}

	/**
	 * Most first, then by name.
	 *
	 * @param array<string, int> $counts
	 * @return array<string, int>
	 */
	private static function ranked_counts( array $counts ): array {
		uksort(
			$counts,
			static function ( $a, $b ) use ( $counts ): int {
				return array( $counts[ $b ], (string) $a ) <=> array( $counts[ $a ], (string) $b );
			}
		);

		return $counts;
	}
}
