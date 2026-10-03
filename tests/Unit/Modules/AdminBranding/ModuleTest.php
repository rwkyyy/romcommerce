<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\AdminBranding;

use Brain\Monkey\Functions;
use RomCommerce\Modules\AdminBranding\Module;
use RomCommerce\Tests\Support\InvokesPrivateMethods;
use RomCommerce\Tests\TestCase;

final class ModuleTest extends TestCase {

	use InvokesPrivateMethods;

	public function test_darken_mixes_each_channel_toward_black_by_the_given_ratio(): void {
		self::assertSame( '#2b398f', $this->invokePrivate( new Module(), 'darken', array( '#303F9F', 0.1 ) ) );
		self::assertSame( '#26327f', $this->invokePrivate( new Module(), 'darken', array( '#303F9F', 0.2 ) ) );
	}

	public function test_lighten_mixes_each_channel_toward_white_by_the_given_ratio(): void {
		self::assertSame( '#7882c1', $this->invokePrivate( new Module(), 'lighten', array( '#303F9F', 0.35 ) ) );
	}

	public function test_hex_to_rgb_triplet_returns_comma_separated_decimal_components(): void {
		self::assertSame( '48, 63, 159', $this->invokePrivate( new Module(), 'hex_to_rgb_triplet', array( '#303F9F' ) ) );
	}

	/** @dataProvider hexColorProvider */
	public function test_is_hex_color( string $value, bool $expected ): void {
		self::assertSame( $expected, $this->invokePrivate( new Module(), 'is_hex_color', array( $value ) ) );
	}

	/** @return array<string, array{0: string, 1: bool}> */
	public static function hexColorProvider(): array {
		return array(
			'six-digit hex is valid'          => array( '#303f9f', true ),
			'three-digit hex is not accepted' => array( '#fff', false ),
			'missing hash is invalid'         => array( '303f9f', false ),
			'named colour is invalid'         => array( 'blue', false ),
			'empty string is invalid'         => array( '', false ),
		);
	}

	/** @dataProvider contrastTextColorProvider */
	public function test_contrast_text_color( string $background, string $expected ): void {
		self::assertSame( $expected, $this->invokePrivate( new Module(), 'contrast_text_color', array( $background ) ) );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function contrastTextColorProvider(): array {
		return array(
			'white background gets black text'             => array( '#ffffff', '#000000' ),
			'black background gets white text'             => array( '#000000', '#ffffff' ),
			'the default mid-blue brand colour gets white text' => array( '#303F9F', '#ffffff' ),
			'a bright yellow brand colour gets black text' => array( '#FFCA28', '#000000' ),
		);
	}

	/**
	 * wc-wp-version-gte-53 is a body class WooCommerce only ever adds in
	 * wp-admin, so admin_brand_css() (via this prefix) must be the only path
	 * that reaches wp-admin's select2 highlight — the frontend call passes an
	 * empty prefix instead, asserted separately below.
	 */
	public function test_select2_highlight_css_scopes_to_the_given_prefix(): void {
		$css = $this->invokePrivate( new Module(), 'select2_highlight_css', array( '#303f9f', '#ffffff', '.wp-admin.wc-wp-version-gte-53' ) );

		self::assertStringContainsString( '.wp-admin.wc-wp-version-gte-53 .select2-container--default .select2-results__option--highlighted[aria-selected]', $css );
		self::assertStringContainsString( '.wp-admin.wc-wp-version-gte-53 .select2-container--default .select2-results__option--highlighted[data-selected]', $css );
		self::assertStringContainsString( '.wp-admin.wc-wp-version-gte-53 .select2-dropdown {', $css );
		self::assertStringContainsString( '.wp-admin.wc-wp-version-gte-53 .select2-dropdown--below {', $css );
		self::assertStringContainsString( 'background-color: #303f9f;', $css );
		self::assertStringContainsString( 'color: #ffffff;', $css );
		self::assertStringContainsString( 'border-color: #303f9f;', $css );
		self::assertStringContainsString( 'box-shadow: 0 0 0 1px #303f9f, 0 2px 1px rgba(0, 0, 0, .1);', $css );
	}

	/**
	 * These two rules hardcode $base rather than reusing WooCommerce's own
	 * var(--wp-admin-theme-color) — a merchant on a non-default WP admin
	 * colour scheme would otherwise see that scheme's colour win over ours,
	 * since WP core sets the variable via body.admin-color-*, which
	 * outranks a bare :root override. Asserting the literal hex here is
	 * what locks that choice in, not just an incidental detail.
	 */
	public function test_select2_highlight_css_hardcodes_the_dropdown_colour_instead_of_the_css_variable(): void {
		$css = $this->invokePrivate( new Module(), 'select2_highlight_css', array( '#303f9f', '#ffffff', '.wp-admin.wc-wp-version-gte-53' ) );

		self::assertStringNotContainsString( 'var(--wp-admin-theme-color', $css );
	}

	public function test_select2_highlight_css_is_unscoped_for_the_frontend(): void {
		$css = $this->invokePrivate( new Module(), 'select2_highlight_css', array( '#303f9f', '#ffffff', '' ) );

		self::assertStringContainsString( '.select2-container--default .select2-results__option--highlighted[aria-selected]', $css );
		self::assertStringContainsString( '.select2-dropdown {', $css );
		self::assertStringContainsString( '.select2-dropdown--below {', $css );
		self::assertStringNotContainsString( '.wp-admin', $css );
	}

	public function test_frontend_select2_enabled_is_off_by_default(): void {
		Functions\expect( 'get_option' )->andReturn( array() );

		self::assertFalse( $this->invokePrivate( new Module(), 'frontend_select2_enabled', array() ) );
	}

	public function test_frontend_select2_enabled_respects_the_stored_setting(): void {
		Functions\expect( 'get_option' )->andReturn( array( 'frontend_select2' => true ) );

		self::assertTrue( $this->invokePrivate( new Module(), 'frontend_select2_enabled', array() ) );
	}
}
