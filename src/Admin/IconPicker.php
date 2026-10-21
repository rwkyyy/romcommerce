<?php

declare(strict_types=1);

namespace RomCommerce\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Settings-pane controls for a Support\CustomIcon choice: the type select and
 * its media / SVG / dashicon panels. admin.js shows the panel matching the
 * select (romcommerceToggleIcon) and drives the shared dashicon grid.
 */
final class IconPicker {

	/** Public list of every dashicon, for merchants who'd rather browse outside wp-admin. */
	private const DASHICONS_REFERENCE = 'https://developer.wordpress.org/resource/dashicons/';

	/**
	 * @param string               $key          Unique per row, used in element ids (e.g. "whatsapp", "perk-2").
	 * @param string               $name_prefix  Field name prefix, e.g. "rc_channel[whatsapp]".
	 * @param array<string, mixed> $stored       Keys icon_type, icon_media_id, icon_svg, icon_dashicon.
	 * @param string               $default_html Markup for the "default" panel (the module's own icon set), '' for none.
	 */
	public static function fields( string $key, string $name_prefix, array $stored, string $default_label, string $default_html = '' ): void {
		$type     = (string) ( $stored['icon_type'] ?? 'default' );
		$media_id = (int) ( $stored['icon_media_id'] ?? 0 );
		$svg      = (string) ( $stored['icon_svg'] ?? '' );
		$dashicon = (string) ( $stored['icon_dashicon'] ?? '' );

		$toggle = 'romcommerceToggleIcon(this,' . wp_json_encode( $key ) . ')';
		echo '<p><label>' . esc_html__( 'Icon', 'romcommerce' ) . ' ';
		echo '<select name="' . esc_attr( $name_prefix . '[icon_type]' ) . '" onchange="' . esc_attr( $toggle ) . '">';
		$labels = array(
			'default'  => $default_label,
			'media'    => __( 'Image (media library)', 'romcommerce' ),
			'svg'      => __( 'Custom SVG', 'romcommerce' ),
			'dashicon' => __( 'Dashicon', 'romcommerce' ),
		);
		foreach ( $labels as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $type, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label></p>';

		if ( '' !== $default_html ) {
			echo '<div id="rc-icon-' . esc_attr( $key ) . '-default"' . ( 'default' === $type ? '' : ' style="display:none;"' ) . '>';
			echo $default_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped by the calling module.
			echo '</div>';
		}

		echo '<div id="rc-icon-' . esc_attr( $key ) . '-media"' . ( 'media' === $type ? '' : ' style="display:none;"' ) . '>';
		echo '<input type="hidden" id="rc-icon-media-id-' . esc_attr( $key ) . '" name="' . esc_attr( $name_prefix . '[icon_media_id]' ) . '" value="' . esc_attr( (string) $media_id ) . '">';
		echo '<span id="rc-icon-media-preview-' . esc_attr( $key ) . '">';
		if ( $media_id > 0 ) {
			echo wp_get_attachment_image( $media_id, array( 32, 32 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image() escapes its own output.
		}
		echo '</span> ';
		$picker = 'romcommerceOpenMediaPicker(' . wp_json_encode( 'rc-icon-media-id-' . $key ) . ',' . wp_json_encode( 'rc-icon-media-preview-' . $key ) . ')';
		echo '<button type="button" class="button" onclick="' . esc_attr( $picker ) . '">' . esc_html__( 'Choose image', 'romcommerce' ) . '</button>';
		echo '</div>';

		echo '<div id="rc-icon-' . esc_attr( $key ) . '-svg"' . ( 'svg' === $type ? '' : ' style="display:none;"' ) . '>';
		echo '<textarea name="' . esc_attr( $name_prefix . '[icon_svg]' ) . '" rows="3" class="large-text code" placeholder="&lt;svg viewBox=&quot;0 0 24 24&quot;&gt;…&lt;/svg&gt;">' . esc_textarea( $svg ) . '</textarea>';
		echo '</div>';

		echo '<div id="rc-icon-' . esc_attr( $key ) . '-dashicon"' . ( 'dashicon' === $type ? '' : ' style="display:none;"' ) . '>';
		echo '<input type="text" id="rc-dashicon-' . esc_attr( $key ) . '" name="' . esc_attr( $name_prefix . '[icon_dashicon]' ) . '" value="' . esc_attr( $dashicon ) . '" class="regular-text" placeholder="dashicons-cart"> ';
		echo '<button type="button" class="button rc-dashicon-browse" data-rc-dashicon-target="rc-dashicon-' . esc_attr( $key ) . '" aria-controls="rc-dashicon-picker" aria-expanded="false">' . esc_html__( 'Browse icons', 'romcommerce' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'Pick an icon from the list, or type its name.', 'romcommerce' ) . ' <a href="' . esc_url( self::DASHICONS_REFERENCE ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'All Dashicons on WordPress.org', 'romcommerce' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * One shared grid per pane: admin.js moves it under the row whose "Browse
	 * icons" was pressed, and a click writes that icon's name into the row's
	 * field.
	 */
	public static function dashicon_grid(): void {
		$slugs = self::dashicon_slugs( self::read_dashicons_css() );
		if ( array() === $slugs ) {
			return;
		}

		echo '<div id="rc-dashicon-picker" class="rc-dashicon-picker" hidden>';
		echo '<label class="screen-reader-text" for="rc-dashicon-search">' . esc_html__( 'Search icons', 'romcommerce' ) . '</label>';
		echo '<input type="search" id="rc-dashicon-search" class="rc-dashicon-search regular-text" placeholder="' . esc_attr__( 'Search icons, e.g. cart', 'romcommerce' ) . '">';
		echo '<div class="rc-dashicon-grid">';
		foreach ( $slugs as $slug ) {
			echo '<button type="button" class="rc-dashicon-pick" data-rc-dashicon="' . esc_attr( $slug ) . '" title="' . esc_attr( $slug ) . '"><span class="dashicons ' . esc_attr( $slug ) . '" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html( $slug ) . '</span></button>';
		}
		echo '</div></div>';
	}

	/** @return array<int, string> Full class names, e.g. "dashicons-cart", in stylesheet order. */
	public static function dashicon_slugs( string $css ): array {
		preg_match_all( '/\\.(dashicons-[a-z0-9-]+):before\b/', $css, $matches );

		return array_values( array_diff( array_unique( $matches[1] ), array( 'dashicons-before' ) ) );
	}

	/**
	 * The installed WordPress's own stylesheet, so the list always matches the
	 * icons this site can actually show.
	 */
	private static function read_dashicons_css(): string {
		$file = ABSPATH . 'wp-includes/css/dashicons.css';
		if ( ! is_readable( $file ) ) {
			return '';
		}

		return (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local core file, read once per settings page view.
	}
}
