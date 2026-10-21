<?php
/**
 * Placement::hook_block() against WooCommerce's real block cart and checkout,
 * so a renamed inner block shows up here instead of as a silently missing
 * widget on a merchant's store.
 *
 * @package RomCommerce
 */

declare(strict_types=1);

namespace RomCommerce\Tests\Integration;

use ReflectionMethod;
use RomCommerce\Support\Placement;
use WC_Install;

final class BlockPlacementTest extends TestCase {

	private function block_content( string $page ): string {
		if ( ! method_exists( WC_Install::class, 'get_' . $page . '_block_content' ) ) {
			$this->markTestSkipped( 'This WooCommerce has no built-in block ' . $page . ' page content to render.' );
		}

		$method = new ReflectionMethod( WC_Install::class, 'get_' . $page . '_block_content' );
		$method->setAccessible( true );

		return (string) $method->invoke( null );
	}

	/** @return array<string, array{0: string}> */
	public function surfaces(): array {
		return array(
			'cart'     => array( 'cart' ),
			'checkout' => array( 'checkout' ),
		);
	}

	/** @dataProvider surfaces */
	public function test_every_slot_renders_in_the_block_page( string $surface ): void {
		$slots = Placement::surface_slots( $surface );
		foreach ( $slots as $slot ) {
			Placement::hook_block(
				$slot,
				static function () use ( $slot ): void {
					echo '<!--rc-slot:' . esc_html( $slot ) . '-->';
				}
			);
		}

		$html = do_blocks( $this->block_content( $surface ) );

		foreach ( $slots as $slot ) {
			self::assertStringContainsString( '<!--rc-slot:' . $slot . '-->', $html, $slot );
		}
	}

	/**
	 * checkout_review is left out: the block checkout's order summary is a
	 * sidebar column whose markup follows the whole fields column. The block
	 * checkout also puts the order note after the payment block, unlike the
	 * classic form, so its two slots are expected there.
	 */
	public function test_checkout_column_slots_render_in_page_order(): void {
		$slots = array_values( array_diff( Placement::surface_slots( 'checkout' ), array( 'checkout_review' ) ) );
		foreach ( $slots as $slot ) {
			Placement::hook_block(
				$slot,
				static function () use ( $slot ): void {
					echo '<!--rc-slot:' . esc_html( $slot ) . '-->';
				}
			);
		}

		$html = do_blocks( $this->block_content( 'checkout' ) );

		$positions = array();
		foreach ( $slots as $slot ) {
			$positions[ $slot ] = strpos( $html, '<!--rc-slot:' . $slot . '-->' );
		}
		$sorted = $positions;
		asort( $sorted );

		$notes    = array( 'checkout_before_notes', 'checkout_after_notes' );
		$expected = array();
		foreach ( array_diff( $slots, $notes ) as $slot ) {
			$expected[] = $slot;
			if ( 'checkout_after_methods' === $slot ) {
				array_push( $expected, ...$notes );
			}
		}

		self::assertSame( $expected, array_keys( $sorted ) );
	}
}
