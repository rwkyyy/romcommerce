<?php

declare(strict_types=1);

namespace RomCommerce\Modules\ReviewsShowcase;

use RomCommerce\Admin\ProSection;
use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;
use RomCommerce\Support\ReviewerName;
use RomCommerce\Support\Words;
use WP_Comment;

defined( 'ABSPATH' ) || exit;

/**
 * Reviews showcase (Lite: grid; the carousel layout is Pro). Shows recent
 * approved product reviews from across the store, read straight from
 * WooCommerce's own reviews (comments with "rating"/"verified" meta), on the
 * main shop page and through [romcommerce_reviews]. Display only: reviews
 * are written by WooCommerce's review form and the multi-product review page.
 *
 * Off the Placements map on purpose: the map has no shop-archive surface, and
 * the two positions are the shop loop's own before/after hooks. On block
 * themes WooCommerce fires those hooks around the Product Collection block's
 * product template, so the same two positions work there.
 *
 * Every colour is a setting with an explicit default, never inherited from
 * the theme (owner, 2026-10-14): the cards carry their own background and
 * text colour, so they stay readable on a dark section.
 */
final class Module implements ModuleInterface, HasSettingsUi {

	private const ID = 'reviews-showcase';

	private const POSITIONS = array( 'after', 'before', 'none' );

	/** Card parts, in the order cards showed them before the order was a setting. */
	private const PARTS = array( 'rating', 'text', 'author', 'product', 'date' );

	private const DEFAULT_HIDDEN = array( 'date' );

