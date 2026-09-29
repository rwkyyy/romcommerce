<?php

declare(strict_types=1);

namespace RomCommerce\Modules\CouponCleanup;

use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Housekeeping for expired coupons. Two independent daily WP-Cron jobs: one
 * moves published coupons past their expiry date to the trash, the other
 * permanently deletes coupons that have already been sitting in the trash for
 * a configurable number of days. Deliberately its own per-post-type trash
 * timer rather than relying on WordPress core's sitewide EMPTY_TRASH_DAYS —
 * that constant affects every post type and some hosts disable it outright,
 * so a merchant who wants coupons purged specifically still gets it.
 */
final class Module implements ModuleInterface, HasSettingsUi {

	private const ID = 'coupon-cleanup';

	private const TRASH_HOOK       = 'romcommerce_trash_expired_coupons';
	private const EMPTY_TRASH_HOOK = 'romcommerce_empty_coupon_trash';

	// Caps each daily run so a store with an unusually large coupon backlog
	// can't turn a WP-Cron pass (triggered by an ordinary page load) into a
	// slow request; any remainder is picked up on the next day's run.
	private const BATCH_SIZE = 200;

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Coupon Cleanup', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		add_action( 'init', array( $this, 'reconcile_cron' ) );
		add_action( self::TRASH_HOOK, array( $this, 'trash_expired_coupons' ) );
		add_action( self::EMPTY_TRASH_HOOK, array( $this, 'empty_coupon_trash' ) );
	}

	/**
	 * Self-healing schedule check, run on every request while the module is
	 * enabled (same self-heal idea as the rewrite-flush other modules use,
	 * since this project's file-deploy workflow never fires
	 * register_activation_hook). wp_next_scheduled() is a cheap single read
	 * against the cron option, so checking on every init is fine — no version
	 * flag needed here the way the rewrite flush needs one.
	 */
	public function reconcile_cron(): void {
		$this->reconcile( self::TRASH_HOOK, $this->trash_expired_enabled() );
		$this->reconcile( self::EMPTY_TRASH_HOOK, $this->empty_trash_enabled() );
	}

	private function reconcile( string $hook, bool $should_run ): void {
		$scheduled = wp_next_scheduled( $hook );

		if ( $should_run && ! $scheduled ) {
			wp_schedule_event( time(), 'daily', $hook );
		} elseif ( ! $should_run && $scheduled ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	public function trash_expired_coupons(): void {
		$expired_ids = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'posts_per_page' => self::BATCH_SIZE,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- coupon counts are small; no persistent cron-only query to cache against.
					array(
						'key'     => 'date_expires',
						'value'   => time(),
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		foreach ( $expired_ids as $coupon_id ) {
			wp_trash_post( $coupon_id );
		}
	}

	public function empty_coupon_trash(): void {
		// wp_trash_post() (called above, and by the native "Move to Trash" admin
		// action) stamps this meta key — the same one WordPress core's own
		// wp_scheduled_delete() reads to age out trashed content.
		$cutoff = time() - ( $this->empty_trash_days() * DAY_IN_SECONDS );

		$trashed_ids = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'trash',
				'posts_per_page' => self::BATCH_SIZE,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- coupon counts are small; no persistent cron-only query to cache against.
					array(
						'key'     => '_wp_trash_meta_time',
						'value'   => $cutoff,
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		foreach ( $trashed_ids as $coupon_id ) {
			wp_delete_post( $coupon_id, true );
		}
	}

	private function trash_expired_enabled(): bool {
		return (bool) ( Settings::get( self::ID )['trash_expired'] ?? true );
	}

	private function empty_trash_enabled(): bool {
		return (bool) ( Settings::get( self::ID )['empty_trash'] ?? false );
	}

	private function empty_trash_days(): int {
		$days = (int) ( Settings::get( self::ID )['empty_trash_days'] ?? 30 );

		return max( 1, $days );
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			$enabled = isset( $_POST['rc_enabled'] );
			Settings::set_enabled( self::ID, $enabled );

			// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			Settings::update(
				self::ID,
				array(
					'trash_expired'    => isset( $_POST['rc_trash_expired'] ),
					'empty_trash'      => isset( $_POST['rc_empty_trash'] ),
					'empty_trash_days' => max( 1, absint( wp_unslash( $_POST['rc_empty_trash_days'] ?? 30 ) ) ),
				)
			);
			// phpcs:enable WordPress.Security.NonceVerification.Missing

			if ( ! $enabled ) {
				// boot() won't run again while disabled, so reconcile_cron()
				// won't fire on the next request either — clear both jobs
				// explicitly rather than leaving them scheduled with no listener.
				wp_clear_scheduled_hook( self::TRASH_HOOK );
				wp_clear_scheduled_hook( self::EMPTY_TRASH_HOOK );
			}

			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'Keeps your coupon list tidy without manual upkeep: expired coupons are moved to the trash automatically, and can optionally be deleted for good after they have sat there a while.', 'romcommerce' ) . '</p>';

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Module', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ';
		echo esc_html__( 'Enable', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Trash expired coupons', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_trash_expired" value="1"' . checked( $this->trash_expired_enabled(), true, false ) . '> ';
		echo esc_html__( 'Every day, move published coupons past their expiry date to the trash', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Empty coupon trash', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_empty_trash" value="1"' . checked( $this->empty_trash_enabled(), true, false ) . '> ';
		echo esc_html__( 'Permanently delete coupons after they have been in the trash for', 'romcommerce' ) . '</label> ';
		echo '<input type="number" name="rc_empty_trash_days" min="1" step="1" value="' . esc_attr( (string) $this->empty_trash_days() ) . '" style="width:70px;"> ';
		echo esc_html__( 'days', 'romcommerce' );
		echo '<p class="description">' . esc_html__( "This is separate from your site's general trash setting — it only affects coupons, and applies even if that site-wide auto-empty is disabled.", 'romcommerce' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';
	}
}
