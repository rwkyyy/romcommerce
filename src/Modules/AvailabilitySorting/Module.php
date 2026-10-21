<?php

declare(strict_types=1);

namespace RomCommerce\Modules\AvailabilitySorting;

use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Moves out-of-stock products to the end of catalog listings while keeping
 * whatever sort order is active (the store's default, or the shopper's pick)
 * as the order inside each group. Stock is prepended to the SQL ORDER BY,
 * not applied by re-sorting each page in PHP, so pagination stays consistent:
 * page 2 continues where page 1 stopped instead of each page burying its own
 * out-of-stock products.
 */
final class Module implements ModuleInterface, HasSettingsUi {

	private const ID = 'availability-sorting';

	/** Query var marking a WP_Query this module should reorder. */
	public const QUERY_VAR = 'romcommerce_stock_last';

	public const SORT_ALWAYS       = 'always';
	public const SORT_DEFAULT_ONLY = 'default_only';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Availability Sorting', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		add_action( 'woocommerce_product_query', array( $this, 'flag_catalog_query' ) );
		add_filter( 'woocommerce_shortcode_products_query', array( $this, 'flag_shortcode_query' ) );
		// WooCommerce's price/popularity/rating sorts overwrite the whole ORDER BY
		// from posts_clauses at priority 10; running later prepends to their order
		// instead of being overwritten by it.
		add_filter( 'posts_clauses', array( $this, 'order_clauses' ), 999, 2 );
	}

	/**
	 * Fires only for WooCommerce's main catalog query (shop, product
	 * taxonomies, product search), never for admin lists or secondary loops.
	 *
	 * @param \WP_Query $query
	 */
	public function flag_catalog_query( $query ): void {
		if ( ! $query instanceof \WP_Query ) {
			return;
		}

		if ( self::SORT_DEFAULT_ONLY === $this->shopper_sort() && $this->shopper_chose_sort() ) {
			return;
		}

		$query->set( self::QUERY_VAR, true );
	}

	/**
	 * The shortcode caches its results in a transient keyed on these args, so
	 * the flag carries the statuses being moved: switching the module or the
	 * backorder option then changes the key instead of serving a cached loop
	 * in the old order.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public function flag_shortcode_query( $args ) {
		$args = is_array( $args ) ? $args : array();

		if ( $this->shortcodes() ) {
			$args[ self::QUERY_VAR ] = implode( ',', $this->buried_statuses() );
		}

		return $args;
	}

	/**
	 * @param array<string, string> $clauses
	 * @param \WP_Query             $query
	 * @return array<string, string>
	 */
	public function order_clauses( $clauses, $query ) {
		if ( ! is_array( $clauses ) || ! $query instanceof \WP_Query || ! $query->get( self::QUERY_VAR ) ) {
			return $clauses;
		}

		global $wpdb;

		return self::prepend_stock_order( $clauses, $this->buried_statuses(), $wpdb->prefix . 'wc_product_meta_lookup', $wpdb->posts );
	}

	/**
	 * A correlated subquery rather than a JOIN: WooCommerce adds its own lookup
	 * table join only when the JOIN clause doesn't already mention
	 * "wc_product_meta_lookup", so a join of ours would make its price/rating
	 * sort reference an alias that was never joined. The subquery is a primary
	 * key lookup per row. A product with no lookup row yet sorts as in stock;
	 * burying a product on missing data would be worse than not moving it.
	 *
	 * @param array<string, string> $clauses
	 * @param array<int, string>    $statuses stock statuses to move last; only values from STATUSES are used.
	 * @return array<string, string>
	 */
	public static function prepend_stock_order( array $clauses, array $statuses, string $lookup_table, string $posts_table ): array {
		$statuses = array_values( array_intersect( $statuses, array( 'outofstock', 'onbackorder' ) ) );
		if ( array() === $statuses ) {
			return $clauses;
		}

		$in   = "'" . implode( "', '", $statuses ) . "'";
		$rank = "EXISTS (SELECT 1 FROM {$lookup_table} WHERE {$lookup_table}.product_id = {$posts_table}.ID AND {$lookup_table}.stock_status IN ({$in})) ASC";

		$orderby = trim( (string) ( $clauses['orderby'] ?? '' ) );

		$clauses['orderby'] = '' === $orderby ? $rank : $rank . ', ' . $orderby;

		return $clauses;
	}

	/**
	 * Whether the shopper picked a sort from the catalog dropdown that differs
	 * from what the store would show anyway. Same unauthenticated read of
	 * ?orderby that WC_Query::get_catalog_ordering_args() makes.
	 */
	private function shopper_chose_sort(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only sort preference, no state change.
		$chosen = isset( $_GET['orderby'] ) && is_string( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';

		if ( '' === $chosen ) {
			return false;
		}

		// WooCommerce's own filter, not just the option: a theme or plugin that
		// changes the default through it must still count as "the default".
		$defaults = array( (string) apply_filters( 'woocommerce_default_catalog_orderby', get_option( 'woocommerce_default_catalog_orderby', 'menu_order' ) ) );
		if ( is_search() ) {
			$defaults[] = 'relevance';
		}

		return ! in_array( $chosen, $defaults, true );
	}

	/** @return array<int, string> */
	private function buried_statuses(): array {
		return $this->backorder_last() ? array( 'outofstock', 'onbackorder' ) : array( 'outofstock' );
	}

	private function shopper_sort(): string {
		$value = Settings::get( self::ID )['shopper_sort'] ?? self::SORT_DEFAULT_ONLY;

		return self::SORT_ALWAYS === $value ? self::SORT_ALWAYS : self::SORT_DEFAULT_ONLY;
	}

	private function backorder_last(): bool {
		return (bool) ( Settings::get( self::ID )['backorder_last'] ?? false );
	}

	private function shortcodes(): bool {
		return (bool) ( Settings::get( self::ID )['shortcodes'] ?? true );
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			Settings::set_enabled( self::ID, isset( $_POST['rc_enabled'] ) );
			$shopper_sort = sanitize_key( wp_unslash( $_POST['rc_shopper_sort'] ?? '' ) );
			Settings::update(
				self::ID,
				array(
					'shopper_sort'   => self::SORT_ALWAYS === $shopper_sort ? self::SORT_ALWAYS : self::SORT_DEFAULT_ONLY,
					'backorder_last' => isset( $_POST['rc_backorder_last'] ),
					'shortcodes'     => isset( $_POST['rc_shortcodes'] ),
				)
			);
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'Moves out-of-stock products to the end of the shop, category, tag and product search listings. Your sort order still applies: in-stock products come first in that order, then out-of-stock products in the same order.', 'romcommerce' ) . '</p>';

		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'WooCommerce is set to hide out-of-stock products from the catalog (WooCommerce → Settings → Products → Inventory), so there are none to move. Only the "on backorder" option below has an effect.', 'romcommerce' ) . '</p></div>';
		}

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Module', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ';
		echo esc_html__( 'Enable', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Shopper sorting', 'romcommerce' ) . '</th><td><fieldset>';
		echo '<label><input type="radio" name="rc_shopper_sort" value="' . esc_attr( self::SORT_DEFAULT_ONLY ) . '"' . checked( $this->shopper_sort(), self::SORT_DEFAULT_ONLY, false ) . '> ';
		echo esc_html__( 'Follow the shopper\'s choice', 'romcommerce' ) . '</label><br>';
		echo '<label><input type="radio" name="rc_shopper_sort" value="' . esc_attr( self::SORT_ALWAYS ) . '"' . checked( $this->shopper_sort(), self::SORT_ALWAYS, false ) . '> ';
		echo esc_html__( 'Keep out-of-stock products last', 'romcommerce' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Applies when a shopper sorts the catalog, e.g. by price.', 'romcommerce' ) . '</p>';
		echo '</fieldset></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'On backorder', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_backorder_last" value="1"' . checked( $this->backorder_last(), true, false ) . '> ';
		echo esc_html__( 'Also move products on backorder to the end', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Shortcodes', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_shortcodes" value="1"' . checked( $this->shortcodes(), true, false ) . '> ';
		echo esc_html__( 'Also apply to product lists from the [products] shortcode and its variants ([sale_products], [best_selling_products]…)', 'romcommerce' ) . '</label></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';
	}
}
