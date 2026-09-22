<?php

declare(strict_types=1);

namespace RomCommerce\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The single AJAX endpoint behind the admin app. It returns the rail + canvas
 * fragments for whatever request the client sends — navigation, a module
 * toggle, or a settings-form save — by delegating to the same Page renderer
 * the full-page load uses, so no rendering or save logic is duplicated here.
 *
 * Gating is layered: this handler requires the manage_woocommerce capability
 * and a valid app nonce for *every* call, including read-only navigation; the
 * mutating requests (toggle, settings save) additionally carry — and Page's own
 * code re-verifies — their existing per-action nonces. A failure returns a JSON
 * error the client falls back to a full page load on, so a stale nonce degrades
 * to the non-AJAX flow rather than breaking the screen.
 */
final class Ajax {

	private const CAPABILITY = 'manage_woocommerce';
	private const ACTION     = 'romcommerce_pane';

	/** Nonce action shared with Menu::enqueue_assets(), which mints the token. */
	public const NONCE = 'romcommerce_admin';

	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
	}

	public function handle(): void {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session expired. The page will reload.', 'romcommerce' ) ), 403 );
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'romcommerce' ) ), 403 );
		}

		wp_send_json_success( ( new Page() )->fragments() );
	}
}
