<?php

declare(strict_types=1);

namespace RomCommerce\Tools;

use InvalidArgumentException;
use RomCommerce\Admin\ModuleRegistry;
use RomCommerce\Modules\CouponCleanup\Module as CouponCleanup;
use RomCommerce\Modules\Maof\PhoneIndex;
use RomCommerce\Modules\PriceHistory\Baseline;
use RomCommerce\Modules\PriceHistory\Module as PriceHistory;
use RomCommerce\Modules\SaleCategorySync\Module as SaleCategorySync;
use RomCommerce\Modules\StoreReport\Module as StoreReport;
use RomCommerce\Modules\VatRates\Importer;
use RomCommerce\Modules\VatRates\Module as VatRatesModule;
use RomCommerce\Modules\VatRates\Rates;
use RomCommerce\Modules\VatRates\WooTaxRateStore;
use RomCommerce\Plugin;
use RomCommerce\Settings;
use RomCommerce\Support\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * What Lite's WP-CLI commands and abilities can do, in one place so the two
 * surfaces stay identical: read status and modules, switch modules on and
 * off, run Lite's own background jobs now, and import EU VAT rates. Pro
 * registers its own, separate set for Pro features (owner, 2026-10-09).
 *
 * Callers check the capability (abilities) or run as a trusted shell user
 * (WP-CLI); these methods validate every input themselves and throw
 * InvalidArgumentException with a message fit to show.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages reach WP-CLI output and REST WP_Error JSON only, never HTML.
final class Operations {

	/** Job id => module that must be enabled for it. */
	public const JOBS = array(
		'maof-phone-index'       => 'maof',
		'price-history-baseline' => 'price-history',
		'sale-category-resync'   => 'sale-category-sync',
		'coupon-cleanup'         => 'coupon-cleanup',
		'store-report'           => 'store-report',
	);

	/** Safety stop for the batch loops; a batch is 100 orders or products. */
	private const MAX_BATCHES = 10000;

	/**
	 * Only Lite's own modules: Pro registers its modules into the same loader
	 * but brings its own commands and abilities (owner, 2026-10-09).
	 */
	private static function is_lite( string $id ): bool {
		$meta = ModuleRegistry::get( $id );

		return null !== $meta && 'lite' === $meta['tier'];
	}

	/** @return array<string, mixed> */
	public static function status(): array {
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		return array(
			'version'             => ROMCOMMERCE_VERSION,
			'woocommerce_version' => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'hpos'                => $hpos,
			'cart_uses_block'     => Blocks::cart_uses_block(),
			'checkout_uses_block' => Blocks::checkout_uses_block(),
			'enabled_modules'     => array_values( array_keys( array_filter( array_column( self::modules(), 'enabled', 'id' ) ) ) ),
		);
	}

	/** @return array<int, array{id: string, title: string, category: string, enabled: bool}> */
	public static function modules(): array {
		$rows = array();

		foreach ( Plugin::instance()->modules()->all() as $id => $module ) {
			if ( ! self::is_lite( (string) $id ) ) {
				continue;
			}

			$rows[] = array(
				'id'       => (string) $id,
				'title'    => $module->title(),
				'category' => (string) ModuleRegistry::get( (string) $id )['category'],
				'enabled'  => $module->is_enabled(),
			);
		}

		return $rows;
	}

	/**
	 * @return array{id: string, enabled: bool}
	 * @throws InvalidArgumentException For an unknown module, or one only its own pane may switch on.
	 */
	public static function set_module( string $id, bool $enabled ): array {
		if ( null === Plugin::instance()->modules()->get( $id ) || ! self::is_lite( $id ) ) {
			/* translators: %s: module id */
			throw new InvalidArgumentException( sprintf( __( 'Unknown module: %s', 'romcommerce' ), $id ) );
		}

		if ( $enabled && ModuleRegistry::pane_only( $id ) ) {
			/* translators: %s: module id */
			throw new InvalidArgumentException( sprintf( __( 'The %s module can only be switched on from its settings pane.', 'romcommerce' ), $id ) );
		}

		Settings::set_enabled( $id, $enabled );

		return array(
			'id'      => $id,
			'enabled' => $enabled,
		);
	}

