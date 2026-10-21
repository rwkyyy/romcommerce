<?php
/**
 * Lite's abilities and the operations behind them (also used by WP-CLI),
 * against WordPress's real Abilities API.
 *
 * @package RomCommerce
 */

declare(strict_types=1);

namespace RomCommerce\Tests\Integration;

use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Plugin;
use RomCommerce\Settings;
use WP_Error;

final class AbilitiesTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API needs WordPress 6.9+.' );
		}
	}

	private function as_admin(): void {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
	}

	public function test_every_ability_is_registered_in_the_romcommerce_category(): void {
		foreach ( array( 'get-status', 'list-modules', 'set-module-enabled', 'run-job', 'import-vat-rates' ) as $name ) {
			$ability = wp_get_ability( 'romcommerce/' . $name );
			self::assertNotNull( $ability, $name );
			self::assertSame( 'romcommerce', $ability->get_category() );
		}
	}

	public function test_a_customer_cannot_run_them(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );

		$result = wp_get_ability( 'romcommerce/list-modules' )->execute();

		self::assertInstanceOf( WP_Error::class, $result );
	}

	public function test_list_modules_reports_what_is_enabled(): void {
		$this->as_admin();

		$modules = wp_get_ability( 'romcommerce/list-modules' )->execute();

		self::assertIsArray( $modules );
		$enabled = array_column( $modules, 'enabled', 'id' );
		self::assertTrue( $enabled['pf-pj-billing'] );
	}

	public function test_set_module_enabled_switches_a_module_off_and_on(): void {
		$this->as_admin();
		$ability = wp_get_ability( 'romcommerce/set-module-enabled' );

		$ability->execute(
			array(
				'id'      => 'clear-cart',
				'enabled' => false,
			)
		);
		self::assertFalse( Settings::is_enabled( 'clear-cart' ) );

		$ability->execute(
			array(
				'id'      => 'clear-cart',
				'enabled' => true,
			)
		);
		self::assertTrue( Settings::is_enabled( 'clear-cart' ) );
	}

	public function test_the_footer_credit_cannot_be_switched_on_remotely(): void {
		$this->as_admin();
		Settings::set_enabled( 'footer-credit', false );

		$result = wp_get_ability( 'romcommerce/set-module-enabled' )->execute(
			array(
				'id'      => 'footer-credit',
				'enabled' => true,
			)
		);

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertFalse( Settings::is_enabled( 'footer-credit' ) );
	}

	public function test_pro_modules_are_left_to_pro(): void {
		$this->as_admin();
		$loader = Plugin::instance()->modules();
		$loader->register(
			new class() implements ModuleInterface {
				public function id(): string {
					return 'gift-wrap';
				}

				public function title(): string {
					return 'Gift wrap';
				}

				public function is_enabled(): bool {
					return false;
				}

				public function boot(): void {
				}
			}
		);

		try {
			$listed = array_column( (array) wp_get_ability( 'romcommerce/list-modules' )->execute(), 'id' );
			$result = wp_get_ability( 'romcommerce/set-module-enabled' )->execute(
				array(
					'id'      => 'gift-wrap',
					'enabled' => true,
				)
			);
		} finally {
			$modules = new \ReflectionProperty( $loader, 'modules' );
			$modules->setAccessible( true );
			$registered = $modules->getValue( $loader );
			unset( $registered['gift-wrap'] );
			$modules->setValue( $loader, $registered );
		}

		self::assertNotContains( 'gift-wrap', $listed );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertFalse( Settings::is_enabled( 'gift-wrap' ) );
	}

	public function test_import_vat_rates_writes_the_rates(): void {
		$this->as_admin();

		$result = wp_get_ability( 'romcommerce/import-vat-rates' )->execute( array( 'countries' => array( 'HU' ) ) );

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'HU:', $result['add'] );
	}

	public function test_run_job_rejects_an_unknown_job_through_the_schema(): void {
		$this->as_admin();

		$result = wp_get_ability( 'romcommerce/run-job' )->execute( array( 'job' => 'drop-tables' ) );

		self::assertInstanceOf( WP_Error::class, $result );
	}

	public function test_run_job_runs_the_coupon_cleanup(): void {
		$this->as_admin();
		$expired = self::factory()->post->create(
			array(
				'post_type'   => 'shop_coupon',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $expired, 'date_expires', time() - DAY_IN_SECONDS );

		$result = wp_get_ability( 'romcommerce/run-job' )->execute( array( 'job' => 'coupon-cleanup' ) );

		self::assertIsArray( $result );
		self::assertSame( 'trash', get_post_status( $expired ) );
	}
}
