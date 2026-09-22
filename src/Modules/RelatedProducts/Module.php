<?php

declare(strict_types=1);

namespace RomCommerce\Modules\RelatedProducts;

use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Rule-based control over WooCommerce's "related products" block: which
 * relation rules apply (category / tag), how many products show and in how
 * many columns, or hiding the block entirely. Everything is done through
 * WooCommerce's own related-products filters — no custom query, no template
 * override — so themes that already customise the block keep working.
 */
final class Module implements ModuleInterface, HasSettingsUi {

	private const ID = 'related-products';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Related Products', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		if ( $this->hide() ) {
			// WooCommerce attaches the related-products output at priority 20;
			// remove exactly that so the section (and its heading) disappears.
			add_action( 'init', array( $this, 'remove_related_output' ) );
			return;
		}

		add_filter( 'woocommerce_product_related_posts_relate_by_category', array( $this, 'relate_by_category' ) );
		add_filter( 'woocommerce_product_related_posts_relate_by_tag', array( $this, 'relate_by_tag' ) );
		add_filter( 'woocommerce_output_related_products_args', array( $this, 'output_args' ) );
	}

	public function remove_related_output(): void {
		remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20 );
	}

	/**
	 * @param bool $relate
	 * @return bool
	 */
	public function relate_by_category( $relate ) {
		return $this->relate_category();
	}

	/**
	 * @param bool $relate
	 * @return bool
	 */
	public function relate_by_tag( $relate ) {
		return $this->relate_tag();
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public function output_args( $args ) {
		$args = is_array( $args ) ? $args : array();

		$args['posts_per_page'] = $this->count();
		$args['columns']        = $this->columns();

		return $args;
	}

	private function hide(): bool {
		return (bool) ( Settings::get( self::ID )['hide'] ?? false );
	}

	private function relate_category(): bool {
		return (bool) ( Settings::get( self::ID )['relate_category'] ?? true );
	}

	private function relate_tag(): bool {
		return (bool) ( Settings::get( self::ID )['relate_tag'] ?? true );
	}

	private function count(): int {
		$count = (int) ( Settings::get( self::ID )['count'] ?? 4 );

		return $count > 0 ? $count : 4;
	}

	private function columns(): int {
		$columns = (int) ( Settings::get( self::ID )['columns'] ?? 4 );

		return $columns > 0 ? $columns : 4;
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			Settings::set_enabled( self::ID, isset( $_POST['rc_enabled'] ) );
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			Settings::update(
				self::ID,
				array(
					'hide'            => isset( $_POST['rc_hide'] ),
					'relate_category' => isset( $_POST['rc_relate_category'] ),
					'relate_tag'      => isset( $_POST['rc_relate_tag'] ),
					'count'           => max( 1, absint( wp_unslash( $_POST['rc_count'] ?? 4 ) ) ),
					'columns'         => max( 1, absint( wp_unslash( $_POST['rc_columns'] ?? 4 ) ) ),
				)
			);
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'Controls the related-products block on single-product pages: which relation rules apply, how many products show, and in how many columns.', 'romcommerce' ) . '</p>';

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Module', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ';
		echo esc_html__( 'Enable', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Hide related products', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_hide" value="1"' . checked( $this->hide(), true, false ) . '> ';
		echo esc_html__( 'Remove the related-products block entirely', 'romcommerce' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'When enabled, the options below are ignored.', 'romcommerce' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Relate by', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_relate_category" value="1"' . checked( $this->relate_category(), true, false ) . '> ';
		echo esc_html__( 'Shared category', 'romcommerce' ) . '</label><br>';
		echo '<label><input type="checkbox" name="rc_relate_tag" value="1"' . checked( $this->relate_tag(), true, false ) . '> ';
		echo esc_html__( 'Shared tag', 'romcommerce' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'If neither is selected, WooCommerce falls back to random products.', 'romcommerce' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Number of products', 'romcommerce' ) . '</th><td>';
		echo '<input type="number" name="rc_count" min="1" value="' . esc_attr( (string) $this->count() ) . '"></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Columns', 'romcommerce' ) . '</th><td>';
		echo '<input type="number" name="rc_columns" min="1" value="' . esc_attr( (string) $this->columns() ) . '"></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';
	}
}
