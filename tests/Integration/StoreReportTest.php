<?php
/**
 * The weekly store report against real WooCommerce orders (both storage
 * backends through the CRUD layer) and WordPress's test mailer.
 *
 * @package RomCommerce
 */

declare(strict_types=1);

namespace RomCommerce\Tests\Integration;

use DateTimeImmutable;
use RomCommerce\Modules\StoreReport\Collector;
use RomCommerce\Modules\StoreReport\Module;
use RomCommerce\Modules\StoreReport\Period;
use RomCommerce\Plugin;
use RomCommerce\Settings;
use WC_Order;

final class StoreReportTest extends TestCase {

	private Period $period;

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		$this->period = Period::last_week( Period::now() );
	}

	private function order_in_period( string $status, string $payment, int $days_into_period = 2 ): WC_Order {
		$order = $this->create_order( array(), $status );
		$order->set_payment_method_title( $payment );
		$order->set_date_created( $this->period->start->modify( sprintf( '%+d days +3 hours', $days_into_period ) )->getTimestamp() );
		$order->save();

		return $order;
	}

	public function test_the_collector_reads_only_the_period_and_every_status(): void {
		$this->order_in_period( 'completed', 'Card' );
		$this->order_in_period( 'processing', 'Ramburs' );
		$this->order_in_period( 'on-hold', 'Transfer bancar' );
		$this->order_in_period( 'pending', 'Card' );
		$this->order_in_period( 'cancelled', 'Card' );

		$outside = $this->create_order( array(), 'completed' );
		$outside->set_date_created( $this->period->start->modify( '-1 hour' )->getTimestamp() );
		$outside->save();

		$result = ( new Collector() )->collect( $this->period )->result();

		self::assertSame( 5, $result['orders'] );
		self::assertSame( 1, $result['completed'] );
		self::assertSame( 1, $result['processing'] );
		self::assertSame( 2, $result['waiting'] );
		self::assertSame( 1, $result['closed'] );
		self::assertSame( 2, $result['paid_orders'] );
		self::assertCount( 2, $result['products'] );
		self::assertSame( 'Test product', $result['products'][0]['name'] );
		self::assertSame( 3, $result['payments']['Card'] );
	}

	public function test_refunds_come_off_the_sales(): void {
		$order = $this->order_in_period( 'completed', 'Card' );
		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => 40,
			)
		);

		$result = ( new Collector() )->collect( $this->period )->result();

		self::assertSame( 60.0, $result['sales'] );
	}

	public function test_attribution_channels_and_campaigns_come_from_the_order_meta(): void {
		$order = $this->order_in_period( 'processing', 'Card' );
		$order->update_meta_data( '_wc_order_attribution_source_type', 'utm' );
		$order->update_meta_data( '_wc_order_attribution_utm_source', 'facebook' );
		$order->update_meta_data( '_wc_order_attribution_utm_campaign', 'toamna-2026' );
		$order->save();

		$result = ( new Collector() )->collect( $this->period )->result();

		self::assertSame( array( 'Source: Facebook' => 1 ), $result['channels'] );
		self::assertSame( array( 'toamna-2026' => 1 ), $result['campaigns'] );
	}

	public function test_the_email_goes_to_the_recipients_with_the_chosen_sections(): void {
		$this->order_in_period( 'completed', 'Card' );
		// One order in the week before, so the email has an earlier period to compare with.
		$this->order_in_period( 'completed', 'Card', -3 );
		Settings::update(
			'store-report',
			array(
				'recipients' => 'ana@magazin.ro, ion@magazin.ro',
				'compare'    => Period::COMPARE_WEEK,
				'sections'   => array( 'orders', 'payments' ),
			)
		);

		$module = Plugin::instance()->modules()->get( 'store-report' );
		self::assertInstanceOf( Module::class, $module );
		self::assertTrue( $module->send( $this->period ) );

		$mailer = tests_retrieve_phpmailer_instance();
		$sent   = $mailer->get_sent();
		self::assertNotFalse( $sent );
		self::assertSame( array( 'ana@magazin.ro', 'ion@magazin.ro' ), array_column( $sent->to, 0 ) );
		self::assertStringContainsString( 'Weekly store report', $sent->subject );
		self::assertStringContainsString( 'Total orders', $sent->body );
		self::assertStringContainsString( 'Payment methods', $sent->body );
		self::assertStringNotContainsString( 'Top products', $sent->body );
		self::assertStringContainsString( 'Changes are against', $sent->body );
	}

	public function test_saving_schedules_the_next_report_on_the_chosen_day_and_hour(): void {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		wp_unschedule_hook( 'romcommerce_store_report_send' );

		$_POST = array(
			'romcommerce_module' => 'store-report',
			'romcommerce_nonce'  => wp_create_nonce( 'romcommerce_save_store-report' ),
			'rc_enabled'         => '1',
			'rc_weekday'         => '3',
			'rc_hour'            => '9',
			'rc_compare'         => 'year',
			'rc_sections'        => array( 'orders', 'aov', 'bogus' ),
		);
		ob_start();
		Plugin::instance()->modules()->get( 'store-report' )->render_settings();
		ob_end_clean();

		$next = wp_next_scheduled( 'romcommerce_store_report_send' );
		self::assertNotFalse( $next );
		$when = ( new DateTimeImmutable( '@' . $next ) )->setTimezone( wp_timezone() );
		self::assertSame( '3 09:00', $when->format( 'N H:i' ) );
		self::assertSame( array( 'orders', 'aov' ), Settings::get( 'store-report' )['sections'] );
		self::assertSame( 'year', Settings::get( 'store-report' )['compare'] );

		Settings::set_enabled( 'store-report', false );
		self::assertFalse( wp_next_scheduled( 'romcommerce_store_report_send' ) );
		Settings::set_enabled( 'store-report', true );
	}

	public function test_a_scheduled_run_sends_each_week_once(): void {
		Settings::update( 'store-report', array( 'weekday' => (int) Period::now()->format( 'N' ) ) );
		delete_option( 'romcommerce_store_report_last_sent' );

		$module = Plugin::instance()->modules()->get( 'store-report' );
		$module->send_scheduled();
		$module->send_scheduled();

		self::assertCount( 1, tests_retrieve_phpmailer_instance()->mock_sent );
	}
}
