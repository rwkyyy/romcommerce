<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\WhatsappFab;

use Brain\Monkey\Functions;
use RomCommerce\Modules\WhatsappFab\Channels;
use RomCommerce\Modules\WhatsappFab\Module;
use RomCommerce\Tests\Support\InvokesPrivateMethods;
use RomCommerce\Tests\TestCase;

final class ModuleTest extends TestCase {

	use InvokesPrivateMethods;

	protected function setUp(): void {
		parent::setUp();
		Functions\stubEscapeFunctions();
		Functions\stubTranslationFunctions();
	}

	public function test_sanitize_dashicon_auto_prefixes_a_bare_slug(): void {
		Functions\when( 'sanitize_html_class' )->returnArg( 1 );

		self::assertSame(
			'dashicons-facebook',
			$this->invokePrivate( new Module(), 'sanitize_dashicon', array( 'facebook' ) )
		);
	}

	public function test_sanitize_dashicon_does_not_double_prefix(): void {
		Functions\when( 'sanitize_html_class' )->returnArg( 1 );

		self::assertSame(
			'dashicons-facebook',
			$this->invokePrivate( new Module(), 'sanitize_dashicon', array( 'dashicons-facebook' ) )
		);
	}

	public function test_sanitize_dashicon_returns_empty_for_blank_slug(): void {
		Functions\when( 'sanitize_html_class' )->returnArg( 1 );

		self::assertSame( '', $this->invokePrivate( new Module(), 'sanitize_dashicon', array( '  ' ) ) );
	}

	/**
	 * The allowlist exists specifically to keep a merchant-pasted SVG from
	 * carrying a <script> tag or an event-handler attribute (onload, etc.) —
	 * wp_kses() itself is WP's, not this module's, but the shape/paint-only
	 * allowlist (Module::ALLOWED_SVG_TAGS) is, and this pins that a <script>
	 * tag specifically never survives it.
	 */
	public function test_sanitize_svg_strips_disallowed_script_tags(): void {
		Functions\when( 'wp_kses' )->alias(
			static function ( string $string, array $allowed_html ): string {
				// A minimal stand-in for wp_kses(): strip any tag whose name
				// isn't in the allowlist — WP's own wp_kses() implementation
				// is out of scope for a Brain Monkey unit test, only whether
				// this module passes it the right allowlist is.
				return (string) preg_replace_callback(
					'/<\/?([a-zA-Z0-9]+)[^>]*>/',
					static function ( array $matches ) use ( $allowed_html ): string {
						return isset( $allowed_html[ strtolower( $matches[1] ) ] ) ? $matches[0] : '';
					},
					$string
				);
			}
		);

		$result = $this->invokePrivate(
			new Module(),
			'sanitize_svg',
			array( '<svg viewBox="0 0 24 24"><script>alert(1)</script><path d="M0 0"/></svg>' )
		);

		self::assertStringNotContainsString( '<script', $result );
		self::assertStringContainsString( '<svg', $result );
	}

	public function test_sanitize_svg_rejects_input_with_no_svg_tag(): void {
		Functions\when( 'wp_kses' )->returnArg( 1 );

		self::assertSame( '', $this->invokePrivate( new Module(), 'sanitize_svg', array( '<p>not an icon</p>' ) ) );
	}

	/** @dataProvider buildHrefProvider */
	public function test_build_href( string $id, string $kind, string $value, string $expected ): void {
		Functions\when( 'sanitize_email' )->returnArg( 1 );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );

