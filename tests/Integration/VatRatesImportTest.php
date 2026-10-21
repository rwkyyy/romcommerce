<?php
/**
 * The EU VAT rates importer against WooCommerce's real tax tables and classes.
 *
 * @package RomCommerce
 */

declare(strict_types=1);

namespace RomCommerce\Tests\Integration;

use RomCommerce\Modules\VatRates\Importer;
use RomCommerce\Modules\VatRates\Rates;
use RomCommerce\Modules\VatRates\WooTaxRateStore;
use WC_Tax;

final class VatRatesImportTest extends TestCase {

	private function importer(): Importer {
		return new Importer( new WooTaxRateStore() );
	}

	/** @return array<string, float> country => rate in $class */
	private function rates_in( string $class ): array {
		$rates = array();
		foreach ( WC_Tax::get_rates_for_tax_class( $class ) as $row ) {
			$rates[ (string) $row->tax_rate_country ] = (float) $row->tax_rate;
		}

		return $rates;
	}

	public function test_imports_the_bundled_tiers_into_woocommerce(): void {
		$bundle = Rates::bundled();
		$at     = $bundle['rates']['AT'];

		$this->importer()->import( $bundle, array( 'AT', 'RO' ), 'VAT', array( 'super-reduced-rate' => 'Super reduced rate' ) );

		self::assertSame( (float) $at['standard'], $this->rates_in( '' )['AT'] );
		self::assertSame( (float) min( $at['reduced'] ), $this->rates_in( 'reduced-rate' )['AT'] );
		self::assertSame( (float) $bundle['rates']['RO']['standard'], $this->rates_in( '' )['RO'] );
		if ( null !== $at['super_reduced'] ) {
			self::assertContains( 'super-reduced-rate', WC_Tax::get_tax_class_slugs() );
		}
	}

	public function test_leaves_the_merchants_own_rate_alone_and_updates_its_own(): void {
		WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country' => 'DE',
				'tax_rate'         => '18',
				'tax_rate_name'    => 'Mine',
				'tax_rate_class'   => '',
			)
		);
		$bundle = Rates::bundled();

		$this->importer()->import( $bundle, array( 'DE', 'FR' ), 'VAT', array() );

		self::assertSame( 18.0, $this->rates_in( '' )['DE'] );

		$changed                              = $bundle;
		$changed['rates']['FR']['standard'] = 21.0;
		$result                               = $this->importer()->import( $changed, array( 'FR' ), 'VAT', array() );

		self::assertSame( array(), $result['add'] );
		self::assertSame( 21.0, $this->rates_in( '' )['FR'] );
		self::assertCount( 1, array_filter( WC_Tax::get_rates_for_tax_class( '' ), static fn( $row ) => 'FR' === $row->tax_rate_country ) );
	}
}
