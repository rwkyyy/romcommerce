<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Support;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use RomCommerce\Support\Blocks;
use RomCommerce\Tests\TestCase;

final class BlocksTest extends TestCase {

	/** @var callable|null */
	private $filter;

	private function capture_filter( string $hook ): void {
		Filters\expectAdded( $hook )->once()->whenHappen(
			function ( $callback ): void {
				$this->filter = $callback;
			}
		);
	}

	public function test_insert_prints_before_the_block(): void {
		$this->capture_filter( 'render_block_woocommerce/cart' );

		Blocks::insert(
			'woocommerce/cart',
			'before',
			static function (): void {
				echo '<p>note</p>';
			}
		);

		self::assertSame( '<p>note</p><div>cart</div>', ( $this->filter )( '<div>cart</div>' ) );
	}

	public function test_insert_prints_after_the_block(): void {
		$this->capture_filter( 'render_block_woocommerce/checkout-actions-block' );

		Blocks::insert(
			'woocommerce/checkout-actions-block',
			'after',
			static function (): void {
				echo '<p>note</p>';
			}
		);

		self::assertSame( '<div>actions</div><p>note</p>', ( $this->filter )( '<div>actions</div>' ) );
	}

	public function test_insert_leaves_the_block_untouched_when_nothing_is_printed(): void {
		$this->capture_filter( 'render_block_woocommerce/cart' );

		Blocks::insert( 'woocommerce/cart', 'before', static function (): void {} );

		self::assertSame( '<div>cart</div>', ( $this->filter )( '<div>cart</div>' ) );
	}

	public function test_checkout_uses_block_falls_back_to_the_checkout_page_content(): void {
		Functions\expect( 'wc_get_page_id' )->once()->with( 'checkout' )->andReturn( 7 );
		Functions\expect( 'has_block' )->once()->with( 'woocommerce/checkout', 7 )->andReturn( true );

		self::assertTrue( Blocks::checkout_uses_block() );
	}

	public function test_cart_uses_block_is_false_without_a_cart_page(): void {
		Functions\when( 'wc_get_page_id' )->justReturn( -1 );
		Functions\expect( 'has_block' )->never();

		self::assertFalse( Blocks::cart_uses_block() );
	}
	public function test_product_template_inserts_print_in_order_on_both_sides_of_the_pages_own_block(): void {
		$this->stub_block_product_page( 7 );

		$print = static function ( string $text ): callable {
			return static function () use ( $text ): void {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test fixture.
			};
		};

		Blocks::insert_in_product_template( 'test/order', 'before', $print( 'B' ), 20 );
		Blocks::insert_in_product_template( 'test/order', 'after', $print( 'D' ), 50 );
		Blocks::insert_in_product_template( 'test/order', 'before', $print( 'A' ), 10 );
		Blocks::insert_in_product_template( 'test/order', 'after', $print( 'C' ), 5 );

		self::assertSame( 'AB<x>CD', Blocks::render_product_inserts( '<x>', array( 'blockName' => 'test/order' ), $this->block_for( 7 ) ) );
	}

	public function test_product_template_inserts_skip_the_same_block_repeated_for_another_product(): void {
		$this->stub_block_product_page( 7 );

		Blocks::insert_in_product_template(
			'test/related',
			'after',
			static function (): void {
				echo 'note';
			}
		);

		self::assertSame( '<x>', Blocks::render_product_inserts( '<x>', array( 'blockName' => 'test/related' ), $this->block_for( 9 ) ) );
	}

	public function test_product_template_inserts_do_nothing_on_classic_themes(): void {
		Functions\when( 'wp_is_block_theme' )->justReturn( false );
		Functions\when( 'is_product' )->justReturn( true );

		Blocks::insert_in_product_template(
			'test/classic',
			'after',
			static function (): void {
				echo 'note';
			}
		);

		self::assertSame( '<x>', Blocks::render_product_inserts( '<x>', array( 'blockName' => 'test/classic' ), $this->block_for( 7 ) ) );
	}

	public function test_product_template_inserts_share_one_filter_per_block(): void {
		Filters\expectAdded( 'render_block_test/shared' )->once();

		Blocks::insert_in_product_template( 'test/shared', 'before', static function (): void {} );
		Blocks::insert_in_product_template( 'test/shared', 'after', static function (): void {} );
	}

	private function stub_block_product_page( int $product_id ): void {
		Functions\when( 'wp_is_block_theme' )->justReturn( true );
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( $product_id );
	}

	private function block_for( int $post_id ): object {
		$block          = new \stdClass();
		$block->context = array( 'postId' => $post_id );

		return $block;
	}
}
