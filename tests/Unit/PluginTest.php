<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit;

use Brain\Monkey\Functions;
use RomCommerce\Plugin;
use RomCommerce\Tests\TestCase;

final class PluginTest extends TestCase {

	public function test_load_textdomain_registers_the_bundled_languages_directory(): void {
		Functions\when( 'plugin_basename' )->justReturn( 'romcommerce/romcommerce.php' );
		Functions\expect( 'load_plugin_textdomain' )->once()->with( 'romcommerce', false, 'romcommerce/languages' );

		Plugin::instance()->load_textdomain();
	}
}
