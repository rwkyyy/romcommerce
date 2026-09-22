<?php

declare(strict_types=1);

namespace RomCommerce\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Shared nonce/capability/identification boilerplate for a module's settings
 * form, so each HasSettingsUi::render_settings() doesn't reimplement it. All
 * module settings forms post back to the same admin.php?page=romcommerce
 * page, so the hidden "romcommerce_module" field is what tells verify()
 * which module's submission this is.
 */
final class SettingsForm {

	private const CAPABILITY = 'manage_woocommerce';

	public static function nonce_field( string $module_id ): void {
		wp_nonce_field( self::action( $module_id ), 'romcommerce_nonce' );
		echo '<input type="hidden" name="romcommerce_module" value="' . esc_attr( $module_id ) . '">';
	}

	public static function verify( string $module_id ): bool {
		if ( ! isset( $_POST['romcommerce_module'], $_POST['romcommerce_nonce'] ) ) {
			return false;
		}

		if ( sanitize_key( wp_unslash( $_POST['romcommerce_module'] ) ) !== $module_id ) {
			return false;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return false;
		}

		return (bool) wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['romcommerce_nonce'] ) ),
			self::action( $module_id )
		);
	}

	private static function action( string $module_id ): string {
		return 'romcommerce_save_' . $module_id;
	}
}
