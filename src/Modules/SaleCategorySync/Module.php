<?php

declare(strict_types=1);

namespace RomCommerce\Modules\SaleCategorySync;

use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a chosen product category in sync with the products currently on sale,
 * so a "Reduceri" (Sale) category / menu / widget always reflects live sale
 * state without manual re-tagging. A product is added to the category when it
 * is on sale and removed when it is not, on every product save and on the daily
 * scheduled-sales pass (which is when timed sales start and end).
 */
final class Module implements ModuleInterface, HasSettingsUi {

	private const ID = 'sale-category-sync';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Sale Category Sync', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		if ( 0 === $this->category_id() ) {
			return;
		}

		add_action( 'woocommerce_after_product_object_save', array( $this, 'sync_product' ) );

		// Priority 20 so WooCommerce's own scheduled-sales handler (priority 10)
		// has already flipped prices before we read is_on_sale().
		add_action( 'woocommerce_scheduled_sales', array( $this, 'resync_all' ), 20 );
	}

	/**
	 * @param WC_Product|mixed $product
	 */
	public function sync_product( $product ): void {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$category_id = $this->category_id();
		if ( 0 === $category_id ) {
			return;
		}

		$this->apply( $product->get_id(), $product->is_on_sale(), $category_id );
	}

	public function resync_all(): void {
		$category_id = $this->category_id();
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

	private function apply( int $product_id, bool $on_sale, int $category_id ): void {
		if ( $on_sale ) {
			wp_set_object_terms( $product_id, array( $category_id ), 'product_cat', true );
		} else {
			wp_remove_object_terms( $product_id, array( $category_id ), 'product_cat' );
		}
	}

	private function category_id(): int {
		return (int) ( Settings::get( self::ID )['category_id'] ?? 0 );
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// SettingsForm::verify() has confirmed the nonce and the manage_woocommerce
			// capability on the line above; the $_POST reads live here inside that guard
			// (not in the handler) so the authorization check is local to the input.
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above via SettingsForm::verify().
			if ( isset( $_POST['rc_create_category'] ) ) {
				$this->handle_create_category();
			} elseif ( isset( $_POST['rc_resync'] ) ) {
				$this->handle_resync();
			} else {
				$this->handle_save(
					isset( $_POST['rc_enabled'] ),
					absint( wp_unslash( $_POST['rc_category_id'] ?? 0 ) )
				);
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing
		}

		$category_id = $this->category_id();

		echo '<p>' . esc_html__( 'Keeps the category below in sync automatically: whenever a product is saved, and once a day when WooCommerce starts/ends scheduled sales, products currently on sale are added to it and products no longer on sale are removed from it. You never need to tag products by hand.', 'romcommerce' ) . '</p>';

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Module', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ';
		echo esc_html__( 'Enable', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Sale category', 'romcommerce' ) . '</th><td>';
		if ( $this->has_product_categories() ) {
			wp_dropdown_categories(
				array(
					'taxonomy'          => 'product_cat',
					'name'              => 'rc_category_id',
					'selected'          => $category_id,
					'show_option_none'  => __( '— none —', 'romcommerce' ),
					'option_none_value' => '0',
					'hide_empty'        => false,
					'hierarchical'      => true,
				)
			);
		} else {
			echo '<em>' . esc_html__( 'No product categories exist yet.', 'romcommerce' ) . '</em>';
			echo '<input type="hidden" name="rc_category_id" value="0">';
		}
		echo '<p class="description">' . esc_html__( 'Products on sale are added to this category; products no longer on sale are removed from it.', 'romcommerce' ) . '</p>';
		echo '<button type="submit" name="rc_create_category" value="1" class="button">' . esc_html__( 'Create a "Reduceri" category', 'romcommerce' ) . '</button>';
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );

		if ( $category_id > 0 ) {
			echo '<p style="margin-top:16px;">';
			echo '<button type="submit" name="rc_resync" value="1" class="button button-secondary">' . esc_html__( 'Resync all products now', 'romcommerce' ) . '</button><br>';
			echo '<span class="description">' . esc_html__( 'Only needed for a one-off fix: for example, you just picked this category, or you imported/changed products in a way that skipped the automatic sync. It walks every published product and re-checks its sale state right now; the automatic sync above keeps handling everything afterwards.', 'romcommerce' ) . '</span>';
			echo '</p>';
		}

		echo '</form>';
	}

	private function handle_save( bool $enabled, int $category_id ): void {
		Settings::set_enabled( self::ID, $enabled );
		Settings::update( self::ID, array( 'category_id' => $category_id ) );
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
	}

	private function handle_create_category(): void {
		$existing = term_exists( 'Reduceri', 'product_cat' );
		if ( is_array( $existing ) && isset( $existing['term_id'] ) ) {
			$term_id = (int) $existing['term_id'];
		} else {
			$created = wp_insert_term( 'Reduceri', 'product_cat', array( 'slug' => 'reduceri' ) );
			if ( is_wp_error( $created ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'The category could not be created.', 'romcommerce' ) . '</p></div>';
				return;
			}
			$term_id = (int) $created['term_id'];
		}

		Settings::update( self::ID, array( 'category_id' => $term_id ) );
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Sale category created and selected.', 'romcommerce' ) . '</p></div>';
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
