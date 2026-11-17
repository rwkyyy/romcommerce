<?php

declare(strict_types=1);

namespace RomCommerce\Modules\AvailabilitySorting;

use DateTimeImmutable;
use RomCommerce\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Catalog Sorting's "Sort by discount" part (the Discount Sorting module until
 * it was merged in, 2026-11-11): the biggest discounts first, then the other
 * products in WooCommerce's default order. Discounts are worked out once a day
 * at the merchant's hour (owner, 2026-11-10) and kept in post meta, so a
 * catalog request reads one stored number per product and computes nothing.
 * Its settings live in the module's option under "discount_" keys.
 */
final class DiscountOrder {

	public const ORDERBY = 'discount';

	/**
	 * Put in front of the orderby query var. WP_Query skips a key it doesn't
	 * know when it builds ORDER BY, but the var keeps it, so the marker reaches
	 * posts_clauses for the main catalog query and the [products] shortcode
	 * alike, with no state carried between hooks.
	 */
	private const MARKER = 'romcommerce_discount';

	public const MODE_DEFAULT = 'default';
	public const MODE_OPTION  = 'option';

	// After WooCommerce starts and ends the day's scheduled sales at midnight.
	public const DEFAULT_HOUR = 1;

	// The 30-day measure reads that module's price log.
	private const PRICE_HISTORY = 'price-history';

	/** @var string */
	private $module_id;

	/** @var Recalculator */
	private $recalculator;

	public function __construct( string $module_id ) {
		$this->module_id    = $module_id;
		$this->recalculator = new Recalculator(
			function (): string {
				return $this->effective_measure();
			}
		);
	}

	public function boot(): void {
		add_action( 'init', array( $this, 'reconcile_cron' ) );
		add_action( Recalculator::HOOK, array( $this, 'run_scheduled' ) );
		add_action( 'romcommerce/module_toggled', array( $this, 'on_toggled' ), 10, 2 );
		$this->recalculator->register();

		add_filter( 'woocommerce_catalog_orderby', array( $this, 'add_sort_option' ) );
		add_filter( 'woocommerce_default_catalog_orderby', array( $this, 'default_orderby' ) );
		add_filter( 'woocommerce_get_catalog_ordering_args', array( $this, 'ordering_args' ), 10, 2 );
		// Before the stock order's 999, which then goes in front of this one:
		// out-of-stock products stay last, and the discounts lead within each
		// group.
		add_filter( 'posts_clauses', array( $this, 'order_clauses' ), 998, 2 );
	}

	public function enabled(): bool {
		return (bool) ( Settings::get( $this->module_id )['discount_enabled'] ?? false );
	}

	/**
	 * Self-healing schedule, like the store report's. No daily event means the
	 * module was just switched on outside its pane (the modules grid, WP-CLI),
	 * so the discounts are also worked out now in the background instead of
	 * waiting for the first night.
	 */
	public function reconcile_cron(): void {
		if ( ! $this->enabled() || false !== wp_next_scheduled( Recalculator::HOOK ) ) {
			return;
		}

		$this->schedule();
		$this->recalculator->queue();
	}

	public function run_scheduled(): void {
		if ( ! $this->enabled() ) {
			return;
		}

		$this->schedule();
		$this->recalculator->start();
	}

	/**
	 * @param string $module_id
	 * @param bool   $enabled
	 */
	public function on_toggled( $module_id, $enabled ): void {
		if ( $this->module_id === $module_id && ! $enabled ) {
			$this->switch_off();

			return;
		}

		// Price history switched on or off changes what the 30-day measure
		// works with (see effective_measure()), so the stored order is redone
		// now rather than at the next daily run.
		if ( self::PRICE_HISTORY === $module_id && $this->enabled() && Recalculator::MEASURE_OMNIBUS === $this->measure() ) {
			$this->recalculator->queue();
		}
	}

	/**
	 * For Lite's WP-CLI command and ability.
	 *
	 * @param callable():bool $in_time
	 * @return array{discounted: int, complete: bool}
	 */
	public function run_now( callable $in_time ): array {
		$this->recalculator->start();
		while ( $this->recalculator->state()['running'] && $in_time() ) {
			$this->recalculator->run_batch();
		}

		$state = $this->recalculator->state();

		return array(
			'discounted' => $state['discounted'],
			'complete'   => ! $state['running'],
		);
	}

