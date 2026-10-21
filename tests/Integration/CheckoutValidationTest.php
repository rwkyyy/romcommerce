<?php
/**
 * PF/PJ billing, VAT ID and postcode validation running together on
 * WooCommerce's real checkout-validation hook, in their real priority order.
 *
 * @package RomCommerce
 */

declare(strict_types=1);

namespace RomCommerce\Tests\Integration;

use RomCommerce\Modules\PfPjBilling\Fields;

final class CheckoutValidationTest extends TestCase {

	private const VALID_CUI  = 'RO18547290';
	private const VALID_IBAN = 'RO49AAAA1B31007593840000';

	/** @return array<string, string> */
	private function company( array $overrides = array() ): array {
		return array_merge(
			array(
				Fields::PERSON_TYPE => Fields::TYPE_PJ,
				'billing_company'   => 'Firma Test SRL',
				Fields::CUI         => self::VALID_CUI,
				'billing_country'   => 'RO',
				'billing_postcode'  => '010011',
			),
			$overrides
		);
	}

	public function test_a_complete_company_order_passes(): void {
		$this->assertSame( array(), $this->run_checkout_validation( $this->company( array( Fields::IBAN => self::VALID_IBAN ) ) ) );
	}

	public function test_a_company_order_without_cui_or_name_is_rejected(): void {
		$codes = $this->run_checkout_validation(
			$this->company(
				array(
					Fields::CUI       => '',
					'billing_company' => '',
				)
			)
		);

		$this->assertContains( 'romcommerce_cui', $codes );
		$this->assertContains( 'romcommerce_company', $codes );
	}

	public function test_a_cui_with_a_bad_checksum_is_rejected_by_vat_id_validation(): void {
		$this->assertSame( array( 'romcommerce_vat_id' ), $this->run_checkout_validation( $this->company( array( Fields::CUI => 'RO18547291' ) ) ) );
	}

	public function test_an_invalid_optional_iban_is_rejected(): void {
		$this->assertSame( array( 'romcommerce_iban' ), $this->run_checkout_validation( $this->company( array( Fields::IBAN => 'RO49AAAA1B31007593840001' ) ) ) );
	}

	public function test_an_individual_order_skips_company_checks(): void {
		$codes = $this->run_checkout_validation(
			array(
				Fields::PERSON_TYPE => Fields::TYPE_PF,
				Fields::CUI         => 'garbage',
				'billing_country'   => 'RO',
				'billing_postcode'  => '010011',
			)
		);

		$this->assertSame( array(), $codes );
	}

	public function test_a_five_digit_romanian_postcode_is_rejected(): void {
		$this->assertSame( array( 'romcommerce_postcode_billing' ), $this->run_checkout_validation( $this->company( array( 'billing_postcode' => '01001' ) ) ) );
	}

	public function test_shipping_postcode_is_only_checked_when_shipping_elsewhere(): void {
		$posted = $this->company(
			array(
				'shipping_country'  => 'RO',
				'shipping_postcode' => 'ABC',
			)
		);

		$this->assertSame( array(), $this->run_checkout_validation( $posted ) );

		$posted['ship_to_different_address'] = '1';
		$this->assertSame( array( 'romcommerce_postcode_shipping' ), $this->run_checkout_validation( $posted ) );
	}

	public function test_non_romanian_postcodes_are_left_alone(): void {
		$posted = $this->company(
			array(
				'billing_country'  => 'DE',
				'billing_postcode' => '1234',
			)
		);

		$this->assertSame( array(), $this->run_checkout_validation( $posted ) );
	}
}
