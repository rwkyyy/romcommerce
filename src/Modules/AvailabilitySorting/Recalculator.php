<?php

declare(strict_types=1);

namespace RomCommerce\Modules\AvailabilitySorting;

use Closure;
use RomCommerce\Modules\PriceHistory\Reference;
use WC_Product;
use WC_Product_Variable;

defined( 'ABSPATH' ) || exit;

/**
 * Works out every published product's discount and keeps it in post meta for
 * the catalog sort. Runs in WP-cron batches over a cursor on the post ID, so
 * each product is visited once per run.
 *
 * The value is written with update_post_meta(), never through a product
 * save: a save would change the product's last modified date, which
 * Out-of-stock product cleanup counts from, and would wake every plugin
 * listening for product saves (feeds, stock syncs) once a day per product.
 */
final class Recalculator {

	/** The daily event, at the merchant's hour. */
	public const HOOK = 'romcommerce_discount_sorting';

	public const BATCH_HOOK = 'romcommerce_discount_sorting_batch';

	public const META_KEY = '_romcommerce_discount';

	public const MEASURE_PERCENT = 'percent';
	public const MEASURE_AMOUNT  = 'amount';
	public const MEASURE_OMNIBUS = 'omnibus';

	private const STATE_OPTION = 'romcommerce_discount_sorting_run';

	private const BATCH_SIZE = 100;

	/** @var Closure():string */
	private $measure;

	/**
	 * @param Closure():string $measure The saved measure, read at each batch.
	 */
	public function __construct( Closure $measure ) {
		$this->measure = $measure;
	}

	public function register(): void {
		add_action( self::BATCH_HOOK, array( $this, 'run_batch' ) );
	}

	/**
	 * Runs one batch now and the rest in the background. A run in progress
	 * continues where it was unless $fresh, which starts over (after the
	 * measure changes, every value has to be worked out again).
	 */
	public function start( bool $fresh = false ): void {
		if ( $fresh || ! $this->state()['running'] ) {
			$this->begin();
		}

		$this->run_batch();
	}

	/** Starts a run in the background only, for requests that shouldn't do the work. */
	public function queue(): void {
		$this->begin();
		wp_schedule_single_event( time(), self::BATCH_HOOK );
	}

	public function stop(): void {
		wp_clear_scheduled_hook( self::BATCH_HOOK );

		$state            = $this->state();
		$state['running'] = false;
		update_option( self::STATE_OPTION, $state, false );
	}

	public function run_batch(): void {
		$state = $this->state();
		if ( ! $state['running'] ) {
			return;
		}

		wp_clear_scheduled_hook( self::BATCH_HOOK );

		$measure = ( $this->measure )();
		$ids     = $this->products( $state['last_id'], self::BATCH_SIZE );

		foreach ( $ids as $id ) {
			$state['last_id'] = $id;

			$product = wc_get_product( $id );
			$value   = $product instanceof WC_Product ? self::best_discount( $product, $measure ) : 0.0;

			// Both read the meta cache wc_get_product() just filled and only
			// query when the value changed, so an unchanged catalogue costs no
			// writes.
			if ( $value > 0 ) {
				update_post_meta( $id, self::META_KEY, wc_format_decimal( $value, 2 ) );
				++$state['discounted'];
			} elseif ( '' !== get_post_meta( $id, self::META_KEY, true ) ) {
				delete_post_meta( $id, self::META_KEY );
			}
		}

		$state['running'] = count( $ids ) === self::BATCH_SIZE;

		if ( ! $state['running'] ) {
			$state['finished']   = time();
			$state['last_count'] = $state['discounted'];

			// The [products] shortcode caches each loop's IDs, in order, in a
			// transient keyed on this version. Bumping it, as WooCommerce does
			// when a product changes, makes the next request sort by the new
			// discounts instead of serving yesterday's order.
			if ( class_exists( 'WC_Cache_Helper' ) ) {
				\WC_Cache_Helper::get_transient_version( 'product_query', true );
			}
		}

		update_option( self::STATE_OPTION, $state, false );

		if ( $state['running'] ) {
			wp_schedule_single_event( time(), self::BATCH_HOOK );
		}
	}

