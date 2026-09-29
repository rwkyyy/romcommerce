<?php

declare(strict_types=1);

namespace RomCommerce\Admin;

defined( 'ABSPATH' ) || exit;

final class Menu {

	private const CAPABILITY = 'manage_woocommerce';
	private const SLUG       = 'romcommerce';

	private string $hook_suffix = '';

	public function register(): void {
		// WooCommerce finishes its own submenu at priority 70. Registering later
		// and without a numeric position avoids both its reserved positions and
		// its custom menu-order pass.
		add_action( 'admin_menu', array( $this, 'add_menu_page' ), 80 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function add_menu_page(): void {
		$this->hook_suffix = (string) add_menu_page(
			__( 'RomCommerce', 'romcommerce' ),
			__( 'RomCommerce', 'romcommerce' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' ),
			$this->icon()
		);

		$this->ensure_dashboard_submenu();
	}

	public function render(): void {
		( new Page() )->render();
	}

	public function enqueue_assets( string $hook ): void {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'romcommerce-admin',
			plugins_url( 'assets/admin.css', ROMCOMMERCE_FILE ),
			array(),
			ROMCOMMERCE_VERSION
		);

		// Needed for the WhatsApp/Contact FAB pane's "choose image" icon picker
		// (wp.media). Loaded here rather than per-module since it must be
		// present before the AJAX-swapped canvas ever renders that pane.
		wp_enqueue_media();

		// WooCommerce's own select2 skin lives inside its general admin.css,
		// not a standalone stylesheet — enqueued here (not per-module) so any
		// pane's taxonomy/term picker (e.g. Legal Guarantee Notice's exclusion
		// list) gets it without each module re-enqueuing it. admin.js
		// re-triggers wc-enhanced-select-init after every AJAX canvas swap,
		// since this script's own auto-init only runs once on page load.
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'wc-enhanced-select' );

		wp_enqueue_script(
			'romcommerce-admin',
			plugins_url( 'assets/admin.js', ROMCOMMERCE_FILE ),
			array(),
			ROMCOMMERCE_VERSION,
			true
		);

		wp_localize_script(
			'romcommerce-admin',
			'romcommerceAdmin',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'action'     => 'romcommerce_pane',
				'nonce'      => wp_create_nonce( Ajax::NONCE ),
				'mediaTitle' => __( 'Choose image', 'romcommerce' ),
			)
		);
	}

	private function icon(): string {
		$path = ROMCOMMERCE_DIR . '/assets/admin-icon.svg';
		if ( ! is_readable( $path ) ) {
			return 'dashicons-store';
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- local plugin asset (not a remote URL); base64 here builds a data: URI for add_menu_page()'s icon param, not obfuscation.
		$svg = (string) file_get_contents( $path );
		if ( '' === $svg ) {
			return 'dashicons-store';
		}

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private function ensure_dashboard_submenu(): void {
		global $submenu;

		foreach ( $submenu[ self::SLUG ] ?? array() as $item ) {
			if ( isset( $item[2] ) && self::SLUG === $item[2] ) {
				return;
			}
		}

		// A CPT shown under this menu (show_in_menu) can register its child
		// before this intentionally late parent. In that case add_menu_page()
		// does not add the usual self-link, so restore it first to keep the
		// RomCommerce app as the default page.
		add_submenu_page(
			self::SLUG,
			__( 'Dashboard', 'romcommerce' ),
			__( 'Dashboard', 'romcommerce' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' ),
			0
		);
	}
}
