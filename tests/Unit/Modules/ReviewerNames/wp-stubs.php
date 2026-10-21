<?php
/**
 * Bare stand-ins for the WordPress classes Reviewer Name Privacy type-checks
 * against. Unit tests never load WordPress, so without these every
 * instanceof check would fail and the filters could not be exercised.
 *
 * @package RomCommerce
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Comment' ) ) {
	final class WP_Comment {
		/** @var string */
		public $comment_ID = '0';
		/** @var string */
		public $comment_post_ID = '0';
		/** @var string */
		public $user_id = '0';
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		/** @var mixed */
		private $data;

		/** @param mixed $data */
		public function __construct( $data = null ) {
			$this->data = $data;
		}

		/** @return mixed */
		public function get_data() {
			return $this->data;
		}

		/** @param mixed $data */
		public function set_data( $data ): void {
			$this->data = $data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		/** @var string */
		private $route;

		public function __construct( string $method = 'GET', string $route = '' ) {
			$this->route = $route;
		}

		public function get_route(): string {
			return $this->route;
		}
	}
}
