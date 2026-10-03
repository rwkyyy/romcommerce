<?php

declare(strict_types=1);

namespace RomCommerce\Modules\WhatsappFab;

use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasPlacements;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;
use RomCommerce\Support\Placement;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Site-wide floating contact hub. An expandable FAB whose channels the merchant
 * configures (WhatsApp, Facebook, Instagram, phone, e-mail, …); only enabled
 * channels render and, if none are enabled, the FAB does not render at all.
 * WhatsApp, Telegram, and Email (`Channels::catalog()`'s `supports_message`
 * channels — the only link formats with a prefilled-message parameter) let the
 * merchant set a custom "hello" message plus an opt-in current-page link
 * appended after it; WhatsApp additionally falls back to its original
 * auto-generated product/page context when no custom message is set, so
 * existing installs keep their current behaviour untouched. Every other
 * channel stays a plain static link — their URL formats have no equivalent
 * parameter. CSS/JS are hand-written strings (no build step) but enqueued via
 * a src=false handle + wp_add_inline_style()/wp_add_inline_script(), not
 * echoed as raw <style>/<script> tags.
 */
final class Module implements ModuleInterface, HasSettingsUi, HasPlacements {

	private const ID = 'whatsapp-fab';

	private const ICON_TYPES = array( 'default', 'media', 'svg', 'dashicon' );

	/**
	 * Deliberately narrow allowlist for merchant-supplied inline SVG icons —
	 * shape/paint primitives only, no scripting-capable elements. wp_kses()
	 * matches attribute names case-insensitively but preserves the original
	 * casing on output, so 'viewbox' here still lets a camelCase viewBox
	 * survive (SVG/XML attribute names are case-sensitive).
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

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'WhatsApp / Contact FAB', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		// Placement hooks wire on init, not here — see HasPlacements.
		add_action( 'init', array( $this, 'register_placements' ) );
	}

	public function register_placements(): void {
		foreach ( $this->placements() as $placement ) {
			if ( $placement['active'] ) {
				Placement::hook( $placement['slot'], array( $this, 'render' ) );
			}
		}
	}

	/**
	 * The FAB is position:fixed, so it occupies the 'fixed' slot — on wp_footer
	 * like the relocating footer content, but pinned to a viewport corner and
	 * never moved into the theme footer, so it never collides with it.
	 * `active` mirrors render()'s own bail-out: with no channel both enabled and
	 * filled in, nothing renders, so the Placements map must not show the FAB.
	 *
	 * @return array<int, array{slot: string, active: bool}>
	 */
	public function placements(): array {
		return array(
			array(
				'slot'   => 'fixed',
				'active' => array() !== $this->active_channels(),
			),
		);
	}

	public function enqueue_assets(): void {
		$channels = $this->active_channels();
		if ( empty( $channels ) ) {
			return;
		}

		if ( $this->any_channel_uses_dashicon( $channels ) ) {
			// The dashicons handle is always registered by core, just not
			// enqueued on the front end by default.
			wp_enqueue_style( 'dashicons' );
		}

		wp_register_style( 'romcommerce-whatsapp-fab', false, array(), ROMCOMMERCE_VERSION );
		wp_enqueue_style( 'romcommerce-whatsapp-fab' );
		wp_add_inline_style( 'romcommerce-whatsapp-fab', $this->styles_css( $this->toggle_color() ) );

		wp_register_script( 'romcommerce-whatsapp-fab', false, array(), ROMCOMMERCE_VERSION, true );
		wp_enqueue_script( 'romcommerce-whatsapp-fab' );
		wp_add_inline_script( 'romcommerce-whatsapp-fab', $this->script_js() );
	}

