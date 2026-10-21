<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\ReviewerNames;

use Brain\Monkey\Functions;
use RomCommerce\Modules\ReviewerNames\Module;
use RomCommerce\Tests\TestCase;
use WP_Comment;
use WP_REST_Request;
use WP_REST_Response;

require_once __DIR__ . '/wp-stubs.php';

final class ModuleTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( false );
	}

	private function review( int $user_id = 0 ): WP_Comment {
		$comment                  = new WP_Comment();
		$comment->comment_ID      = '7';
		$comment->comment_post_ID = '12';
		$comment->user_id         = (string) $user_id;

		return $comment;
	}

	public function test_shortens_a_product_review_name_on_the_storefront(): void {
		self::assertSame( 'Maria E. I.', ( new Module() )->filter_author( 'Maria Elena Ionescu', 7, $this->review() ) );
		self::assertSame( 'Anonymous', ( new Module() )->filter_author( 'maria@example.com', 7, $this->review() ) );
	}

	public function test_leaves_names_alone_in_wp_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		self::assertSame( 'Maria Ionescu', ( new Module() )->filter_author( 'Maria Ionescu', 7, $this->review() ) );
	}

	public function test_leaves_names_alone_outside_a_page_render(): void {
		Functions\when( 'did_action' )->justReturn( 0 );

		self::assertSame( 'Maria Ionescu', ( new Module() )->filter_author( 'Maria Ionescu', 7, $this->review() ) );
	}

	public function test_leaves_blog_comments_alone(): void {
		Functions\when( 'get_post_type' )->justReturn( 'post' );

		self::assertSame( 'Maria Ionescu', ( new Module() )->filter_author( 'Maria Ionescu', 7, $this->review() ) );
	}

	public function test_leaves_shop_staff_replies_alone(): void {
		Functions\when( 'user_can' )->justReturn( true );

		self::assertSame( 'Magazinul Exemplu', ( new Module() )->filter_author( 'Magazinul Exemplu', 7, $this->review( 3 ) ) );
	}

	public function test_passes_through_when_wordpress_found_no_comment(): void {
		self::assertSame( 'Anonim', ( new Module() )->filter_author( 'Anonim', 0, null ) );
	}

	public function test_store_api_review_list_is_shortened_for_visitors(): void {
		$response = new WP_REST_Response(
			array(
				array( 'reviewer' => 'Ion Popescu' ),
				array( 'reviewer' => 'Maria Elena Ionescu' ),
			)
		);

		$result = ( new Module() )->filter_store_api_reviews( $response, null, new WP_REST_Request( 'GET', '/wc/store/v1/products/reviews' ) );

		self::assertSame( array( array( 'reviewer' => 'Ion P.' ), array( 'reviewer' => 'Maria E. I.' ) ), $result->get_data() );
	}

	public function test_store_api_review_list_is_untouched_for_moderators_and_other_routes(): void {
		$data = array( array( 'reviewer' => 'Ion Popescu' ) );

		$other = ( new Module() )->filter_store_api_reviews( new WP_REST_Response( $data ), null, new WP_REST_Request( 'GET', '/wc/store/v1/products' ) );
		self::assertSame( $data, $other->get_data() );

		Functions\when( 'current_user_can' )->justReturn( true );
		$moderator = ( new Module() )->filter_store_api_reviews( new WP_REST_Response( $data ), null, new WP_REST_Request( 'GET', '/wc/store/v1/products/reviews' ) );
		self::assertSame( $data, $moderator->get_data() );
	}

	public function test_public_rest_comment_name_is_shortened_for_visitors(): void {
		$response = ( new Module() )->filter_rest_comment( new WP_REST_Response( array( 'author_name' => 'Ion Popescu' ) ), $this->review() );

		self::assertSame( array( 'author_name' => 'Ion P.' ), $response->get_data() );
	}

	public function test_store_api_route_match(): void {
		self::assertTrue( Module::is_store_api_reviews_route( '/wc/store/v1/products/reviews' ) );
		self::assertTrue( Module::is_store_api_reviews_route( '/wc/store/products/reviews' ) );
		self::assertFalse( Module::is_store_api_reviews_route( '/wc/store/v1/products/reviewsx' ) );
		self::assertFalse( Module::is_store_api_reviews_route( '/wc/v3/products/reviews' ) );
	}
}
