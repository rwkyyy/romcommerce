<?php

declare(strict_types=1);

namespace RomCommerce\Modules\ReviewerNames;

use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;
use RomCommerce\Support\ReviewerName;
use WP_Comment;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Shortens product reviewers' names on the storefront to the first name and
 * initials (owner, 2026-10-14). The name is changed where WordPress hands it
 * out, get_comment_author(), so it never reaches the page, unlike CSS
 * truncation, which only hides it. That one filter covers WooCommerce's review
 * list (classic template and Product Reviews block), its review structured
 * data, the Recent Reviews widget, comment feeds and review plugins that ask
 * WordPress for the name (TWB WooCommerce Reviews does). Plugins that print
 * the raw database field are out of reach.
 *
 * The public REST routes that return review names get the same treatment for
 * visitors; anyone who can moderate comments still sees full names there.
 */
final class Module implements ModuleInterface, HasSettingsUi {

	private const ID = 'reviewer-names';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Reviewer Name Privacy', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		// Late, so the name other plugins settled on is the one shortened.
		add_filter( 'get_comment_author', array( $this, 'filter_author' ), 20, 3 );
		add_filter( 'rest_prepare_comment', array( $this, 'filter_rest_comment' ), 20, 2 );
		// Not rest_post_dispatch: that only runs for HTTP requests, and
		// rest_do_request() (block data preloading) would skip it.
		add_filter( 'rest_request_after_callbacks', array( $this, 'filter_store_api_reviews' ), 20, 3 );
	}

	/**
	 * @param string|mixed    $author
	 * @param string|int      $comment_id
	 * @param WP_Comment|null $comment
	 * @return string|mixed
	 */
	public function filter_author( $author, $comment_id = 0, $comment = null ) {
		if ( ! is_string( $author ) || ! $comment instanceof WP_Comment || ! self::on_storefront() || ! self::is_customer_review( $comment ) ) {
			return $author;
		}

		return ReviewerName::for_display( $author );
	}

	/**
	 * /wp/v2/comments/<id> is public for any approved comment, reviews included.
	 *
	 * @param WP_REST_Response|mixed $response
	 * @param WP_Comment|mixed       $comment
	 * @return WP_REST_Response|mixed
	 */
	public function filter_rest_comment( $response, $comment ) {
		if ( ! $response instanceof WP_REST_Response || ! $comment instanceof WP_Comment || current_user_can( 'moderate_comments' ) || ! self::is_customer_review( $comment ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( is_array( $data ) && isset( $data['author_name'] ) && is_string( $data['author_name'] ) ) {
			$data['author_name'] = ReviewerName::for_display( $data['author_name'] );
			$response->set_data( $data );
		}

		return $response;
	}

	/**
	 * WooCommerce's Store API reads the raw comment_author for its public
	 * review list (used by the All Reviews / Reviews by Product blocks),
	 * bypassing get_comment_author(), so its response is rewritten here.
	 *
	 * @param WP_REST_Response|mixed $result
	 * @param mixed                  $handler
	 * @param WP_REST_Request|mixed  $request
	 * @return WP_REST_Response|mixed
	 */
	public function filter_store_api_reviews( $result, $handler, $request ) {
		if ( ! $result instanceof WP_REST_Response || ! $request instanceof WP_REST_Request || ! self::is_store_api_reviews_route( $request->get_route() ) || current_user_can( 'moderate_comments' ) ) {
			return $result;
		}

		$data = $result->get_data();
		if ( ! is_array( $data ) ) {
			return $result;
		}

		foreach ( $data as $index => $review ) {
			if ( is_array( $review ) && isset( $review['reviewer'] ) && is_string( $review['reviewer'] ) ) {
				$data[ $index ]['reviewer'] = ReviewerName::for_display( $review['reviewer'] );
			}
		}
		$result->set_data( $data );

		return $result;
	}

	public static function is_store_api_reviews_route( string $route ): bool {
		return 1 === preg_match( '#^/wc/store(/v\d+)?/products/reviews(/|$)#', $route );
	}

	/**
	 * A page being drawn for a visitor. template_redirect never fires in
	 * wp-admin, admin-ajax, REST, cron or wp-comments-post.php, so wp-admin
	 * screens and the notification emails WordPress sends after a review is
	 * posted keep the full name.
	 */
	private static function on_storefront(): bool {
		return ! is_admin() && did_action( 'template_redirect' ) > 0;
	}

	/**
	 * A comment on a product, left out when shop staff wrote it: a reply
	 * signed "Magazinul X" must not become "Magazinul X.".
	 */
	private static function is_customer_review( WP_Comment $comment ): bool {
		if ( 'product' !== get_post_type( (int) $comment->comment_post_ID ) ) {
			return false;
		}

		$user_id = (int) $comment->user_id;

		return ! ( $user_id > 0 && user_can( $user_id, 'moderate_comments' ) );
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via SettingsForm::verify().
			Settings::set_enabled( self::ID, isset( $_POST['rc_enabled'] ) );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'Shows the people who review your products by their first name and initials (Ion Popescu becomes Ion P., Maria Elena Ionescu becomes Maria E. I.). The full name is left out of the page itself, not just hidden with CSS. A name that is an email address shows as "Anonymous".', 'romcommerce' ) . '</p>';
		echo '<p>' . esc_html__( 'Applies to the review list on product pages (classic and block themes), the review data WooCommerce adds for search engines, the Recent Reviews widget, WooCommerce\'s review blocks, and review plugins that read names through WordPress, such as TWB WooCommerce Reviews. Replies from your shop staff keep their name.', 'romcommerce' ) . '</p>';
		echo '<p>' . esc_html__( 'Full names stay in wp-admin and in the emails WordPress sends you about new reviews. A plugin that prints names straight from the database is not changed. A name written surname first, such as Popescu Ion, shows as Popescu I.', 'romcommerce' ) . '</p>';

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Module', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ';
		echo esc_html__( 'Enable', 'romcommerce' ) . '</label></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';
	}
}
