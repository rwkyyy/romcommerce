<?php

declare(strict_types=1);

namespace RomCommerce\Tools;

use InvalidArgumentException;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Lite's abilities (WordPress Abilities API, core since 6.9), mirroring the
 * WP-CLI commands through Operations. Only registered where the API exists;
 * the plugin's floor is WP 6.0. Every ability needs manage_woocommerce, and
 * they are shown in the REST API so MCP clients can use them through the
 * MCP Adapter, which RomCommerce does not bundle.
 */
final class Abilities {

	private const CATEGORY = 'romcommerce';

	private const CAPABILITY = 'manage_woocommerce';

	/** Time budget per run-job call, well inside a typical 30 s PHP request limit. */
	private const JOB_SECONDS = 20;

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
	}

	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'RomCommerce', 'romcommerce' ),
				'description' => __( 'RomCommerce Lite: Romanian commerce tools for WooCommerce.', 'romcommerce' ),
			)
		);
	}

	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		foreach ( self::definitions() as $name => $definition ) {
			wp_register_ability( $name, $definition );
		}
	}

	public static function can_manage(): bool {
		return current_user_can( self::CAPABILITY );
	}

	/** @return array<string, array<string, mixed>> */
	public static function definitions(): array {
		$permission = array( self::class, 'can_manage' );

		return array(
			'romcommerce/get-status'         => array(
				'label'               => __( 'Get RomCommerce status', 'romcommerce' ),
				'description'         => __( 'Returns the RomCommerce and WooCommerce versions, whether orders use HPOS, whether the cart and checkout are blocks, and the enabled modules.', 'romcommerce' ),
				'category'            => self::CATEGORY,
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static function () {
					return Operations::status();
				},
				'permission_callback' => $permission,
				'meta'                => self::meta( true, false, true ),
			),
			'romcommerce/list-modules'       => array(
				'label'               => __( 'List RomCommerce modules', 'romcommerce' ),
				'description'         => __( 'Lists every RomCommerce Lite module with its id, title, category and whether it is enabled.', 'romcommerce' ),
				'category'            => self::CATEGORY,
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'       => array( 'type' => 'string' ),
							'title'    => array( 'type' => 'string' ),
							'category' => array( 'type' => 'string' ),
							'enabled'  => array( 'type' => 'boolean' ),
						),
					),
				),
				'execute_callback'    => static function () {
					return Operations::modules();
				},
				'permission_callback' => $permission,
				'meta'                => self::meta( true, false, true ),
			),
			'romcommerce/set-module-enabled' => array(
				'label'               => __( 'Enable or disable a RomCommerce module', 'romcommerce' ),
				'description'         => __( 'Switches one RomCommerce Lite module on or off by its id (see list-modules). Some modules can only be switched on from their own settings pane.', 'romcommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array(
							'type'    => 'string',
							'pattern' => '^[a-z0-9-]+$',
						),
						'enabled' => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'id', 'enabled' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static function ( $input ) {
					return self::guard(
						static function () use ( $input ) {
							return Operations::set_module( (string) ( $input['id'] ?? '' ), (bool) ( $input['enabled'] ?? false ) );
						}
					);
				},
				'permission_callback' => $permission,
				'meta'                => self::meta( false, false, true ),
			),
			'romcommerce/run-job'            => array(
				'label'               => __( 'Run a RomCommerce background job now', 'romcommerce' ),
				'description'         => __( 'Runs one of RomCommerce Lite\'s background jobs now: maof-phone-index, price-history-baseline, sale-category-resync, coupon-cleanup or store-report (emails the weekly report for the last full week). The first two work in batches for up to 20 seconds per call; call again until "complete" is true. Coupon cleanup permanently deletes trashed coupons when that option is switched on.', 'romcommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'job' => array(
							'type' => 'string',
							'enum' => array_keys( Operations::JOBS ),
						),
					),
					'required'             => array( 'job' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static function ( $input ) {
					return self::guard(
						static function () use ( $input ) {
							return Operations::run_job( (string) ( $input['job'] ?? '' ), self::JOB_SECONDS );
						}
					);
				},
				'permission_callback' => $permission,
				'meta'                => self::meta( false, true, false ),
			),
			'romcommerce/import-vat-rates'   => array(
				'label'               => __( 'Import EU VAT rates', 'romcommerce' ),
				'description'         => __( 'Writes the bundled VAT rates of the given EU countries into WooCommerce\'s tax tables. Rows RomCommerce created earlier are updated; rows the merchant made are never changed.', 'romcommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'countries' => array(
							'type'  => 'array',
							'items' => array(
								'type'    => 'string',
								'pattern' => '^[A-Za-z]{2}$',
							),
						),
						'all'       => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static function ( $input ) {
					return self::guard(
						static function () use ( $input ) {
							return Operations::import_vat_rates( array_map( 'strval', (array) ( $input['countries'] ?? array() ) ), ! empty( $input['all'] ) );
						}
					);
				},
				'permission_callback' => $permission,
				'meta'                => self::meta( false, false, true ),
			),
		);
	}

	/**
	 * @param callable(): array<string, mixed> $operation
	 * @return array<string, mixed>|WP_Error
	 */
	private static function guard( callable $operation ) {
		try {
			return $operation();
		} catch ( InvalidArgumentException $exception ) {
			return new WP_Error( 'romcommerce_invalid', $exception->getMessage() );
		}
	}

	/** @return array<string, mixed> */
	private static function meta( bool $readonly, bool $destructive, bool $idempotent ): array {
		return array(
			'annotations'  => array(
				'readonly'    => $readonly,
				'destructive' => $destructive,
				'idempotent'  => $idempotent,
			),
			'show_in_rest' => true,
		);
	}
}
