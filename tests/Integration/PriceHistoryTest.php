<?php
/**
 * The Omnibus price log against a real database and real product saves.
 *
 * @package RomCommerce
 */

declare(strict_types=1);

namespace RomCommerce\Tests\Integration;

use RomCommerce\Modules\PriceHistory\Baseline;
use RomCommerce\Modules\PriceHistory\Log;
use RomCommerce\Modules\PriceHistory\Module;

final class PriceHistoryTest extends TestCase {

	/** @return array<int, string> logged prices for $product_id, newest first. */
	private function logged_prices( int $product_id ): array {
		return array_map(
			static function ( array $row ): string {
				return (string) (float) $row['price'];
			},
			Log::recent( $product_id, 3650 )
		);
	}

	private function days_ago( int $days ): string {
		return gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	}

	public function test_saving_a_new_product_logs_its_price(): void {
		$product = $this->create_product( '100' );

		$this->assertSame( array( '100' ), $this->logged_prices( $product->get_id() ) );
	}

	public function test_only_actual_price_changes_are_logged(): void {
		$product = $this->create_product( '100' );

		$product->set_name( 'Renamed, same price' );
		$product->save();
		$product->set_sale_price( '80' );
		$product->save();

		$this->assertSame( array( '80', '100' ), $this->logged_prices( $product->get_id() ) );
	}

	/**
	 * Logs a price change for $product as of $days_ago (0 = now), exactly as a
	 * real save would, so reference_price() sees a realistic history.
	 */
	private function price_at( int $product_id, string $price, int $days_ago ): void {
		Log::insert( $product_id, wc_format_decimal( $price, 4 ), $this->days_ago( $days_ago ) );
	}

	private function reference( \WC_Product $product ): float {
		$product = wc_get_product( $product->get_id() );

		return (float) Log::reference_price( $product->get_id(), wc_format_decimal( $product->get_price(), wc_get_price_decimals() ) );
	}

	/**
	 * The owner's worked example (2026-10-04): regular 150, a 100 promotion
	 * within the last 30 days, a new 80 sale starting today. The reference is
	 * 100, the lowest price in the 30 days before today's reduction; the 80
	 * itself must not count.
	 */
	public function test_reference_is_the_lowest_price_in_the_30_days_before_the_sale_started(): void {
		Log::ensure_table();
		$product = $this->create_product( '150' );
		$this->price_at( $product->get_id(), '150', 60 );
		$this->price_at( $product->get_id(), '100', 20 );
		$this->price_at( $product->get_id(), '150', 10 );

		$product->set_sale_price( '80' );
		$product->save();

		$this->assertSame( 100.0, $this->reference( $product ) );
	}

	public function test_a_running_sale_never_enters_its_own_reference_on_later_days(): void {
		global $wpdb;

		Log::ensure_table();
		$product = $this->create_product( '100' );
		$this->price_at( $product->get_id(), '100', 60 );
		$product->set_sale_price( '80' );
		$product->save();

		// Five days into the sale: everything logged today moves five days back
		// together. A rolling "last 30 days" window would now contain the 80
		// itself; the anchored reference must still be 100.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Log::table_name() . ' SET logged_at = DATE_SUB( logged_at, INTERVAL 5 DAY ) WHERE product_id = %d AND logged_at > %s', $product->get_id(), $this->days_ago( 1 ) ) );

		$this->assertSame( 100.0, $this->reference( $product ) );
	}

	public function test_an_earlier_separate_promotion_counts_toward_a_later_one(): void {
		Log::ensure_table();
		$product = $this->create_product( '150' );
		$this->price_at( $product->get_id(), '150', 60 );
		$this->price_at( $product->get_id(), '90', 15 );
		$this->price_at( $product->get_id(), '150', 5 );

		$product->set_sale_price( '120' );
		$product->save();

		$this->assertSame( 90.0, $this->reference( $product ) );
	}

	/**
	 * A deeper cut mid-sale is treated as a new reduction with its own anchor:
	 * the conservative reading, which never overstates the discount.
	 */
	public function test_changing_the_sale_price_starts_a_new_reduction(): void {
		Log::ensure_table();
		$product = $this->create_product( '100' );
		$this->price_at( $product->get_id(), '100', 60 );
		$product->set_sale_price( '80' );
		$product->save();

		$product->set_sale_price( '70' );
		$product->save();

		$this->assertSame( 80.0, $this->reference( $product ) );
	}

	public function test_a_price_older_than_the_window_still_counts_while_it_was_in_force(): void {
		Log::ensure_table();
		$product = $this->create_product( '100' );
		$this->price_at( $product->get_id(), '100', 90 );

		$product->set_sale_price( '80' );
		$product->save();

		$this->assertSame( 100.0, $this->reference( $product ) );
	}
	private function floor_line( \WC_Product $product ): string {
		$GLOBALS['product'] = wc_get_product( $product->get_id() );
		ob_start();
		( new Module() )->render_frontend_floor();

		return wp_strip_all_tags( (string) ob_get_clean() );
	}

	/**
	 * A sale already running when the module was switched on: nothing on
	 * record before it, so the line shows the sale price, not the regular one.
	 */
	public function test_a_sale_with_no_earlier_history_shows_its_own_price_as_the_floor(): void {
		Log::ensure_table();
		$product = $this->create_product( '150' );
		$product->set_sale_price( '80' );
		$product->save();
		$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( 'DELETE FROM ' . Log::table_name() . ' WHERE product_id = %d', $product->get_id() ) );

		$line = $this->floor_line( $product );

		$this->assertStringContainsString( '80', $line );
		$this->assertStringNotContainsString( '150', $line );
	}

	public function test_the_baseline_records_current_prices_once_including_variations(): void {
		global $wpdb;

		Log::ensure_table();
		$simple = $this->create_product( '150' );
		$simple->set_sale_price( '80' );
		$simple->save();

		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Variabil' );
		$parent->save();
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '60' );
		$variation->save();

		// As if the products predated the module.
		$wpdb->query( 'DELETE FROM ' . Log::table_name() );

		( new Baseline( new Module() ) )->run_batch();
		( new Baseline( new Module() ) )->run_batch();

		$this->assertSame( array( '80' ), $this->logged_prices( $simple->get_id() ) );
		$this->assertSame( array( '60' ), $this->logged_prices( $variation->get_id() ) );
		$this->assertSame( array(), $this->logged_prices( $parent->get_id() ) );
		$this->assertTrue( get_option( 'romcommerce_price_history_baseline' )['complete'] );
	}
}
