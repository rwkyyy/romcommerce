<?php

declare(strict_types=1);

namespace RomCommerce\Modules\VatRates;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce's tax-rate table as the importer sees it, so the import rules
 * can be unit-tested without a database.
 */
interface TaxRateStore {

	/**
	 * @param string $tax_class Tax class slug, '' for Standard.
	 * @return array<int, array{id: int, country: string, state: string, rate: float}>
	 */
	public function rates( string $tax_class ): array;

	public function insert( string $country, float $rate, string $name, string $tax_class ): int;

	public function update_rate( int $id, float $rate ): void;

	/** Creates the tax class when the store doesn't have it. */
	public function ensure_class( string $slug, string $name ): void;
}