	public function render(): void {
		if ( is_admin() ) {
			return;
		}

		$channels = $this->active_channels();
		if ( empty( $channels ) ) {
			return;
		}

		$position   = 'right' === $this->position() ? 'right' : 'left';
		$cycle_attr = ( $this->cycle_icons() && count( $channels ) > 1 ) ? ' data-rc-cycle="1"' : '';

		echo '<div id="romcommerce-fab" class="romcommerce-fab pos-' . esc_attr( $position ) . '"' . $cycle_attr . '>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $cycle_attr is a static literal, not user input.
		echo '<ul class="romcommerce-fab-list" aria-hidden="true">';
		foreach ( $channels as $id => $channel ) {
			$this->render_channel( $id, $channel );
		}
		echo '</ul>';

		echo '<button type="button" class="romcommerce-fab-toggle" aria-expanded="false" aria-controls="romcommerce-fab" aria-label="' . esc_attr__( 'Deschideți canalele de contact', 'romcommerce' ) . '">';
		echo '<span class="romcommerce-fab-toggle-icon">';
		echo $this->toggle_icon(); // phpcs:ignore WordPress.Security.EscapeOutput -- static inline SVG
		echo '</span>';
		echo '</button>';
		echo '</div>';
	}

	/**
	 * @param array{label: string, color: string, kind: string, hint: string, icon: string, text: string, supports_message: bool} $channel
	 */
	private function render_channel( string $id, array $channel ): void {
		$stored = $this->channel_settings( $id );
		$value  = (string) ( $stored['value'] ?? '' );
		$label  = '' !== (string) ( $stored['label'] ?? '' ) ? (string) $stored['label'] : $channel['label'];
		$href   = $this->build_href( $id, $channel['kind'], $value, $stored );

		if ( '' === $href ) {
			return;
		}

		$is_whatsapp = Channels::KIND_WHATSAPP === $channel['kind'];
		$extra_attrs = '';
		if ( $is_whatsapp ) {
			$extra_attrs = ' data-rc-wa-number="' . esc_attr( $this->digits( $value ) ) . '" data-rc-wa-base="' . esc_attr( $this->message_text( $id, $stored ) ) . '"';
		}

		echo '<li>';
		echo '<a class="romcommerce-fab-channel" style="background:' . esc_attr( $this->resolve_color( $channel, $stored ) ) . '" href="' . esc_url( $href ) . '"';
		if ( Channels::KIND_URL === $channel['kind'] ) {
			echo ' target="_blank" rel="noopener noreferrer"';
		}
		echo ' aria-label="' . esc_attr( $label ) . '"' . $extra_attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $extra_attrs built from esc_attr above

		echo $this->resolve_icon( $channel, $stored ); // phpcs:ignore WordPress.Security.EscapeOutput -- resolve_icon() sanitizes or escapes every branch before returning.

		echo '</a>';
		echo '<span class="romcommerce-fab-label">' . esc_html( $label ) . '</span>';
		echo '</li>';
	}

	/**
	 * @param array{color: string, ...} $channel
	 * @param array<string, mixed>      $stored
	 */
	private function resolve_color( array $channel, array $stored ): string {
		$color = $this->sanitize_hex_color( (string) ( $stored['color'] ?? '' ) );

		return '' !== $color ? $color : $channel['color'];
	}

	/**
	 * @param array{icon: string, text: string} $channel
	 * @param array<string, mixed>              $stored
	 */
	private function resolve_icon( array $channel, array $stored ): string {
		$type = (string) ( $stored['icon_type'] ?? 'default' );

		if ( 'media' === $type ) {
			$media_id = (int) ( $stored['icon_media_id'] ?? 0 );
			$image    = $media_id > 0 ? (string) wp_get_attachment_image( $media_id, array( 24, 24 ), false, array( 'alt' => '' ) ) : '';
			if ( '' !== $image ) {
				return $image;
			}
		} elseif ( 'svg' === $type ) {
			$svg = trim( (string) ( $stored['icon_svg'] ?? '' ) );
			if ( '' !== $svg ) {
				return $svg;
			}
		} elseif ( 'dashicon' === $type ) {
			$slug = (string) ( $stored['icon_dashicon'] ?? '' );
			if ( '' !== $slug ) {
				return '<span class="dashicons ' . esc_attr( $slug ) . '" aria-hidden="true"></span>';
			}
		}

		if ( '' !== $channel['icon'] ) {
			return $channel['icon'];
		}

		return '<span class="romcommerce-fab-monogram">' . esc_html( $channel['text'] ) . '</span>';
	}

