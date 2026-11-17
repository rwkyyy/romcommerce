<?php

declare(strict_types=1);

namespace RomCommerce\Modules\SaleCategorySync;

use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;
use WC_Product;
use WP_Post;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps up to two product categories in sync without manual re-tagging: a
 * "Reduceri" (Sale) category with the products currently on sale, and a
 * "Noutăți" (New) category with the products published in the last N days,
 * optionally only those in stock. Each part has its own switch. Products are
 * added or removed on every product save and on the daily scheduled-sales
 * pass (which is when timed sales start and end, and when new products age
 * out).
 */
final class Module implements ModuleInterface, HasSettingsUi {

	private const ID = 'sale-category-sync';

	private const DEFAULT_NEW_DAYS = 30;
	private const MAX_NEW_DAYS     = 365;

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Category Sync', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		// Hooked unconditionally: sync_product() and resync_all() each bail when
		// no category is set, so a category chosen mid-request applies at once.
		add_action( 'woocommerce_after_product_object_save', array( $this, 'sync_product' ) );

		// Priority 20 so WooCommerce's own scheduled-sales handler (priority 10)
		// has already flipped prices before we read is_on_sale(). The same daily
		// pass ages products out of the new products category, so that part
		// needs no cron event of its own.
		add_action( 'woocommerce_scheduled_sales', array( $this, 'resync_all' ), 20 );

