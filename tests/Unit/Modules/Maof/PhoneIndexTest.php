<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\Maof;

use Brain\Monkey\Functions;
use Mockery;
use RomCommerce\Modules\Maof\PhoneIndex;
use RomCommerce\Tests\TestCase;
use WC_Order;

final class PhoneIndexTest extends TestCase {

	/** @return WC_Order&\Mockery\MockInterface */
	private function order( string $phone, string $stored ) {
		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_type' )->andReturn( 'shop_order' );
		$order->shouldReceive( 'get_billing_phone' )->andReturn( $phone );
		$order->shouldReceive( 'get_meta' )->with( PhoneIndex::META_KEY )->andReturn( $stored );
		$order->shouldReceive( 'get_meta' )->with( '_anonymized' )->andReturn( '' );

		return $order;
	}

	public function test_sync_stores_the_normalised_phone(): void {
		$order = $this->order( '0712 345 678', '' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( PhoneIndex::META_KEY, '+40712345678' );

		( new PhoneIndex() )->sync( $order );
	}

	public function test_sync_leaves_an_up_to_date_copy_alone(): void {
		$order = $this->order( '+40.712.345.678', '+40712345678' );
		$order->shouldNotReceive( 'update_meta_data' );
		$order->shouldNotReceive( 'delete_meta_data' );

		( new PhoneIndex() )->sync( $order );
	}

	public function test_sync_deletes_the_copy_when_the_phone_is_cleared(): void {
		$order = $this->order( '', '+40712345678' );
		$order->shouldReceive( 'delete_meta_data' )->once()->with( PhoneIndex::META_KEY );

		( new PhoneIndex() )->sync( $order );
	}

	/** WooCommerce's privacy eraser swaps the phone for a placeholder, not ''. */
	public function test_sync_deletes_the_copy_when_the_order_is_anonymised(): void {
		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_type' )->andReturn( 'shop_order' );
		$order->shouldReceive( 'get_billing_phone' )->andReturn( '+00000000' );
		$order->shouldReceive( 'get_meta' )->with( '_anonymized' )->andReturn( 'yes' );
		$order->shouldReceive( 'get_meta' )->with( PhoneIndex::META_KEY )->andReturn( '+40712345678' );
		$order->shouldReceive( 'delete_meta_data' )->once()->with( PhoneIndex::META_KEY );

		( new PhoneIndex() )->sync( $order );
	}

	public function test_legacy_store_lookups_use_the_custom_query_var(): void {
		self::assertSame( array( PhoneIndex::QUERY_VAR => '+40712345678' ), PhoneIndex::query_args( '+40712345678' ) );
	}

	public function test_sync_ignores_refunds(): void {
		$refund = Mockery::mock( WC_Order::class );
		$refund->shouldReceive( 'get_type' )->andReturn( 'shop_order_refund' );
		$refund->shouldNotReceive( 'update_meta_data' );

		( new PhoneIndex() )->sync( $refund );
	}

	public function test_legacy_query_gets_the_meta_clause_back_when_woocommerce_dropped_it(): void {
		$args = ( new PhoneIndex() )->restore_legacy_meta_query(
			array( 'post_type' => 'shop_order' ),
			array( PhoneIndex::QUERY_VAR => '+40712345678' )
		);

		self::assertSame( array( PhoneIndex::clause( '+40712345678' ) ), $args['meta_query'] );
	}

	public function test_other_order_queries_are_untouched(): void {
		$args = array( 'post_type' => 'shop_order' );

		self::assertSame( $args, ( new PhoneIndex() )->restore_legacy_meta_query( $args, array( 'billing_email' => 'a@b.ro' ) ) );
	}

	public function test_toggling_another_module_off_does_not_purge(): void {
		Functions\expect( 'delete_post_meta_by_key' )->never();

		( new PhoneIndex() )->on_module_toggled( 'clear-cart', false );
		( new PhoneIndex() )->on_module_toggled( 'maof', true );
	}

	public function test_backfill_is_not_scheduled_once_complete(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'complete' => true,
				'page'     => 4,
			)
		);
		Functions\expect( 'wp_schedule_single_event' )->never();

		( new PhoneIndex() )->schedule_backfill();
	}

	public function test_backfill_is_scheduled_while_incomplete(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->with( Mockery::type( 'int' ), PhoneIndex::BACKFILL_HOOK );

		( new PhoneIndex() )->schedule_backfill();
	}

	public function test_progress_is_unknown_before_the_first_batch(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		self::assertSame(
			array(
				'complete' => false,
				'done'     => 0,
				'total'    => null,
			),
			PhoneIndex::progress()
		);
	}

	/** Orders placed while the backfill runs can push done past the first count. */
	public function test_progress_never_reports_more_done_than_total(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'complete' => false,
				'page'     => 3,
				'done'     => 205,
				'total'    => 200,
			)
		);

		self::assertSame( 200, PhoneIndex::progress()['done'] );
	}
}
