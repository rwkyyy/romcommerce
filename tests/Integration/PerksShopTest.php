<?php
/**
 * Perks shop's shortcodes against a real WordPress (attachments, settings).
 *
 * @package RomCommerce
 */

declare(strict_types=1);

namespace RomCommerce\Tests\Integration;

use RomCommerce\Settings;

final class PerksShopTest extends TestCase {

	public function test_the_badges_shortcode_renders_the_saved_badges_escaped(): void {
		Settings::update(
			'perks-shop',
			array(
				'badges' => array(
					array(
						'icon'  => 'truck',
						'title' => 'Livrare <b>gratuită</b>',
						'text'  => 'Peste 200 lei',
					),
				),
			)
		);

		$html = do_shortcode( '[romcommerce_perks]' );

		self::assertStringContainsString( 'Livrare &lt;b&gt;gratuită&lt;/b&gt;', $html );
		self::assertStringContainsString( '<svg class="romcommerce-perk-icon"', $html );
	}

	public function test_the_payment_methods_shortcode_needs_an_image(): void {
		Settings::update( 'perks-shop', array( 'payment_heading' => 'Metode de plată' ) );
		self::assertSame( '', do_shortcode( '[romcommerce_payment_methods]' ) );

		$image_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		Settings::update(
			'perks-shop',
			array(
				'payment_heading'  => 'Metode de plată',
				'payment_image_id' => $image_id,
			)
		);

		$html = do_shortcode( '[romcommerce_payment_methods]' );
		self::assertStringContainsString( 'Metode de plată', $html );
		self::assertStringContainsString( 'romcommerce-payment-methods-image', $html );
	}
}
