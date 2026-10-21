<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Support;

use Brain\Monkey\Functions;
use RomCommerce\Support\CustomIcon;
use RomCommerce\Tests\TestCase;

final class CustomIconTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubEscapeFunctions();
		Functions\when( 'sanitize_html_class' )->returnArg( 1 );
	}

	public function test_sanitize_dashicon_auto_prefixes_a_bare_slug(): void {
		self::assertSame( 'dashicons-facebook', CustomIcon::sanitize_dashicon( 'facebook' ) );
	}

	public function test_sanitize_dashicon_does_not_double_prefix(): void {
		self::assertSame( 'dashicons-facebook', CustomIcon::sanitize_dashicon( 'dashicons-facebook' ) );
	}

	public function test_sanitize_dashicon_returns_empty_for_blank_slug(): void {
		self::assertSame( '', CustomIcon::sanitize_dashicon( '  ' ) );
	}

	/**
	 * The allowlist exists specifically to keep a merchant-pasted SVG from
	 * carrying a <script> tag or an event-handler attribute (onload, etc.) —
	 * wp_kses() itself is WP's, but the shape/paint-only allowlist is ours,
	 * and this pins that a <script> tag never survives it.
	 */
	public function test_sanitize_svg_strips_disallowed_script_tags(): void {
		Functions\when( 'wp_kses' )->alias(
			static function ( string $string, array $allowed_html ): string {
				// A minimal stand-in for wp_kses(): strip any tag whose name
				// isn't in the allowlist.
				return (string) preg_replace_callback(
					'/<\/?([a-zA-Z0-9]+)[^>]*>/',
					static function ( array $matches ) use ( $allowed_html ): string {
						return isset( $allowed_html[ strtolower( $matches[1] ) ] ) ? $matches[0] : '';
					},
					$string
				);
			}
		);

		$result = CustomIcon::sanitize_svg( '<svg viewBox="0 0 24 24"><script>alert(1)</script><path d="M0 0"/></svg>' );

		self::assertStringNotContainsString( '<script', $result );
		self::assertStringContainsString( '<svg', $result );
	}

	public function test_sanitize_svg_rejects_input_with_no_svg_tag(): void {
		Functions\when( 'wp_kses' )->returnArg( 1 );

		self::assertSame( '', CustomIcon::sanitize_svg( '<p>not an icon</p>' ) );
	}

	public function test_an_unknown_type_saves_as_default(): void {
		self::assertSame( 'default', CustomIcon::sanitize_type( 'emoji' ) );
		self::assertSame( 'svg', CustomIcon::sanitize_type( 'svg' ) );
	}

	public function test_render_returns_nothing_for_the_default_type_so_the_module_falls_back(): void {
		self::assertSame( '', CustomIcon::render( array( 'icon_type' => 'default' ), 24 ) );
		self::assertSame( '', CustomIcon::render( array(), 24 ) );
	}

	public function test_render_dashicon_carries_the_extra_class(): void {
		self::assertSame(
			'<span class="dashicons dashicons-cart romcommerce-perk-icon" aria-hidden="true"></span>',
			CustomIcon::render(
				array(
					'icon_type'     => 'dashicon',
					'icon_dashicon' => 'cart',
				),
				28,
				'romcommerce-perk-icon'
			)
		);
	}

	public function test_render_media_without_an_attachment_falls_back(): void {
		self::assertSame( '', CustomIcon::render( array( 'icon_type' => 'media' ), 24 ) );
	}

	public function test_any_dashicon_needs_a_slug_not_just_the_type(): void {
		self::assertFalse( CustomIcon::any_dashicon( array( array( 'icon_type' => 'dashicon' ) ) ) );
		self::assertTrue(
			CustomIcon::any_dashicon(
				array(
					array( 'icon_type' => 'svg' ),
					array(
						'icon_type'     => 'dashicon',
						'icon_dashicon' => 'dashicons-cart',
					),
				)
			)
		);
	}
}