		self::assertSame( $expected, $this->invokePrivate( new Module(), 'build_href', array( $id, $kind, $value, array() ) ) );
	}

	/** @return array<string, array{0: string, 1: string, 2: string, 3: string}> */
	public static function buildHrefProvider(): array {
		return array(
			'tel strips everything but digits and a leading plus' => array(
				'phone',
				Channels::KIND_TEL,
				'+40 (712) 345-678',
				'tel:+40712345678',
			),
			'mailto with no hello message passes through a bare address' => array(
				'email',
				Channels::KIND_MAILTO,
				'contact@example.ro',
				'mailto:contact@example.ro',
			),
			'a generic url channel is escaped as a raw url' => array(
				'instagram',
				Channels::KIND_URL,
				'https://instagram.com/example',
				'https://instagram.com/example',
			),
			'telegram with no hello message is a bare url, despite supporting messages' => array(
				'telegram',
				Channels::KIND_URL,
				'https://t.me/example',
				'https://t.me/example',
			),
			'blank value yields no link at all, regardless of kind' => array(
				'phone',
				Channels::KIND_TEL,
				'   ',
				'',
			),
		);
	}

	public function test_build_href_appends_the_hello_message_for_mailto(): void {
		Functions\when( 'sanitize_email' )->returnArg( 1 );

		$href = $this->invokePrivate(
			new Module(),
			'build_href',
			array( 'email', Channels::KIND_MAILTO, 'contact@example.ro', array( 'hello_message' => 'Hi there' ) )
		);

		self::assertSame( 'mailto:contact@example.ro?body=Hi%20there', $href );
	}

	public function test_build_href_appends_the_hello_message_for_telegram(): void {
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'add_query_arg' )->alias(
			static function ( string $key, string $value, string $url ): string {
				return $url . '?' . $key . '=' . rawurlencode( $value );
			}
		);

		$href = $this->invokePrivate(
			new Module(),
			'build_href',
			array( 'telegram', Channels::KIND_URL, 'https://t.me/example', array( 'hello_message' => 'Hi there' ) )
		);

		self::assertSame( 'https://t.me/example?text=Hi%20there', $href );
	}

	public function test_message_text_returns_the_hello_message_alone_when_append_link_is_off(): void {
		$text = $this->invokePrivate(
			new Module(),
			'message_text',
			array( 'telegram', array( 'hello_message' => 'Hi there', 'append_page_link' => false ) )
		);

		self::assertSame( 'Hi there', $text );
	}

	public function test_message_text_appends_the_current_page_link_when_checked(): void {
		Functions\when( 'is_product' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://example.ro/' );

		$text = $this->invokePrivate(
			new Module(),
			'message_text',
			array( 'telegram', array( 'hello_message' => 'Hi there', 'append_page_link' => true ) )
		);

		self::assertSame( 'Hi there https://example.ro/', $text );
	}

	public function test_message_text_is_empty_for_telegram_and_email_when_no_hello_message_is_set(): void {
		self::assertSame( '', $this->invokePrivate( new Module(), 'message_text', array( 'telegram', array() ) ) );
		self::assertSame( '', $this->invokePrivate( new Module(), 'message_text', array( 'email', array() ) ) );
	}

	/**
	 * WhatsApp alone keeps its original auto-generated product/page context
	 * when no custom hello message is set, so installs that predate the
	 * hello-message field see no behaviour change.
	 */
	public function test_message_text_falls_back_to_the_legacy_whatsapp_context_when_no_hello_message_is_set(): void {
		Functions\when( 'is_product' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'get_bloginfo' )->justReturn( 'Example Shop' );
		Functions\when( 'home_url' )->justReturn( 'https://example.ro/' );

		$text = $this->invokePrivate( new Module(), 'message_text', array( 'whatsapp', array() ) );

		self::assertSame( 'Example Shop - https://example.ro/', $text );
	}

	public function test_digits_strips_non_numeric_characters(): void {
		self::assertSame( '40712345678', $this->invokePrivate( new Module(), 'digits', array( '+40 (712) 345-678' ) ) );
	}

	public function test_resolve_color_falls_back_to_the_channel_default_when_stored_color_is_invalid(): void {
		$module  = new Module();
		$channel = array( 'color' => '#303f9f' );

		self::assertSame(
			'#303f9f',
			$this->invokePrivate( $module, 'resolve_color', array( $channel, array( 'color' => 'not-a-hex-color' ) ) )
		);
		self::assertSame(
			'#ff0000',
			$this->invokePrivate( $module, 'resolve_color', array( $channel, array( 'color' => '#ff0000' ) ) )
		);
	}

	public function test_resolve_icon_falls_back_to_the_channel_default_then_the_monogram(): void {
		$module = new Module();

		self::assertSame(
			'<default-icon>',
			$this->invokePrivate(
				$module,
				'resolve_icon',
				array( array( 'icon' => '<default-icon>', 'text' => 'FB' ), array( 'icon_type' => 'default' ) )
			)
		);

		self::assertSame(
			'<span class="romcommerce-fab-monogram">FB</span>',
			$this->invokePrivate(
				$module,
				'resolve_icon',
				array( array( 'icon' => '', 'text' => 'FB' ), array( 'icon_type' => 'default' ) )
			)
		);
	}

	public function test_resolve_icon_dashicon_type_renders_the_stored_slug(): void {
		$result = $this->invokePrivate(
			new Module(),
			'resolve_icon',
			array(
				array( 'icon' => '<default-icon>', 'text' => 'FB' ),
				array(
					'icon_type'     => 'dashicon',
					'icon_dashicon' => 'dashicons-facebook',
				),
			)
		);

		self::assertSame( '<span class="dashicons dashicons-facebook" aria-hidden="true"></span>', $result );
	}

	public function test_placements_marks_the_fab_inactive_when_no_channel_would_render(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'channels' => array(
					'whatsapp' => array(
						'enabled' => true,
						'value'   => '  ',
					),
					'phone'    => array(
						'enabled' => false,
						'value'   => '0712345678',
					),
				),
			)
		);

		self::assertFalse( ( new Module() )->placements()[0]['active'] );
	}

	public function test_placements_marks_the_fab_active_once_a_channel_is_enabled_and_filled_in(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'channels' => array(
					'phone' => array(
						'enabled' => true,
						'value'   => '0712345678',
					),
				),
			)
		);

		self::assertTrue( ( new Module() )->placements()[0]['active'] );
	}
}
