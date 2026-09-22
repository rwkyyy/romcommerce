<?php

declare(strict_types=1);

namespace RomCommerce\Modules\WhatsappFab;

use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Site-wide floating contact hub. An expandable FAB whose channels the merchant
 * configures (WhatsApp, Facebook, Instagram, phone, e-mail, …); only enabled
 * channels render and, if none are enabled, the FAB does not render at all.
 * Only the WhatsApp channel is contextual — its click-to-chat message is
 * prefilled with the current product's title (and selected variation) on a
 * product page, otherwise the current page. CSS/JS are hand-written strings
 * (no build step) but enqueued via a src=false handle + wp_add_inline_style()/
 * wp_add_inline_script(), not echoed as raw <style>/<script> tags.
 */
final class Module implements ModuleInterface, HasSettingsUi {

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
		add_action( 'wp_footer', array( $this, 'render' ) );
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
		wp_add_inline_style( 'romcommerce-whatsapp-fab', $this->styles_css() );

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

		$position = 'right' === $this->position() ? 'right' : 'left';

		echo '<div id="romcommerce-fab" class="romcommerce-fab pos-' . esc_attr( $position ) . '">';
		echo '<ul class="romcommerce-fab-list" aria-hidden="true">';
		foreach ( $channels as $id => $channel ) {
			$this->render_channel( $id, $channel );
		}
		echo '</ul>';

