<?php
/**
 * Reviews showcase against real WooCommerce reviews (comments + rating meta).
 *
 * @package RomCommerce
 */

declare(strict_types=1);

namespace RomCommerce\Tests\Integration;

use RomCommerce\Settings;

final class ReviewsShowcaseTest extends TestCase {

	private function review( int $product_id, int $rating, string $text, string $author = 'Ion Popescu', bool $verified = false ): void {
		$id = wp_insert_comment(
			array(
				'comment_post_ID'  => $product_id,
				'comment_author'   => $author,
				'comment_content'  => $text,
				'comment_type'     => 'review',
				'comment_approved' => 1,
			)
		);
		update_comment_meta( $id, 'rating', $rating );
		update_comment_meta( $id, 'verified', $verified ? 1 : 0 );
	}

	public function test_shows_reviews_at_or_above_the_minimum_rating_with_short_names(): void {
		$product = $this->create_product();
		$this->review( $product->get_id(), 5, 'Excelent, recomand.' );
		$this->review( $product->get_id(), 2, 'Nu mi-a plăcut.' );

		$html = do_shortcode( '[romcommerce_reviews]' );

		self::assertStringContainsString( 'Excelent, recomand.', $html );
		self::assertStringNotContainsString( 'Nu mi-a plăcut.', $html );
		self::assertStringContainsString( 'Ion P.', $html );
		self::assertStringNotContainsString( 'Popescu', $html );
	}

	public function test_verified_only_hides_reviews_from_non_buyers(): void {
		$product = $this->create_product();
		$this->review( $product->get_id(), 5, 'De la un cumpărător.', 'Ana Ionescu', true );
		$this->review( $product->get_id(), 5, 'De la un vizitator.', 'Dan Pop', false );
		Settings::update( 'reviews-showcase', array( 'verified_only' => true ) );

		$html = do_shortcode( '[romcommerce_reviews]' );

		self::assertStringContainsString( 'De la un cumpărător.', $html );
		self::assertStringNotContainsString( 'De la un vizitator.', $html );
	}

	public function test_renders_nothing_without_reviews(): void {
		self::assertSame( '', do_shortcode( '[romcommerce_reviews]' ) );
	}
}
