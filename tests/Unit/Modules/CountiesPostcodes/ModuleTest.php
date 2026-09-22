<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\CountiesPostcodes;

use Brain\Monkey\Functions;
use Mockery;
use RomCommerce\Modules\CountiesPostcodes\Module;
use RomCommerce\Tests\TestCase;
use WP_Error;

final class ModuleTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
	}

	public function test_valid_ro_postcode_adds_no_error(): void {
		$errors = Mockery::mock( WP_Error::class );
		$errors->shouldNotReceive( 'add' );

		( new Module() )->validate_postcodes(
			array(
				'billing_country'  => 'RO',
				'billing_postcode' => '010101',
			),
			$errors
		);
	}

	public function test_non_ro_country_is_not_checked(): void {
		$errors = Mockery::mock( WP_Error::class );
		$errors->shouldNotReceive( 'add' );

		( new Module() )->validate_postcodes(
			array(
				'billing_country'  => 'DE',
				'billing_postcode' => 'not-six-digits',
			),
			$errors
		);
	}

	public function test_invalid_ro_billing_postcode_adds_error(): void {
		$errors = Mockery::mock( WP_Error::class );
		$errors->shouldReceive( 'add' )
			->once()
			->with( 'romcommerce_postcode_billing', Mockery::type( 'string' ) );

		( new Module() )->validate_postcodes(
			array(
				'billing_country'  => 'RO',
				'billing_postcode' => '123',
			),
			$errors
		);
	}

	public function test_shipping_address_checked_only_when_ship_to_different_address(): void {
		$errors = Mockery::mock( WP_Error::class );
		$errors->shouldReceive( 'add' )
			->once()
			->with( 'romcommerce_postcode_shipping', Mockery::type( 'string' ) );

		( new Module() )->validate_postcodes(
			array(
				'ship_to_different_address' => true,
				'billing_country'           => 'RO',
				'billing_postcode'          => '010101',
				'shipping_country'          => 'RO',
				'shipping_postcode'         => 'bad',
			),
			$errors
		);
	}
}
