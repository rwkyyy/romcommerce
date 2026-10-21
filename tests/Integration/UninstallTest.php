<?php
/**
 * uninstall.php against a real options table and real user meta. The
 * price-history DROP TABLE isn't asserted: WP_UnitTestCase rewrites DROP TABLE
 * to DROP TEMPORARY TABLE inside tests, so it can't be observed here.
 *
 * @package RomCommerce
 */

declare(strict_types=1);

namespace RomCommerce\Tests\Integration;

final class UninstallTest extends TestCase {

	/**
	 * uninstall.php declares its functions at file scope and runs on include,
	 * so it can only be required once per PHP process; later calls reuse it.
	 */
	private function run_uninstall(): void {
		if ( function_exists( 'romcommerce_uninstall_site' ) ) {
			romcommerce_uninstall_site();
			return;
		}

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'romcommerce/romcommerce.php' );
		}

		require WP_PLUGIN_DIR . '/romcommerce/uninstall.php';
	}

	private function seed( bool $delete_data ): int {
		$core                             = get_option( 'romcommerce_core', array() );
		$core['delete_data_on_uninstall'] = $delete_data;
		update_option( 'romcommerce_core', $core );
		update_option( 'romcommerce_whatsapp_fab', array( 'position' => 'right' ) );
		update_option( 'woocommerce_currency', 'RON' );

		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'romcommerce_delivery_addresses', array( array( 'label' => 'Acasă' ) ) );

		return $user_id;
	}

	public function test_uninstall_removes_settings_but_keeps_customer_data_by_default(): void {
		$user_id = $this->seed( false );

		$this->run_uninstall();
		wp_cache_flush();

		$this->assertFalse( get_option( 'romcommerce_core' ) );
		$this->assertFalse( get_option( 'romcommerce_whatsapp_fab' ) );
		$this->assertSame( 'RON', get_option( 'woocommerce_currency' ), 'Uninstall must never touch options outside its own prefix.' );
		$this->assertNotEmpty( get_user_meta( $user_id, 'romcommerce_delivery_addresses', true ) );
	}

	public function test_uninstall_always_removes_the_maof_phone_index(): void {
		$this->seed( false );
		$order = $this->create_order( array( 'phone' => '0712 345 678' ) );
		$this->assertNotSame( '', $order->get_meta( '_romcommerce_phone_e164' ) );

		$this->run_uninstall();
		wp_cache_flush();

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( '', $order->get_meta( '_romcommerce_phone_e164' ) );
		$this->assertSame( '0712 345 678', $order->get_billing_phone() );
	}

	public function test_uninstall_always_removes_cached_reference_prices_but_keeps_prps(): void {
		$this->seed( false );
		$product_id = $this->create_product( '150' )->get_id();
		update_post_meta( $product_id, '_romcommerce_omnibus_reference', array( 'price' => '150', 'reference' => '' ) );
		update_post_meta( $product_id, '_romcommerce_prp', '180' );

		$this->run_uninstall();
		wp_cache_flush();

		$this->assertSame( '', get_post_meta( $product_id, '_romcommerce_omnibus_reference', true ) );
		$this->assertSame( '180', get_post_meta( $product_id, '_romcommerce_prp', true ) );
	}

	public function test_uninstall_with_delete_data_also_removes_saved_addresses(): void {
		$user_id    = $this->seed( true );
		$product_id = $this->create_product( '150' )->get_id();
		update_post_meta( $product_id, '_romcommerce_prp', '180' );

		$this->run_uninstall();
		wp_cache_flush();

		$this->assertSame( '', get_post_meta( $product_id, '_romcommerce_prp', true ) );

		$this->assertFalse( get_option( 'romcommerce_core' ) );
		$this->assertSame( '', get_user_meta( $user_id, 'romcommerce_delivery_addresses', true ) );
	}
}
