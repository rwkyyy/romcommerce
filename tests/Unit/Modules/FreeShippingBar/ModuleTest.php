<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\FreeShippingBar;

use Brain\Monkey\Functions;
use Mockery;
use RomCommerce\Modules\FreeShippingBar\Module;
use RomCommerce\Tests\Support\InvokesPrivateMethods;
use RomCommerce\Tests\TestCase;

final class ModuleTest extends TestCase {

	use InvokesPrivateMethods;

	/** @dataProvider colorProvider */
	public function test_sanitize_color_accepts_valid_hex_and_falls_back_otherwise( string $input, string $expected ): void {
		self::assertSame(
			$expected,
			$this->invokePrivate( new Module(), 'sanitize_color', array( $input, '#303f9f' ) )
		);
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function colorProvider(): array {
		return array(
			'valid 6-digit hex'    => array( '#a1b2c3', '#a1b2c3' ),
			'valid 3-digit hex'    => array( '#abc', '#abc' ),
			'untrimmed valid hex'  => array( '  #abc  ', '#abc' ),
			'missing hash'         => array( 'a1b2c3', '#303f9f' ),
			'not a color at all'   => array( 'red', '#303f9f' ),
			'css injection attempt' => array( '#fff;background:url(x)', '#303f9f' ),
			'empty string'         => array( '', '#303f9f' ),
		);
	}

	public function test_parse_overrides_reads_country_amount_lines(): void {
		Functions\when( 'wc_format_decimal' )->returnArg( 1 );

		$result = $this->invokePrivate(
			new Module(),
			'parse_overrides',
			array( "RO:200\nhu:250.5\n\nbad-line-no-colon\nDE:0\nFR:-5\n  IT : 99 \n" )
		);

		self::assertSame(
			array(
				'RO' => 200.0,
				'HU' => 250.5,
				'IT' => 99.0,
			),
			$result
		);
	}

	public function test_parse_overrides_returns_empty_array_for_blank_input(): void {
		Functions\when( 'wc_format_decimal' )->returnArg( 1 );

		self::assertSame( array(), $this->invokePrivate( new Module(), 'parse_overrides', array( '' ) ) );
	}

	/**
	 * The per-country manual override must win over the auto-read WooCommerce
	 * free-shipping method threshold — that precedence is the whole point of
	 * the override existing (shops whose free shipping isn't a core WC
	 * method have no other way to surface a threshold at all).
	 */
	public function test_country_override_prefers_shipping_country_then_billing_country(): void {
		Functions\expect( 'get_option' )
			->andReturn( array( 'overrides' => array( 'RO' => 200.0 ) ) );

		$customer = Mockery::mock();
		$customer->shouldReceive( 'get_shipping_country' )->andReturn( 'RO' );
		$customer->shouldReceive( 'get_billing_country' )->never();

		// A plain stdClass stand-in for WC()'s return value: only property
		// access is needed here, and stdClass (unlike a Mockery double) is
		// exempt from PHP 8.2+'s dynamic-property deprecation.
		$wc           = new \stdClass();
		$wc->customer = $customer;

		Functions\expect( 'WC' )->andReturn( $wc );

		self::assertSame( 200.0, $this->invokePrivate( new Module(), 'country_override', array() ) );
	}

	public function test_country_override_falls_back_to_billing_country_when_shipping_country_is_blank(): void {
		Functions\expect( 'get_option' )
			->andReturn( array( 'overrides' => array( 'RO' => 200.0 ) ) );

		$customer = Mockery::mock();
		$customer->shouldReceive( 'get_shipping_country' )->andReturn( '' );
		$customer->shouldReceive( 'get_billing_country' )->andReturn( 'RO' );

		$wc           = new \stdClass();
		$wc->customer = $customer;

		Functions\expect( 'WC' )->andReturn( $wc );

		self::assertSame( 200.0, $this->invokePrivate( new Module(), 'country_override', array() ) );
	}

	public function test_country_override_is_null_when_none_configured(): void {
		Functions\expect( 'get_option' )->andReturn( array() );

		self::assertNull( $this->invokePrivate( new Module(), 'country_override', array() ) );
	}

	/**
	 * @dataProvider shapes
	 * @param array<string, mixed> $stored
	 */
	public function test_the_bar_shape_is_square_or_rounded_and_old_square_bars_stay_square( array $stored, string $expected ): void {
		Functions\when( 'get_option' )->justReturn( $stored );

		self::assertSame( $expected, $this->invokePrivate( new Module(), 'shape', array() ) );
	}

	/** @return array<string, array{0: array<string, mixed>, 1: string}> */
	public static function shapes(): array {
		return array(
			'nothing saved'              => array( array(), 'rounded' ),
			'saved square'               => array( array( 'shape' => 'square' ), 'square' ),
			'unknown value'              => array( array( 'shape' => 'pill' ), 'rounded' ),
			'old radius 0'               => array( array( 'border_radius' => 0 ), 'square' ),
			'old radius 6'               => array( array( 'border_radius' => 6 ), 'rounded' ),
			'new shape wins over radius' => array(
				array(
					'border_radius' => 0,
					'shape'         => 'rounded',
				),
				'rounded',
			),
		);
	}

	public function test_the_thickness_stays_between_1_and_5_pixels(): void {
		foreach ( array( 5 => array(), 1 => array( 'thickness' => 0 ), 3 => array( 'thickness' => 3 ) ) as $expected => $stored ) {
			Functions\when( 'get_option' )->justReturn( $stored );
			self::assertSame( $expected, $this->invokePrivate( new Module(), 'thickness', array() ) );
		}
		Functions\when( 'get_option' )->justReturn( array( 'thickness' => 12 ) );
		self::assertSame( 5, $this->invokePrivate( new Module(), 'thickness', array() ) );
	}

	public function test_placements_default_to_all_three_slots_active(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$placements = ( new Module() )->placements();

		self::assertSame( array( 'product', 'cart', 'checkout' ), array_column( $placements, 'key' ) );
		self::assertSame( array( 'add_to_cart', 'cart', 'checkout_review' ), array_column( $placements, 'slot' ) );
		self::assertSame( array( true, true, true ), array_column( $placements, 'active' ) );
	}

	public function test_placements_follow_the_merchants_stored_area_choice(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $name ) {
				return 'romcommerce_placement_choices' === $name
					? array( 'free-shipping-bar' => array( 'cart' => 'cart_before_checkout_button' ) )
					: array();
			}
		);

		$placements = ( new Module() )->placements();

		self::assertSame( array( 'add_to_cart', 'cart_before_checkout_button', 'checkout_review' ), array_column( $placements, 'slot' ) );
	}

	public function test_placements_reflect_each_toggled_off_placement(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'placement_product'  => false,
				'placement_cart'     => true,
				'placement_checkout' => false,
			)
		);

		$active = array();
		foreach ( ( new Module() )->placements() as $placement ) {
			$active[ $placement['slot'] ] = $placement['active'];
		}

		self::assertSame(
			array(
				'add_to_cart'     => false,
				'cart'            => true,
				'checkout_review' => false,
			),
			$active
		);
	}
}
