<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\PfPjBilling;

use Brain\Monkey\Functions;
use RomCommerce\Modules\PfPjBilling\BillingData;
use RomCommerce\Modules\PfPjBilling\BlockCheckout;
use RomCommerce\Modules\PfPjBilling\Fields;
use RomCommerce\Tests\TestCase;

final class BillingDataTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
	}

	/** @param array<string, string> $values */
	private function company( array $values = array() ): array {
		return array_merge(
			array(
				Fields::PERSON_TYPE => Fields::TYPE_PJ,
				'billing_company'   => 'Exemplu SRL',
				Fields::CUI         => 'RO18547290',
			),
			$values
		);
	}

	public function test_a_complete_company_has_no_errors(): void {
		self::assertSame( array(), BillingData::errors( $this->company(), false ) );
	}

	public function test_a_company_needs_a_cui_and_a_name(): void {
		$errors = BillingData::errors( $this->company( array( Fields::CUI => '', 'billing_company' => '' ) ), false );

		self::assertSame( array( 'romcommerce_cui', 'romcommerce_company' ), array_keys( $errors ) );
	}

	public function test_a_bad_iban_is_rejected_only_when_given(): void {
		self::assertArrayHasKey( 'romcommerce_iban', BillingData::errors( $this->company( array( Fields::IBAN => 'RO00XXXX' ) ), false ) );
		self::assertSame( array(), BillingData::errors( $this->company( array( Fields::IBAN => '' ) ), false ) );
	}

	public function test_an_individual_needs_nothing(): void {
		self::assertSame( array(), BillingData::errors( array( Fields::PERSON_TYPE => Fields::TYPE_PF ), true ) );
	}

	public function test_a_bad_cnp_is_rejected_only_with_the_cnp_field_on(): void {
		$values = array(
			Fields::PERSON_TYPE => Fields::TYPE_PF,
			Fields::CNP         => '1234567890123',
		);

		self::assertArrayHasKey( 'romcommerce_cnp', BillingData::errors( $values, true ) );
		self::assertSame( array(), BillingData::errors( $values, false ) );
	}

	public function test_a_company_order_keeps_the_ro_prefix_and_drops_spaces(): void {
		$billing = BillingData::for_order( $this->company( array( Fields::CUI => 'ro 185 472 90', Fields::IBAN => 'ro49 aaaa' ) ), false );

		self::assertSame( 'RO18547290', $billing['cui'] );
		self::assertSame( 'RO49AAAA', $billing['iban'] );
		self::assertSame( Fields::TYPE_PJ, $billing['type'] );
	}

	public function test_an_individual_order_gets_the_cnp_placeholder_unless_the_field_is_on_and_filled(): void {
		$values = array(
			Fields::PERSON_TYPE => Fields::TYPE_PF,
			Fields::CNP         => '1960101-123456',
		);

		self::assertSame( array( 'type' => 'pf', 'cnp' => Fields::CNP_PLACEHOLDER ), BillingData::for_order( $values, false ) );
		self::assertSame( array( 'type' => 'pf', 'cnp' => '1960101123456' ), BillingData::for_order( $values, true ) );
	}

	public function test_block_values_map_onto_the_classic_keys(): void {
		$values = BlockCheckout::values(
			array(
				Fields::BLOCK_IS_COMPANY => true,
				Fields::BLOCK_COMPANY    => ' Exemplu SRL ',
				Fields::BLOCK_CUI        => 'RO18547290',
			)
		);

		self::assertSame( Fields::TYPE_PJ, $values[ Fields::PERSON_TYPE ] );
		self::assertSame( 'Exemplu SRL', $values['billing_company'] );
		self::assertSame( 'RO18547290', $values[ Fields::CUI ] );
		self::assertSame( '', $values[ Fields::IBAN ] );
	}

	public function test_a_missing_company_box_means_an_individual(): void {
		self::assertSame( Fields::TYPE_PF, BlockCheckout::values( array() )[ Fields::PERSON_TYPE ] );
	}
}
