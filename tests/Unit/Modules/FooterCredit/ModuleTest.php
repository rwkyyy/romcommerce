<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\FooterCredit;

use Brain\Monkey\Functions;
use RomCommerce\Modules\FooterCredit\Module;
use RomCommerce\Tests\TestCase;

final class ModuleTest extends TestCase {

	public function test_off_until_the_merchant_switches_it_on(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		self::assertFalse( ( new Module() )->is_enabled() );
	}

	public function test_the_credit_links_to_romcommerce_with_nofollow(): void {
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_kses' )->returnArg();

		ob_start();
		( new Module() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Magazin realizat cu <a href="https://romcommerce.ro/" rel="nofollow">RomCommerce</a>', $html );
		self::assertStringContainsString( 'text-align:center', $html );
	}
}
