<?php

declare(strict_types=1);

namespace RomCommerce\Modules\PerksShop;

defined( 'ABSPATH' ) || exit;

/**
 * The badge icon set: plain 24×24 line icons drawn for RomCommerce, stroked
 * in currentColor so they take the theme's or the merchant's colour. Fixed
 * markup, never merchant-supplied, so it needs no SVG sanitising.
 */
final class Icons {

	private const PATHS = array(
		'truck'    => '<path d="M3 7h11v8H3z"/><path d="M14 10h4l3 3v2h-7z"/><circle cx="7" cy="17" r="1.8"/><circle cx="17" cy="17" r="1.8"/>',
		'return'   => '<path d="M9 7H4V2"/><path d="M4.5 7A8 8 0 1 1 4 13"/>',
		'lock'     => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
		'shield'   => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M8.5 12l2.5 2.5 4.5-5"/>',
		'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'gift'     => '<rect x="3" y="8" width="18" height="4"/><rect x="5" y="12" width="14" height="9"/><path d="M12 8v13"/><path d="M12 8c-1.5-3-5-3-5-1s3 1 5 1c2 0 5 1 5-1s-3.5-2-5 1"/>',
		'support'  => '<path d="M4 13a8 8 0 0 1 16 0"/><rect x="3" y="13" width="4" height="6" rx="1"/><rect x="17" y="13" width="4" height="6" rx="1"/>',
		'card'     => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M6 15h4"/>',
		'star'     => '<path d="M12 3l2.6 6 6.4.5-4.9 4.3 1.5 6.2L12 16.7 6.4 20l1.5-6.2L3 9.5 9.4 9z"/>',
		'check'    => '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.7 2.7L16 9.8"/>',
		'location' => '<path d="M12 21s7-6.2 7-12a7 7 0 0 0-14 0c0 5.8 7 12 7 12z"/><circle cx="12" cy="9" r="2.5"/>',
	);

	/** @return array<string, string> icon key => label, for the settings dropdown. */
	public static function labels(): array {
		return array(
			'truck'    => __( 'Delivery truck', 'romcommerce' ),
			'return'   => __( 'Returns', 'romcommerce' ),
			'lock'     => __( 'Padlock', 'romcommerce' ),
			'shield'   => __( 'Shield', 'romcommerce' ),
			'clock'    => __( 'Clock', 'romcommerce' ),
			'gift'     => __( 'Gift', 'romcommerce' ),
			'support'  => __( 'Customer support', 'romcommerce' ),
			'card'     => __( 'Card payment', 'romcommerce' ),
			'star'     => __( 'Star', 'romcommerce' ),
			'check'    => __( 'Check mark', 'romcommerce' ),
			'location' => __( 'Location', 'romcommerce' ),
		);
	}

	public static function exists( string $key ): bool {
		return isset( self::PATHS[ $key ] );
	}

	public static function svg( string $key ): string {
		if ( ! isset( self::PATHS[ $key ] ) ) {
			return '';
		}

		return '<svg class="romcommerce-perk-icon" viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . self::PATHS[ $key ] . '</svg>';
	}
}