		// A scheduled product goes live through WordPress's cron, not a
		// WooCommerce CRUD save, so the save hook above never sees it published.
		add_action( 'future_to_publish', array( $this, 'sync_scheduled_post' ) );
	}

	/**
	 * @param WC_Product|mixed $product
	 */
	public function sync_product( $product ): void {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$sale_category_id = $this->active_sale_category_id();
		if ( $sale_category_id > 0 ) {
			$this->apply( $product->get_id(), $product->is_on_sale(), $sale_category_id );
		}

		$new_category_id = $this->active_new_category_id();
		if ( $new_category_id > 0 && ! $product->is_type( 'variation' ) ) {
			$this->apply( $product->get_id(), $this->is_new( $product ), $new_category_id );
		}
	}

	/**
	 * @param WP_Post|mixed $post
	 */
	public function sync_scheduled_post( $post ): void {
		if ( ! $post instanceof WP_Post || 'product' !== $post->post_type ) {
			return;
		}

		$this->sync_product( wc_get_product( $post->ID ) );
	}

	public function resync_all(): void {
		$this->resync_sale();
		$this->resync_new();
	}

	private function resync_sale(): void {
		$category_id = $this->active_sale_category_id();
		if ( 0 === $category_id ) {
			return;
		}

		$paged = 1;
		do {
			$products = wc_get_products(
				array(
					'status'   => 'publish',
					'type'     => array( 'simple', 'variable', 'grouped', 'external' ),
					'limit'    => 100,
					'page'     => $paged,
					'paginate' => false,
					'return'   => 'objects',
				)
			);

			$products       = (array) $products;
			$products_count = count( $products );

			foreach ( $products as $product ) {
				if ( $product instanceof WC_Product ) {
					$this->apply( $product->get_id(), $product->is_on_sale(), $category_id );
				}
			}

			++$paged;
		} while ( 100 === $products_count );
	}

	// IDs only: the products due in the category are bounded by the day window,
	// and the ones already in it by the category's size, so nothing here walks
	// the whole catalogue.
	private function resync_new(): void {
		$category_id = $this->active_new_category_id();
		if ( 0 === $category_id ) {
			return;
		}

		$term = get_term( $category_id, 'product_cat' );
		if ( ! $term instanceof WP_Term ) {
			return;
		}

		$due = array_map(
			'intval',
			(array) wc_get_products(
				array(
					'status'       => 'publish',
					'date_created' => '>=' . $this->new_cutoff(),
					'limit'        => -1,
					'return'       => 'ids',
				)
			)
		);

		$listed = array_map(
			'intval',
			(array) wc_get_products(
				array(
					'category' => array( $term->slug ),
					'limit'    => -1,
					'return'   => 'ids',
				)
			)
		);

		if ( $this->new_in_stock_only() ) {
			$out_of_stock = array_map(
				'intval',
				(array) wc_get_products(
					array(
						'status'       => 'publish',
						'date_created' => '>=' . $this->new_cutoff(),
						'stock_status' => 'outofstock',
						'limit'        => -1,
						'return'       => 'ids',
					)
				)
			);

			$due = array_diff( $due, $out_of_stock );
		}

		foreach ( array_diff( $listed, $due ) as $product_id ) {
			$this->apply( $product_id, false, $category_id );
		}

		foreach ( array_diff( $due, $listed ) as $product_id ) {
			$this->apply( $product_id, true, $category_id );
		}
	}

	private function apply( int $product_id, bool $in_category, int $category_id ): void {
		if ( $in_category ) {
			wp_set_object_terms( $product_id, array( $category_id ), 'product_cat', true );
		} else {
			wp_remove_object_terms( $product_id, array( $category_id ), 'product_cat' );
		}
	}

	private function is_new( WC_Product $product ): bool {
		if ( 'publish' !== $product->get_status() ) {
			return false;
		}

		if ( $this->new_in_stock_only() && ! $product->is_in_stock() ) {
			return false;
		}

		$published = $product->get_date_created();

		return null !== $published && $published->getTimestamp() >= $this->new_cutoff();
	}

	private function new_cutoff(): int {
		return time() - $this->new_days() * DAY_IN_SECONDS;
	}

	private function category_id(): int {
		return (int) ( Settings::get( self::ID )['category_id'] ?? 0 );
	}

	// Stores from before the sale part had its own switch saved only a
	// category: a chosen category means the sale part was on, and stays on.
	private function sale_enabled(): bool {
		$settings = Settings::get( self::ID );

		return (bool) ( $settings['sale_enabled'] ?? ( (int) ( $settings['category_id'] ?? 0 ) > 0 ) );
	}

	private function active_sale_category_id(): int {
		return $this->sale_enabled() ? $this->category_id() : 0;
	}

	private function new_category_id(): int {
		return (int) ( Settings::get( self::ID )['new_category_id'] ?? 0 );
	}

	private function new_enabled(): bool {
		return (bool) ( Settings::get( self::ID )['new_enabled'] ?? false );
	}

	private function new_in_stock_only(): bool {
		return (bool) ( Settings::get( self::ID )['new_in_stock_only'] ?? false );
	}

	private function active_new_category_id(): int {
		return $this->new_enabled() ? $this->new_category_id() : 0;
	}

	private function new_days(): int {
		$days = (int) ( Settings::get( self::ID )['new_days'] ?? self::DEFAULT_NEW_DAYS );

		return max( 1, min( self::MAX_NEW_DAYS, $days ) );
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// SettingsForm::verify() has confirmed the nonce and the manage_woocommerce
			// capability on the line above; the $_POST reads live here inside that guard
			// (not in the handler) so the authorization check is local to the input.
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above via SettingsForm::verify().
			if ( isset( $_POST['rc_create_category'] ) ) {
				$this->handle_create_category( 'Reduceri', 'reduceri', 'category_id', __( 'Sale category created and selected.', 'romcommerce' ) );
			} elseif ( isset( $_POST['rc_create_new_category'] ) ) {
				$this->handle_create_category( 'Noutăți', 'noutati', 'new_category_id', __( 'New products category created and selected.', 'romcommerce' ) );
			} elseif ( isset( $_POST['rc_resync'] ) ) {
				$this->handle_resync();
			} else {
				$this->handle_save(
					isset( $_POST['rc_enabled'] ),
					isset( $_POST['rc_sale_enabled'] ),
					absint( wp_unslash( $_POST['rc_category_id'] ?? 0 ) ),
					isset( $_POST['rc_new_enabled'] ),
					absint( wp_unslash( $_POST['rc_new_category_id'] ?? 0 ) ),
					absint( wp_unslash( $_POST['rc_new_days'] ?? self::DEFAULT_NEW_DAYS ) ),
					isset( $_POST['rc_new_in_stock_only'] )
				);
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing
		}

		$has_categories = $this->has_product_categories();

		echo '<p>' . esc_html__( 'Fills the categories below automatically, on every product save and once a day: products on sale go into the sale category, newly published products into the new products category. Products leave once they no longer qualify, so you never tag them by hand.', 'romcommerce' ) . '</p>';

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Module', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ';
		echo esc_html__( 'Enable', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Products on sale', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_sale_enabled" value="1"' . checked( $this->sale_enabled(), true, false ) . '> ';
		echo esc_html__( 'Add products on sale to a category', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Sale category', 'romcommerce' ) . '</th><td>';
		$this->render_category_dropdown( 'rc_category_id', $this->category_id(), $has_categories );
		echo '<p class="description">' . esc_html__( 'Products on sale are added to this category; products no longer on sale are removed from it.', 'romcommerce' ) . '</p>';
		echo '<button type="submit" name="rc_create_category" value="1" class="button">' . esc_html__( 'Create a "Reduceri" category', 'romcommerce' ) . '</button>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'New products', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_new_enabled" value="1"' . checked( $this->new_enabled(), true, false ) . '> ';
		echo esc_html__( 'Add newly published products to a category for', 'romcommerce' ) . '</label> ';
		echo '<input type="number" name="rc_new_days" min="1" max="' . esc_attr( (string) self::MAX_NEW_DAYS ) . '" step="1" value="' . esc_attr( (string) $this->new_days() ) . '" style="width:70px;"> ';
		echo esc_html__( 'days', 'romcommerce' );
		echo '<br><label><input type="checkbox" name="rc_new_in_stock_only" value="1"' . checked( $this->new_in_stock_only(), true, false ) . '> ';
		echo esc_html__( 'Leave out products that are out of stock', 'romcommerce' ) . '</label>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'New products category', 'romcommerce' ) . '</th><td>';
		$this->render_category_dropdown( 'rc_new_category_id', $this->new_category_id(), $has_categories );
		echo '<p class="description">' . esc_html__( 'Products are added on the day they are published and removed once the days above have passed.', 'romcommerce' ) . '</p>';
		echo '<button type="submit" name="rc_create_new_category" value="1" class="button">' . esc_html__( 'Create a "Noutăți" category', 'romcommerce' ) . '</button>';
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );

		if ( $this->active_sale_category_id() > 0 || $this->active_new_category_id() > 0 ) {
			echo '<p style="margin-top:16px;">';
			echo '<button type="submit" name="rc_resync" value="1" class="button button-secondary">' . esc_html__( 'Resync all products now', 'romcommerce' ) . '</button><br>';
			echo '<span class="description">' . esc_html__( 'Only needed for a one-off fix, for example right after you pick a category or after an import that skipped the automatic sync. It re-checks every published product now; the automatic sync handles everything afterwards.', 'romcommerce' ) . '</span>';
			echo '</p>';
		}

		echo '</form>';
	}

	private function render_category_dropdown( string $name, int $selected, bool $has_categories ): void {
		if ( ! $has_categories ) {
			echo '<em>' . esc_html__( 'No product categories exist yet.', 'romcommerce' ) . '</em>';
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0">';
			return;
		}

		wp_dropdown_categories(
			array(
				'taxonomy'          => 'product_cat',
				'name'              => $name,
				'selected'          => $selected,
				'show_option_none'  => __( '— none —', 'romcommerce' ),
				'option_none_value' => '0',
				'hide_empty'        => false,
				'hierarchical'      => true,
			)
		);
	}

	private function handle_save( bool $enabled, bool $sale_enabled, int $category_id, bool $new_enabled, int $new_category_id, int $new_days, bool $new_in_stock_only ): void {
		// One category for both parts would have them undo each other: the sale
		// part removes every product not on sale, including the new ones.
		$clash = $new_category_id > 0 && $new_category_id === $category_id;
		if ( $clash ) {
			$new_category_id = 0;
		}

		Settings::set_enabled( self::ID, $enabled );
		Settings::update(
			self::ID,
			array(
				'sale_enabled'      => $sale_enabled,
				'category_id'       => $category_id,
				'new_enabled'       => $new_enabled,
				'new_category_id'   => $new_category_id,
				'new_days'          => max( 1, min( self::MAX_NEW_DAYS, $new_days ) ),
				'new_in_stock_only' => $new_in_stock_only,
			)
		);

		if ( $clash ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The new products category must be different from the sale category. Pick another one.', 'romcommerce' ) . '</p></div>';
			return;
		}

		echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
	}

	private function handle_create_category( string $name, string $slug, string $setting_key, string $success_notice ): void {
		$existing = term_exists( $name, 'product_cat' );
		if ( is_array( $existing ) && isset( $existing['term_id'] ) ) {
			$term_id = (int) $existing['term_id'];
		} else {
			$created = wp_insert_term( $name, 'product_cat', array( 'slug' => $slug ) );
			if ( is_wp_error( $created ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'The category could not be created.', 'romcommerce' ) . '</p></div>';
				return;
			}
			$term_id = (int) $created['term_id'];
		}

		// Settings::update() replaces the whole option, so the other part's
		// settings are carried over rather than wiped.
		Settings::update( self::ID, array_merge( Settings::get( self::ID ), array( $setting_key => $term_id ) ) );
		echo '<div class="notice notice-success"><p>' . esc_html( $success_notice ) . '</p></div>';
	}

	private function handle_resync(): void {
		$this->resync_all();
		echo '<div class="notice notice-success"><p>' . esc_html__( 'All products resynced.', 'romcommerce' ) . '</p></div>';
	}

	private function has_product_categories(): bool {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 1,
				'fields'     => 'ids',
			)
		);

		return is_array( $terms ) && ! empty( $terms );
	}
}