	/**
	 * @param array<string, string> $options
	 * @return array<string, string>
	 */
	public function add_sort_option( $options ) {
		$options = is_array( $options ) ? $options : array();

		if ( $this->enabled() ) {
			$options[ self::ORDERBY ] = __( 'Sort by discount', 'romcommerce' );
		}

		return $options;
	}

	/**
	 * Filtered rather than written to WooCommerce's option, so switching the
	 * part off gives the store its own default order back.
	 *
	 * @param string $orderby
	 * @return string
	 */
	public function default_orderby( $orderby ) {
		return $this->enabled() && self::MODE_DEFAULT === $this->mode() ? self::ORDERBY : $orderby;
	}

	/**
	 * WooCommerce falls back to its default order for a key it doesn't know;
	 * that order stays as the tie-break behind the discount.
	 *
	 * @param array<string, mixed> $args
	 * @param string               $orderby
	 * @return array<string, mixed>
	 */
	public function ordering_args( $args, $orderby ) {
		if ( self::ORDERBY !== $orderby || ! is_array( $args ) || ! $this->enabled() ) {
			return $args;
		}

		$args['orderby'] = self::MARKER . ' menu_order title';
		$args['order']   = 'ASC';

		return $args;
	}

	/**
	 * A correlated subquery instead of a meta join, for the same reason as the
	 * stock order's: WP_Query's meta_key join would drop every product without
	 * a stored discount from the listing. A product with none sorts after all
	 * discounted ones (MySQL puts NULL last in a descending order).
	 *
	 * @param array<string, string> $clauses
	 * @param \WP_Query             $query
	 * @return array<string, string>
	 */
	public function order_clauses( $clauses, $query ) {
		if ( ! is_array( $clauses ) || ! $query instanceof \WP_Query ) {
			return $clauses;
		}

		$orderby = $query->get( 'orderby' );
		if ( ! is_string( $orderby ) || false === strpos( $orderby, self::MARKER ) ) {
			return $clauses;
		}

		global $wpdb;

		$rank = "(SELECT CAST(rc_discount.meta_value AS DECIMAL(14,4)) FROM {$wpdb->postmeta} rc_discount WHERE rc_discount.post_id = {$wpdb->posts}.ID AND rc_discount.meta_key = '" . Recalculator::META_KEY . "' LIMIT 1) DESC";

		$current            = trim( (string) ( $clauses['orderby'] ?? '' ) );
		$clauses['orderby'] = '' === $current ? $rank : $rank . ', ' . $current;

		return $clauses;
	}

	public static function next_run( DateTimeImmutable $now, int $hour ): DateTimeImmutable {
		$candidate = $now->setTime( max( 0, min( 23, $hour ) ), 0 );

		return $candidate > $now ? $candidate : $candidate->modify( '+1 day' );
	}

	/**
	 * The settings this part saves, merged into the module's option by the pane.
	 *
	 * @return array<string, mixed>
	 */
	public static function sanitize( bool $enabled, string $mode, string $measure, int $hour ): array {
		return array(
			'discount_enabled' => $enabled,
			'discount_mode'    => self::MODE_OPTION === $mode ? self::MODE_OPTION : self::MODE_DEFAULT,
			'discount_measure' => self::sanitize_measure( $measure ),
			'discount_hour'    => max( 0, min( 23, $hour ) ),
		);
	}

	/**
	 * Taken before the pane saves, so after_save() can tell what changed.
	 *
	 * @return array{active: bool, had_event: bool, measure: string, hour: int}
	 */
	public function snapshot( bool $module_enabled ): array {
		return array(
			'active'    => $module_enabled && $this->enabled(),
			'had_event' => false !== wp_next_scheduled( Recalculator::HOOK ),
			'measure'   => $this->effective_measure(),
			'hour'      => $this->hour(),
		);
	}

