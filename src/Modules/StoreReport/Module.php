<?php

declare(strict_types=1);

namespace RomCommerce\Modules\StoreReport;

use RomCommerce\Admin\ProSection;
use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;
use WC_Email;

defined( 'ABSPATH' ) || exit;

/**
 * Weekly store report by email (Lite; other intervals are Pro). Orders by
 * status, sales and average order value, top products and categories, payment
 * methods and, where WooCommerce's Order Attribution is on, the top channels
 * and campaigns, each optionally compared with an earlier week. The merchant
 * picks the sections, the day and the hour.
 *
 * Built in a WP-Cron event, never on a shopper request. The week reported on
 * is the one before the latest send time that has passed, so a late WP-Cron
 * run still reports on the right week, and the last week sent is remembered
 * so it never goes out twice.
 */
final class Module implements ModuleInterface, HasSettingsUi {

	private const ID = 'store-report';

	private const HOOK = 'romcommerce_store_report_send';

	private const LAST_SENT_OPTION = 'romcommerce_store_report_last_sent';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Weekly Store Report', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		add_action( 'init', array( $this, 'reconcile_cron' ) );
		add_action( self::HOOK, array( $this, 'send_scheduled' ) );
		add_action( 'romcommerce/module_toggled', array( $this, 'on_toggle' ), 10, 2 );
	}

	/**
	 * Self-healing schedule, like Coupon Cleanup's: one pending event at the
	 * next send time while the module is on.
	 */
	public function reconcile_cron(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			$this->schedule();
		}
	}

	/**
	 * @param string $module_id
	 * @param bool   $enabled
	 */
	public function on_toggle( $module_id, $enabled ): void {
		if ( self::ID === $module_id && ! $enabled ) {
			wp_unschedule_hook( self::HOOK );
		}
	}

	public function send_scheduled(): void {
		$this->schedule();

		$due    = Period::next_send( Period::now(), $this->weekday(), $this->hour() )->modify( '-7 days' );
		$period = Period::last_week( $due );
		if ( get_option( self::LAST_SENT_OPTION ) === $period->key() ) {
			return;
		}

		if ( $this->send( $period ) ) {
			update_option( self::LAST_SENT_OPTION, $period->key(), false );
		}
	}

	public function send( Period $period ): bool {
		if ( ! function_exists( 'WC' ) || ! class_exists( WC_Email::class ) ) {
			return false;
		}

		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 0 );
		}

		$collector = new Collector();
		$compared  = $period->compared( $this->compare() );
		$now       = $collector->collect( $period )->result();
		$before    = null === $compared ? null : $collector->collect( $compared )->result();
		$body      = Report::html( $now, $compared, $before, $this->sections(), Collector::attribution_available() );

		$mailer = WC()->mailer();
		/* translators: %s: date range */
		$heading = sprintf( __( 'Weekly report: %s', 'romcommerce' ), $period->label() );
		$message = ( new WC_Email() )->style_inline( $mailer->wrap_message( $heading, $body ) );
		$subject = sprintf(
			/* translators: 1: site name, 2: date range */
			__( '[%1$s] Weekly store report: %2$s', 'romcommerce' ),
			wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			$period->label()
		);

		return (bool) $mailer->send( implode( ',', $this->recipients() ), $subject, $message );
	}

	/** Lite's WP-CLI command and ability use this, like the "Send a test report" button. */
	public function send_now(): bool {
		return $this->send( Period::last_week( Period::now() ) );
	}

	private function schedule(): void {
		wp_unschedule_hook( self::HOOK );
		wp_schedule_single_event( Period::next_send( Period::now(), $this->weekday(), $this->hour() )->getTimestamp(), self::HOOK );
	}

	private function weekday(): int {
		return max( 1, min( 7, (int) ( Settings::get( self::ID )['weekday'] ?? 1 ) ) );
	}

	private function hour(): int {
		return max( 0, min( 23, (int) ( Settings::get( self::ID )['hour'] ?? 8 ) ) );
	}

	private function compare(): string {
		$compare = (string) ( Settings::get( self::ID )['compare'] ?? Period::COMPARE_WEEK );

		return in_array( $compare, array( Period::COMPARE_NONE, Period::COMPARE_WEEK, Period::COMPARE_MONTH, Period::COMPARE_YEAR ), true ) ? $compare : Period::COMPARE_WEEK;
	}

	/** @return array<int, string> Every section until the merchant unticks some. */
	private function sections(): array {
		$stored = Settings::get( self::ID )['sections'] ?? null;

		return is_array( $stored ) ? array_values( array_intersect( Report::SECTIONS, $stored ) ) : Report::SECTIONS;
	}

	/** @return array<int, string> */
	private function recipients(): array {
		$recipients = self::parse_recipients( (string) ( Settings::get( self::ID )['recipients'] ?? '' ) );

		return array() !== $recipients ? $recipients : array( (string) get_option( 'admin_email' ) );
	}

	/** @return array<int, string> Valid addresses from a comma, semicolon or line separated list. */
	public static function parse_recipients( string $raw ): array {
		$parts = preg_split( '/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );

		return array_values( array_unique( array_filter( array_map( 'sanitize_email', is_array( $parts ) ? $parts : array() ), 'is_email' ) ) );
	}

	/** @return array<string, string> section => label, in the email's order. */
	private static function section_labels(): array {
		return array(
			'orders'     => __( 'Total orders', 'romcommerce' ),
			'completed'  => __( 'Completed orders', 'romcommerce' ),
			'processing' => __( 'Processing orders', 'romcommerce' ),
			'waiting'    => __( 'On hold / waiting orders (on hold, pending payment and custom statuses)', 'romcommerce' ),
			'aov'        => __( 'Sales and average order value', 'romcommerce' ),
			'products'   => __( 'Top 5 products', 'romcommerce' ),
			'categories' => __( 'Top 5 categories', 'romcommerce' ),
			'payments'   => __( 'Payment methods share', 'romcommerce' ),
			'sources'    => __( 'Order sources: top 5 channels and top 5 campaigns', 'romcommerce' ),
		);
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above via SettingsForm::verify().
			if ( isset( $_POST['rc_send_test'] ) ) {
				$sent = $this->send_now();
				echo '<div class="notice ' . ( $sent ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( $sent ? __( 'Test report sent for the last full week.', 'romcommerce' ) : __( 'The report could not be sent. Check that your site can send email.', 'romcommerce' ) ) . '</p></div>';
			} else {
				$compare  = sanitize_key( wp_unslash( (string) ( $_POST['rc_compare'] ?? Period::COMPARE_WEEK ) ) );
				$sections = isset( $_POST['rc_sections'] ) && is_array( $_POST['rc_sections'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['rc_sections'] ) ) : array();
				$enabled  = isset( $_POST['rc_enabled'] );

				Settings::set_enabled( self::ID, $enabled );
				Settings::update(
					self::ID,
					array(
						'recipients' => implode( ', ', self::parse_recipients( sanitize_textarea_field( wp_unslash( (string) ( $_POST['rc_recipients'] ?? '' ) ) ) ) ),
						'weekday'    => max( 1, min( 7, absint( wp_unslash( $_POST['rc_weekday'] ?? 1 ) ) ) ),
						'hour'       => max( 0, min( 23, absint( wp_unslash( $_POST['rc_hour'] ?? 8 ) ) ) ),
						'compare'    => in_array( $compare, array( Period::COMPARE_NONE, Period::COMPARE_WEEK, Period::COMPARE_MONTH, Period::COMPARE_YEAR ), true ) ? $compare : Period::COMPARE_WEEK,
						'sections'   => array_values( array_intersect( Report::SECTIONS, $sections ) ),
					)
				);

				if ( $enabled ) {
					$this->schedule();
				} else {
					wp_unschedule_hook( self::HOOK );
				}

				echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing
		}

		$settings = Settings::get( self::ID );

		echo '<p>' . esc_html__( 'A weekly email summing up your store: orders by status, average order value, best-selling products and categories, payment methods and where your orders come from. It covers the seven full days before the day it is sent and contains totals only, never customer details.', 'romcommerce' ) . '</p>';

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Module', 'romcommerce' ) . '</th><td><label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ' . esc_html__( 'Email me a weekly store report', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row"><label for="rc-report-recipients">' . esc_html__( 'Send to', 'romcommerce' ) . '</label></th><td>';
		echo '<input type="text" id="rc-report-recipients" class="regular-text" name="rc_recipients" value="' . esc_attr( (string) ( $settings['recipients'] ?? '' ) ) . '" placeholder="' . esc_attr( (string) get_option( 'admin_email' ) ) . '">';
		echo '<p class="description">' . esc_html__( 'One or more email addresses, separated by commas. Empty: the site admin email.', 'romcommerce' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="rc-report-weekday">' . esc_html__( 'Day', 'romcommerce' ) . '</label></th><td><select id="rc-report-weekday" name="rc_weekday">';
		for ( $day = 1; $day <= 7; $day++ ) {
			// 2024-01-01 was a Monday: day N of that week gives the localized weekday name.
			$name = wp_date( 'l', (int) gmmktime( 12, 0, 0, 1, $day, 2024 ), new \DateTimeZone( 'UTC' ) );
			echo '<option value="' . esc_attr( (string) $day ) . '"' . selected( $this->weekday(), $day, false ) . '>' . esc_html( (string) $name ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="rc-report-hour">' . esc_html__( 'Time', 'romcommerce' ) . '</label></th><td><select id="rc-report-hour" name="rc_hour">';
		for ( $hour = 0; $hour <= 23; $hour++ ) {
			echo '<option value="' . esc_attr( (string) $hour ) . '"' . selected( $this->hour(), $hour, false ) . '>' . esc_html( sprintf( '%02d:00', $hour ) ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: timezone name, e.g. Europe/Bucharest */
				__( 'In your site\'s timezone (%s). WordPress sends scheduled emails on the first visit after this time, so on a quiet site it can be a little later.', 'romcommerce' ),
				wp_timezone_string()
			)
		) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="rc-report-compare">' . esc_html__( 'Compare with', 'romcommerce' ) . '</label></th><td><select id="rc-report-compare" name="rc_compare">';
		$compare_options = array(
			Period::COMPARE_WEEK  => __( 'The previous week', 'romcommerce' ),
			Period::COMPARE_MONTH => __( 'The same week of the previous month (4 weeks earlier)', 'romcommerce' ),
			Period::COMPARE_YEAR  => __( 'The same week of the previous year (52 weeks earlier)', 'romcommerce' ),
			Period::COMPARE_NONE  => __( 'No comparison', 'romcommerce' ),
		);
		foreach ( $compare_options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $this->compare(), $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Comparisons keep the weekdays lined up, so a weekend is compared with a weekend.', 'romcommerce' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Include', 'romcommerce' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Include', 'romcommerce' ) . '</legend>';
		$attribution = Collector::attribution_available();
		foreach ( self::section_labels() as $section => $label ) {
			echo '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="rc_sections[]" value="' . esc_attr( $section ) . '"' . checked( in_array( $section, $this->sections(), true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		if ( ! $attribution ) {
			echo '<p class="description">' . esc_html__( 'Order sources need WooCommerce\'s Order Attribution (WooCommerce 8.5 or newer, WooCommerce → Settings → Advanced → Features). It is off or unavailable here, so the email leaves that section out.', 'romcommerce' ) . '</p>';
		}
		echo '</fieldset></td></tr>';

		if ( $this->is_enabled() ) {
			$next = wp_next_scheduled( self::HOOK );
			if ( false !== $next ) {
				echo '<tr><th scope="row">' . esc_html__( 'Next report', 'romcommerce' ) . '</th><td>' . esc_html( wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $next ) ) . '</td></tr>';
			}
		}

		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';

		if ( $this->is_enabled() ) {
			echo '<form method="post">';
			SettingsForm::nonce_field( self::ID );
			echo '<p><button type="submit" name="rc_send_test" value="1" class="button">' . esc_html__( 'Send a test report now', 'romcommerce' ) . '</button> ';
			echo '<span class="description">' . esc_html__( 'Emails the report for the last full week to the addresses above, with the saved settings.', 'romcommerce' ) . '</span></p>';
			echo '</form>';
		}

		ProSection::open( __( 'Report interval', 'romcommerce' ) );
		echo '<p>';
		foreach ( array( __( 'Daily', 'romcommerce' ), __( 'Weekly', 'romcommerce' ), __( 'Monthly', 'romcommerce' ), __( 'Quarterly', 'romcommerce' ), __( 'Yearly', 'romcommerce' ) ) as $index => $interval ) {
			echo '<label style="margin-right:16px;"><input type="radio" disabled' . ( 1 === $index ? ' checked' : '' ) . '> ' . esc_html( $interval ) . '</label>';
		}
		echo '</p>';
		ProSection::close();
	}
}