	/**
	 * A variable product counts its biggest variation discount. Sale dates
	 * count: a scheduled sale that hasn't started yet is no discount.
	 */
	public static function best_discount( WC_Product $product, string $measure ): float {
		if ( self::MEASURE_OMNIBUS === $measure ) {
			return self::best_reduction( $product );
		}

		if ( $product instanceof WC_Product_Variable ) {
			// WooCommerce already sets a variation's sale price to its regular
			// price here when its sale isn't running.
			$prices = $product->get_variation_prices( false );
			$best   = 0.0;
			foreach ( (array) ( $prices['regular_price'] ?? array() ) as $variation_id => $regular ) {
				$best = max( $best, self::discount( (float) $regular, (float) ( $prices['sale_price'][ $variation_id ] ?? $regular ), $measure ) );
			}

			return $best;
		}

		if ( ! $product->is_on_sale() ) {
			return 0.0;
		}

		return self::discount( (float) $product->get_regular_price(), (float) $product->get_sale_price(), $measure );
	}

	/**
	 * The reduction Price history shows next to a sale, in percent: the
	 * current price against the lowest price of the 30 days before it took
	 * effect, so the order follows the reductions shoppers see. A sale with
	 * nothing on record before it, or that isn't below that lowest price,
	 * shows no reduction and gets none here.
	 */
	private static function best_reduction( WC_Product $product ): float {
		if ( ! $product->is_on_sale() ) {
			return 0.0;
		}

		if ( ! $product instanceof WC_Product_Variable ) {
			return '' === (string) $product->get_price() ? 0.0 : self::reduction( $product );
		}

		$prices = $product->get_variation_prices( false );
		$best   = 0.0;
		foreach ( (array) ( $prices['price'] ?? array() ) as $variation_id => $price ) {
			if ( (float) ( $prices['regular_price'][ $variation_id ] ?? 0 ) <= (float) $price ) {
				continue;
			}

			$variation = wc_get_product( $variation_id );
			if ( $variation instanceof WC_Product ) {
				$best = max( $best, self::reduction( $variation ) );
			}
		}

		return $best;
	}

	private static function reduction( WC_Product $product ): float {
		$reference = Reference::for_product( $product );

		return null === $reference ? 0.0 : self::discount( (float) $reference, (float) $product->get_price(), self::MEASURE_PERCENT );
	}

	public static function discount( float $regular, float $sale, string $measure ): float {
		if ( $regular <= 0 || $sale < 0 || $sale >= $regular ) {
			return 0.0;
		}

		return self::MEASURE_AMOUNT === $measure ? $regular - $sale : ( $regular - $sale ) / $regular * 100;
	}

	/** @return array{running: bool, last_id: int, discounted: int, finished: int, last_count: int} */
	public function state(): array {
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		return array(
			'running'    => ! empty( $state['running'] ),
			'last_id'    => max( 0, (int) ( $state['last_id'] ?? 0 ) ),
			'discounted' => max( 0, (int) ( $state['discounted'] ?? 0 ) ),
			'finished'   => max( 0, (int) ( $state['finished'] ?? 0 ) ),
			'last_count' => max( 0, (int) ( $state['last_count'] ?? 0 ) ),
		);
	}

	private function begin(): void {
		$state = $this->state();

		$state['running']    = true;
		$state['last_id']    = 0;
		$state['discounted'] = 0;

		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Products are posts on every WooCommerce version (HPOS only moves orders).
	 *
	 * @return array<int, int>
	 */
	private function products( int $after_id, int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- background batch over a cursor, nothing to cache.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT %d", $after_id, $limit ) );

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}
}