	/**
	 * @param array{active: bool, had_event: bool, measure: string, hour: int} $before
	 */
	public function after_save( array $before, bool $module_enabled ): void {
		// The part's own switch fires no module_toggled, so switching it off
		// cleans up here.
		if ( ! $module_enabled || ! $this->enabled() ) {
			$this->switch_off();

			return;
		}

		if ( ! $before['had_event'] || $before['hour'] !== $this->hour() ) {
			$this->schedule();
		}

		// Switching on, or a new measure, needs every value worked out again;
		// a changed hour or mode doesn't.
		if ( ! $before['active'] || ! $before['had_event'] || $before['measure'] !== $this->effective_measure() ) {
			$this->recalculator->start( true );
		}
	}

	public function recalculate(): void {
		$this->recalculator->start();
	}

	public function render_rows( bool $module_enabled ): void {
		echo '<tr><th scope="row">' . esc_html__( 'Sort by discount', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_discount_enabled" value="1"' . checked( $this->enabled(), true, false ) . '> ';
		echo esc_html__( 'Add a "Sort by discount" order', 'romcommerce' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Sorts the shop, category, tag and product search listings with the biggest discounts first, then the other products in your default order.', 'romcommerce' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Use it as', 'romcommerce' ) . '</th><td><fieldset>';
		echo '<label><input type="radio" name="rc_discount_mode" value="' . esc_attr( self::MODE_DEFAULT ) . '"' . checked( $this->mode(), self::MODE_DEFAULT, false ) . '> ';
		echo esc_html__( 'The shop\'s default order', 'romcommerce' ) . '</label><br>';
		echo '<label><input type="radio" name="rc_discount_mode" value="' . esc_attr( self::MODE_OPTION ) . '"' . checked( $this->mode(), self::MODE_OPTION, false ) . '> ';
		echo esc_html__( 'Only a choice in the shop\'s sorting dropdown', 'romcommerce' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Either way, "Sort by discount" is added to the sorting dropdown. As the default order, it takes the place of WooCommerce\'s default product sorting while this module is on.', 'romcommerce' ) . '</p>';
		echo '</fieldset></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Biggest discount by', 'romcommerce' ) . '</th><td><fieldset>';
		echo '<label><input type="radio" name="rc_discount_measure" value="' . esc_attr( Recalculator::MEASURE_PERCENT ) . '"' . checked( $this->measure(), Recalculator::MEASURE_PERCENT, false ) . '> ';
		echo esc_html__( 'Percentage off the regular price', 'romcommerce' ) . '</label><br>';
		echo '<label><input type="radio" name="rc_discount_measure" value="' . esc_attr( Recalculator::MEASURE_AMOUNT ) . '"' . checked( $this->measure(), Recalculator::MEASURE_AMOUNT, false ) . '> ';
		echo esc_html__( 'Amount saved off the regular price', 'romcommerce' ) . '</label><br>';
		echo '<label><input type="radio" name="rc_discount_measure" value="' . esc_attr( Recalculator::MEASURE_OMNIBUS ) . '"' . checked( $this->measure(), Recalculator::MEASURE_OMNIBUS, false ) . '> ';
		echo esc_html__( 'Percentage off the lowest price of the 30 days before the sale', 'romcommerce' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'By percentage, half off a cheap product comes before a fifth off an expensive one. By amount, the most money saved comes first, which puts expensive products ahead. A variable product counts its biggest variation discount.', 'romcommerce' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'That lowest price is the one Price history shows next to a sale, so the order follows the reductions shoppers see. A sale that isn\'t below it, or one that started before Price history was on, counts as no reduction. Needs Price history on; while it is off, the percentage off the regular price is used.', 'romcommerce' ) . '</p>';
		if ( Recalculator::MEASURE_OMNIBUS === $this->measure() && ! Settings::is_enabled( self::PRICE_HISTORY ) ) {
			echo '<p class="description"><strong>' . esc_html__( 'Price history is off, so the percentage off the regular price is used for now.', 'romcommerce' ) . '</strong></p>';
		}
		echo '</fieldset></td></tr>';

		echo '<tr><th scope="row"><label for="rc-discount-hour">' . esc_html__( 'Recalculate every day at', 'romcommerce' ) . '</label></th><td><select id="rc-discount-hour" name="rc_discount_hour">';
		for ( $hour = 0; $hour <= 23; $hour++ ) {
			echo '<option value="' . esc_attr( (string) $hour ) . '"' . selected( $this->hour(), $hour, false ) . '>' . esc_html( sprintf( '%02d:00', $hour ) ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: timezone name, e.g. Europe/Bucharest */
				__( 'In your site\'s timezone (%s). A product put on sale or taken off sale keeps its place until then. 01:00 comes after WooCommerce starts and ends the day\'s scheduled sales at midnight. WordPress runs scheduled tasks on the first visit after this time.', 'romcommerce' ),
				wp_timezone_string()
			)
		) . '</p></td></tr>';

		if ( $module_enabled && $this->enabled() ) {
			$this->render_status_rows();
		}
	}

