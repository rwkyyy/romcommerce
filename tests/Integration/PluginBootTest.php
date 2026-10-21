<?php
/**
 * @package RomCommerce
 */

declare(strict_types=1);

namespace RomCommerce\Tests\Integration;

use Automattic\WooCommerce\Utilities\OrderUtil;
use RomCommerce\Plugin;

final class PluginBootTest extends TestCase {

	public function test_every_registered_module_boots_against_real_woocommerce(): void {
		$modules = Plugin::instance()->modules()->all();

		$this->assertCount( 29, $modules, 'A module was added or removed: update romcommerce_tests_enable_all_modules() too.' );

		foreach ( $modules as $id => $module ) {
			$this->assertTrue( $module->is_enabled(), "Module {$id} should be enabled by the test bootstrap." );
		}
	}

	/**
	 * Guards the matrix itself: if this fails, the HPOS / legacy CI jobs are
	 * silently testing the same storage backend twice.
	 */
	public function test_order_storage_matches_the_requested_mode(): void {
		if ( ! class_exists( OrderUtil::class ) ) {
			$this->assertFalse( romcommerce_tests_hpos(), 'HPOS requested on a WooCommerce without OrderUtil.' );
			return;
		}

		$this->assertSame( romcommerce_tests_hpos(), OrderUtil::custom_orders_table_usage_is_enabled() );
	}

	/**
	 * Mirrors a site whose locale is ro_RO: the locale is forced, then the
	 * plugin's textdomain is (re)loaded the way init does. The WP test suite
	 * points WP_LANG_DIR at its own fixture folder with no ro_RO core files,
	 * so switch_to_locale() can't be used here.
	 */
	public function test_bundled_romanian_translation_loads(): void {
		$force_ro = static function (): string {
			return 'ro_RO';
		};

		add_filter( 'determine_locale', $force_ro );
		add_filter( 'locale', $force_ro );
		unload_textdomain( 'romcommerce', true );
		Plugin::instance()->load_textdomain();

		try {
			$this->assertSame( 'Setările au fost salvate.', __( 'Settings saved.', 'romcommerce' ) );
			$this->assertSame( '%d de module', _n( '%d module', '%d modules', 20, 'romcommerce' ) );
			$this->assertSame( 'Conformitate și legal', \RomCommerce\Admin\ModuleRegistry::categories()['compliance-legal'] );
			$this->assertSame( 'Listă de verificare a conformității', \RomCommerce\Admin\ModuleRegistry::get( 'compliance-checklist' )['label'] );
		} finally {
			remove_filter( 'determine_locale', $force_ro );
			remove_filter( 'locale', $force_ro );
			unload_textdomain( 'romcommerce', true );
			Plugin::instance()->load_textdomain();
		}
	}
}
