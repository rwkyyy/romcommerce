<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\PhoneValidation;

use Brain\Monkey\Functions;
use Mockery;
use RomCommerce\Modules\PhoneValidation\Module;
use RomCommerce\Modules\PhoneValidation\Validator;
use RomCommerce\Tests\TestCase;
use WP_Error;

final class ValidatorTest extends TestCase {

	/** @return array<string, array{0: string, 1: string, 2: bool}> */
	public static function numbers(): array {
		return array(
			'RO mobile'                       => array( '0712345678', 'RO', true ),
			'RO mobile with spaces'           => array( '0712 345 678', 'RO', true ),
			'RO mobile without the 0'         => array( '712345678', 'RO', true ),
			'RO landline'                     => array( '021 312 34 56', 'RO', true ),
			'RO too short'                    => array( '071234567', 'RO', false ),
			'RO premium prefix'               => array( '0900123456', 'RO', false ),
			'RO international'                => array( '+40 712 345 678', 'RO', true ),
			'RO international with 00'        => array( '0040712345678', 'RO', true ),
			'RO country code without plus'    => array( '40712345678', 'RO', true ),
			'RO country code, bad range'      => array( '40812345678', 'RO', false ),
			'RO international, bad range'     => array( '+40812345678', 'DE', false ),
			'foreign international'           => array( '+49 30 1234567', 'RO', true ),
			'international too short'         => array( '+49123', 'DE', false ),
			'foreign national'                => array( '030 1234567', 'DE', true ),
			'letters'                         => array( '07123ABC78', 'RO', false ),
			'empty is left to required check' => array( '', 'RO', true ),
		);
	}

	/** @dataProvider numbers */
	public function test_is_valid( string $phone, string $country, bool $expected ): void {
		self::assertSame( $expected, Validator::is_valid( $phone, $country ) );
	}

	public function test_classic_checkout_checks_shipping_only_when_shipping_elsewhere(): void {
		Functions\stubTranslationFunctions();
		$errors = Mockery::mock( WP_Error::class );
		$errors->shouldReceive( 'add' )->once()->with( 'romcommerce_phone_billing', Mockery::type( 'string' ) );

		( new Module() )->validate_classic(
			array(
				'billing_phone'    => '071234',
				'billing_country'  => 'RO',
				'shipping_phone'   => '071234',
				'shipping_country' => 'RO',
			),
			$errors
		);
	}

	public function test_block_address_error_carries_its_group(): void {
		Functions\stubTranslationFunctions();
		$errors = Mockery::mock( WP_Error::class );
		$errors->shouldReceive( 'add' )->once()->with( 'romcommerce_phone_shipping', Mockery::type( 'string' ) );

		( new Module() )->validate_block_address(
			$errors,
			array(
				'phone'   => '071234',
				'country' => 'RO',
			),
			'shipping'
		);
	}
}
