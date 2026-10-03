<?php

declare(strict_types=1);

namespace RomCommerce\Modules\WhatsappFab;

defined( 'ABSPATH' ) || exit;

/**
 * Static catalog of the contact channels the FAB can expose. Each entry carries
 * a default (translatable) label, a brand colour, a link "kind" (how the href is
 * built), a value hint for the settings field, and either an inline SVG glyph or
 * a text monogram fallback — the reference build's shape. Brand SVGs are provided
 * for the channels with an unambiguous public glyph (WhatsApp, phone, email); the
 * rest use a coloured monogram rather than shipping approximate/trademarked
 * vector art, exactly the fallback the spec allows.
 *
 * `supports_message` marks the channels whose link format actually carries a
 * prefilled message to the recipient app (wa.me's `text=`, t.me's `text=`,
 * mailto's `body=`) — WhatsApp, Telegram, Email. Every other channel's URL has
 * no such parameter (opening Messenger/Facebook/Instagram/YouTube/TikTok just
 * loads the profile), so a "message" setting there would be stored but never
 * reach the visitor — deliberately left off the catalog entry instead.
 */
final class Channels {

	public const KIND_WHATSAPP = 'whatsapp';
	public const KIND_URL      = 'url';
	public const KIND_TEL      = 'tel';
	public const KIND_MAILTO   = 'mailto';

	/**
	 * @return array<string, array{label: string, color: string, kind: string, hint: string, icon: string, text: string, supports_message: bool}>
	 */
	public static function catalog(): array {
		return array(
			'whatsapp'       => array(
				'label'            => __( 'WhatsApp', 'romcommerce' ),
				'color'            => '#25D366',
				'kind'             => self::KIND_WHATSAPP,
				'hint'             => __( 'Phone in international format, digits only (e.g. 40712345678)', 'romcommerce' ),
				'icon'             => self::whatsapp_icon(),
				'text'             => '',
				'supports_message' => true,
			),
			'phone'          => array(
				'label'            => __( 'Telefon', 'romcommerce' ),
				'color'            => '#303F9F',
				'kind'             => self::KIND_TEL,
				'hint'             => __( 'Phone number', 'romcommerce' ),
				'icon'             => self::phone_icon(),
				'text'             => '',
				'supports_message' => false,
			),
			'email'          => array(
				'label'            => __( 'E-mail', 'romcommerce' ),
				'color'            => '#D32F2F',
				'kind'             => self::KIND_MAILTO,
				'hint'             => __( 'E-mail address', 'romcommerce' ),
				'icon'             => self::email_icon(),
				'text'             => '',
				'supports_message' => true,
			),
			'messenger'      => array(
				'label'            => __( 'Messenger', 'romcommerce' ),
				'color'            => '#0084FF',
				'kind'             => self::KIND_URL,
				'hint'             => __( 'Messenger link (m.me/…)', 'romcommerce' ),
				'icon'             => '',
				'text'             => 'M',
				'supports_message' => false,
			),
			'facebook'       => array(
				'label'            => __( 'Facebook', 'romcommerce' ),
				'color'            => '#1877F2',
				'kind'             => self::KIND_URL,
				'hint'             => __( 'Facebook Page URL', 'romcommerce' ),
				'icon'             => '',
				'text'             => 'f',
				'supports_message' => false,
			),
			'facebook_group' => array(
				'label'            => __( 'Grup Facebook', 'romcommerce' ),
				'color'            => '#1877F2',
				'kind'             => self::KIND_URL,
				'hint'             => __( 'Facebook Group URL', 'romcommerce' ),
				'icon'             => '',
				'text'             => 'g',
				'supports_message' => false,
			),
			'instagram'      => array(
				'label'            => __( 'Instagram', 'romcommerce' ),
				'color'            => '#E4405F',
				'kind'             => self::KIND_URL,
				'hint'             => __( 'Instagram profile URL', 'romcommerce' ),
				'icon'             => '',
				'text'             => 'IG',
				'supports_message' => false,
			),
			'youtube'        => array(
				'label'            => __( 'YouTube', 'romcommerce' ),
				'color'            => '#FF0000',
				'kind'             => self::KIND_URL,
				'hint'             => __( 'YouTube channel URL', 'romcommerce' ),
				'icon'             => '',
				'text'             => 'YT',
				'supports_message' => false,
			),
			'tiktok'         => array(
				'label'            => __( 'TikTok', 'romcommerce' ),
				'color'            => '#000000',
				'kind'             => self::KIND_URL,
				'hint'             => __( 'TikTok profile URL', 'romcommerce' ),
				'icon'             => '',
				'text'             => 'TT',
				'supports_message' => false,
			),
			'telegram'       => array(
				'label'            => __( 'Telegram', 'romcommerce' ),
				'color'            => '#1C88BC',
				'kind'             => self::KIND_URL,
				'hint'             => __( 'Telegram link (t.me/…)', 'romcommerce' ),
				'icon'             => '',
				'text'             => 'TG',
				'supports_message' => true,
			),
		);
	}

	private static function whatsapp_icon(): string {
		return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.149-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.148-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.29.173-1.414-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>';
	}

	private static function phone_icon(): string {
		return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg>';
	}

	private static function email_icon(): string {
		return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>';
	}
}
