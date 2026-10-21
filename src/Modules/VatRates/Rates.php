<?php

declare(strict_types=1);

namespace RomCommerce\Modules\VatRates;

use InvalidArgumentException;

/**
 * The bundled EU VAT rate table (data/eu-vat-rates.json), refreshed monthly
 * by the "Bundled data" GitHub workflow from api.vatcomply.com and reviewed
 * as a pull request; the plugin never calls that API itself. Adapted from the
 * owner's fgsync-oblio plugin (GPL-2.0-or-later).
 *
 * Also loaded by bin/update-eu-vat-rates.php outside WordPress, hence no
 * ABSPATH guard and no WordPress functions.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages go to the update workflow log and admin notices that escape them.
final class Rates {

	public const SOURCE = 'https://api.vatcomply.com/vat_rates';

	private const MEMBER_STATES = 27;

	private const MAX_RATE = 30.0;

	/** @var array{source: string, fetched_at: string, rates: array<string, array<string, mixed>>}|null */
	private static ?array $bundled = null;

	/**
	 * Builds the bundle from a vatcomply response: member states only, keyed
	 * by WooCommerce country code (Greece is EL in VAT data, GR in WooCommerce).
	 *
	 * @param array<int, mixed> $items      vatcomply /vat_rates response.
	 * @param string            $fetched_at Date of the fetch (Y-m-d).
	 * @return array{source: string, fetched_at: string, rates: array<string, array{standard: float, reduced: array<int, float>, super_reduced: float|null, parking: float|null}>}
	 */
	public static function from_vatcomply( array $items, string $fetched_at ): array {
		$rates = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || true !== ( $item['member_state'] ?? null ) || ! is_string( $item['country_code'] ?? null ) ) {
				continue;
			}

			$reduced = is_array( $item['reduced_rates'] ?? null ) ? array_map( array( self::class, 'number' ), array_values( $item['reduced_rates'] ) ) : array();
			$reduced = array_values( array_diff( $reduced, self::region_only_rates( $item ) ) );
			sort( $reduced );

			$country           = 'EL' === $item['country_code'] ? 'GR' : $item['country_code'];
			$rates[ $country ] = array(
				'standard'      => self::number( $item['standard_rate'] ?? null ),
				'reduced'       => $reduced,
				'super_reduced' => self::optional( $item['super_reduced_rate'] ?? null ),
				'parking'       => self::optional( $item['parking_rate'] ?? null ),
			);
		}

		ksort( $rates );

		return array(
			'source'     => self::SOURCE,
			'fetched_at' => $fetched_at,
			'rates'      => $rates,
		);
	}

	/**
	 * Reduced rates that apply only in an outlying region (Corsica and the
	 * French overseas departments, the Azores and Madeira, the Greek islands,
	 * Jungholz and Mittelberg), which vatcomply lists alongside the national
	 * ones under the "region" category. Left in, they'd become the country's
	 * lowest reduced rate (France: 0.9% instead of 5.5%).
	 *
	 * @param array<string, mixed> $item One vatcomply entry.
	 * @return array<int, float>
	 */
	private static function region_only_rates( array $item ): array {
		$categories = is_array( $item['rate_categories'] ?? null ) ? $item['rate_categories'] : array();
		$regional   = array();
		$national   = array();

		foreach ( $categories as $category => $rates ) {
			foreach ( (array) $rates as $rate ) {
				if ( 'region' === $category ) {
					$regional[] = self::number( $rate );
				} else {
					$national[] = self::number( $rate );
				}
			}
		}

		return array_values( array_diff( $regional, $national ) );
	}

	/**
	 * @param array<string, mixed> $data Bundle contents.
	 * @throws InvalidArgumentException When the table is incomplete or holds an implausible rate.
	 */
	public static function validate( array $data ): void {
		$rates = $data['rates'] ?? null;

		if ( ! is_array( $rates ) || self::MEMBER_STATES !== count( $rates ) ) {
			throw new InvalidArgumentException( sprintf( 'Expected %d EU member states, got %d.', self::MEMBER_STATES, is_array( $rates ) ? count( $rates ) : 0 ) );
		}

		if ( ! isset( $rates['RO'] ) ) {
			throw new InvalidArgumentException( 'Romania is missing from the EU VAT rates.' );
		}

		if ( ! is_string( $data['fetched_at'] ?? null ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $data['fetched_at'] ) ) {
			throw new InvalidArgumentException( 'Missing or malformed fetched_at date.' );
		}

		foreach ( $rates as $country => $rate ) {
			$all = array_merge( array( $rate['standard'] ?? null ), (array) ( $rate['reduced'] ?? array() ), array_filter( array( $rate['super_reduced'] ?? null, $rate['parking'] ?? null ), 'is_numeric' ) );

			foreach ( $all as $value ) {
				if ( ! is_numeric( $value ) || (float) $value < 0 || (float) $value > self::MAX_RATE ) {
					throw new InvalidArgumentException( sprintf( 'Implausible VAT rate for %s: %s', $country, is_scalar( $value ) ? (string) $value : gettype( $value ) ) );
				}
			}

			if ( (float) $rate['standard'] <= 0 ) {
				throw new InvalidArgumentException( sprintf( 'Standard VAT rate for %s must be positive.', $country ) );
			}
		}
	}

	/**
	 * Whether two bundles hold the same rates, ignoring when they were fetched.
	 *
	 * @param array<string, mixed> $left
	 * @param array<string, mixed> $right
	 */
	public static function same_rates( array $left, array $right ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- also runs in the update script, outside WordPress.
		return json_encode( $left['rates'] ?? null ) === json_encode( $right['rates'] ?? null );
	}

	/**
	 * The rate WooCommerce gets for one tier: WooCommerce's Reduced rate class
	 * holds one rate per country, so for countries with several reduced rates
	 * it takes the lowest (the one covering basics such as food).
	 *
	 * @param array<string, mixed> $rate One country's entry from the bundle.
	 */
	public static function tier( array $rate, string $tier ): ?float {
		if ( 'reduced' === $tier ) {
			$reduced = (array) ( $rate['reduced'] ?? array() );

			return array() === $reduced ? null : (float) min( $reduced );
		}

		return is_numeric( $rate[ $tier ] ?? null ) ? (float) $rate[ $tier ] : null;
	}

	/**
	 * @return array{source: string, fetched_at: string, rates: array<string, array<string, mixed>>}
	 * @throws InvalidArgumentException When the bundled file is missing or invalid.
	 */
	public static function bundled(): array {
		if ( null !== self::$bundled ) {
			return self::$bundled;
		}

		$path = dirname( __DIR__, 3 ) . '/data/eu-vat-rates.json';
		$data = is_readable( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local bundled file.

		if ( ! is_array( $data ) ) {
			throw new InvalidArgumentException( 'The bundled EU VAT rate file is missing or unreadable.' );
		}

		self::validate( $data );
		self::$bundled = $data;

		return $data;
	}

	/** @param mixed $value Rate from the API. */
	private static function number( $value ): float {
		return is_numeric( $value ) ? (float) $value : -1.0;
	}

	/** @param mixed $value Optional rate from the API. */
	private static function optional( $value ): ?float {
		return is_numeric( $value ) ? (float) $value : null;
	}
}
