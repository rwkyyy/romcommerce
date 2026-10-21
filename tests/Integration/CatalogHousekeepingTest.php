<?php
/**
 * Sale Category Sync and Coupon Cleanup: background jobs that change catalog
 * data without anyone watching, so they get exercised against real data.
 *
 * @package RomCommerce
 */

declare(strict_types=1);

namespace RomCommerce\Tests\Integration;

use RomCommerce\Plugin;
use WC_Coupon;

final class CatalogHousekeepingTest extends TestCase {

	private function sale_category(): int {
		$term = wp_insert_term( 'Reduceri', 'product_cat' );
		$this->assertIsArray( $term );

		update_option( 'romcommerce_sale_category_sync', array( 'category_id' => (int) $term['term_id'] ) );

		return (int) $term['term_id'];
	}

	/** @return array<int, int> */
	private function category_ids( int $product_id ): array {
		return array_map( 'intval', wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) ) );
	}

	public function test_a_product_joins_the_sale_category_while_on_sale_and_leaves_after(): void {
		$category = $this->sale_category();
		$product  = $this->create_product( '100', '80' );

		$this->assertContains( $category, $this->category_ids( $product->get_id() ) );

		$product->set_sale_price( '' );
		$product->save();

		$this->assertNotContains( $category, $this->category_ids( $product->get_id() ) );
	}

	public function test_sale_sync_keeps_the_products_other_categories(): void {
		$category = $this->sale_category();
		$other    = wp_insert_term( 'Electrocasnice', 'product_cat' );
		$this->assertIsArray( $other );

		$product = $this->create_product( '100' );
		$product->set_category_ids( array( (int) $other['term_id'] ) );
		$product->set_sale_price( '80' );
		$product->save();

		$ids = $this->category_ids( $product->get_id() );
		$this->assertContains( $category, $ids );
		$this->assertContains( (int) $other['term_id'], $ids );
	}

	private function coupon( string $code, ?int $expires ): int {
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_amount( 10 );
		$coupon->set_date_expires( $expires );

		return $coupon->save();
	}

	public function test_expired_coupons_are_trashed_and_live_ones_kept(): void {
		$expired   = $this->coupon( 'expired', time() - DAY_IN_SECONDS );
		$live      = $this->coupon( 'live', time() + DAY_IN_SECONDS );
		$no_expiry = $this->coupon( 'forever', null );

		do_action( 'romcommerce_trash_expired_coupons' );

		$this->assertSame( 'trash', get_post_status( $expired ) );
		$this->assertSame( 'publish', get_post_status( $live ) );
		$this->assertSame( 'publish', get_post_status( $no_expiry ) );
	}

	public function test_coupon_trash_is_emptied_only_after_the_retention_period(): void {
		update_option(
			'romcommerce_coupon_cleanup',
			array(
				'empty_trash'      => true,
				'empty_trash_days' => 30,
			)
		);

		$old    = $this->coupon( 'old', time() - DAY_IN_SECONDS );
		$recent = $this->coupon( 'recent', time() - DAY_IN_SECONDS );
		wp_trash_post( $old );
		wp_trash_post( $recent );
		update_post_meta( $old, '_wp_trash_meta_time', time() - 31 * DAY_IN_SECONDS );

		do_action( 'romcommerce_empty_coupon_trash' );

		$this->assertNull( get_post( $old ) );
		$this->assertSame( 'trash', get_post_status( $recent ) );
	}

	public function test_cleanup_jobs_are_scheduled_to_match_the_settings(): void {
		wp_clear_scheduled_hook( 'romcommerce_trash_expired_coupons' );
		wp_clear_scheduled_hook( 'romcommerce_empty_coupon_trash' );
		update_option( 'romcommerce_coupon_cleanup', array( 'empty_trash' => false ) );

		Plugin::instance()->modules()->get( 'coupon-cleanup' )->reconcile_cron();

		$this->assertNotFalse( wp_next_scheduled( 'romcommerce_trash_expired_coupons' ) );
		$this->assertFalse( wp_next_scheduled( 'romcommerce_empty_coupon_trash' ) );
	}
}
