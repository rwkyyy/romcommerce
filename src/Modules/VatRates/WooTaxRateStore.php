<?php

declare(strict_types=1);

namespace RomCommerce\Modules\VatRates;

use WC_Tax;

defined( 'ABSPATH' ) || exit;

final class WooTaxRateStore implements TaxRateStore {

	public function rates( string $tax_class ): array {
		$rows = array();

		foreach ( WC_Tax::get_rates_for_tax_class( $tax_class ) as $rate ) {
			$rows[] = array(
				'id'      => (int) $rate->tax_rate_id,
				'country' => (string) $rate->tax_rate_country,
				'state'   => (string) $rate->tax_rate_state,
				'rate'    => (float) $rate->tax_rate,
			);
		}

		return $rows;
	}

	public function insert( string $country, float $rate, string $name, string $tax_class ): int {
		return (int) WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => $country,
				'tax_rate_state'    => '',
				'tax_rate'          => (string) $rate,
				'tax_rate_name'     => $name,
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => '' === $tax_class ? 1 : 0,
				'tax_rate_order'    => 0,
				'tax_rate_class'    => $tax_class,
			)
		);
	}

	public function update_rate( int $id, float $rate ): void {
		WC_Tax::_update_tax_rate( $id, array( 'tax_rate' => (string) $rate ) );
	}

	public function ensure_class( string $slug, string $name ): void {
		if ( '' === $slug || in_array( $slug, WC_Tax::get_tax_class_slugs(), true ) ) {
			return;
		}

		WC_Tax::create_tax_class( $name, $slug );
	}
}
