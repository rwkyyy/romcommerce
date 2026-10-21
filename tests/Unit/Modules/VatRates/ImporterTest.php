<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\VatRates;

use Brain\Monkey\Functions;
use RomCommerce\Modules\VatRates\Importer;
use RomCommerce\Modules\VatRates\TaxRateStore;
use RomCommerce\Tests\TestCase;

final class ImporterTest extends TestCase {

	/** @var array<string, mixed> */
	private array $option = array();

	private object $store;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_option' )->alias(
			function () {
				return $this->option;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->option = $value;
				return true;
			}
		);

		$this->store = new class() implements TaxRateStore {
			/** @var array<string, array<int, array{id: int, country: string, state: string, rate: float}>> */
			public array $rows = array();

			/** @var array<int, string> */
			public array $classes = array( 'reduced-rate' );

			private int $next = 100;

			public function rates( string $tax_class ): array {
				return $this->rows[ $tax_class ] ?? array();
			}

			public function insert( string $country, float $rate, string $name, string $tax_class ): int {
				$this->rows[ $tax_class ][] = array(
					'id'      => ++$this->next,
					'country' => $country,
					'state'   => '',
					'rate'    => $rate,
				);
				return $this->next;
			}

			public function update_rate( int $id, float $rate ): void {
				foreach ( $this->rows as $class => $rows ) {
					foreach ( $rows as $index => $row ) {
						if ( $row['id'] === $id ) {
							$this->rows[ $class ][ $index ]['rate'] = $rate;
						}
					}
				}
			}

			public function ensure_class( string $slug, string $name ): void {
				if ( '' !== $slug && ! in_array( $slug, $this->classes, true ) ) {
					$this->classes[] = $slug;
				}
			}
		};
	}

	/** @return array<string, mixed> */
	private function bundle( float $ro_standard = 21.0 ): array {
		return array(
			'fetched_at' => '2026-10-10',
			'rates'      => array(
				'RO' => array(
					'standard'      => $ro_standard,
					'reduced'       => array( 11.0 ),
					'super_reduced' => null,
					'parking'       => null,
				),
				'AT' => array(
					'standard'      => 20.0,
					'reduced'       => array( 10.0, 13.0 ),
					'super_reduced' => 4.9,
					'parking'       => null,
				),
			),
		);
	}

	private function import( array $bundle, array $countries ): array {
		return ( new Importer( $this->store ) )->import( $bundle, $countries, 'VAT', array() );
	}

	public function test_imports_every_tier_a_country_has_and_creates_missing_classes(): void {
		$result = $this->import( $this->bundle(), array( 'AT' ) );

		self::assertSame(
			array(
				'AT:'                   => 20.0,
				'AT:reduced-rate'       => 10.0,
				'AT:super-reduced-rate' => 4.9,
			),
			$result['add']
		);
		self::assertContains( 'super-reduced-rate', $this->store->classes );
		self::assertNotContains( 'parking-rate', $this->store->classes );
	}

	public function test_only_the_picked_countries_are_imported(): void {
		$this->import( $this->bundle(), array( 'RO' ) );

		self::assertSame( array( 'RO' ), array_unique( array_column( $this->store->rows[''], 'country' ) ) );
	}

	public function test_a_rerun_updates_its_own_rows_in_place(): void {
		$this->import( $this->bundle(), array( 'RO' ) );
		$result = $this->import( $this->bundle( 19.0 ), array( 'RO' ) );

		self::assertSame( array(), $result['add'] );
		self::assertSame(
			array(
				'from' => 21.0,
				'to'   => 19.0,
			),
			$result['update']['RO:']
		);
		self::assertCount( 1, $this->store->rows[''] );
		self::assertSame( 19.0, $this->store->rows[''][0]['rate'] );
	}

	public function test_the_merchants_own_row_is_never_touched(): void {
		$this->store->rows[''] = array(
			array(
				'id'      => 7,
				'country' => 'RO',
				'state'   => '',
				'rate'    => 19.0,
			),
		);

		$result = $this->import( $this->bundle(), array( 'RO' ) );

		self::assertSame( array( 'RO:' ), $result['kept'] );
		self::assertSame( 19.0, $this->store->rows[''][0]['rate'] );
		self::assertCount( 1, $this->store->rows[''] );
	}

	public function test_has_updates_only_for_imported_countries_with_changed_rates(): void {
		$importer = new Importer( $this->store );
		self::assertFalse( $importer->has_updates( $this->bundle() ) );

		$this->import( $this->bundle(), array( 'RO' ) );

		self::assertFalse( ( new Importer( $this->store ) )->has_updates( $this->bundle() ) );
		self::assertTrue( ( new Importer( $this->store ) )->has_updates( $this->bundle( 19.0 ) ) );
	}

	public function test_selected_countries_drops_codes_outside_the_eu(): void {
		self::assertSame( array( 'DE', 'RO' ), \RomCommerce\Modules\VatRates\Module::selected_countries( false, array( 'ro', 'GB', 'de' ) ) );
		self::assertCount( 27, \RomCommerce\Modules\VatRates\Module::selected_countries( true, array() ) );
	}
}
