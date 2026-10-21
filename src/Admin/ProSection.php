<?php

declare(strict_types=1);

namespace RomCommerce\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The one way a Lite pane shows Pro-only options: last in the pane, after the
 * form's own Save button, in a hatched and greyed box under a Pro badge, so
 * nothing in it reads as available (owner, 2026-10-13/14, D25: merchants
 * mistook mixed-in teasers for working settings). Rendering it outside the
 * <form> also keeps teasers render-only (WP.org constraint 4): whatever sits
 * inside can never be posted.
 */
final class ProSection {

	private const PRO_URL = 'https://romcommerce.ro/pro';

	/** @param bool $has_preview Whether disabled preview fields follow, which the note then explains. */
	public static function open( string $title, bool $has_preview = true ): void {
		$note = $has_preview
			? __( 'Not included in this plugin. Shown as a preview only; nothing here can be changed or saved.', 'romcommerce' )
			: __( 'Not included in this plugin.', 'romcommerce' );

		echo '<section class="rc-pro-section" aria-label="' . esc_attr( $title ) . '">';
		echo '<p class="rc-pro-section-head"><span class="rc-pro-badge">' . esc_html__( 'Pro', 'romcommerce' ) . '</span> ' . esc_html( $title ) . '</p>';
		echo '<p class="rc-pro-section-note">' . esc_html( $note ) . '</p>';
		echo '<div class="rc-pro-section-body">';
	}

	public static function close(): void {
		echo '</div>';
		echo '<p class="rc-pro-section-cta"><a href="' . esc_url( self::PRO_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Get RomCommerce Pro', 'romcommerce' ) . '</a></p>';
		echo '</section>';
	}

	/** A Pro-only item that needs no preview fields, just one line saying what it is. */
	public static function line( string $title, string $text ): void {
		self::open( $title, false );
		echo '<p>' . esc_html( $text ) . '</p>';
		self::close();
	}
}
