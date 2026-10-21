<?php
/**
 * PF/PJ data written at checkout must survive a round-trip through the order
 * data store, on HPOS and legacy storage alike, since invoicing plugins read
 * it back long after checkout.
 *
 * @package RomCommerce
 */

declare(strict_types=1);

namespace RomCommerce\Tests\Integration;

use RomCommerce\Modules\PfPjBilling\Fields;

final class PfPjOrderDataTest extends TestCase {

	private function checkout_order( array $posted ): \WC_Order {
		$_POST = $posted;

		$order = wc_create_order();
		$order->add_product( $this->create_product() );
		do_action( 'woocommerce_checkout_create_order', $order, array() );
		$order->save();

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $reloaded );

		return $reloaded;
	}

	public function test_company_details_are_normalised_and_persisted(): void {
		$order = $this->checkout_order(
			array(
				Fields::PERSON_TYPE => Fields::TYPE_PJ,
				'billing_company'   => 'Firma Test SRL',
				Fields::CUI         => 'ro 185 472 90',
				Fields::REG_COM     => 'J40/1234/2020',
				Fields::IBAN        => 'ro49 aaaa 1b31 0075 9384 0000',
			)
		);

		$this->assertSame( 'Firma Test SRL', $order->get_billing_company() );

		$billing = $order->get_meta( Fields::META_KEY );
		$this->assertSame( Fields::TYPE_PJ, $billing['type'] );
		$this->assertSame( 'RO18547290', $billing['cui'] );
		$this->assertSame( 'J40/1234/2020', $billing['reg_com'] );
		$this->assertSame( 'RO49AAAA1B31007593840000', $billing['iban'] );
	}

	public function test_an_individual_gets_the_cnp_placeholder_when_the_cnp_field_is_off(): void {
		$order = $this->checkout_order(
			array(
				Fields::PERSON_TYPE => Fields::TYPE_PF,
				Fields::CNP         => '1900101221140',
			)
		);

		$billing = $order->get_meta( Fields::META_KEY );
		$this->assertSame( Fields::TYPE_PF, $billing['type'] );
		$this->assertSame( Fields::CNP_PLACEHOLDER, $billing['cnp'], 'A CNP must never be stored while the CNP field is disabled.' );
	}
}
