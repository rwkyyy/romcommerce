<?php

declare(strict_types=1);

namespace RomCommerce\Modules\StoreReport;

use WC_Order;
use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a period's orders through WooCommerce's CRUD layer (both order storage
 * backends), in batches, and feeds them to Stats. Runs only from the report's
 * cron event or the "send a test report" button, never on a shopper request.
 *
 * One instance collects both the reported and the compared period, so product
 * names and categories are looked up once per report, not once per period.
 */
final class Collector {

	private const BATCH = 100;

	/** @var array<int, array<int, string>> product id => category term id => name, for one run. */
	private array $categories = array();

	/** @var array<int, string> product id => current name, for one run. */
	private array $names = array();

	public function collect( Period $period ): Stats {
		$paid     = wc_get_is_paid_statuses();
		$stats    = new Stats( $paid );
		$statuses = $this->statuses();
		$prefix   = self::attribution_prefix();
		$page     = 1;

		do {
			$orders = wc_get_orders(
				array(
					'type'         => 'shop_order',
					'status'       => $statuses,
					'date_created' => $period->start->getTimestamp() . '...' . ( $period->end->getTimestamp() - 1 ),
					'limit'        => self::BATCH,
					'paged'        => $page,
					'orderby'      => 'ID',
					'order'        => 'ASC',
				)
			);

			$orders = is_array( $orders ) ? $orders : array();
			foreach ( $orders as $order ) {
				if ( $order instanceof WC_Order ) {
					$stats->add( $this->row( $order, $prefix, in_array( (string) $order->get_status(), $paid, true ) ) );
				}
			}

			$full = count( $orders ) === self::BATCH;
			++$page;
		} while ( $full );

		return $stats;
	}

	/**
	 * Whether WooCommerce records where orders come from (Order Attribution,
	 * WooCommerce 8.5+, on unless the merchant turned it off).
	 */
	public static function attribution_available(): bool {
		$features = '\Automattic\WooCommerce\Utilities\FeaturesUtil';

		return class_exists( '\Automattic\WooCommerce\Internal\Orders\OrderAttributionController' )
			&& class_exists( $features )
			&& $features::feature_is_enabled( 'order_attribution' );
	}

	/**
	 * Every order status except the block checkout's drafts, which aren't
	 * orders yet. Custom statuses registered through wc_order_statuses count.
	 *
	 * @return array<int, string>
	 */
	private function statuses(): array {
		return array_values(
			array_diff(
				array_map(
					static function ( string $status ): string {
						return 'wc-' === substr( $status, 0, 3 ) ? substr( $status, 3 ) : $status;
					},
					array_keys( wc_get_order_statuses() )
				),
				array( 'checkout-draft' )
			)
		);
	}

	/**
	 * Line items only for paid orders: Stats ranks nothing else, and skipping
	 * them spares the refund and product lookups on failed and pending orders.
	 *
	 * @return array{status: string, total: float, payment: string, source_type: string, source: string, campaign: string, items: array<int, array{product_id: int, name: string, qty: int, total: float, categories: array<int, string>}>}
	 */
	private function row( WC_Order $order, string $prefix, bool $paid ): array {
		$items = array();

		foreach ( $paid ? $order->get_items() : array() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product_id = (int) $item->get_product_id();
			$qty        = (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item( $item->get_id() );
			$total      = (float) $item->get_total() - (float) $order->get_total_refunded_for_item( $item->get_id() );

			$items[] = array(
				'product_id' => $product_id,
				'name'       => $this->product_name( $product_id, (string) $item->get_name() ),
				'qty'        => max( 0, $qty ),
				'total'      => $total,
				'categories' => $this->categories( $product_id ),
			);
		}

		return array(
			'status'      => (string) $order->get_status(),
			'total'       => (float) $order->get_total() - (float) $order->get_total_refunded(),
			'payment'     => (string) $order->get_payment_method_title(),
			'source_type' => (string) $order->get_meta( $prefix . 'source_type' ),
			'source'      => (string) $order->get_meta( $prefix . 'utm_source' ),
			'campaign'    => (string) $order->get_meta( $prefix . 'utm_campaign' ),
			'items'       => $items,
		);
	}

	/** The product's current name, so variations add up under their parent; the order line's name if it's gone. */
	private function product_name( int $product_id, string $fallback ): string {
		if ( $product_id <= 0 ) {
			return $fallback;
		}

		if ( ! isset( $this->names[ $product_id ] ) ) {
			$product                    = wc_get_product( $product_id );
			$this->names[ $product_id ] = $product ? (string) $product->get_name() : $fallback;
		}

		return $this->names[ $product_id ];
	}

	/** @return array<int, string> */
	private function categories( int $product_id ): array {
		if ( ! isset( $this->categories[ $product_id ] ) ) {
			$terms                           = $product_id > 0 ? get_the_terms( $product_id, 'product_cat' ) : array();
			$this->categories[ $product_id ] = array();
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$this->categories[ $product_id ][ (int) $term->term_id ] = (string) $term->name;
			}
		}

		return $this->categories[ $product_id ];
	}

	/**
	 * WooCommerce's attribution meta prefix ("_wc_order_attribution_"),
	 * through the same filter WooCommerce applies to it.
	 */
	private static function attribution_prefix(): string {
		$prefix = trim( (string) apply_filters( 'wc_order_attribution_tracking_field_prefix', 'wc_order_attribution_' ), '_' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter, read so a changed prefix still matches.

		return '_' . $prefix . '_';
	}
}
