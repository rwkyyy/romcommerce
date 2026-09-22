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

	public static function last_price( int $product_id ): ?string {
		global $wpdb;

		$table = self::table_name();
		// $table is derived from $wpdb->prefix (never user input) so it cannot
		// be a prepared placeholder; the values below are prepared.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table (not WP core), single-row lookup on an indexed column; caching would add invalidation complexity disproportionate to this query's cost.
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT price FROM {$table} WHERE product_id = %d ORDER BY logged_at DESC, id DESC LIMIT 1",
				$product_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null === $value ? null : (string) $value;
	}

	/**
	 * Lowest effective price applicable across the last 30 days. Includes the
	 * price already in effect when the window opened (the most recent entry
	 * before the window start): a price set 60 days ago and left unchanged was
	 * still the effective price throughout the last 30 days, so Omnibus counts
	 * it even though its log row predates the window.
	 */
	public static function thirty_day_floor( int $product_id ): ?string {
		global $wpdb;

		$table        = self::table_name();
		$window_start = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table (not WP core); $table is derived from $wpdb->prefix, never user input.
		$in_window = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN(price) FROM {$table} WHERE product_id = %d AND logged_at >= %s",
				$product_id,
				$window_start
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
