<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\MultiAddress;

use Brain\Monkey\Functions;
use Mockery;
use RomCommerce\Modules\MultiAddress\AddressBook;
use RomCommerce\Tests\TestCase;

final class AddressBookTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
	}

	public function test_sanitize_keeps_only_known_fields_and_fills_the_rest_blank(): void {
		$clean = AddressBook::sanitize(
			array(
				'first_name'      => 'Ana',
				'city'            => 'Cluj',
				'not_a_real_field' => 'should be dropped',
			)
		);

		self::assertSame( 'Ana', $clean['first_name'] );
		self::assertSame( 'Cluj', $clean['city'] );
		self::assertArrayNotHasKey( 'not_a_real_field', $clean );
		self::assertSame( '', $clean['last_name'] );
		self::assertSame( array_keys( AddressBook::sanitize( array() ) ), array_keys( $clean ) );
	}

	/**
	 * This is the field-level half of the 2026-09-21 owner-reported bug:
	 * MyAccount::handle_save() previously accepted and silently saved blank
	 * required addresses. sanitize() itself doesn't enforce required fields
	 * (that check lives in MyAccount), but it must at least always produce
	 * every required key so that check has something to look at.
	 */
	public function test_sanitize_always_produces_every_required_key_even_when_empty(): void {
		$clean = AddressBook::sanitize( array() );

		foreach ( AddressBook::REQUIRED as $field ) {
			self::assertArrayHasKey( $field, $clean );
			self::assertSame( '', $clean[ $field ] );
		}
	}

	public function test_save_returns_empty_string_and_does_not_persist_once_the_cap_is_hit(): void {
		$existing = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$existing[ 'addr_' . $i ] = array();
		}

		Functions\expect( 'get_user_meta' )->andReturn( $existing );
		Functions\expect( 'update_user_meta' )->never();

		self::assertSame( '', AddressBook::save( 1, array( 'first_name' => 'Ana' ) ) );
	}

	public function test_save_generates_an_id_and_persists_when_under_the_cap(): void {
		Functions\expect( 'get_user_meta' )->andReturn( array( 'addr_existing' => array() ) );
		Functions\expect( 'update_user_meta' )
			->once()
			->with(
				1,
				'romcommerce_delivery_addresses',
				Mockery::on(
					static function ( array $all ): bool {
						return isset( $all['addr_existing'] ) && count( $all ) === 2;
					}
				)
			);

		$id = AddressBook::save( 1, array( 'first_name' => 'Ana' ) );

		self::assertStringStartsWith( 'addr_', $id );
		self::assertNotSame( 'addr_existing', $id );
	}

	public function test_save_overwrites_an_existing_id_without_growing_the_book(): void {
		Functions\expect( 'get_user_meta' )->andReturn( array( 'addr_1' => array( 'first_name' => 'Old' ) ) );
		Functions\expect( 'update_user_meta' )
			->once()
			->with(
				1,
				'romcommerce_delivery_addresses',
				Mockery::on(
					static function ( array $all ): bool {
						return 1 === count( $all ) && 'New' === $all['addr_1']['first_name'];
					}
				)
			);

		self::assertSame( 'addr_1', AddressBook::save( 1, array( 'first_name' => 'New' ), 'addr_1' ) );
	}

	public function test_summary_joins_only_the_non_empty_parts(): void {
		self::assertSame(
			'Str. Exemplu 1, Cluj, 400000',
			AddressBook::summary(
				array(
					'address_1' => 'Str. Exemplu 1',
					'city'      => 'Cluj',
					'state'     => '',
					'postcode'  => '400000',
				)
			)
		);
	}
}
