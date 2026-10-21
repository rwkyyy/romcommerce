<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\ReviewsShowcase;

use Brain\Monkey\Functions;
use RomCommerce\Modules\ReviewsShowcase\Module;
use RomCommerce\Tests\TestCase;

final class ModuleTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
	}

	public function test_query_args_filter_on_rating_and_optionally_on_verified_buyers(): void {
		$args = Module::query_args( 4, 6, false );

		self::assertSame( 'product', $args['post_type'] );
		self::assertSame( 'publish', $args['post_status'] );
		self::assertSame( 'review', $args['type'] );
		self::assertSame( 6, $args['number'] );
		self::assertCount( 1, $args['meta_query'] );
		self::assertSame( 4, $args['meta_query'][0]['value'] );

		self::assertSame( 'verified', Module::query_args( 4, 6, true )['meta_query'][1]['key'] );
	}

	public function test_unsaved_settings_keep_the_previous_card_layout(): void {
		$config = Module::normalize( array() );

		self::assertSame( array( 'rating', 'text', 'author', 'product', 'date' ), $config['parts'] );
		self::assertSame( array( 'date' ), $config['hidden_parts'] );
		self::assertSame( 40, $config['word_limit'] );
		self::assertTrue( $config['short_names'] );
		self::assertFalse( $config['card_shadow'] );
		self::assertSame( 0, $config['card_radius'] );
		self::assertSame( 16, $config['card_gap'] );
		self::assertSame( 'after', $config['position'] );
		self::assertSame( 'Ce spun clienții noștri', $config['heading'] );
	}

	public function test_unsaved_settings_still_give_the_cards_their_own_colours(): void {
		$config = Module::normalize( array() );

		self::assertSame( '#ffffff', $config['card_background'] );
		self::assertSame( '#1e1e1e', $config['card_text_color'] );
		self::assertSame( '#767676', $config['heading_color'] );
		self::assertArrayNotHasKey( 'star_color', $config );
	}

	public function test_name_and_product_colours_follow_the_card_text_until_picked(): void {
		$config = Module::normalize( array( 'card_text_color' => '#333333' ) );
		self::assertSame( '#333333', $config['author_color'] );
		self::assertSame( '#333333', $config['product_color'] );

		$config = Module::normalize(
			array(
				'card_text_color' => '#333333',
				'author_color'    => '#ff0000',
				'product_color'   => '#0000ff',
			)
		);
		self::assertSame( '#ff0000', $config['author_color'] );
		self::assertSame( '#0000ff', $config['product_color'] );
	}

	public function test_settings_are_clamped_to_their_ranges(): void {
		$config = Module::normalize(
			array(
				'count'       => 99,
				'columns'     => 0,
				'word_limit'  => -3,
				'card_radius' => 500,
				'card_gap'    => array( 'x' ),
				'position'    => 'sidebar',
			)
		);

		self::assertSame( 12, $config['count'] );
		self::assertSame( 1, $config['columns'] );
		self::assertSame( 0, $config['word_limit'] );
		self::assertSame( 32, $config['card_radius'] );
		self::assertSame( 0, $config['card_gap'] );
		self::assertSame( 'after', $config['position'] );
	}

	public function test_the_review_text_can_never_be_hidden(): void {
		self::assertSame( array( 'author', 'date' ), Module::normalize( array( 'hidden_parts' => array( 'text', 'author', 'date', 'bogus' ) ) )['hidden_parts'] );
	}

	public function test_sanitize_parts_keeps_the_requested_order_and_adds_missing_parts_once(): void {
		self::assertSame(
			array( 'author', 'rating', 'text', 'product', 'date' ),
			Module::sanitize_parts( array( 'author', 'image', 'author', 'rating', 42, '<b>' ) )
		);
		self::assertSame( array( 'rating', 'text', 'author', 'product', 'date' ), Module::sanitize_parts( array() ) );
	}

	public function test_move_part_swaps_with_the_neighbour_and_stops_at_the_ends(): void {
		$parts = array( 'rating', 'text', 'author', 'product', 'date' );

		self::assertSame( array( 'rating', 'author', 'text', 'product', 'date' ), Module::move_part( $parts, 'author:up' ) );
		self::assertSame( array( 'rating', 'text', 'product', 'author', 'date' ), Module::move_part( $parts, 'author:down' ) );
		self::assertSame( $parts, Module::move_part( $parts, 'rating:up' ) );
		self::assertSame( $parts, Module::move_part( $parts, 'date:down' ) );
		self::assertSame( $parts, Module::move_part( $parts, 'image:up' ) );
		self::assertSame( $parts, Module::move_part( $parts, 'author:left' ) );
		self::assertSame( $parts, Module::move_part( $parts, '' ) );
	}

	public function test_clean_color_accepts_hex_only(): void {
		self::assertSame( '#aabbcc', Module::clean_color( '#ABC', '#000000' ) );
		self::assertSame( '#ffffff', Module::clean_color( ' #FFFFFF ', '#000000' ) );
		self::assertSame( '#000000', Module::clean_color( 'red', '#000000' ) );
		self::assertSame( '#000000', Module::clean_color( '#12345g', '#000000' ) );
		self::assertSame( '#000000', Module::clean_color( '#fff;background:url(x)', '#000000' ) );
	}

	public function test_style_vars_derive_the_border_from_the_text_colour_and_leave_the_stars_alone(): void {
		$css = Module::style_vars( Module::normalize( array() ) );

		self::assertStringContainsString( '--rc-reviews-line:rgba(30,30,30,.15);', $css );
		self::assertStringContainsString( '--rc-reviews-author:#1e1e1e;', $css );
		self::assertStringNotContainsString( 'star', $css );
		self::assertStringContainsString( '--rc-reviews-shadow:none;', $css );
		self::assertStringContainsString( '--rc-reviews-radius:0px;', $css );

		$css = Module::style_vars( Module::normalize( array( 'card_shadow' => true ) ) );
		self::assertStringNotContainsString( '--rc-reviews-shadow:none;', $css );
	}
}