	public function render_recalculate_button( bool $module_enabled ): void {
		if ( ! $module_enabled || ! $this->enabled() ) {
			return;
		}

		echo '<p><button type="submit" name="rc_recalculate" value="1" class="button button-secondary">' . esc_html__( 'Recalculate now', 'romcommerce' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'Uses the saved settings, so save any change first. The first products are done right away and the rest in the background. If your site uses a page cache, the new order shows once its pages refresh.', 'romcommerce' ) . '</p>';
	}

	private function render_status_rows(): void {
		$state     = $this->recalculator->state();
		$date_time = (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );

		if ( $state['running'] ) {
			echo '<tr><th scope="row">' . esc_html__( 'In progress', 'romcommerce' ) . '</th><td>';
			/* translators: %d: number of products with a discount found so far */
			echo esc_html( sprintf( _n( 'Working out discounts in the background, %d product with a discount so far.', 'Working out discounts in the background, %d products with a discount so far.', $state['discounted'], 'romcommerce' ), $state['discounted'] ) );
			echo '</td></tr>';
		}

		if ( $state['finished'] > 0 ) {
			echo '<tr><th scope="row">' . esc_html__( 'Last calculated', 'romcommerce' ) . '</th><td>';
			echo esc_html(
				sprintf(
					/* translators: 1: date and time, 2: number of products */
					_n( '%1$s: %2$d product with a discount.', '%1$s: %2$d products with a discount.', $state['last_count'], 'romcommerce' ),
					(string) wp_date( $date_time, $state['finished'] ),
					$state['last_count']
				)
			);
			echo '</td></tr>';
		}

		$next = wp_next_scheduled( Recalculator::HOOK );
		if ( false !== $next ) {
			echo '<tr><th scope="row">' . esc_html__( 'Next calculation', 'romcommerce' ) . '</th><td>' . esc_html( (string) wp_date( $date_time, $next ) ) . '</td></tr>';
		}
	}

	private function switch_off(): void {
		wp_unschedule_hook( Recalculator::HOOK );
		$this->recalculator->stop();
	}

	private function schedule(): void {
		wp_unschedule_hook( Recalculator::HOOK );
		wp_schedule_single_event( self::next_run( new DateTimeImmutable( 'now', wp_timezone() ), $this->hour() )->getTimestamp(), Recalculator::HOOK );
	}

	private function mode(): string {
		return self::MODE_OPTION === ( Settings::get( $this->module_id )['discount_mode'] ?? '' ) ? self::MODE_OPTION : self::MODE_DEFAULT;
	}

	private function measure(): string {
		return self::sanitize_measure( (string) ( Settings::get( $this->module_id )['discount_measure'] ?? '' ) );
	}

	/**
	 * Price history keeps no log while it is off, so the 30-day reference
	 * would be missing or out of date; the percentage off the regular price
	 * stands in until it is back on.
	 */
	private function effective_measure(): string {
		$measure = $this->measure();

		return Recalculator::MEASURE_OMNIBUS === $measure && ! Settings::is_enabled( self::PRICE_HISTORY ) ? Recalculator::MEASURE_PERCENT : $measure;
	}

	private static function sanitize_measure( string $measure ): string {
		return in_array( $measure, array( Recalculator::MEASURE_AMOUNT, Recalculator::MEASURE_OMNIBUS ), true ) ? $measure : Recalculator::MEASURE_PERCENT;
	}

	private function hour(): int {
		return max( 0, min( 23, (int) ( Settings::get( $this->module_id )['discount_hour'] ?? self::DEFAULT_HOUR ) ) );
	}
}
