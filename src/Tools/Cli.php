<?php

declare(strict_types=1);

namespace RomCommerce\Tools;

use InvalidArgumentException;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Manage RomCommerce Lite from the command line.
 *
 * ## EXAMPLES
 *
 *     wp romcommerce status
 *     wp romcommerce module list
 *     wp romcommerce module enable phone-validation vat-rates
 *     wp romcommerce run maof-phone-index
 *     wp romcommerce vat-rates import RO HU --yes
 */
final class Cli {

	public static function register(): void {
		WP_CLI::add_command( 'romcommerce', self::class );
	}

	/**
	 * Shows versions, order storage, cart/checkout type and enabled modules.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function status( array $args, array $assoc_args ): void {
		$status = Operations::status();

		if ( 'json' === ( $assoc_args['format'] ?? '' ) ) {
			WP_CLI::line( (string) wp_json_encode( $status ) );
			return;
		}

		$rows = array();
		foreach ( $status as $field => $value ) {
			$rows[] = array(
				'field' => $field,
				'value' => is_array( $value ) ? implode( ', ', $value ) : ( is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value ),
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'field', 'value' ) );
	}

	/**
	 * Lists, enables or disables modules.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list, enable or disable.
	 * ---
	 * options:
	 *   - list
	 *   - enable
	 *   - disable
	 * ---
	 *
	 * [<id>...]
	 * : Module ids, for enable and disable.
	 *
	 * [--format=<format>]
	 * : For list: table, json, csv or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp romcommerce module list --format=json
	 *     wp romcommerce module disable clear-cart
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function module( array $args, array $assoc_args ): void {
		$action = (string) array_shift( $args );

		if ( 'list' === $action ) {
			WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', Operations::modules(), array( 'id', 'title', 'category', 'enabled' ) );
			return;
		}

		if ( array() === $args ) {
			WP_CLI::error( 'Give at least one module id. See: wp romcommerce module list' );
		}

		foreach ( $args as $id ) {
			try {
				Operations::set_module( sanitize_key( $id ), 'enable' === $action );
				WP_CLI::success( sprintf( '%s %s.', $id, 'enable' === $action ? 'enabled' : 'disabled' ) );
			} catch ( InvalidArgumentException $exception ) {
				WP_CLI::warning( $exception->getMessage() );
			}
		}
	}

	/**
	 * Runs one of Lite's background jobs now, instead of waiting for WP-Cron.
	 *
	 * ## OPTIONS
	 *
	 * <job>
	 * : maof-phone-index (index order phones for MAOF), price-history-baseline
	 * (record current prices), sale-category-resync (resync the sale
	 * category), coupon-cleanup (run the enabled coupon cleanup jobs; the
	 * trash purge is permanent), store-report (email the weekly report for
	 * the last full week).
	 *
	 * [--yes]
	 * : Skip the confirmation for coupon-cleanup.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function run( array $args, array $assoc_args ): void {
		$job = sanitize_key( (string) ( $args[0] ?? '' ) );

		if ( 'coupon-cleanup' === $job ) {
			WP_CLI::confirm( 'Run coupon cleanup now? If emptying the coupon trash is switched on, those coupons are deleted permanently.', $assoc_args );
		}

		try {
			$result = Operations::run_job( $job );
		} catch ( InvalidArgumentException $exception ) {
			WP_CLI::error( $exception->getMessage() );
		}

		WP_CLI::success( $job . ': ' . wp_json_encode( $result ) );
	}

	/**
	 * Lists the bundled EU VAT rates or imports them into WooCommerce.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list or import.
	 * ---
	 * options:
	 *   - list
	 *   - import
	 * ---
	 *
	 * [<country>...]
	 * : EU country codes to import, e.g. RO HU.
	 *
	 * [--all]
	 * : Import all 27 EU member states.
	 *
	 * [--yes]
	 * : Skip the confirmation for import.
	 *
	 * [--format=<format>]
	 * : For list: table, json, csv or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand vat-rates
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function vat_rates( array $args, array $assoc_args ): void {
		$action = (string) array_shift( $args );

		if ( 'list' === $action ) {
			$rows = array();
			foreach ( \RomCommerce\Modules\VatRates\Rates::bundled()['rates'] as $country => $rate ) {
				$rows[] = array(
					'country'       => $country,
					'standard'      => $rate['standard'],
					'reduced'       => implode( ' / ', (array) $rate['reduced'] ),
					'super_reduced' => $rate['super_reduced'] ?? '',
					'parking'       => $rate['parking'] ?? '',
				);
			}
			WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'country', 'standard', 'reduced', 'super_reduced', 'parking' ) );
			return;
		}

		if ( array() === $args && ! isset( $assoc_args['all'] ) ) {
			WP_CLI::error( 'Give at least one EU country code, or --all.' );
		}

		WP_CLI::confirm( 'Write these VAT rates into WooCommerce\'s tax tables? Rows RomCommerce created earlier are updated; your own rows are left alone.', $assoc_args );

		try {
			$result = Operations::import_vat_rates( array_map( 'sanitize_text_field', $args ), isset( $assoc_args['all'] ) );
		} catch ( InvalidArgumentException $exception ) {
			WP_CLI::error( $exception->getMessage() );
		}

		WP_CLI::success( sprintf( '%d added, %d updated, %d left as they were (your own rates).', count( $result['add'] ), count( $result['update'] ), count( $result['kept'] ) ) );
	}
}