	/** @param array<string, array{icon: string, text: string, ...}> $channels */
	private function any_channel_uses_dashicon( array $channels ): bool {
		foreach ( array_keys( $channels ) as $id ) {
			if ( 'dashicon' === ( $this->channel_settings( $id )['icon_type'] ?? 'default' ) ) {
				return true;
			}
		}

		return false;
	}

	private function sanitize_svg( string $raw ): string {
		$clean = wp_kses( $raw, self::ALLOWED_SVG_TAGS );

		return false !== stripos( $clean, '<svg' ) ? $clean : '';
	}

	private function sanitize_dashicon( string $slug ): string {
		$slug = sanitize_html_class( trim( $slug ) );
		if ( '' === $slug ) {
			return '';
		}

		return 0 === strpos( $slug, 'dashicons-' ) ? $slug : 'dashicons-' . $slug;
	}

	/**
	 * @param array<string, mixed> $stored
	 */
	private function build_href( string $id, string $kind, string $value, array $stored ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		if ( Channels::KIND_WHATSAPP === $kind ) {
			$digits = $this->digits( $value );

			return '' === $digits ? '' : 'https://wa.me/' . $digits . '?text=' . rawurlencode( $this->message_text( $id, $stored ) );
		}

		if ( Channels::KIND_TEL === $kind ) {
			return 'tel:' . preg_replace( '/[^0-9+]/', '', $value );
		}

		if ( Channels::KIND_MAILTO === $kind ) {
			$email = sanitize_email( $value );
			if ( '' === $email ) {
				return '';
			}

			$text = $this->message_text( $id, $stored );

			return '' === $text ? 'mailto:' . $email : 'mailto:' . $email . '?body=' . rawurlencode( $text );
		}

		$url = esc_url_raw( $value );
		if ( '' === $url || empty( Channels::catalog()[ $id ]['supports_message'] ) ) {
			return $url;
		}

		$text = $this->message_text( $id, $stored );

		// add_query_arg() URL-encodes the value itself; passing an already-encoded
		// string here would double-encode it.
		return '' === $text ? $url : add_query_arg( 'text', $text, $url );
	}

	/**
	 * Only `Channels::catalog()`'s `supports_message` channels (WhatsApp,
	 * Telegram, Email) ever call this — every other channel's build_href()
	 * branch never reaches it.
	 *
	 * @param array<string, mixed> $stored
	 */
	private function message_text( string $id, array $stored ): string {
		$hello = trim( (string) ( $stored['hello_message'] ?? '' ) );

		if ( '' === $hello ) {
			// WhatsApp alone keeps its original auto-generated context so
			// installs that predate the hello-message field see no change.
			return 'whatsapp' === $id ? $this->whatsapp_base_text() : '';
		}

		if ( empty( $stored['append_page_link'] ) ) {
			return $hello;
		}

		return $hello . ' ' . $this->current_page_link();
	}

	private function whatsapp_base_text(): string {
		return $this->current_page_title() . ' - ' . $this->current_page_link();
	}

	private function current_page_title(): string {
		if ( function_exists( 'is_product' ) && is_product() ) {
			$product = wc_get_product( get_queried_object_id() );
			if ( $product instanceof WC_Product ) {
				return $product->get_name();
			}
		}

		if ( is_singular() ) {
			return get_the_title();
		}

		return get_bloginfo( 'name' );
	}

	private function current_page_link(): string {
		if ( function_exists( 'is_product' ) && is_product() ) {
			$product = wc_get_product( get_queried_object_id() );
			if ( $product instanceof WC_Product ) {
				return get_permalink( $product->get_id() );
			}
		}

		if ( is_singular() ) {
			return get_permalink();
		}

		return home_url( '/' );
	}

