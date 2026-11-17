<?php

declare(strict_types=1);

namespace RomCommerce\Modules\OutOfStockCleanup;

use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Moves published products that are out of stock to the trash once they have
 * gone unchanged for the chosen number of months. "Unchanged" is the
 * product's last modified date (owner, 2026-11-09), which a sale that empties
 * the stock also sets. A daily WP-Cron job, never a storefront request.
 */
final class Module implements ModuleInterface, HasSettingsUi {

	private const ID = 'out-of-stock-cleanup';

	private const HOOK = 'romcommerce_out_of_stock_cleanup';

	private const LOG_OPTION = 'romcommerce_out_of_stock_cleanup_log';

	// Trashing a product also trashes its variations and updates WooCommerce's
	// lookup tables, so batches stay small; a full batch queues the next one
	// a few minutes later instead of waiting a day.
	private const BATCH_SIZE = 50;

	private const MONTHS = array( 1, 2, 3, 6, 9, 12, 18, 24 );

	private const DEFAULT_MONTHS = 12;

	private const IMAGES_PLUGIN = 'wc-delete-product-images';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Out-of-Stock Product Cleanup', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		add_action( 'init', array( $this, 'reconcile_cron' ) );
		add_action( self::HOOK, array( $this, 'run_cron' ) );
		add_action( 'romcommerce/module_toggled', array( $this, 'on_toggled' ), 10, 2 );
	}

	/**
	 * Same self-healing check as Coupon cleanup: this project's file deploys
	 * never fire an activation hook, and wp_next_scheduled() is one read of
	 * the autoloaded cron option.
	 */
	public function reconcile_cron(): void {
		if ( self::trash_available() && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::HOOK );
		}
	}

	/**
	 * @param string $module_id
	 * @param bool   $enabled
	 */
	public function on_toggled( $module_id, $enabled ): void {
		if ( self::ID === $module_id && ! $enabled ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}

	public function run_cron(): void {
		$this->run_batch();
	}

	/**
	 * @return int Products moved to the trash.
	 */
	public function run_batch(): int {
		if ( ! self::trash_available() ) {
			return 0;
		}

		$ids = wc_get_products( $this->query_args( self::BATCH_SIZE ) );

		$trashed = 0;
		foreach ( (array) $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product instanceof WC_Product || 'outofstock' !== $product->get_stock_status() ) {
				continue;
			}

			$product->delete();
			if ( 'publish' !== get_post_status( (int) $id ) ) {
				++$trashed;
			}
		}

		$this->log( $trashed );

		// Only a batch that fully went through queues the next one, so products
		// something refuses to trash can't keep the job looping.
		if ( $trashed >= self::BATCH_SIZE ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::HOOK );
		}

		return $trashed;
	}

	/**
	 * Every due product, in batches, for WP-CLI and the abilities.
	 *
	 * @param callable():bool $in_time
	 * @return array{trashed: int, complete: bool}
	 */
	public function run_now( callable $in_time ): array {
		$trashed = 0;
		do {
			$batch    = $this->run_batch();
			$trashed += $batch;
		} while ( $batch >= self::BATCH_SIZE && $in_time() );

		return array(
			'trashed'  => $trashed,
			'complete' => 0 === $this->due_count(),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function query_args( int $limit ): array {
		return array(
			'status'        => 'publish',
			'stock_status'  => 'outofstock',
			'date_modified' => '<' . $this->cutoff(),
			'limit'         => $limit,
			'orderby'       => 'modified',
			'order'         => 'ASC',
			'return'        => 'ids',
		);
	}

	private function due_count(): int {
		$result = wc_get_products( array( 'paginate' => true ) + $this->query_args( 1 ) );

		return is_object( $result ) && isset( $result->total ) ? (int) $result->total : 0;
	}

	private function cutoff(): int {
		return (int) strtotime( '-' . $this->months() . ' months' );
	}

	private function months(): int {
		$months = (int) ( Settings::get( self::ID )['months'] ?? self::DEFAULT_MONTHS );

		return in_array( $months, self::MONTHS, true ) ? $months : self::DEFAULT_MONTHS;
	}

	/**
	 * With the trash switched off (EMPTY_TRASH_DAYS set to 0), WordPress
	 * deletes a "trashed" product for good straight away. The module only
	 * ever moves products to the trash, so it stops instead.
	 */
	private static function trash_available(): bool {
		return defined( 'EMPTY_TRASH_DAYS' ) && EMPTY_TRASH_DAYS > 0;
	}

	private function log( int $trashed ): void {
		$log = get_option( self::LOG_OPTION, array() );
		$log = is_array( $log ) ? $log : array();

		// A continuation batch belongs to the same run as the one before it.
		$same_run = isset( $log['time'] ) && time() - (int) $log['time'] < HOUR_IN_SECONDS;

		update_option(
			self::LOG_OPTION,
			array(
				'time'    => time(),
				'trashed' => ( $same_run ? (int) ( $log['trashed'] ?? 0 ) : 0 ) + $trashed,
			),
			false
		);
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			Settings::set_enabled( self::ID, isset( $_POST['rc_enabled'] ) );
			$months = absint( wp_unslash( $_POST['rc_months'] ?? self::DEFAULT_MONTHS ) );
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			Settings::update( self::ID, array( 'months' => in_array( $months, self::MONTHS, true ) ? $months : self::DEFAULT_MONTHS ) );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'Once a day, moves published products that are out of stock to the trash when they have not changed for the number of months you choose. Products on backorder, drafts and private products are left alone.', 'romcommerce' ) . '</p>';

		if ( ! self::trash_available() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The trash is switched off on this site (EMPTY_TRASH_DAYS is 0), so products would be deleted for good instead. The cleanup does not run until the trash is switched back on.', 'romcommerce' ) . '</p></div>';
		}

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Module', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ';
		echo esc_html__( 'Enable', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row"><label for="rc-oos-months">' . esc_html__( 'Move to trash after', 'romcommerce' ) . '</label></th><td>';
		echo '<select id="rc-oos-months" name="rc_months">';
		foreach ( self::MONTHS as $months ) {
			/* translators: %d: number of months. */
			echo '<option value="' . esc_attr( (string) $months ) . '"' . selected( $this->months(), $months, false ) . '>' . esc_html( sprintf( _n( '%d month', '%d months', $months, 'romcommerce' ), $months ) ) . '</option>';
		}
		echo '</select> ' . esc_html__( 'out of stock and unchanged', 'romcommerce' );
		echo '<p class="description">' . esc_html__( 'Counted from the product\'s last modified date, which is usually when it sold out. Editing the product starts the count again, and so does a plugin that re-saves products, such as a stock sync.', 'romcommerce' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Due now', 'romcommerce' ) . '</th><td>';
		$due = $this->due_count();
		echo esc_html(
			sprintf(
				/* translators: 1: number of products, 2: date. */
				_n( '%1$d product out of stock and unchanged since before %2$s.', '%1$d products out of stock and unchanged since before %2$s.', $due, 'romcommerce' ),
				$due,
				(string) wp_date( (string) get_option( 'date_format' ), $this->cutoff() )
			)
		);
		echo '<p class="description">' . esc_html__( 'Saved settings only: change the months and save to see the new count.', 'romcommerce' ) . '</p>';
		echo '</td></tr>';

		$log = get_option( self::LOG_OPTION, array() );
		if ( is_array( $log ) && ! empty( $log['time'] ) ) {
			echo '<tr><th scope="row">' . esc_html__( 'Last run', 'romcommerce' ) . '</th><td>';
			$count = (int) ( $log['trashed'] ?? 0 );
			echo esc_html(
				sprintf(
					/* translators: 1: date and time, 2: number of products. */
					_n( '%1$s: %2$d product moved to the trash.', '%1$s: %2$d products moved to the trash.', $count, 'romcommerce' ),
					(string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $log['time'] ),
					$count
				)
			);
			echo '</td></tr>';
		}

		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';

		if ( self::trash_available() ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %d: number of days. */
					_n( 'WordPress deletes products from the trash for good after %d day. Until then you can restore them from Products, Trash.', 'WordPress deletes products from the trash for good after %d days. Until then you can restore them from Products, Trash.', (int) EMPTY_TRASH_DAYS, 'romcommerce' ),
					(int) EMPTY_TRASH_DAYS
				)
			) . '</p>';
		}

		$this->render_images_recommendation();
	}

	/**
	 * A link to WordPress's own plugin details window, where the merchant
	 * installs it themselves: Lite never installs anything. It stays in this
	 * pane, never an admin notice. Inside an AJAX pane swap the thickbox
	 * script isn't on the page, so the link opens the details as a page.
	 */
	private function render_images_recommendation(): void {
		echo '<h3>' . esc_html__( 'Product images', 'romcommerce' ) . '</h3>';
		echo '<p>' . esc_html__( 'When a product is deleted for good, WordPress keeps its images in the Media Library. Delete product images for WooCommerce, a free plugin, removes them at that moment, and keeps any image another product still uses.', 'romcommerce' ) . '</p>';

		if ( defined( 'UPRISE_WC_DELETE_IMAGES_VERSION' ) ) {
			echo '<p><span class="dashicons dashicons-yes" aria-hidden="true"></span> ' . esc_html__( 'Delete product images for WooCommerce is active.', 'romcommerce' ) . '</p>';
			return;
		}

		echo '<p>';
		if ( current_user_can( 'install_plugins' ) ) {
			add_thickbox();
			wp_enqueue_script( 'plugin-install' );
			echo '<a class="button thickbox open-plugin-details-modal" href="' . esc_url( self_admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . self::IMAGES_PLUGIN . '&TB_iframe=true&width=772&height=600' ) ) . '">' . esc_html__( 'Plugin details', 'romcommerce' ) . '</a> ';
		}
		echo '<a href="' . esc_url( 'https://wordpress.org/plugins/' . self::IMAGES_PLUGIN . '/' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'WordPress.org page', 'romcommerce' ) . '</a>';
		echo '</p>';
	}
}