		echo '<button type="button" class="romcommerce-fab-toggle" aria-expanded="false" aria-controls="romcommerce-fab" aria-label="' . esc_attr__( 'Deschideți canalele de contact', 'romcommerce' ) . '">';
		echo $this->toggle_icon(); // phpcs:ignore WordPress.Security.EscapeOutput -- static inline SVG
		echo '</button>';
		echo '</div>';
	}

	/**
	 * @param array{label: string, color: string, kind: string, hint: string, icon: string, text: string} $channel
	 */
	private function render_channel( string $id, array $channel ): void {
		$stored = $this->channel_settings( $id );
		$value  = (string) ( $stored['value'] ?? '' );
		$label  = '' !== (string) ( $stored['label'] ?? '' ) ? (string) $stored['label'] : $channel['label'];
		$href   = $this->build_href( $channel['kind'], $value );

		if ( '' === $href ) {
			return;
		}

		$is_whatsapp = Channels::KIND_WHATSAPP === $channel['kind'];
		$extra_attrs = '';
		if ( $is_whatsapp ) {
			$extra_attrs = ' data-rc-wa-number="' . esc_attr( $this->digits( $value ) ) . '" data-rc-wa-base="' . esc_attr( $this->whatsapp_base_text() ) . '"';
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
		$color = trim( (string) ( $stored['color'] ?? '' ) );

		return preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color ) ? $color : $channel['color'];
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

	private function build_href( string $kind, string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		if ( Channels::KIND_WHATSAPP === $kind ) {
			$digits = $this->digits( $value );

			return '' === $digits ? '' : 'https://wa.me/' . $digits . '?text=' . rawurlencode( $this->whatsapp_base_text() );
		}

		if ( Channels::KIND_TEL === $kind ) {
			return 'tel:' . preg_replace( '/[^0-9+]/', '', $value );
		}

		if ( Channels::KIND_MAILTO === $kind ) {
			$email = sanitize_email( $value );

			return '' === $email ? '' : 'mailto:' . $email;
		}

		return esc_url_raw( $value );
	}

	private function whatsapp_base_text(): string {
		if ( function_exists( 'is_product' ) && is_product() ) {
			$product = wc_get_product( get_queried_object_id() );
			if ( $product instanceof WC_Product ) {
				return $product->get_name() . ' - ' . get_permalink( $product->get_id() );
			}
		}

		if ( is_singular() ) {
			return get_the_title() . ' - ' . get_permalink();
		}

		return get_bloginfo( 'name' ) . ' - ' . home_url( '/' );
	}

	private function digits( string $value ): string {
		return (string) preg_replace( '/\D/', '', $value );
	}

	/**
	 * @return array<string, array{label: string, color: string, kind: string, hint: string, icon: string, text: string}>
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

	private function toggle_icon(): string {
		return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>';
	}

	private function styles_css(): string {
		return '.romcommerce-fab{position:fixed;z-index:9999;bottom:20px;}'
			. '.romcommerce-fab.pos-left{left:20px;}'
			. '.romcommerce-fab.pos-right{right:20px;}'
			. '.romcommerce-fab-toggle{width:56px;height:56px;border-radius:50%;border:0;cursor:pointer;background:#303f9f;color:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 10px rgba(0,0,0,.3);}'
			. '.romcommerce-fab-toggle svg{width:28px;height:28px;fill:currentColor;}'
			. '.romcommerce-fab-list{list-style:none;margin:0 0 12px;padding:0;display:none;}'
			. '.romcommerce-fab.is-open .romcommerce-fab-list{display:block;}'
			. '.romcommerce-fab-list li{margin-bottom:10px;display:flex;align-items:center;}'
			. '.romcommerce-fab.pos-right .romcommerce-fab-list li{flex-direction:row-reverse;}'
			. '.romcommerce-fab-channel{width:46px;height:46px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;text-decoration:none;box-shadow:0 2px 8px rgba(0,0,0,.25);font-weight:700;}'
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
				$this->parse_channels( ( isset( $_POST['rc_channel'] ) && is_array( $_POST['rc_channel'] ) ) ? wp_unslash( $_POST['rc_channel'] ) : array() )
			);
			// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		echo '<p>' . esc_html__( 'A floating multi-channel contact button. Enable the channels you use and enter each one\'s link or number. Only WhatsApp prefills a message from the current page.', 'romcommerce' ) . '</p>';

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Module', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ';
		echo esc_html__( 'Enable', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Position', 'romcommerce' ) . '</th><td>';
		echo '<select name="rc_position">';
		echo '<option value="left"' . selected( $this->position(), 'left', false ) . '>' . esc_html__( 'Bottom left', 'romcommerce' ) . '</option>';
		echo '<option value="right"' . selected( $this->position(), 'right', false ) . '>' . esc_html__( 'Bottom right', 'romcommerce' ) . '</option>';
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Bottom left avoids colliding with the cart widget and most live-chat plugins.', 'romcommerce' ) . '</p></td></tr>';

		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Channels', 'romcommerce' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Each channel can use its own colour and icon: the default brand icon, an image from your media library, a custom SVG, or a dashicon (WordPress\' built-in icon set).', 'romcommerce' ) . '</p>';
		echo '<table class="form-table"><tbody>';
		foreach ( Channels::catalog() as $id => $channel ) {
			$stored        = $this->channel_settings( $id );
			$enabled       = ! empty( $stored['enabled'] );
			$value         = (string) ( $stored['value'] ?? '' );
			$label         = (string) ( $stored['label'] ?? '' );
			$color         = (string) ( $stored['color'] ?? '' );
			$icon_type     = (string) ( $stored['icon_type'] ?? 'default' );
			$icon_media_id = (int) ( $stored['icon_media_id'] ?? 0 );
			$icon_svg      = (string) ( $stored['icon_svg'] ?? '' );
			$icon_dashicon = (string) ( $stored['icon_dashicon'] ?? '' );

			echo '<tr><th scope="row">' . esc_html( $channel['label'] ) . '</th><td>';
			echo '<label><input type="checkbox" name="rc_channel[' . esc_attr( $id ) . '][enabled]" value="1"' . checked( $enabled, true, false ) . '> ';
			echo esc_html__( 'Enabled', 'romcommerce' ) . '</label><br>';
			echo '<input type="text" name="rc_channel[' . esc_attr( $id ) . '][value]" value="' . esc_attr( $value ) . '" class="regular-text" placeholder="' . esc_attr( $channel['hint'] ) . '"><br>';
			echo '<input type="text" name="rc_channel[' . esc_attr( $id ) . '][label]" value="' . esc_attr( $label ) . '" class="regular-text" placeholder="' . esc_attr( $channel['label'] ) . '">';
			echo '<p class="description">' . esc_html( $channel['hint'] ) . '</p>';

			echo '<p><label>' . esc_html__( 'Colour', 'romcommerce' ) . ' ';
			echo '<input type="color" name="rc_channel[' . esc_attr( $id ) . '][color]" value="' . esc_attr( '' !== $color ? $color : $channel['color'] ) . '"></label></p>';

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

			echo '</td></tr>';
		}
		echo '</tbody></table>';

		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';
	}

	/**
	 * @param bool                                      $enabled  Module enable flag.
	 * @param string                                    $position Sanitized FAB position.
	 * @param array<string, array<string, string|bool>> $channels Parsed channel settings.
	 */
	private function handle_save( bool $enabled, string $position, array $channels ): void {
		Settings::set_enabled( self::ID, $enabled );
		Settings::update(
			self::ID,
			array(
				'position' => $position,
				'channels' => $channels,
			)
		);

		echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
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

			$color = trim( (string) ( $entry['color'] ?? '' ) );
			if ( '' !== $color && ! preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color ) ) {
				$color = '';
			}

			$channels[ $id ] = array(
				'enabled'       => ! empty( $entry['enabled'] ),
				'value'         => sanitize_text_field( (string) ( $entry['value'] ?? '' ) ),
				'label'         => sanitize_text_field( (string) ( $entry['label'] ?? '' ) ),
				'color'         => $color,
				'icon_type'     => $icon_type,
				'icon_media_id' => absint( $entry['icon_media_id'] ?? 0 ),
				'icon_svg'      => $this->sanitize_svg( (string) ( $entry['icon_svg'] ?? '' ) ),
				'icon_dashicon' => $this->sanitize_dashicon( (string) ( $entry['icon_dashicon'] ?? '' ) ),
			);
		}

		return $channels;
	}
}
