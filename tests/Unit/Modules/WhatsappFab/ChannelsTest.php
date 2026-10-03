<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\WhatsappFab;

use Brain\Monkey\Functions;
use RomCommerce\Modules\WhatsappFab\Channels;
use RomCommerce\Tests\TestCase;

final class ChannelsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
	}

	public function test_catalog_has_the_expected_channel_keys(): void {
		$catalog = Channels::catalog();

		self::assertSame(
			array( 'whatsapp', 'phone', 'email', 'messenger', 'facebook', 'facebook_group', 'instagram', 'youtube', 'tiktok', 'telegram' ),
			array_keys( $catalog )
		);
	}

	public function test_every_channel_entry_has_the_required_shape(): void {
		foreach ( Channels::catalog() as $key => $channel ) {
			self::assertArrayHasKey( 'label', $channel, "channel {$key} missing label" );
			self::assertArrayHasKey( 'color', $channel, "channel {$key} missing color" );
			self::assertMatchesRegularExpression( '/^#[0-9A-Fa-f]{6}$/', $channel['color'], "channel {$key} has an invalid color" );
			self::assertArrayHasKey( 'kind', $channel, "channel {$key} missing kind" );
			self::assertArrayHasKey( 'hint', $channel, "channel {$key} missing hint" );
			self::assertArrayHasKey( 'icon', $channel, "channel {$key} missing icon" );
			self::assertArrayHasKey( 'text', $channel, "channel {$key} missing text" );
			self::assertArrayHasKey( 'supports_message', $channel, "channel {$key} missing supports_message" );
			// Every channel needs either an icon glyph or a text monogram fallback.
			self::assertTrue( '' !== $channel['icon'] || '' !== $channel['text'], "channel {$key} has neither icon nor text" );
		}
	}

	public function test_only_whatsapp_uses_the_whatsapp_kind(): void {
		$catalog = Channels::catalog();

		self::assertSame( Channels::KIND_WHATSAPP, $catalog['whatsapp']['kind'] );
		self::assertSame( Channels::KIND_TEL, $catalog['phone']['kind'] );
		self::assertSame( Channels::KIND_MAILTO, $catalog['email']['kind'] );
		self::assertSame( Channels::KIND_URL, $catalog['facebook']['kind'] );
	}

	/**
	 * WhatsApp, Telegram, and Email are the only channels whose link format
	 * carries a prefilled-message parameter (wa.me/t.me's `text=`, mailto's
	 * `body=`) — every other channel's URL has nowhere for a message to go.
	 */
	public function test_only_whatsapp_telegram_and_email_support_a_message(): void {
		$catalog = Channels::catalog();

		foreach ( $catalog as $key => $channel ) {
			$expected = in_array( $key, array( 'whatsapp', 'telegram', 'email' ), true );
			self::assertSame( $expected, $channel['supports_message'], "channel {$key} has the wrong supports_message flag" );
		}
	}
}
