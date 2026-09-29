<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit;

use Brain\Monkey\Functions;
use RomCommerce\Settings;
use RomCommerce\Tests\TestCase;

final class SettingsTest extends TestCase {

	/**
	 * A module never touched by the merchant (e.g. right after an update adds
	 * a new one) must read as disabled — a prior default of true meant every
	 * module looked "active" until explicitly turned off.
	 */
	public function test_is_enabled_defaults_to_false_for_an_untouched_module(): void {
		Functions\expect( 'get_option' )->andReturn( array() );

		self::assertFalse( Settings::is_enabled( 'some-module' ) );
	}

	public function test_is_enabled_respects_an_explicit_default_override(): void {
		Functions\expect( 'get_option' )->andReturn( array() );

		self::assertTrue( Settings::is_enabled( 'sal-pictogram', true ) );
	}

	public function test_is_enabled_respects_a_stored_value_over_either_default(): void {
		Functions\expect( 'get_option' )->andReturn( array( 'enabled_modules' => array( 'some-module' => false ) ) );

		self::assertFalse( Settings::is_enabled( 'some-module', true ) );
	}
}