	private function digits( string $value ): string {
		return (string) preg_replace( '/\D/', '', $value );
	}

	/**
	 * @return array<string, array{label: string, color: string, kind: string, hint: string, icon: string, text: string, supports_message: bool}>
	 */
	private function active_channels(): array {
		$active = array();
		foreach ( Channels::catalog() as $id => $channel ) {
			$stored = $this->channel_settings( $id );
			if ( empty( $stored['enabled'] ) || '' === trim( (string) ( $stored['value'] ?? '' ) ) ) {
				continue;
			}
			$active[ $id ] = $channel;
		}

		return $active;
	}

	/** @return array<string, mixed> */
	private function channel_settings( string $id ): array {
		$channels = Settings::get( self::ID )['channels'] ?? array();
		if ( ! is_array( $channels ) || ! isset( $channels[ $id ] ) || ! is_array( $channels[ $id ] ) ) {
			return array();
		}

		return $channels[ $id ];
	}

	private function position(): string {
		return (string) ( Settings::get( self::ID )['position'] ?? 'left' );
	}

	private function toggle_color(): string {
		$color = $this->sanitize_hex_color( (string) ( Settings::get( self::ID )['toggle_color'] ?? '' ) );

		return '' !== $color ? $color : '#303f9f';
	}

	private function cycle_icons(): bool {
		return ! empty( Settings::get( self::ID )['cycle_icons'] );
	}

	private function toggle_icon(): string {
		return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>';
	}

	/**
	 * @param string $toggle_color Hex colour already validated by toggle_color() — safe to
	 *                              splice into the raw CSS string without HTML-attribute escaping.
	 */
	private function styles_css( string $toggle_color ): string {
		// display:flex + align-items so the toggle button (an inline-block sibling
		// after the list, not itself a flex item of any row) anchors to the same
		// edge as the channel list instead of always hugging the container's left
		// edge — without this, opening the list on pos-right widens the container
		// leftward and the toggle visibly detaches from the right-anchored icons.
		return '.romcommerce-fab{position:fixed;z-index:9999;bottom:20px;display:flex;flex-direction:column;}'
			. '.romcommerce-fab.pos-left{left:20px;align-items:flex-start;}'
			. '.romcommerce-fab.pos-right{right:20px;align-items:flex-end;}'
			. '.romcommerce-fab-toggle{width:56px;height:56px;border-radius:50%;border:0;cursor:pointer;background:' . $toggle_color . ';color:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 10px rgba(0,0,0,.3);}'
			// A theme's own global a:hover/a:active (or button:hover) rule otherwise
			// wins the cascade over our plain :hover-less color:#fff above, repainting
			// the icon via fill:currentColor in whatever hover colour that theme uses.
			. '.romcommerce-fab-toggle:hover,.romcommerce-fab-toggle:focus,.romcommerce-fab-toggle:active{color:#fff;}'
			. '.romcommerce-fab-toggle-icon{display:flex;align-items:center;justify-content:center;opacity:1;transition:opacity .2s ease;}'
			. '.romcommerce-fab-toggle-icon.is-fading{opacity:0;}'
			. '.romcommerce-fab-toggle-icon svg{width:28px;height:28px;fill:currentColor;}'
			. '.romcommerce-fab-toggle-icon img{width:24px;height:24px;object-fit:contain;}'
			. '.romcommerce-fab-list{list-style:none;margin:0 0 12px;padding:0;display:none;}'
			. '.romcommerce-fab.is-open .romcommerce-fab-list{display:block;}'
			. '.romcommerce-fab-list li{margin-bottom:10px;display:flex;align-items:center;}'
			. '.romcommerce-fab.pos-right .romcommerce-fab-list li{flex-direction:row-reverse;}'
			. '.romcommerce-fab-channel{width:46px;height:46px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;text-decoration:none;box-shadow:0 2px 8px rgba(0,0,0,.25);font-weight:700;}'
			. '.romcommerce-fab-channel:link,.romcommerce-fab-channel:visited,.romcommerce-fab-channel:hover,.romcommerce-fab-channel:focus,.romcommerce-fab-channel:active{color:#fff;text-decoration:none;}'
			. '.romcommerce-fab-channel svg{width:24px;height:24px;fill:currentColor;}'
			. '.romcommerce-fab-monogram{font-size:20px;font-weight:700;line-height:1;}'
			. '.romcommerce-fab-label{background:#1e1e1e;color:#fff;padding:4px 8px;border-radius:4px;font-size:13px;margin:0 8px;white-space:nowrap;}';
	}

