<?php

declare(strict_types=1);

namespace RomCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * A merchant-chosen icon: an image from the media library, an inline SVG or a
 * dashicon. Shared by the Floating contact FAB channels and the Perks shop
 * badges; each module keeps its own "default" icon set and falls back to it
 * when render() returns ''.
 */
final class CustomIcon {

	public const TYPES = array( 'default', 'media', 'svg', 'dashicon' );

	/**
	 * Deliberately narrow allowlist for merchant-supplied inline SVG icons —
	 * shape/paint primitives only, no scripting-capable elements. wp_kses()
	 * lowercases attribute names, which is fine inline in HTML: the browser's
	 * parser restores SVG's camelCase viewBox from 'viewbox'.
	 *
	 * @var array<string, array<string, bool>>
	 */
	private const ALLOWED_SVG_TAGS = array(
		'svg'     => array(
			'viewbox'      => true,
			'width'        => true,
			'height'       => true,
			'xmlns'        => true,
			'fill'         => true,
			'aria-hidden'  => true,
			'focusable'    => true,
			'class'        => true,
			'stroke'       => true,
			'stroke-width' => true,
		),
		'path'    => array(
			'd'               => true,
			'fill'            => true,
			'fill-rule'       => true,
			'clip-rule'       => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
		),
		'g'       => array(
			'fill'      => true,
			'transform' => true,
			'stroke'    => true,
		),
		'circle'  => array(
			'cx'     => true,
			'cy'     => true,
			'r'      => true,
			'fill'   => true,
			'stroke' => true,
		),
		'rect'    => array(
			'x'      => true,
			'y'      => true,
			'width'  => true,
			'height' => true,
			'rx'     => true,
			'ry'     => true,
			'fill'   => true,
			'stroke' => true,
		),
		'polygon' => array(
			'points' => true,
			'fill'   => true,
		),
		'line'    => array(
			'x1'           => true,
			'y1'           => true,
			'x2'           => true,
			'y2'           => true,
			'stroke'       => true,
			'stroke-width' => true,
		),
		'title'   => array(),
	);

	public static function sanitize_type( string $type ): string {
		return in_array( $type, self::TYPES, true ) ? $type : 'default';
	}

	public static function sanitize_svg( string $raw ): string {
		$clean = wp_kses( $raw, self::ALLOWED_SVG_TAGS );

		return false !== stripos( $clean, '<svg' ) ? $clean : '';
	}

	public static function sanitize_dashicon( string $slug ): string {
		$slug = sanitize_html_class( trim( $slug ) );
		if ( '' === $slug ) {
			return '';
		}

		return 0 === strpos( $slug, 'dashicons-' ) ? $slug : 'dashicons-' . $slug;
	}

	/**
	 * @param array<string, mixed> $stored Keys icon_type, icon_media_id, icon_svg, icon_dashicon.
	 * @param int                  $size   Pixel size requested for a media-library image.
	 * @param string               $class  Class added to whatever is returned.
	 * @return string Escaped markup, or '' when the merchant chose the default or left the choice empty.
	 */
	public static function render( array $stored, int $size, string $class = '' ): string {
		$type = (string) ( $stored['icon_type'] ?? 'default' );

		if ( 'media' === $type ) {
			$media_id = (int) ( $stored['icon_media_id'] ?? 0 );
			$attrs    = array( 'alt' => '' );
			if ( '' !== $class ) {
				$attrs['class'] = $class;
			}

			return $media_id > 0 ? (string) wp_get_attachment_image( $media_id, array( $size, $size ), false, $attrs ) : '';
		}

		if ( 'svg' === $type ) {
			$svg = self::sanitize_svg( trim( (string) ( $stored['icon_svg'] ?? '' ) ) );
			if ( '' === $svg || '' === $class ) {
				return $svg;
			}

			return '<span class="' . esc_attr( $class ) . '" aria-hidden="true">' . $svg . '</span>';
		}

		if ( 'dashicon' === $type ) {
			$slug = self::sanitize_dashicon( (string) ( $stored['icon_dashicon'] ?? '' ) );

			return '' !== $slug ? '<span class="dashicons ' . esc_attr( trim( $slug . ' ' . $class ) ) . '" aria-hidden="true"></span>' : '';
		}

		return '';
	}

	/** @param array<int|string, array<string, mixed>> $items */
	public static function any_dashicon( array $items ): bool {
		foreach ( $items as $stored ) {
			if ( 'dashicon' === ( $stored['icon_type'] ?? 'default' ) && '' !== (string) ( $stored['icon_dashicon'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}
}
