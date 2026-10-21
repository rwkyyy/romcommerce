<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\MerchantWidget;

use Brain\Monkey\Functions;
use DateTimeZone;
use Mockery;
use RomCommerce\Modules\MerchantWidget\SurveyOptIn;
use RomCommerce\Tests\TestCase;
use WC_DateTime;
use WC_Order;

final class SurveyOptInTest extends TestCase {

	private const PLACED = 1760518800; // 2025-10-15 09:00 UTC, 12:00 in Bucharest.

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_email' )->alias(
			static function ( string $email ) {
				return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
			}
		);
		Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'Europe/Bucharest' ) );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return WC_Order&\Mockery\MockInterface
	 */
	private function order( array $overrides = array() ) {
		$created = Mockery::mock( WC_DateTime::class );
		$created->allows( 'getTimestamp' )->andReturn( $overrides['placed'] ?? self::PLACED );

		$order = Mockery::mock( WC_Order::class );
		$order->allows( 'get_billing_email' )->andReturn( $overrides['email'] ?? 'ana@example.ro' );
		$order->allows( 'get_shipping_country' )->andReturn( $overrides['shipping_country'] ?? 'RO' );
		$order->allows( 'get_billing_country' )->andReturn( $overrides['billing_country'] ?? 'RO' );
		$order->allows( 'get_date_created' )->andReturn( $created );
		$order->allows( 'get_order_number' )->andReturn( '1042' );
		$order->allows( 'get_items' )->andReturn( array() );

		return $order;
	}

	public function test_the_config_carries_googles_required_fields(): void {
		$config = ( new SurveyOptIn( '759980522', 5, 'BOTTOM_LEFT_DIALOG' ) )->config( $this->order() );

		self::assertSame(
			array(
				'merchant_id'             => 759980522,
				'order_id'                => '1042',
				'email'                   => 'ana@example.ro',
				'delivery_country'        => 'RO',
				'estimated_delivery_date' => '2025-10-20',
				'opt_in_style'            => 'BOTTOM_LEFT_DIALOG',
			),
			$config
		);
	}

	public function test_the_billing_country_is_used_when_nothing_ships(): void {
		$config = ( new SurveyOptIn( '1', 5, 'CENTER_DIALOG' ) )->config(
			$this->order(
				array(
					'shipping_country' => '',
					'billing_country'  => 'md',
				)
			)
		);

		self::assertSame( 'MD', $config['delivery_country'] );
	}

	/** @dataProvider incompleteOrderProvider */
	public function test_nothing_is_sent_when_google_would_reject_the_order( string $merchant_id, array $overrides ): void {
		self::assertSame( array(), ( new SurveyOptIn( $merchant_id, 5, 'CENTER_DIALOG' ) )->config( $this->order( $overrides ) ) );
	}

	/** @return array<string, array{0: string, 1: array<string, string>}> */
	public static function incompleteOrderProvider(): array {
		return array(
			'no merchant id'  => array( '', array() ),
			'no email'        => array( '1', array( 'email' => '' ) ),
			'invalid email'   => array( '1', array( 'email' => 'not-an-email' ) ),
			'unknown country' => array(
				'1',
				array(
					'shipping_country' => 'ZZ',
					'billing_country'  => 'ZZ',
				),
			),
			'no country'      => array(
				'1',
				array(
					'shipping_country' => '',
					'billing_country'  => '',
				),
			),
		);
	}

	public function test_an_unknown_dialog_style_falls_back_to_googles_default(): void {
		$config = ( new SurveyOptIn( '1', 5, 'SIDEBAR' ) )->config( $this->order() );

		self::assertSame( 'CENTER_DIALOG', $config['opt_in_style'] );
	}

	public function test_the_delivery_date_counts_days_in_the_store_timezone(): void {
		// 23:30 UTC on the 15th is already the 16th in Bucharest.
		self::assertSame( '2025-10-19', SurveyOptIn::delivery_date( 1760571000, 3, new DateTimeZone( 'Europe/Bucharest' ) ) );
	}

	public function test_delivery_days_are_kept_within_range(): void {
		$config = ( new SurveyOptIn( '1', 0, 'CENTER_DIALOG' ) )->config( $this->order() );
		self::assertSame( '2025-10-16', $config['estimated_delivery_date'] );

		$config = ( new SurveyOptIn( '1', 999, 'CENTER_DIALOG' ) )->config( $this->order() );
		self::assertSame( '2025-12-14', $config['estimated_delivery_date'] );
	}

	/** The order number is filterable text printed inside a <script> tag. */
	public function test_the_script_cannot_be_broken_out_of(): void {
		$js = SurveyOptIn::script_js( array( 'order_id' => '</script><script>alert(1)</script>' ) );

		self::assertStringNotContainsString( '</script>', $js );
		self::assertStringContainsString( 'window.gapi.surveyoptin.render(', $js );
		self::assertStringStartsWith( 'window.romcommerceRenderOptIn=function(){', $js );
	}

	public function test_no_order_outside_the_confirmation_page(): void {
		Functions\when( 'is_order_received_page' )->justReturn( false );

		self::assertNull( SurveyOptIn::received_order( self::PLACED ) );
	}

	/** @dataProvider confirmationPageProvider */
	public function test_the_confirmation_page_order_is_used_only_when_safe( string $key, string $status_match, int $now, bool $expected ): void {
		Functions\when( 'is_order_received_page' )->justReturn( true );
		Functions\when( 'get_query_var' )->justReturn( '1042' );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'absint' )->alias( 'abs' );

		$order = $this->order();
		$order->allows( 'get_order_key' )->andReturn( 'wc_order_abc' );
		$order->allows( 'has_status' )->andReturn( 'blocked' === $status_match );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$_GET['key'] = $key;
		$result      = SurveyOptIn::received_order( $now );
		unset( $_GET['key'] );

		self::assertSame( $expected, $order === $result );
	}

	/** @return array<string, array{0: string, 1: string, 2: int, 3: bool}> */
	public static function confirmationPageProvider(): array {
		return array(
			'fresh order, right key'       => array( 'wc_order_abc', 'ok', self::PLACED + 60, true ),
			'wrong key'                    => array( 'wc_order_xyz', 'ok', self::PLACED + 60, false ),
			'no key'                       => array( '', 'ok', self::PLACED + 60, false ),
			'failed or cancelled order'    => array( 'wc_order_abc', 'blocked', self::PLACED + 60, false ),
			'reopened more than an hour on' => array( 'wc_order_abc', 'ok', self::PLACED + 3601, false ),
		);
	}
}