	/**
	 * Runs one of Lite's background jobs now: to completion, or for about
	 * $seconds when given. The batch jobs keep their progress, so a time-boxed
	 * run (an ability over REST, under PHP's request time limit) is continued
	 * by calling again until `complete` is true.
	 *
	 * @param int $seconds Time budget for the batch jobs; 0 for none (WP-CLI).
	 * @return array<string, mixed> What the job did.
	 * @throws InvalidArgumentException For an unknown job, or one whose module is off.
	 */
	public static function run_job( string $job, int $seconds = 0 ): array {
		if ( ! isset( self::JOBS[ $job ] ) ) {
			/* translators: 1: job id, 2: comma-separated list of job ids */
			throw new InvalidArgumentException( sprintf( __( 'Unknown job: %1$s. Available: %2$s', 'romcommerce' ), $job, implode( ', ', array_keys( self::JOBS ) ) ) );
		}

		$module = Plugin::instance()->modules()->get( self::JOBS[ $job ] );
		if ( null === $module || ! $module->is_enabled() ) {
			/* translators: %s: module id */
			throw new InvalidArgumentException( sprintf( __( 'Switch on the %s module first.', 'romcommerce' ), self::JOBS[ $job ] ) );
		}

		$deadline = $seconds > 0 ? microtime( true ) + $seconds : 0.0;
		$in_time  = static function () use ( $deadline ): bool {
			return 0.0 === $deadline || microtime( true ) < $deadline;
		};

		switch ( $job ) {
			case 'maof-phone-index':
				$index   = new PhoneIndex();
				$batches = 0;
				while ( ! PhoneIndex::progress()['complete'] && $batches < self::MAX_BATCHES && $in_time() ) {
					$index->backfill_batch();
					++$batches;
				}
				return array( 'batches' => $batches ) + PhoneIndex::progress();

			case 'price-history-baseline':
				if ( ! $module instanceof PriceHistory ) {
					break;
				}
				$baseline = new Baseline( $module );
				$batches  = 0;
				while ( ! $baseline->is_complete() && $batches < self::MAX_BATCHES && $in_time() ) {
					$baseline->run_batch();
					++$batches;
				}
				return array(
					'batches'  => $batches,
					'complete' => $baseline->is_complete(),
				);

			case 'sale-category-resync':
				if ( $module instanceof SaleCategorySync ) {
					$module->resync_all();
					return array( 'resynced' => true );
				}
				break;

			case 'coupon-cleanup':
				if ( $module instanceof CouponCleanup ) {
					return $module->run_now();
				}
				break;

			case 'store-report':
				if ( $module instanceof StoreReport ) {
					return array( 'sent' => $module->send_now() );
				}
				break;
		}

		return array();
	}

	/**
	 * @param array<int, string> $countries EU country codes; the VAT rates module validates them.
	 * @return array{add: array<string, float>, update: array<string, array{from: float, to: float}>, kept: array<int, string>}
	 * @throws InvalidArgumentException When the module is off, no valid country is given, or the bundle is broken.
	 */
	public static function import_vat_rates( array $countries, bool $all ): array {
		$module = Plugin::instance()->modules()->get( 'vat-rates' );
		if ( null === $module || ! $module->is_enabled() ) {
			throw new InvalidArgumentException( __( 'Switch on the vat-rates module first.', 'romcommerce' ) );
		}

		$selected = VatRatesModule::selected_countries( $all, $countries );
		if ( array() === $selected ) {
			throw new InvalidArgumentException( __( 'Give at least one EU country code, or --all.', 'romcommerce' ) );
		}

		return ( new Importer( new WooTaxRateStore() ) )->import( Rates::bundled(), $selected, VatRatesModule::rate_name(), VatRatesModule::class_names() );
	}
}
