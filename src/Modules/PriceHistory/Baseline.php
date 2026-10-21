<?php

declare(strict_types=1);

namespace RomCommerce\Modules\PriceHistory;

defined( 'ABSPATH' ) || exit;

/**
 * Records every product's current price once, as the log's starting point.
 * The log otherwise only learns a price when the product is next saved, so a
 * shop that turns the module on mid-sale would lose that sale's price entirely
 * once it ends, and a later promotion's reference would ignore it.
 *
 * Runs in WP-cron batches over every product and variation, through the same
 * log_price() a save uses, so a product whose price is already on record gets
 * nothing new. The run restarts when the module is switched back on, which
 * also catches up prices changed while it was off.
 */
final class Baseline {

	public const HOOK = 'romcommerce_price_history_baseline';

	private const STATE_OPTION = 'romcommerce_price_history_baseline';

	private const BATCH_SIZE = 100;

	/** @var Module */
	private $module;

	public function __construct( Module $module ) {
		$this->module = $module;
	}

	public function register(): void {
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( self::HOOK, array( $this, 'run_batch' ) );
		add_action( 'romcommerce/module_toggled', array( $this, 'on_module_toggled' ), 10, 2 );
	}

	/**
	 * Checked on init, like the MAOF phone index: the file-deploy workflow
	 * never fires an activation hook. One autoloaded option read once complete.
	 */
	public function schedule(): void {
		if ( $this->state()['complete'] || false !== wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time(), self::HOOK );
	}

	public function is_complete(): bool {
		return $this->state()['complete'];
	}

	public function run_batch(): void {
		$state = $this->state();
		if ( $state['complete'] ) {
			return;
		}

		// Cron runs on front-end requests too, where admin_init never fired.
		Log::ensure_table();

		$ids = wc_get_products(
			array(
				'type'    => array_merge( array_keys( wc_get_product_types() ), array( 'variation' ) ),
				'limit'   => self::BATCH_SIZE,
				'page'    => $state['page'],
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'ids',
			)
		);
		$ids = is_array( $ids ) ? $ids : array();

		foreach ( $ids as $id ) {
			$this->module->log_price( (int) $id );
		}

		$complete = count( $ids ) < self::BATCH_SIZE;
		update_option(
			self::STATE_OPTION,
			array(
				'complete' => $complete,
				'page'     => $state['page'] + 1,
			),
			true
		);

		if ( ! $complete ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
	}

	/**
	 * Reset on disable: that request still has the module booted, so this
	 * listener is attached, and the next request after re-enabling reschedules.
	 */
	public function on_module_toggled( string $module_id, bool $enabled ): void {
		if ( 'price-history' !== $module_id || $enabled ) {
			return;
		}

		wp_clear_scheduled_hook( self::HOOK );
		delete_option( self::STATE_OPTION );
	}

	/** @return array{complete: bool, page: int} */
	private function state(): array {
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		return array(
			'complete' => ! empty( $state['complete'] ),
			'page'     => max( 1, (int) ( $state['page'] ?? 1 ) ),
		);
	}
}
