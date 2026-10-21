<?php
/**
 * @package RomCommerce
 */

declare(strict_types=1);

namespace RomCommerce\Tests\Integration;

use RomCommerce\Modules\MerchantWidget\SurveyOptIn;

final class SurveyOptInTest extends TestCase {

	public function test_a_real_order_gives_google_its_fields_and_the_gtins_woocommerce_holds(): void {
		$product = $this->create_product();
		if ( method_exists( $product, 'set_global_unique_id' ) ) {
			$product->set_global_unique_id( '5941234567890' );
			$product->save();
		}

		$order = wc_create_order();
		$order->add_product( $product );
		$order->set_billing_email( 'ana@example.ro' );
		$order->set_billing_country( 'RO' );
		$order->set_status( 'processing' );
		$order->save();

		$config = ( new SurveyOptIn( '759980522', 5, 'CENTER_DIALOG' ) )->config( $order );

		$this->assertSame( 'ana@example.ro', $config['email'] );
		$this->assertSame( 'RO', $config['delivery_country'] );
		$this->assertSame( (string) $order->get_order_number(), $config['order_id'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $config['estimated_delivery_date'] );

		if ( method_exists( $product, 'set_global_unique_id' ) ) {
			$this->assertSame( array( array( 'gtin' => '5941234567890' ) ), $config['products'] );
		} else {
			$this->assertArrayNotHasKey( 'products', $config );
		}
	}

	public function test_a_plain_http_confirmation_page_loads_nothing_from_google(): void {
		$this->assertFalse( is_ssl() );

		( new SurveyOptIn( '759980522', 5, 'CENTER_DIALOG' ) )->enqueue();

		$this->assertFalse( wp_script_is( 'romcommerce-survey-optin-google', 'enqueued' ) );
	}
}