	private function script_js(): string {
		return '(function(){'
			. 'var fab=document.getElementById("romcommerce-fab");if(!fab){return;}'
			. 'var toggle=fab.querySelector(".romcommerce-fab-toggle");var list=fab.querySelector(".romcommerce-fab-list");'
			. 'function setOpen(open){fab.classList.toggle("is-open",open);toggle.setAttribute("aria-expanded",open?"true":"false");list.setAttribute("aria-hidden",open?"false":"true");}'
			. 'toggle.addEventListener("click",function(e){e.stopPropagation();setOpen(!fab.classList.contains("is-open"));});'
			. 'document.addEventListener("click",function(e){if(!fab.contains(e.target)){setOpen(false);}});'
			. 'document.addEventListener("keydown",function(e){if(e.key==="Escape"){setOpen(false);}});'
			. 'var iconSlot=toggle.querySelector(".romcommerce-fab-toggle-icon");'
			. 'if(iconSlot&&fab.hasAttribute("data-rc-cycle")&&!(window.matchMedia&&window.matchMedia("(prefers-reduced-motion: reduce)").matches)){'
			. 'var staticIcon=iconSlot.innerHTML;'
			. 'var channelIcons=Array.prototype.map.call(fab.querySelectorAll(".romcommerce-fab-channel"),function(a){return a.innerHTML;});'
			. 'var cycleIndex=-1;'
			. 'var swapIcon=function(html){iconSlot.classList.add("is-fading");setTimeout(function(){iconSlot.innerHTML=html;iconSlot.classList.remove("is-fading");},200);};'
			. 'var cycleTick=function(){'
			. 'if(fab.classList.contains("is-open")){setTimeout(cycleTick,1200);return;}'
			. 'cycleIndex++;'
			. 'if(cycleIndex>=channelIcons.length){cycleIndex=-1;swapIcon(staticIcon);setTimeout(cycleTick,2500);return;}'
			. 'swapIcon(channelIcons[cycleIndex]);setTimeout(cycleTick,1200);'
			. '};'
			. 'setTimeout(cycleTick,2500);'
			. '}'
			. 'var wa=fab.querySelector("[data-rc-wa-number]");'
			. 'if(wa&&window.jQuery){window.jQuery(".variations_form").on("found_variation",function(ev,variation){'
			. 'var base=wa.getAttribute("data-rc-wa-base");var extra=variation&&variation.variation_id?(" (#"+variation.variation_id+")"):"";'
			. 'wa.setAttribute("href","https://wa.me/"+wa.getAttribute("data-rc-wa-number")+"?text="+encodeURIComponent(base+extra));});}'
			. '})();';
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// SettingsForm::verify() has confirmed the nonce and the manage_woocommerce
			// capability on the line above; the $_POST reads live here inside that guard
			// (not in the handlers) so the authorization check is local to the input.
			// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above via SettingsForm::verify(); rc_channel elements are sanitized per-field in parse_channels().
			$this->handle_save(
				isset( $_POST['rc_enabled'] ),
				( isset( $_POST['rc_position'] ) && 'right' === sanitize_key( wp_unslash( $_POST['rc_position'] ) ) ) ? 'right' : 'left',
				isset( $_POST['rc_toggle_color'] ) ? $this->sanitize_hex_color( (string) wp_unslash( $_POST['rc_toggle_color'] ) ) : '',
				isset( $_POST['rc_cycle_icons'] ),
				$this->parse_channels( ( isset( $_POST['rc_channel'] ) && is_array( $_POST['rc_channel'] ) ) ? wp_unslash( $_POST['rc_channel'] ) : array() )
			);
			// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		echo '<p>' . esc_html__( 'A floating multi-channel contact button. Enable the channels you use and enter each one\'s link or number. WhatsApp, Telegram, and E-mail can also prefill a message to the person who opens the link.', 'romcommerce' ) . '</p>';

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );

