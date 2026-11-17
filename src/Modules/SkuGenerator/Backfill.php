<?php

declare(strict_types=1);

namespace RomCommerce\Modules\SkuGenerator;

use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Gives existing products and variations without a SKU one, after the
 * merchant presses the pane's button (or runs the sku-fill job). The pane
 * request does the first batch itself, so a small catalogue is done at once;
 * the rest continues in WP-cron batches. A cursor on the post ID means every
 * product is visited once per run, even one that can't get a SKU.
 *
 * Products are posts on every WooCommerce version (HPOS only moves orders),
 * so finding the missing SKUs is one query on posts and postmeta.
 */
final class Backfill {

	public const HOOK = 'romcommerce_sku_generator_fill';

	private const STATE_OPTION = 'romcommerce_sku_generator_fill';

	private const BATCH_SIZE = 50;

	/** @var Generator */
	private $generator;

	public function __construct( Generator $generator ) {
		$this->generator = $generator;
	}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run_batch' ) );
		add_action( 'romcommerce/module_toggled', array( $this, 'on_module_toggled' ), 10, 2 );
	}

	/** Starts a run, or continues the one in progress, with one batch now. */
	public function start(): void {
		if ( ! $this->state()['running'] ) {
			$this->save_state( true, 0, 0 );
		}

		$this->run_batch();
	}

	public function run_batch(): void {
		$state = $this->state();
		if ( ! $state['running'] ) {
			return;
		}

		wp_clear_scheduled_hook( self::HOOK );

		$ids     = $this->missing( $state['last_id'], self::BATCH_SIZE );
		$last_id = $state['last_id'];
		$filled  = $state['filled'];

		foreach ( $ids as $id ) {
			$last_id = $id;
			$product = wc_get_product( $id );
			if ( ! $product instanceof WC_Product || ! $this->generator->assign( $product ) ) {
				continue;
			}

			$product->save();
			$filled += 1 + $this->generator->fill_variations( $product );
		}

		$running = count( $ids ) === self::BATCH_SIZE;
		$this->save_state( $running, $last_id, $filled );

		if ( $running ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
	}

	/** @return array{running: bool, last_id: int, filled: int} */
	public function state(): array {
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		return array(
			'running' => ! empty( $state['running'] ),
			'last_id' => max( 0, (int) ( $state['last_id'] ?? 0 ) ),
			'filled'  => max( 0, (int) ( $state['filled'] ?? 0 ) ),
		);
	}

	/** Products, and variations unless they are set to get no SKU, still without one. */
	public function count_missing(): int {
		global $wpdb;

		$types = $this->post_types();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin-only count shown in the settings pane.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku' WHERE p.post_type IN (%s, %s) AND p.post_status NOT IN ('trash', 'auto-draft') AND ( m.meta_value IS NULL OR m.meta_value = '' )", $types[0], $types[1] ) );
	}

	public function on_module_toggled( string $module_id, bool $enabled ): void {
		if ( 'sku-generator' !== $module_id || $enabled ) {
			return;
		}

		wp_clear_scheduled_hook( self::HOOK );
		delete_option( self::STATE_OPTION );
	}

	/** @return array<int, int> */
	private function missing( int $after_id, int $limit ): array {
		global $wpdb;

		$types = $this->post_types();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- background batch over a cursor, nothing to cache.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku' WHERE p.post_type IN (%s, %s) AND p.post_status NOT IN ('trash', 'auto-draft') AND ( m.meta_value IS NULL OR m.meta_value = '' ) AND p.ID > %d ORDER BY p.ID ASC LIMIT %d", $types[0], $types[1], $after_id, $limit ) );

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Always two, so both queries keep a fixed placeholder list: without
	 * variations, "product" fills both.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function post_types(): array {
		return 'none' === $this->generator->variation_mode() ? array( 'product', 'product' ) : array( 'product', 'product_variation' );
	}

	private function save_state( bool $running, int $last_id, int $filled ): void {
		update_option(
			self::STATE_OPTION,
			array(
				'running' => $running,
				'last_id' => $last_id,
				'filled'  => $filled,
			),
			false
		);
	}
}
