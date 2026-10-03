<?php

declare(strict_types=1);

namespace RomCommerce\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * One-time "RomCommerce is active" notice shown across wp-admin until a shop
 * manager dismisses it. Every module is opt-in (see Settings::is_enabled()),
 * so a merchant landing on an unrelated screen after activation would
 * otherwise have no indication the plugin is there at all.
 */
final class WelcomeNotice {

	private const OPTION      = 'romcommerce_welcome_notice_dismissed';
	private const NONCE       = 'romcommerce_dismiss_welcome_notice';
	private const QUERY_ARG   = 'romcommerce_dismiss_notice';
	private const CAPABILITY  = 'manage_woocommerce';
	private const SETTINGS_ID = 'toplevel_page_romcommerce';

	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_dismiss' ) );
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	public function maybe_dismiss(): void {
		if ( ! isset( $_GET[ self::QUERY_ARG ] ) || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		check_admin_referer( self::NONCE );

		update_option( self::OPTION, true, false );

		wp_safe_redirect( remove_query_arg( array( self::QUERY_ARG, '_wpnonce' ) ) );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) || get_option( self::OPTION ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( $screen && self::SETTINGS_ID === $screen->id ) {
			return;
		}

		$dismiss_url = wp_nonce_url( add_query_arg( self::QUERY_ARG, '1' ), self::NONCE );

		echo '<div class="notice notice-info romcommerce-welcome-notice"><p>';
		printf(
			/* translators: 1: settings link opening tag, 2: settings link closing tag, 3: dismiss link opening tag, 4: dismiss link closing tag */
			esc_html__( 'RomCommerce is active. %1$sClick here%2$s to choose which modules to enable. %3$sDismiss%4$s', 'romcommerce' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=romcommerce' ) ) . '">',
			'</a>',
			'<a href="' . esc_url( $dismiss_url ) . '">',
			'</a>'
		);
		echo '</p></div>';
	}
}
