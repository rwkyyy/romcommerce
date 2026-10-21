<?php

declare(strict_types=1);

namespace RomCommerce\Modules\PriceHistory;

defined( 'ABSPATH' ) || exit;

/**
 * Append-only price-change log backing the Omnibus 30-day floor. A dedicated
 * table (not post meta) because it is an ever-growing, date-range-queried
 * series — post meta would either bloat one row unboundedly or scatter one
 * row per change. The CRUD-only rule is an *orders* rule (HPOS abstraction);
 * products carry no such constraint, so a purpose-built table is correct here.
 */
final class Log {

	private const DB_VERSION        = '1';
	private const DB_VERSION_OPTION = 'romcommerce_price_history_db';

	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'romcommerce_price_history';
	}

	/**
	 * Version-guarded so it is a no-op on every request after the first. Called
	 * on admin_init (covers the file-deploy workflow, which never fires the
	 * activation hook) and from the activation hook (covers normal installs).
	 */
	public static function ensure_table(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::create_table();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	private static function create_table(): void {
		global $wpdb;

		$table   = self::table_name();
		$collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	product_id bigint(20) unsigned NOT NULL,
	price decimal(19,4) NOT NULL,
	logged_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY product_logged (product_id, logged_at)
) {$collate};";

		dbDelta( $sql );
	}

	public static function insert( int $product_id, string $price, string $logged_at ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table (not WP core), no caching applicable to a write.
		$wpdb->insert(
			self::table_name(),
			array(
				'product_id' => $product_id,
				'price'      => $price,
				'logged_at'  => $logged_at,
			),
			array( '%d', '%s', '%s' )
		);
	}

	/**
	 * The most recent log row: the price currently on record and when it took
	 * effect.
	 *
	 * @return array{id: int, price: string, logged_at: string}|null
	 */
	public static function latest( int $product_id ): ?array {
		global $wpdb;

		$table = self::table_name();
		// $table is derived from $wpdb->prefix (never user input) so it cannot
		// be a prepared placeholder; the values below are prepared.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table (not WP core), single-row lookup on an indexed column; caching would add invalidation complexity disproportionate to this query's cost.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, price, logged_at FROM {$table} WHERE product_id = %d ORDER BY logged_at DESC, id DESC LIMIT 1",
				$product_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $row ) || ! isset( $row['id'], $row['price'], $row['logged_at'] ) ) {
			return null;
		}

		return array(
			'id'        => (int) $row['id'],
			'price'     => (string) $row['price'],
			'logged_at' => (string) $row['logged_at'],
		);
	}

	/**
	 * The Omnibus reference for the price now in force: the lowest price
	 * applied in the 30 days *before* that price took effect (HG 947/2000
	 * art. 4^1(1)-(2), introduced by HG 686/2022). The window is anchored to
	 * when the current price was logged, not to "now", so a running sale never
	 * enters its own reference, not even on later days, while the sale price
	 * itself stays in the log and counts toward any later, separate reduction.
	 * A sale price changed mid-sale is a new reduction and gets a new anchor.
	 *
	 * @param string $current_price The product's effective price, normalised
	 *                              the same way it is logged.
	 */
	public static function reference_price( int $product_id, string $current_price ): ?string {
		$latest = self::latest( $product_id );

		if ( null !== $latest && (float) $latest['price'] === (float) $current_price ) {
			return self::floor_before( $product_id, $latest['logged_at'], $latest['id'] );
		}

		// Never logged (e.g. set by an import that bypassed WooCommerce's save
		// hooks), so it can only have started now.
		return self::floor_before( $product_id, gmdate( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Lowest price applied during the 30 days before $anchor (a UTC
	 * Y-m-d H:i:s timestamp). Includes the price already in effect when that
	 * window opened (the latest row before it): a price set 60 days earlier and
	 * left unchanged was still being applied throughout the window even though
	 * its log row predates it.
	 *
	 * @param int $anchor_id The anchor's own log row, when it has one. Rows in
	 *                       the same second count as earlier only if logged
	 *                       before it, so a change made seconds before the sale
	 *                       still counts; 0 means every row up to $anchor counts.
	 */
	public static function floor_before( int $product_id, string $anchor, int $anchor_id = 0 ): ?string {
		global $wpdb;

		$table        = self::table_name();
		$anchor_ts    = (int) strtotime( $anchor . ' UTC' );
		$window_start = gmdate( 'Y-m-d H:i:s', $anchor_ts - 30 * DAY_IN_SECONDS );
		$id_bound     = $anchor_id > 0 ? $anchor_id : PHP_INT_MAX;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table (not WP core); $table is derived from $wpdb->prefix, never user input.
		$in_window = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN(price) FROM {$table} WHERE product_id = %d AND logged_at >= %s AND ( logged_at < %s OR ( logged_at = %s AND id < %d ) )",
				$product_id,
				$window_start,
				$anchor,
				$anchor,
				$id_bound
			)
		);

		$carried = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT price FROM {$table} WHERE product_id = %d AND logged_at < %s ORDER BY logged_at DESC, id DESC LIMIT 1",
				$product_id,
				$window_start
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$candidates = array();
		if ( null !== $in_window ) {
			$candidates[] = (float) $in_window;
		}
		if ( null !== $carried ) {
			$candidates[] = (float) $carried;
		}

		if ( empty( $candidates ) ) {
			return null;
		}

		return (string) min( $candidates );
	}

	/**
	 * @return array<int, array{price: string, logged_at: string}>
	 */
	public static function recent( int $product_id, int $days = 30 ): array {
		global $wpdb;

		$table = self::table_name();
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table (not WP core); $table is derived from $wpdb->prefix, never user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT price, logged_at FROM {$table} WHERE product_id = %d AND logged_at >= %s ORDER BY logged_at DESC, id DESC",
				$product_id,
				$since
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}
}