		echo '<div class="rc-cat-head"><span class="rc-tile"></span><h3>' . esc_html__( 'General settings', 'romcommerce' ) . '</h3><span class="rc-rule"></span></div>';

		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Module', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ';
		echo esc_html__( 'Enable', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Position', 'romcommerce' ) . '</th><td>';
		Placement::position_field(
			'rc_position',
			array(
				'left'  => __( 'Bottom left', 'romcommerce' ),
				'right' => __( 'Bottom right', 'romcommerce' ),
			),
			$this->position()
		);
		echo '<p class="description">' . esc_html__( 'Bottom left avoids colliding with the cart widget and most live-chat plugins.', 'romcommerce' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Toggle button colour', 'romcommerce' ) . '</th><td>';
		echo '<input type="color" name="rc_toggle_color" value="' . esc_attr( $this->toggle_color() ) . '"></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Cycle animation', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_cycle_icons" value="1"' . checked( $this->cycle_icons(), true, false ) . '> ';
		echo esc_html__( 'Cycle through the active channel icons on the closed toggle button', 'romcommerce' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Shows each enabled channel\'s icon in turn, then pauses for a few seconds before cycling again. Only applies with 2 or more channels enabled.', 'romcommerce' ) . '</p></td></tr>';

		echo '</tbody></table>';

		$total_channels   = count( Channels::catalog() );
		$enabled_channels = 0;
		foreach ( array_keys( Channels::catalog() ) as $count_id ) {
			if ( ! empty( $this->channel_settings( $count_id )['enabled'] ) ) {
				++$enabled_channels;
			}
		}

		echo '<div class="rc-cat-head"><span class="rc-tile"></span><h3>' . esc_html__( 'Channels', 'romcommerce' ) . '</h3><span class="rc-rule"></span>';
		echo '<span class="rc-count">' . esc_html(
			/* translators: 1: number of enabled channels, 2: total available channels. */
			sprintf( __( '%1$d/%2$d enabled', 'romcommerce' ), $enabled_channels, $total_channels )
		) . '</span></div>';
		echo '<p class="description">' . esc_html__( 'Each channel can use its own colour and icon: the default brand icon, an image from your media library, a custom SVG, or a dashicon (WordPress\' built-in icon set).', 'romcommerce' ) . '</p>';

		echo '<div class="rc-item-grid">';
		foreach ( Channels::catalog() as $id => $channel ) {
			$stored         = $this->channel_settings( $id );
			$enabled        = ! empty( $stored['enabled'] );
			$value          = (string) ( $stored['value'] ?? '' );
			$label          = (string) ( $stored['label'] ?? '' );
			$color          = (string) ( $stored['color'] ?? '' );
			$resolved_color = '' !== $color ? $color : $channel['color'];
			$icon_type      = (string) ( $stored['icon_type'] ?? 'default' );
			$icon_media_id  = (int) ( $stored['icon_media_id'] ?? 0 );
			$icon_svg       = (string) ( $stored['icon_svg'] ?? '' );
			$icon_dashicon  = (string) ( $stored['icon_dashicon'] ?? '' );
			$hello_message  = (string) ( $stored['hello_message'] ?? '' );
			$append_link    = ! empty( $stored['append_page_link'] );

			echo '<div class="rc-item' . ( $enabled ? ' is-enabled' : '' ) . '">';
			echo '<div class="rc-item-head">';
			echo '<span class="rc-tile" style="background:' . esc_attr( $resolved_color ) . '"></span>';
			echo '<h4>' . esc_html( $channel['label'] ) . '</h4>';
			echo '<label class="rc-item-enable"><input type="checkbox" name="rc_channel[' . esc_attr( $id ) . '][enabled]" value="1"' . checked( $enabled, true, false ) . '> ';
			echo esc_html__( 'Enabled', 'romcommerce' ) . '</label>';
			echo '</div>';

			echo '<div class="rc-item-body">';
			echo '<input type="text" name="rc_channel[' . esc_attr( $id ) . '][value]" value="' . esc_attr( $value ) . '" class="regular-text" placeholder="' . esc_attr( $channel['hint'] ) . '">';
			echo '<input type="text" name="rc_channel[' . esc_attr( $id ) . '][label]" value="' . esc_attr( $label ) . '" class="regular-text" placeholder="' . esc_attr( $channel['label'] ) . '">';
			echo '<p class="description">' . esc_html( $channel['hint'] ) . '</p>';

			if ( $channel['supports_message'] ) {
				echo '<p><label>' . esc_html__( 'Hello message', 'romcommerce' ) . '<br>';
				echo '<input type="text" name="rc_channel[' . esc_attr( $id ) . '][hello_message]" value="' . esc_attr( $hello_message ) . '" class="regular-text" placeholder="' . esc_attr__( 'e.g. Hello, I have a question about:', 'romcommerce' ) . '"></label></p>';

				$hello_description = 'whatsapp' === $id
					? __( 'Leave blank to keep the automatic message (product name and page link).', 'romcommerce' )
					: __( 'Leave blank to use a plain link with no message.', 'romcommerce' );
				echo '<p class="description">' . esc_html( $hello_description ) . '</p>';

				echo '<p><label><input type="checkbox" name="rc_channel[' . esc_attr( $id ) . '][append_page_link]" value="1"' . checked( $append_link, true, false ) . '> ';
				echo esc_html__( 'Add a link to the current page after the hello message', 'romcommerce' ) . '</label></p>';
			}

			echo '<p><label>' . esc_html__( 'Colour', 'romcommerce' ) . ' ';
			echo '<input type="color" name="rc_channel[' . esc_attr( $id ) . '][color]" value="' . esc_attr( $resolved_color ) . '"></label></p>';

			$toggle_onchange = 'romcommerceToggleIcon(this,' . wp_json_encode( $id ) . ')';

			echo '<p><label>' . esc_html__( 'Icon', 'romcommerce' ) . ' ';
			echo '<select name="rc_channel[' . esc_attr( $id ) . '][icon_type]" onchange="' . esc_attr( $toggle_onchange ) . '">';
			$icon_labels = array(
				'default'  => __( 'Default', 'romcommerce' ),
				'media'    => __( 'Image (media library)', 'romcommerce' ),
				'svg'      => __( 'Custom SVG', 'romcommerce' ),
				'dashicon' => __( 'Dashicon', 'romcommerce' ),
			);
			foreach ( $icon_labels as $type_value => $type_label ) {
				echo '<option value="' . esc_attr( $type_value ) . '"' . selected( $icon_type, $type_value, false ) . '>' . esc_html( $type_label ) . '</option>';
			}
			echo '</select></label></p>';

			echo '<div id="rc-icon-' . esc_attr( $id ) . '-media"' . ( 'media' === $icon_type ? '' : ' style="display:none;"' ) . '>';
			echo '<input type="hidden" id="rc-icon-media-id-' . esc_attr( $id ) . '" name="rc_channel[' . esc_attr( $id ) . '][icon_media_id]" value="' . esc_attr( (string) $icon_media_id ) . '">';
			echo '<span id="rc-icon-media-preview-' . esc_attr( $id ) . '">';
			if ( $icon_media_id > 0 ) {
				echo wp_get_attachment_image( $icon_media_id, array( 32, 32 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image() escapes its own output.
			}
			echo '</span> ';
			$picker_onclick = 'romcommerceOpenMediaPicker(' . wp_json_encode( 'rc-icon-media-id-' . $id ) . ',' . wp_json_encode( 'rc-icon-media-preview-' . $id ) . ')';
			echo '<button type="button" class="button" onclick="' . esc_attr( $picker_onclick ) . '">' . esc_html__( 'Choose image', 'romcommerce' ) . '</button>';
			echo '</div>';

			echo '<div id="rc-icon-' . esc_attr( $id ) . '-svg"' . ( 'svg' === $icon_type ? '' : ' style="display:none;"' ) . '>';
			echo '<textarea name="rc_channel[' . esc_attr( $id ) . '][icon_svg]" rows="3" class="large-text code" placeholder="&lt;svg viewBox=&quot;0 0 24 24&quot;&gt;…&lt;/svg&gt;">' . esc_textarea( $icon_svg ) . '</textarea>';
			echo '</div>';

			echo '<div id="rc-icon-' . esc_attr( $id ) . '-dashicon"' . ( 'dashicon' === $icon_type ? '' : ' style="display:none;"' ) . '>';
			echo '<input type="text" name="rc_channel[' . esc_attr( $id ) . '][icon_dashicon]" value="' . esc_attr( $icon_dashicon ) . '" class="regular-text" placeholder="dashicons-facebook">';
			echo '<p class="description">' . esc_html__( 'A dashicon slug. See the Dashicons reference under any WordPress admin page.', 'romcommerce' ) . '</p>';
			echo '</div>';

			echo '</div></div>';
		}
		echo '</div>';

		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';
	}

	/**
	 * @param bool                                      $enabled      Module enable flag.
	 * @param string                                    $position     Sanitized FAB position.
	 * @param string                                    $toggle_color Sanitized toggle button hex colour, or '' to reset to the default.
	 * @param bool                                      $cycle_icons  Whether the toggle button cycles through active channel icons.
	 * @param array<string, array<string, string|bool>> $channels     Parsed channel settings.
	 */
	private function handle_save( bool $enabled, string $position, string $toggle_color, bool $cycle_icons, array $channels ): void {
		Settings::set_enabled( self::ID, $enabled );
		Settings::update(
			self::ID,
			array(
				'position'     => $position,
				'toggle_color' => $toggle_color,
				'cycle_icons'  => $cycle_icons,
				'channels'     => $channels,
			)
		);

		echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
	}

	private function sanitize_hex_color( string $color ): string {
		$color = trim( $color );

		return preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color ) ? $color : '';
	}

	/**
	 * @param array<int|string, mixed> $raw Unslashed rc_channel POST array.
	 * @return array<string, array<string, string|bool|int>>
	 */
	private function parse_channels( array $raw ): array {
		$channels = array();
		foreach ( Channels::catalog() as $id => $channel ) {
			$entry = isset( $raw[ $id ] ) && is_array( $raw[ $id ] ) ? $raw[ $id ] : array();

			$icon_type = (string) ( $entry['icon_type'] ?? 'default' );
			if ( ! in_array( $icon_type, self::ICON_TYPES, true ) ) {
				$icon_type = 'default';
			}

			$color = $this->sanitize_hex_color( (string) ( $entry['color'] ?? '' ) );

			$channels[ $id ] = array(
				'enabled'          => ! empty( $entry['enabled'] ),
				'value'            => sanitize_text_field( (string) ( $entry['value'] ?? '' ) ),
				'label'            => sanitize_text_field( (string) ( $entry['label'] ?? '' ) ),
				'color'            => $color,
				'icon_type'        => $icon_type,
				'icon_media_id'    => absint( $entry['icon_media_id'] ?? 0 ),
				'icon_svg'         => $this->sanitize_svg( (string) ( $entry['icon_svg'] ?? '' ) ),
				'icon_dashicon'    => $this->sanitize_dashicon( (string) ( $entry['icon_dashicon'] ?? '' ) ),
				'hello_message'    => sanitize_text_field( (string) ( $entry['hello_message'] ?? '' ) ),
				'append_page_link' => ! empty( $entry['append_page_link'] ),
			);
		}

		return $channels;
	}
}