	/**
	 * The heading sits on the merchant's own section background, which can be
	 * light or dark, so its default is a mid grey that reads on both (4.5:1
	 * on white and on black). The cards have their own background.
	 */
	private const DEFAULT_COLORS = array(
		'heading_color'   => '#767676',
		'card_background' => '#ffffff',
		'card_text_color' => '#1e1e1e',
	);

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Reviews Showcase', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		add_shortcode( 'romcommerce_reviews', array( $this, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		// After WooCommerce's pagination (priority 10) when below the products;
		// after its notices, result count and sorting when above them.
		add_action( 'woocommerce_after_shop_loop', array( $this, 'render_after_loop' ), 20 );
		add_action( 'woocommerce_before_shop_loop', array( $this, 'render_before_loop' ), 40 );
	}

	public function enqueue_assets(): void {
		wp_register_style( 'romcommerce-reviews-showcase', false, array(), ROMCOMMERCE_VERSION );
		wp_enqueue_style( 'romcommerce-reviews-showcase' );
		wp_add_inline_style( 'romcommerce-reviews-showcase', $this->styles_css() );
	}

	public function render_after_loop(): void {
		if ( 'after' === self::normalize( Settings::get( self::ID ) )['position'] && $this->on_shop_first_page() ) {
			echo $this->grid_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in grid_html().
		}
	}

	public function render_before_loop(): void {
		if ( 'before' === self::normalize( Settings::get( self::ID ) )['position'] && $this->on_shop_first_page() ) {
			echo $this->grid_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in grid_html().
		}
	}

	public function shortcode(): string {
		return $this->grid_html();
	}

	/**
	 * Comment query args for the showcase: approved reviews of published
	 * products with at least $min_rating stars, newest first. Without the
	 * post status, reviews of draft or private products would show too.
	 *
	 * @return array<string, mixed>
	 */
	public static function query_args( int $min_rating, int $count, bool $verified_only ): array {
		$meta = array(
			array(
				'key'     => 'rating',
				'value'   => $min_rating,
				'compare' => '>=',
				'type'    => 'NUMERIC',
			),
		);

		if ( $verified_only ) {
			$meta[] = array(
				'key'   => 'verified',
				'value' => '1',
			);
		}

		return array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'status'      => 'approve',
			'type'        => 'review',
			'number'      => $count,
			'orderby'     => 'comment_date_gmt',
			'order'       => 'DESC',
			'meta_query'  => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a handful of rows, filtered on WooCommerce's own review meta.
		);
	}

	/**
	 * Stored settings with every default and bound applied. Defaults keep the
	 * look stores had before these were settings, apart from the explicit
	 * colours.
	 *
	 * @param array<string, mixed> $stored
	 * @return array{heading: string, position: string, min_rating: int, count: int, columns: int, verified_only: bool, word_limit: int, short_names: bool, parts: array<int, string>, hidden_parts: array<int, string>, card_shadow: bool, card_radius: int, card_gap: int, heading_color: string, card_background: string, card_text_color: string, author_color: string, product_color: string}
	 */
	public static function normalize( array $stored ): array {
		$position  = (string) ( $stored['position'] ?? 'after' );
		$card_text = self::clean_color( (string) ( $stored['card_text_color'] ?? '' ), self::DEFAULT_COLORS['card_text_color'] );

		return array(
			'heading'         => isset( $stored['heading'] ) ? (string) $stored['heading'] : __( 'Ce spun clienții noștri', 'romcommerce' ),
			'position'        => in_array( $position, self::POSITIONS, true ) ? $position : 'after',
			'min_rating'      => self::clamp( $stored['min_rating'] ?? 4, 1, 5 ),
			'count'           => self::clamp( $stored['count'] ?? 6, 1, 12 ),
			'columns'         => self::clamp( $stored['columns'] ?? 3, 1, 4 ),
			'verified_only'   => (bool) ( $stored['verified_only'] ?? false ),
			'word_limit'      => self::clamp( $stored['word_limit'] ?? 40, 0, 200 ),
			'short_names'     => (bool) ( $stored['short_names'] ?? true ),
			'parts'           => self::sanitize_parts( isset( $stored['parts'] ) && is_array( $stored['parts'] ) ? $stored['parts'] : array() ),
			'hidden_parts'    => self::sanitize_hidden( isset( $stored['hidden_parts'] ) && is_array( $stored['hidden_parts'] ) ? $stored['hidden_parts'] : self::DEFAULT_HIDDEN ),
			'card_shadow'     => (bool) ( $stored['card_shadow'] ?? false ),
			'card_radius'     => self::clamp( $stored['card_radius'] ?? 0, 0, 32 ),
			'card_gap'        => self::clamp( $stored['card_gap'] ?? 16, 0, 48 ),
			'heading_color'   => self::clean_color( (string) ( $stored['heading_color'] ?? '' ), self::DEFAULT_COLORS['heading_color'] ),
			'card_background' => self::clean_color( (string) ( $stored['card_background'] ?? '' ), self::DEFAULT_COLORS['card_background'] ),
			'card_text_color' => $card_text,
			// Unset until the merchant picks one, so they follow the card text.
			'author_color'    => self::clean_color( (string) ( $stored['author_color'] ?? '' ), $card_text ),
			'product_color'   => self::clean_color( (string) ( $stored['product_color'] ?? '' ), $card_text ),
		);
	}

	/**
	 * Every known part exactly once: the requested ones first, in the order
	 * asked, then any left out, in their default order.
	 *
	 * @param array<int|string, mixed> $requested
	 * @return array<int, string>
	 */
	public static function sanitize_parts( array $requested ): array {
		$parts = array();
		foreach ( $requested as $part ) {
			if ( is_string( $part ) && in_array( $part, self::PARTS, true ) && ! in_array( $part, $parts, true ) ) {
				$parts[] = $part;
			}
		}

		foreach ( self::PARTS as $part ) {
			if ( ! in_array( $part, $parts, true ) ) {
				$parts[] = $part;
			}
		}

		return $parts;
	}

	/**
	 * Swaps one part with its neighbour, from a "part:up" / "part:down" move
	 * button posted without JavaScript.
	 *
	 * @param array<int, string> $parts
	 * @return array<int, string>
	 */
	public static function move_part( array $parts, string $move ): array {
		$pieces = explode( ':', $move );
		if ( 2 !== count( $pieces ) || ! in_array( $pieces[1], array( 'up', 'down' ), true ) ) {
			return $parts;
		}

		$parts = array_values( $parts );
		$from  = array_search( $pieces[0], $parts, true );
		if ( false === $from ) {
			return $parts;
		}

		$to = 'up' === $pieces[1] ? $from - 1 : $from + 1;
		if ( $to < 0 || $to >= count( $parts ) ) {
			return $parts;
		}

		$parts[ $from ] = $parts[ $to ];
		$parts[ $to ]   = $pieces[0];

		return $parts;
	}

	public static function clean_color( string $color, string $default ): string {
		$color = strtolower( trim( $color ) );

		if ( preg_match( '/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $color, $short ) ) {
			return '#' . $short[1] . $short[1] . $short[2] . $short[2] . $short[3] . $short[3];
		}

		return preg_match( '/^#[0-9a-f]{6}$/', $color ) ? $color : $default;
	}

	/**
	 * The section's inline custom properties. The border is the card text
	 * colour made translucent, so it follows whatever card colours the
	 * merchant picks. Stars get no colour here: they use the store's own
	 * star rating style (owner, 2026-10-14).
	 *
	 * @param array{columns: int, card_gap: int, card_radius: int, card_shadow: bool, heading_color: string, card_background: string, card_text_color: string, author_color: string, product_color: string} $config
	 */
	public static function style_vars( array $config ): string {
		$vars = array(
			'--rc-reviews-columns' => (string) $config['columns'],
			'--rc-reviews-gap'     => $config['card_gap'] . 'px',
			'--rc-reviews-radius'  => $config['card_radius'] . 'px',
			'--rc-reviews-shadow'  => $config['card_shadow'] ? '0 4px 16px rgba(0,0,0,.16)' : 'none',
			'--rc-reviews-heading' => $config['heading_color'],
			'--rc-reviews-bg'      => $config['card_background'],
			'--rc-reviews-text'    => $config['card_text_color'],
			'--rc-reviews-line'    => self::rgba( $config['card_text_color'], '.15' ),
			'--rc-reviews-author'  => $config['author_color'],
			'--rc-reviews-product' => $config['product_color'],
		);

		$css = '';
		foreach ( $vars as $name => $value ) {
			$css .= $name . ':' . $value . ';';
		}

		return $css;
	}

	/**
	 * @param mixed $value
	 */
	private static function clamp( $value, int $min, int $max ): int {
		return max( $min, min( $max, is_scalar( $value ) ? (int) $value : $min ) );
	}

	/**
	 * The review text is never hidden: a card without it says nothing.
	 *
	 * @param array<int|string, mixed> $hidden
	 * @return array<int, string>
	 */
	private static function sanitize_hidden( array $hidden ): array {
		return array_values( array_intersect( array_diff( self::PARTS, array( 'text' ) ), $hidden ) );
	}

	private static function rgba( string $hex, string $alpha ): string {
		$rgb = sscanf( $hex, '#%02x%02x%02x' );
		$rgb = is_array( $rgb ) ? array_map( 'intval', $rgb ) : array( 0, 0, 0 );

		return 'rgba(' . implode( ',', $rgb ) . ',' . $alpha . ')';
	}

	private function grid_html(): string {
		$config  = self::normalize( Settings::get( self::ID ) );
		$reviews = get_comments( self::query_args( $config['min_rating'], $config['count'], $config['verified_only'] ) );
		$parts   = array_values( array_diff( $config['parts'], $config['hidden_parts'] ) );

		$cards = '';
		foreach ( is_array( $reviews ) ? $reviews : array() as $review ) {
			if ( $review instanceof WP_Comment && '' !== trim( (string) $review->comment_content ) ) {
				$cards .= $this->card_html( $review, $parts, $config['word_limit'], $config['short_names'] );
			}
		}

		if ( '' === $cards ) {
			return '';
		}

		// The woocommerce class lets WooCommerce's own ".woocommerce .star-rating"
		// rules style the stars on pages that aren't WooCommerce pages, the way
		// WooCommerce's shortcodes wrap their output.
		$html = '<section class="romcommerce-reviews woocommerce" style="' . esc_attr( self::style_vars( $config ) ) . '">';
		if ( '' !== $config['heading'] ) {
			$html .= '<h2 class="romcommerce-reviews-heading">' . esc_html( $config['heading'] ) . '</h2>';
		}

		return $html . '<ul class="romcommerce-reviews-grid">' . $cards . '</ul></section>';
	}

	/**
	 * One block element per part, so no two parts ever share a line.
	 *
	 * @param array<int, string> $parts Visible parts, top to bottom.
	 */
	private function card_html( WP_Comment $review, array $parts, int $word_limit, bool $short_names ): string {
		$html = '';
		foreach ( $parts as $part ) {
			$html .= $this->part_html( $part, $review, $word_limit, $short_names );
		}

		return '<li class="romcommerce-review">' . $html . '</li>';
	}

	private function part_html( string $part, WP_Comment $review, int $word_limit, bool $short_names ): string {
		switch ( $part ) {
			case 'rating':
				return $this->stars_html( (int) get_comment_meta( (int) $review->comment_ID, 'rating', true ) );

			case 'text':
				return '<blockquote class="romcommerce-review-text">' . esc_html( Words::limit( wp_strip_all_tags( (string) $review->comment_content ), $word_limit ) ) . '</blockquote>';

			case 'author':
				// Through get_comment_author() so Reviewer Name Privacy, when on, applies here too.
				return '<p class="romcommerce-review-author">' . esc_html( ReviewerName::for_display( get_comment_author( $review ), $short_names ) ) . '</p>';

			case 'product':
				$product = wc_get_product( (int) $review->comment_post_ID );
				if ( ! $product || 'publish' !== $product->get_status() ) {
					return '';
				}

				return '<p class="romcommerce-review-product"><a href="' . esc_url( (string) $product->get_permalink() ) . '">' . esc_html( $product->get_name() ) . '</a></p>';

			case 'date':
				return '<p class="romcommerce-review-date"><time datetime="' . esc_attr( (string) get_comment_date( 'c', $review ) ) . '">' . esc_html( (string) get_comment_date( '', $review ) ) . '</time></p>';
		}

		return '';
	}

	/** WooCommerce's own markup, so the stars look like the rest of the store's ratings. */
	private function stars_html( int $rating ): string {
		$rating = max( 0, min( 5, $rating ) );
		if ( 0 === $rating ) {
			return '';
		}

		return '<div class="romcommerce-review-stars">' . wc_get_rating_html( (float) $rating ) . '</div>';
	}

	private function on_shop_first_page(): bool {
		return function_exists( 'is_shop' ) && is_shop() && ! is_paged();
	}

	/**
	 * Selectors are two classes deep and every element gets its colour, margin
	 * and padding from here, so theme rules on h2, blockquote, ul and a (often
	 * one element or one class plus an element) can't restyle the cards on
	 * classic or block themes.
	 */
	private function styles_css(): string {
		$s = '.romcommerce-reviews';

		return $s . '{margin:2em 0;padding:0;}'
			. $s . ' .romcommerce-reviews-heading{margin:0 0 16px;padding:0;color:var(--rc-reviews-heading);font-size:1.5em;line-height:1.3;text-align:start;}'
			. $s . ' .romcommerce-reviews-grid{list-style:none;margin:0;padding:0;display:grid;gap:var(--rc-reviews-gap);grid-template-columns:repeat(var(--rc-reviews-columns),minmax(0,1fr));}'
			. '@media (max-width:782px){' . $s . ' .romcommerce-reviews-grid{grid-template-columns:1fr;}}'
			. $s . ' .romcommerce-review{list-style:none;margin:0;padding:16px;display:flex;flex-direction:column;gap:8px;text-align:start;overflow-wrap:break-word;background:var(--rc-reviews-bg);color:var(--rc-reviews-text);border:1px solid var(--rc-reviews-line);border-radius:var(--rc-reviews-radius);box-shadow:var(--rc-reviews-shadow);}'
			. $s . ' .romcommerce-review::before,' . $s . ' .romcommerce-review::after{content:none;}'
			. $s . ' .romcommerce-review>*{margin:0;padding:0;color:inherit;}'
			// Layout only: the star colour stays the store's.
			. $s . ' .romcommerce-review-stars .star-rating{float:none;margin:0;}'
			. $s . ' .romcommerce-review-text{border:0;background:none;font-style:normal;font-size:1em;quotes:none;}'
			. $s . ' .romcommerce-review-text::before,' . $s . ' .romcommerce-review-text::after{content:none;}'
			. $s . ' .romcommerce-review-author,' . $s . ' .romcommerce-review-product,' . $s . ' .romcommerce-review-date{font-size:.875em;}'
			. $s . ' .romcommerce-review-author{font-weight:600;color:var(--rc-reviews-author);}'
			. $s . ' .romcommerce-review-product{color:var(--rc-reviews-product);}'
			. $s . ' .romcommerce-review-product a{color:inherit;text-decoration:underline;}';
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above via SettingsForm::verify().
			$enabled = isset( $_POST['rc_enabled'] );
			$input   = array(
				'heading'         => sanitize_text_field( wp_unslash( (string) ( $_POST['rc_heading'] ?? '' ) ) ),
				'position'        => sanitize_key( wp_unslash( (string) ( $_POST['rc_position'] ?? 'after' ) ) ),
				'min_rating'      => absint( wp_unslash( $_POST['rc_min_rating'] ?? 4 ) ),
				'count'           => absint( wp_unslash( $_POST['rc_count'] ?? 6 ) ),
				'columns'         => absint( wp_unslash( $_POST['rc_columns'] ?? 3 ) ),
				'verified_only'   => isset( $_POST['rc_verified_only'] ),
				'word_limit'      => absint( wp_unslash( $_POST['rc_word_limit'] ?? 40 ) ),
				'short_names'     => isset( $_POST['rc_short_names'] ),
				'parts'           => isset( $_POST['rc_parts'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['rc_parts'] ) ) : array(),
				'shown_parts'     => isset( $_POST['rc_show_parts'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['rc_show_parts'] ) ) : array(),
				'move'            => sanitize_text_field( wp_unslash( (string) ( $_POST['rc_move'] ?? '' ) ) ),
				'card_shadow'     => isset( $_POST['rc_card_shadow'] ),
				'card_radius'     => absint( wp_unslash( $_POST['rc_card_radius'] ?? 0 ) ),
				'card_gap'        => absint( wp_unslash( $_POST['rc_card_gap'] ?? 16 ) ),
				'heading_color'   => sanitize_text_field( wp_unslash( (string) ( $_POST['rc_heading_color'] ?? '' ) ) ),
				'card_background' => sanitize_text_field( wp_unslash( (string) ( $_POST['rc_card_background'] ?? '' ) ) ),
				'card_text_color' => sanitize_text_field( wp_unslash( (string) ( $_POST['rc_card_text_color'] ?? '' ) ) ),
				'author_color'    => sanitize_text_field( wp_unslash( (string) ( $_POST['rc_author_color'] ?? '' ) ) ),
				'product_color'   => sanitize_text_field( wp_unslash( (string) ( $_POST['rc_product_color'] ?? '' ) ) ),
			);
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			$this->save( $enabled, $input );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
		}

		$config = self::normalize( Settings::get( self::ID ) );

		echo '<p>' . esc_html__( 'Shows recent product reviews from across your store as a grid on the shop page. Use [romcommerce_reviews] to show it anywhere else, such as the homepage.', 'romcommerce' ) . '</p>';
		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Module', 'romcommerce' ) . '</th><td><label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ' . esc_html__( 'Enable', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row"><label for="rc-reviews-heading">' . esc_html__( 'Heading', 'romcommerce' ) . '</label></th><td><input type="text" id="rc-reviews-heading" class="regular-text" name="rc_heading" value="' . esc_attr( $config['heading'] ) . '"></td></tr>';

		echo '<tr><th scope="row"><label for="rc-reviews-position">' . esc_html__( 'Shop page', 'romcommerce' ) . '</label></th><td><select id="rc-reviews-position" name="rc_position">';
		$positions = array(
			'after'  => __( 'Below the products', 'romcommerce' ),
			'before' => __( 'Above the products', 'romcommerce' ),
			'none'   => __( 'Not on the shop page (shortcode only)', 'romcommerce' ),
		);
		foreach ( $positions as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $config['position'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Shown on the first page of the main shop page only.', 'romcommerce' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="rc-reviews-min">' . esc_html__( 'Minimum rating', 'romcommerce' ) . '</label></th><td><select id="rc-reviews-min" name="rc_min_rating">';
		for ( $stars = 5; $stars >= 1; $stars-- ) {
			/* translators: %d: number of stars */
			echo '<option value="' . esc_attr( (string) $stars ) . '"' . selected( $config['min_rating'], $stars, false ) . '>' . esc_html( sprintf( _n( '%d star or more', '%d stars or more', $stars, 'romcommerce' ), $stars ) ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="rc-reviews-count">' . esc_html__( 'Number of reviews', 'romcommerce' ) . '</label></th><td><input type="number" id="rc-reviews-count" name="rc_count" min="1" max="12" class="small-text" value="' . esc_attr( (string) $config['count'] ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="rc-reviews-columns">' . esc_html__( 'Columns', 'romcommerce' ) . '</label></th><td><input type="number" id="rc-reviews-columns" name="rc_columns" min="1" max="4" class="small-text" value="' . esc_attr( (string) $config['columns'] ) . '"></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Verified buyers', 'romcommerce' ) . '</th><td><label><input type="checkbox" name="rc_verified_only" value="1"' . checked( $config['verified_only'], true, false ) . '> ' . esc_html__( 'Only show reviews from customers who bought the product', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row"><label for="rc-reviews-words">' . esc_html__( 'Review length', 'romcommerce' ) . '</label></th><td><input type="number" id="rc-reviews-words" name="rc_word_limit" min="0" max="200" class="small-text" value="' . esc_attr( (string) $config['word_limit'] ) . '"> ' . esc_html__( 'words at most', 'romcommerce' );
		echo '<p class="description">' . esc_html__( 'Longer reviews stop after the last whole word and end with "…". 0 shows every review in full.', 'romcommerce' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Reviewer names', 'romcommerce' ) . '</th><td><label><input type="checkbox" name="rc_short_names" value="1"' . checked( $config['short_names'], true, false ) . '> ' . esc_html__( 'Show the first name and initials only (Ion Popescu becomes Ion P.)', 'romcommerce' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'The full name is left out of the page itself, not just hidden. A name that is an email address always shows as "Anonymous". With the Reviewer Name Privacy module on, names here are shortened either way.', 'romcommerce' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Card content', 'romcommerce' ) . '</th><td>';
		$this->render_parts_field( $config['parts'], $config['hidden_parts'] );
		echo '</td></tr>';

		$colors = array(
			'heading_color'   => array( __( 'Heading colour', 'romcommerce' ), __( 'The heading sits on your page\'s own background, so pick a colour that reads on it (white on a dark section).', 'romcommerce' ) ),
			'card_background' => array( __( 'Card background', 'romcommerce' ), '' ),
			'card_text_color' => array( __( 'Review text colour', 'romcommerce' ), __( 'Also used, lighter, for the card border.', 'romcommerce' ) ),
			'author_color'    => array( __( 'Reviewer name colour', 'romcommerce' ), '' ),
			'product_color'   => array( __( 'Product name colour', 'romcommerce' ), __( 'The stars use your store\'s own star rating style.', 'romcommerce' ) ),
		);
		foreach ( $colors as $key => $copy ) {
			$id = 'rc-reviews-' . str_replace( '_', '-', $key );
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $copy[0] ) . '</label></th><td><input type="color" id="' . esc_attr( $id ) . '" name="' . esc_attr( 'rc_' . $key ) . '" value="' . esc_attr( $config[ $key ] ) . '">';
			if ( '' !== $copy[1] ) {
				echo '<p class="description">' . esc_html( $copy[1] ) . '</p>';
			}
			echo '</td></tr>';
		}

		echo '<tr><th scope="row">' . esc_html__( 'Drop shadow', 'romcommerce' ) . '</th><td><label><input type="checkbox" name="rc_card_shadow" value="1"' . checked( $config['card_shadow'], true, false ) . '> ' . esc_html__( 'Add a soft shadow under each card', 'romcommerce' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="rc-reviews-radius">' . esc_html__( 'Corner radius', 'romcommerce' ) . '</label></th><td><input type="number" id="rc-reviews-radius" name="rc_card_radius" min="0" max="32" class="small-text" value="' . esc_attr( (string) $config['card_radius'] ) . '"> px</td></tr>';
		echo '<tr><th scope="row"><label for="rc-reviews-gap">' . esc_html__( 'Space between cards', 'romcommerce' ) . '</label></th><td><input type="number" id="rc-reviews-gap" name="rc_card_gap" min="0" max="48" class="small-text" value="' . esc_attr( (string) $config['card_gap'] ) . '"> px</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';

		ProSection::open( __( 'Carousel and product images', 'romcommerce' ) );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Layout', 'romcommerce' ) . '</th><td><label><input type="checkbox" disabled> ' . esc_html__( 'Show the reviews as a sliding carousel instead of a grid', 'romcommerce' ) . '</label></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Product image', 'romcommerce' ) . '</th><td><label><input type="checkbox" disabled> ' . esc_html__( 'Show the product image in each card, when the product has one', 'romcommerce' ) . '</label></td></tr>';
		echo '</tbody></table>';
		ProSection::close();
	}

	/**
	 * The card parts as a list the merchant reorders with the arrow buttons.
	 * The hidden rc_parts[] inputs post in list order, so with JavaScript an
	 * arrow just moves its row and Save stores the new order. Without it, each
	 * arrow is a submit button and save() applies the move server-side. The
	 * handlers are inline because this pane arrives by AJAX, where <script>
	 * tags don't run.
	 *
	 * @param array<int, string> $parts
	 * @param array<int, string> $hidden
	 */
	private function render_parts_field( array $parts, array $hidden ): void {
		$labels = array(
			'rating'  => __( 'Star rating', 'romcommerce' ),
			'text'    => __( 'Review text', 'romcommerce' ),
			'author'  => __( 'Reviewer name', 'romcommerce' ),
			'product' => __( 'Product name, linked to the product', 'romcommerce' ),
			'date'    => __( 'Review date', 'romcommerce' ),
		);

		// Enter in a text field "clicks" the form's first submit button, which
		// is the top row's up arrow; this lets that click save the form instead
		// of being swallowed as a move.
		$enter_saves = "var f=document.activeElement;if(f&&f.form===this.form&&'INPUT'===f.tagName&&/^(text|number)$/.test(f.type)){return true;}";
		$move_up     = $enter_saves . "var r=this.closest('li'),s=r.previousElementSibling;if(s){r.parentNode.insertBefore(r,s);}this.focus();return false;";
		$move_down   = $enter_saves . "var r=this.closest('li'),s=r.nextElementSibling;if(s){r.parentNode.insertBefore(s,r);}this.focus();return false;";

		echo '<ol style="list-style:none;margin:0;padding:0;max-width:30em;">';
		foreach ( $parts as $part ) {
			$label = $labels[ $part ] ?? $part;
			echo '<li style="display:flex;align-items:center;gap:8px;margin:0 0 4px;padding:4px 8px;border:1px solid #c3c4c7;background:#fff;">';
			echo '<input type="hidden" name="rc_parts[]" value="' . esc_attr( $part ) . '">';
			echo '<label style="flex:1;">';
			if ( 'text' === $part ) {
				echo '<input type="checkbox" checked disabled> ';
			} else {
				echo '<input type="checkbox" name="rc_show_parts[]" value="' . esc_attr( $part ) . '"' . checked( ! in_array( $part, $hidden, true ), true, false ) . '> ';
			}
			echo esc_html( $label ) . '</label>';
			/* translators: %s: card part, such as "Reviewer name" */
			echo '<button type="submit" class="button button-small" name="rc_move" value="' . esc_attr( $part . ':up' ) . '" aria-label="' . esc_attr( sprintf( __( 'Move up: %s', 'romcommerce' ), $label ) ) . '" onclick="' . esc_attr( $move_up ) . '">&uarr;</button>';
			/* translators: %s: card part, such as "Reviewer name" */
			echo '<button type="submit" class="button button-small" name="rc_move" value="' . esc_attr( $part . ':down' ) . '" aria-label="' . esc_attr( sprintf( __( 'Move down: %s', 'romcommerce' ), $label ) ) . '" onclick="' . esc_attr( $move_down ) . '">&darr;</button>';
			echo '</li>';
		}
		echo '</ol>';
		echo '<p class="description">' . esc_html__( 'Each ticked item gets its own line in the card, top to bottom in this order. Use the arrows to move an item, then save. The review text is always shown.', 'romcommerce' ) . '</p>';
	}

	/**
	 * Saves the pane from values render_settings() already read and
	 * sanitised; never reads $_POST itself.
	 *
	 * @param array<string, mixed> $input
	 */
	private function save( bool $enabled, array $input ): void {
		$shown = isset( $input['shown_parts'] ) && is_array( $input['shown_parts'] ) ? $input['shown_parts'] : array();

		$input['parts']        = self::move_part( self::sanitize_parts( isset( $input['parts'] ) && is_array( $input['parts'] ) ? $input['parts'] : array() ), (string) ( $input['move'] ?? '' ) );
		$input['hidden_parts'] = array_values( array_diff( self::PARTS, $shown ) );
		unset( $input['shown_parts'], $input['move'] );

		Settings::set_enabled( self::ID, $enabled );
		Settings::update( self::ID, self::normalize( $input ) );
	}
}
