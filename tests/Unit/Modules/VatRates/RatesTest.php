<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\VatRates;

use InvalidArgumentException;
use RomCommerce\Modules\VatRates\Rates;
use RomCommerce\Tests\TestCase;

final class RatesTest extends TestCase {

	public function test_the_bundled_file_is_valid_and_complete(): void {
		$bundle = Rates::bundled();

		self::assertCount( 27, $bundle['rates'] );
		self::assertArrayHasKey( 'GR', $bundle['rates'] );
		self::assertArrayNotHasKey( 'EL', $bundle['rates'] );
	}

	public function test_from_vatcomply_keeps_member_states_maps_greece_and_sorts_reduced_rates(): void {
		$bundle = Rates::from_vatcomply(
			array(
				array(
					'country_code'       => 'EL',
					'member_state'       => true,
					'standard_rate'      => 24,
					'reduced_rates'      => array( 13, 6 ),
					'super_reduced_rate' => null,
					'parking_rate'       => null,
				),
				array(
					'country_code'  => 'GB',
					'member_state'  => false,
					'standard_rate' => 20,
				),
			),
			'2026-10-10'
		);

		self::assertSame( array( 'GR' ), array_keys( $bundle['rates'] ) );
		self::assertSame( array( 6.0, 13.0 ), $bundle['rates']['GR']['reduced'] );
	}

	public function test_from_vatcomply_drops_reduced_rates_that_only_apply_in_a_region(): void {
		$bundle = Rates::from_vatcomply(
			array(
				array(
					'country_code'       => 'FR',
					'member_state'       => true,
					'standard_rate'      => 20,
					'reduced_rates'      => array( 0.9, 1.05, 5.5, 8.5, 10, 13 ),
					'super_reduced_rate' => 2.1,
					'parking_rate'       => null,
					'rate_categories'    => array(
						'foodstuffs'     => array( 5.5 ),
						'accommodation'  => array( 10.0 ),
						'region'         => array( 0.9, 1.05, 8.5, 13.0, 10.0 ),
						'pharmaceutical' => array( 2.1, 5.5, 10.0 ),
					),
				),
			),
			'2026-10-10'
		);

		self::assertSame( array( 5.5, 10.0 ), $bundle['rates']['FR']['reduced'] );
		self::assertSame( 5.5, Rates::tier( $bundle['rates']['FR'], 'reduced' ) );
	}

	public function test_validate_rejects_an_incomplete_table(): void {
		$this->expectException( InvalidArgumentException::class );

		Rates::validate(
			array(
				'fetched_at' => '2026-10-10',
				'rates'      => array( 'RO' => array( 'standard' => 21.0 ) ),
			)
		);
	}

	public function test_validate_rejects_an_implausible_rate(): void {
		$bundle                              = Rates::bundled();
		$bundle['rates']['RO']['standard'] = 210.0;

		$this->expectException( InvalidArgumentException::class );

		Rates::validate( $bundle );
	}

	public function test_tier_takes_the_lowest_reduced_rate_and_null_for_a_missing_tier(): void {
		$rate = array(
			'standard'      => 20.0,
			'reduced'       => array( 13.0, 10.0, 19.0 ),
			'super_reduced' => null,
			'parking'       => 12.0,
		);

		self::assertSame( 10.0, Rates::tier( $rate, 'reduced' ) );
		self::assertSame( 12.0, Rates::tier( $rate, 'parking' ) );
		self::assertNull( Rates::tier( $rate, 'super_reduced' ) );
		self::assertNull( Rates::tier( array( 'reduced' => array() ), 'reduced' ) );
	}
}
