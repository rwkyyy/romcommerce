<?php
/**
 * MAOF's related-order lookups go through wc_get_orders(), whose billing-field
 * query support differs between HPOS and legacy storage — so these run on both.
 *
 * @package RomCommerce
 */

declare(strict_types=1);

namespace RomCommerce\Tests\Integration;

use RomCommerce\Modules\Maof\Finder;
use RomCommerce\Modules\Maof\PhoneIndex;
use RomCommerce\Settings;

final class MaofFinderTest extends TestCase {

	/** @return array<int, int> */
	private function related_ids( \WC_Order $order ): array {
		return array_map(
			static function ( array $match ): int {
				return $match['order_id'];
			},
			( new Finder() )->related_orders( $order )
		);
	}

	public function test_orders_sharing_an_email_match_case_insensitively(): void {
		$earlier = $this->create_order( array( 'email' => 'Client@Example.ro' ) );
		$current = $this->create_order( array( 'email' => 'client@example.ro' ) );
		$this->create_order( array( 'email' => 'someone.else@example.ro' ) );

		$this->assertSame( array( $earlier->get_id() ), $this->related_ids( $current ) );
	}

	/**
	 * @dataProvider phone_formats
	 */
	public function test_the_same_romanian_number_matches_across_formats( string $stored ): void {
		$earlier = $this->create_order(
			array(
				'email' => 'first@example.ro',
				'phone' => $stored,
			)
		);
		$current = $this->create_order(
			array(
				'email' => 'second@example.ro',
				'phone' => '0712345678',
			)
		);

		$this->assertSame( array( $earlier->get_id() ), $this->related_ids( $current ), "Stored as \"{$stored}\"." );
	}

	/**
	 * The last two were missed before the phone index (found by this suite,
	 * 2026-10-03): WooCommerce stores the phone as typed, and the old lookup
	 * only tried a few exact spellings.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function phone_formats(): array {
		return array(
			'national'                => array( '0712345678' ),
			'international with plus' => array( '+40712345678' ),
			'international with 00'   => array( '0040712345678' ),
			'national with spaces'    => array( '0712 345 678' ),
			'international with dots' => array( '+40.712.345.678' ),
		);
	}

	/**
	 * More unrelated orders than the finder's 25-order query limit, all newer
	 * than the match. If the index lookup were silently dropped (WooCommerce
	 * 7.0's legacy store ignores meta_query; PhoneIndex re-adds it), the query
	 * would return the newest 25 and the match would never be seen.
	 */
	public function test_the_index_lookup_finds_a_match_behind_many_newer_orders(): void {
		$earlier = $this->create_order(
			array(
				'email' => 'first@example.ro',
				'phone' => '0712 345 678',
			)
		);
		for ( $i = 0; $i < 26; $i++ ) {
			$this->create_order(
				array(
					'email' => "noise{$i}@example.ro",
					'phone' => sprintf( '0723 000 %03d', $i ),
				)
			);
		}
		$current = $this->create_order(
			array(
				'email' => 'second@example.ro',
				'phone' => '0712345678',
			)
		);

		$this->assertSame( array( $earlier->get_id() ), $this->related_ids( $current ) );
	}

	private function indexed_phone( int $order_id ): string {
		return (string) wc_get_order( $order_id )->get_meta( PhoneIndex::META_KEY );
	}

	public function test_saving_an_order_keeps_the_index_in_step_with_the_billing_phone(): void {
		$order = $this->create_order( array( 'phone' => '0712 345 678' ) );
		$this->assertSame( '+40712345678', $this->indexed_phone( $order->get_id() ) );
		$this->assertSame( '0712 345 678', wc_get_order( $order->get_id() )->get_billing_phone(), 'The phone as typed is never rewritten.' );

		$order->set_billing_phone( '0040 723 111 222' );
		$order->save();
		$this->assertSame( '+40723111222', $this->indexed_phone( $order->get_id() ) );
	}

	public function test_the_privacy_eraser_removes_the_indexed_phone(): void {
		$order = $this->create_order( array( 'phone' => '0712345678' ) );

		\WC_Privacy_Erasers::remove_order_personal_data( wc_get_order( $order->get_id() ) );

		$this->assertSame( '', $this->indexed_phone( $order->get_id() ) );
	}

	public function test_the_backfill_indexes_orders_saved_before_the_index_existed(): void {
		$order = $this->create_order( array( 'phone' => '0712 345 678' ) );
		PhoneIndex::purge();
		$this->assertSame( '', $this->indexed_phone( $order->get_id() ) );
		$this->assertNull( PhoneIndex::progress()['total'] );

		do_action( PhoneIndex::BACKFILL_HOOK );

		$this->assertSame( '+40712345678', $this->indexed_phone( $order->get_id() ) );
		$this->assertFalse( wp_next_scheduled( PhoneIndex::BACKFILL_HOOK ), 'A single short batch completes the backfill.' );
		$this->assertSame(
			array(
				'complete' => true,
				'done'     => 1,
				'total'    => 1,
			),
			PhoneIndex::progress()
		);
	}

	public function test_switching_maof_off_purges_the_index(): void {
		$order = $this->create_order( array( 'phone' => '0712345678' ) );

		Settings::set_enabled( 'maof', false );
		wp_cache_flush();

		$this->assertSame( '', $this->indexed_phone( $order->get_id() ) );
		$this->assertSame( '0712345678', wc_get_order( $order->get_id() )->get_billing_phone() );

		Settings::set_enabled( 'maof', true );
	}

	public function test_a_match_on_both_email_and_phone_is_strong(): void {
		$this->create_order(
			array(
				'email' => 'client@example.ro',
				'phone' => '0712345678',
			)
		);
		$current = $this->create_order(
			array(
				'email' => 'client@example.ro',
				'phone' => '+40712345678',
			)
		);

		$matches = ( new Finder() )->related_orders( $current );

		$this->assertCount( 1, $matches );
		$this->assertSame( 'both', $matches[0]['basis'] );
		$this->assertTrue( $matches[0]['strong'] );
	}

	public function test_active_duplicate_count_ignores_completed_orders(): void {
		$this->create_order( array( 'email' => 'dup@example.ro' ), 'processing' );
		$this->create_order( array( 'email' => 'dup@example.ro' ), 'completed' );
		$current = $this->create_order( array( 'email' => 'dup@example.ro' ), 'on-hold' );

		$this->assertSame( 1, ( new Finder() )->active_duplicate_count( $current ) );
	}
}
