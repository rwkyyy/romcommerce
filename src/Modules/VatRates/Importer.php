<?php

declare(strict_types=1);

namespace RomCommerce\Modules\VatRates;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the bundled EU VAT rates for the countries the merchant picks into
 * WooCommerce's tax tables: Standard, Reduced rate, and Super reduced /
 * Parking rate classes created only when a picked country has that tier.
 *
 * Each row it creates is tracked by "{country}:{class slug}", so later runs
 * update those rows in place and never touch a row the merchant made: a
 * country that already has the merchant's own row in a class is left alone.
 * Adapted from the owner's fgsync-oblio importer, which covers Standard only.
 */
final class Importer {

	public const OPTION = 'romcommerce_vat_rates_import';

	/** Bundle tier => WooCommerce tax class slug ('' is Standard). */
	public const TIERS = array(
		'standard'      => '',
		'reduced'       => 'reduced-rate',
		'super_reduced' => 'super-reduced-rate',
		'parking'       => 'parking-rate',
	);

	private TaxRateStore $store;

	/** @var array<string, array<int, array{id: int, country: string, state: string, rate: float}>> */
	private array $rows = array();

	public function __construct( TaxRateStore $store ) {
		$this->store = $store;
	}

	/**
	 * What an import of $countries would do, without writing anything.
	 *
	 * @param array<string, mixed> $bundle    Validated Rates bundle.
	 * @param array<int, string>   $countries Country codes to import.
	 * @return array{add: array<string, float>, update: array<string, array{from: float, to: float}>, kept: array<int, string>}
	 */
	public function plan( array $bundle, array $countries ): array {
		$imported = $this->imported_rows();
		$plan     = array(
			'add'    => array(),
			'update' => array(),
			'kept'   => array(),
		);

		foreach ( $countries as $country ) {
			$rate = $bundle['rates'][ $country ] ?? null;
			if ( ! is_array( $rate ) ) {
				continue;
			}

			foreach ( self::TIERS as $tier => $class ) {
				$target = Rates::tier( $rate, $tier );
				if ( null === $target ) {
					continue;
				}

				$key  = $country . ':' . $class;
				$rows = $this->rows_for( $class );
				$own  = $this->row( $rows, $imported[ $key ] ?? 0 );

				if ( null !== $own ) {
					if ( abs( $own['rate'] - $target ) > 0.0001 ) {
						$plan['update'][ $key ] = array(
							'from' => $own['rate'],
							'to'   => $target,
						);
					}
					continue;
				}

				if ( $this->has_country_row( $rows, $country ) ) {
					$plan['kept'][] = $key;
					continue;
				}

				$plan['add'][ $key ] = $target;
			}
		}

		return $plan;
	}

	/**
	 * @param array<string, mixed>  $bundle      Validated Rates bundle.
	 * @param array<int, string>    $countries   Country codes to import.
	 * @param string                $name        Tax rate label shown to customers.
	 * @param array<string, string> $class_names Class slug => name, for classes that may need creating.
	 * @return array{add: array<string, float>, update: array<string, array{from: float, to: float}>, kept: array<int, string>}
	 */
	public function import( array $bundle, array $countries, string $name, array $class_names ): array {
		$plan     = $this->plan( $bundle, $countries );
		$imported = $this->imported_rows();

		foreach ( $plan['add'] as $key => $rate ) {
			list( $country, $class ) = explode( ':', $key, 2 );
			$this->store->ensure_class( $class, $class_names[ $class ] ?? $class );
			$imported[ $key ] = $this->store->insert( $country, $rate, $name, $class );
		}

		foreach ( $plan['update'] as $key => $change ) {
			$this->store->update_rate( (int) $imported[ $key ], $change['to'] );
		}

		update_option(
			self::OPTION,
			array(
				'fetched_at' => (string) $bundle['fetched_at'],
				'rows'       => $imported,
				'countries'  => array_values( array_unique( array_merge( $this->imported_countries(), $countries ) ) ),
			),
			false
		);
		$this->rows = array();

		return $plan;
	}

	/**
	 * Whether rows imported earlier would change with the bundled rates, the
	 * prompt for a "refresh imported rates" after a plugin update.
	 *
	 * @param array<string, mixed> $bundle Validated Rates bundle.
	 */
	public function has_updates( array $bundle ): bool {
		$countries = $this->imported_countries();

		return array() !== $countries && array() !== $this->plan( $bundle, $countries )['update'];
	}

	/** @return array<int, string> */
	public function imported_countries(): array {
		$state = get_option( self::OPTION, array() );

		return is_array( $state ) && is_array( $state['countries'] ?? null ) ? array_values( array_map( 'strval', $state['countries'] ) ) : array();
	}

	/** @return array<string, int> "{country}:{class}" => tax rate id created by the import. */
	private function imported_rows(): array {
		$state = get_option( self::OPTION, array() );

		return is_array( $state ) && is_array( $state['rows'] ?? null ) ? array_map( 'intval', $state['rows'] ) : array();
	}

	/** @return array<int, array{id: int, country: string, state: string, rate: float}> */
	private function rows_for( string $class ): array {
		if ( ! isset( $this->rows[ $class ] ) ) {
			$this->rows[ $class ] = $this->store->rates( $class );
		}

		return $this->rows[ $class ];
	}

	/**
	 * @param array<int, array{id: int, country: string, state: string, rate: float}> $rows
	 * @return array{id: int, country: string, state: string, rate: float}|null
	 */
	private function row( array $rows, int $id ): ?array {
		foreach ( $rows as $row ) {
			if ( $id > 0 && $row['id'] === $id ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Any row for the country counts as the merchant's own choice, including
	 * state-specific ones.
	 *
	 * @param array<int, array{id: int, country: string, state: string, rate: float}> $rows
	 */
	private function has_country_row( array $rows, string $country ): bool {
		foreach ( $rows as $row ) {
			if ( $row['country'] === $country ) {
				return true;
			}
		}

		return false;
	}
}
