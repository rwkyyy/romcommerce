<?php

declare(strict_types=1);

namespace RomCommerce\Modules\PerksShop;

use RomCommerce\Admin\IconPicker;
use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasPlacements;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;
use RomCommerce\Support\CustomIcon;
use RomCommerce\Support\Placement;

defined( 'ABSPATH' ) || exit;

/**
 * Perks shop (Lite): trust/service badges ("fast delivery", "14-day
 * returns", "secure payment") under Add to Cart, and a payment-methods
 * widget (a heading over an image banner, e.g. the card processor's logos)
 * in the site footer. Both also come as shortcodes, [romcommerce_perks] and
 * [romcommerce_payment_methods]. Not a loyalty or points system.
 */
final class Module implements ModuleInterface, HasSettingsUi, HasPlacements {

	private const ID = 'perks-shop';

	private const MAX_BADGES = 6;

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Perks Shop', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		add_shortcode( 'romcommerce_perks', array( $this, 'badges_shortcode' ) );
		add_shortcode( 'romcommerce_payment_methods', array( $this, 'payment_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		// Placement hooks wire on init, not here — see HasPlacements.
		add_action( 'init', array( $this, 'register_placements' ) );
	}

	public function register_placements(): void {
		$callbacks = array(
			'product' => array( $this, 'render_product_badges' ),
			'footer'  => array( $this, 'render_footer_payment' ),
		);

		foreach ( $this->placements() as $placement ) {
			if ( $placement['active'] && isset( $callbacks[ $placement['key'] ] ) ) {
				Placement::hook( $placement['slot'], $callbacks[ $placement['key'] ], $placement['priority'] ?? null );
			}
		}
	}

	/**
	 * Priority 5 on the Add to Cart slot: the badges sit right under the
	 * button, above the free shipping bar and WhatsApp button (priority 10).
	 *
	 * @return array<int, array{key: string, slot: string, active: bool, priority?: int, choices?: array<int, string>}>
	 */
	public function placements(): array {
		$settings = Settings::get( self::ID );

		return Placement::resolve(
			self::ID,
			array(
				array(
					'key'      => 'product',
					'slot'     => 'add_to_cart',
					'priority' => 5,
					'active'   => (bool) ( $settings['show_on_product'] ?? true ) && array() !== $this->badges(),
					'choices'  => Placement::surface_slots( 'product' ),
				),
				array(
					'key'    => 'footer',
					'slot'   => 'footer',
					'active' => (bool) ( $settings['payment_in_footer'] ?? true ) && $this->payment_image_id() > 0,
				),
			)
		);
	}

	public function enqueue_assets(): void {
		wp_register_style( 'romcommerce-perks-shop', false, array(), ROMCOMMERCE_VERSION );
		wp_enqueue_style( 'romcommerce-perks-shop' );
		wp_add_inline_style( 'romcommerce-perks-shop', $this->styles_css() );

		if ( CustomIcon::any_dashicon( $this->badges() ) ) {
			// Registered by core everywhere, enqueued on the front end only on request.
			wp_enqueue_style( 'dashicons' );
		}

		if ( ( Settings::get( self::ID )['payment_in_footer'] ?? true ) && $this->payment_image_id() > 0 ) {
			wp_register_script( 'romcommerce-perks-shop', false, array(), ROMCOMMERCE_VERSION, true );
			wp_enqueue_script( 'romcommerce-perks-shop' );
			wp_add_inline_script( 'romcommerce-perks-shop', Placement::footer_relocation_script( 'romcommerce-payment-methods' ) );
		}
	}

	public function render_product_badges(): void {
		echo $this->badges_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in badges_html().
	}

	public function render_footer_payment(): void {
		$html = $this->payment_html();
		if ( '' === $html ) {
			return;
		}

		echo '<div id="romcommerce-payment-methods" data-rc-footer-order="' . esc_attr( (string) Placement::footer_rank( self::ID ) ) . '" style="padding:12px 16px;">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html built and escaped in payment_html().
	}

	public function badges_shortcode(): string {
		return $this->badges_html();
	}

	public function payment_shortcode(): string {
		return $this->payment_html();
	}

	/**
	 * The badges with a title, in the merchant's order; rows left blank in the
	 * settings are skipped.
	 *
	 * @return array<int, array{icon: string, icon_type: string, icon_media_id: int, icon_svg: string, icon_dashicon: string, title: string, text: string}>
	 */
	public function badges(): array {
		$stored = Settings::get( self::ID )['badges'] ?? null;
		$badges = is_array( $stored ) ? $stored : self::default_badges();

		return array_values(
			array_filter(
				array_map( array( self::class, 'clean_badge' ), $badges ),
				static function ( array $badge ): bool {
					return '' !== $badge['title'];
				}
			)
		);
	}

	/**
	 * "icon" is the built-in set's key; the icon_* keys hold a custom icon
	 * (Support\CustomIcon). Badges saved before custom icons existed have no
	 * icon_type and keep their built-in icon.
	 *
	 * @param mixed $badge
	 * @return array{icon: string, icon_type: string, icon_media_id: int, icon_svg: string, icon_dashicon: string, title: string, text: string}
	 */
	public static function clean_badge( $badge ): array {
		$badge = is_array( $badge ) ? $badge : array();
		$icon  = (string) ( $badge['icon'] ?? '' );

		return array(
			'icon'          => Icons::exists( $icon ) ? $icon : '',
			'icon_type'     => CustomIcon::sanitize_type( (string) ( $badge['icon_type'] ?? 'default' ) ),
			'icon_media_id' => absint( $badge['icon_media_id'] ?? 0 ),
			'icon_svg'      => (string) ( $badge['icon_svg'] ?? '' ),
			'icon_dashicon' => (string) ( $badge['icon_dashicon'] ?? '' ),
			'title'         => trim( (string) ( $badge['title'] ?? '' ) ),
			'text'          => trim( (string) ( $badge['text'] ?? '' ) ),
		);
	}

	/** @return array<int, array{icon: string, title: string, text: string}> */
	private static function default_badges(): array {
		return array(
			array(
				'icon'  => 'truck',
				'title' => __( 'Livrare rapidă', 'romcommerce' ),
				'text'  => __( 'În 1–3 zile lucrătoare', 'romcommerce' ),
			),
			array(
				'icon'  => 'return',
				'title' => __( 'Retur în 14 zile', 'romcommerce' ),
				'text'  => __( 'Simplu, fără explicații', 'romcommerce' ),
			),
			array(
				'icon'  => 'lock',
				'title' => __( 'Plată securizată', 'romcommerce' ),
				'text'  => __( 'Date protejate', 'romcommerce' ),
			),
		);
	}

	private function badges_html(): string {
		$badges = $this->badges();
		if ( array() === $badges ) {
			return '';
		}

		$html = '<ul class="romcommerce-perks">';
		foreach ( $badges as $badge ) {
			$icon  = CustomIcon::render( $badge, 28, 'romcommerce-perk-icon' );
			$html .= '<li class="romcommerce-perk">' . ( '' !== $icon ? $icon : Icons::svg( $badge['icon'] ) );
			$html .= '<span class="romcommerce-perk-body"><strong class="romcommerce-perk-title">' . esc_html( $badge['title'] ) . '</strong>';
			if ( '' !== $badge['text'] ) {
				$html .= '<span class="romcommerce-perk-text">' . esc_html( $badge['text'] ) . '</span>';
			}
			$html .= '</span></li>';
		}

		return $html . '</ul>';
	}

	private function payment_html(): string {
		$image_id = $this->payment_image_id();
		if ( $image_id <= 0 ) {
			return '';
		}

		$heading = (string) ( Settings::get( self::ID )['payment_heading'] ?? __( 'Metode de plată', 'romcommerce' ) );
		$image   = wp_get_attachment_image( $image_id, 'medium_large', false, array( 'class' => 'romcommerce-payment-methods-image' ) );
		if ( '' === $image ) {
			return '';
		}

		$html = '<div class="romcommerce-payment-methods">';
		if ( '' !== $heading ) {
			$html .= '<p class="romcommerce-payment-methods-heading">' . esc_html( $heading ) . '</p>';
		}

		return $html . $image . '</div>';
	}

	private function payment_image_id(): int {
		return (int) ( Settings::get( self::ID )['payment_image_id'] ?? 0 );
	}

	private function icon_color(): string {
		$color = sanitize_hex_color( (string) ( Settings::get( self::ID )['icon_color'] ?? '' ) );

		return is_string( $color ) ? $color : '';
	}

	private function text_color(): string {
		$color = sanitize_hex_color( (string) ( Settings::get( self::ID )['text_color'] ?? '' ) );

		return is_string( $color ) ? $color : '';
	}

	private function styles_css(): string {
		$css = '.romcommerce-perks{list-style:none;margin:1em 0;padding:0;display:flex;flex-wrap:wrap;gap:12px 20px;flex-basis:100%;width:100%;}'
			. '.romcommerce-perk{display:flex;align-items:center;gap:10px;margin:0;flex:1 1 160px;}'
			. '.romcommerce-perk-icon{flex:0 0 auto;}'
			. 'img.romcommerce-perk-icon{width:28px;height:28px;object-fit:contain;}'
			. 'span.romcommerce-perk-icon svg{display:block;width:28px;height:28px;fill:currentColor;}'
			. '.romcommerce-perk .dashicons.romcommerce-perk-icon{width:28px;height:28px;font-size:28px;}'
			. '.romcommerce-perk-body{display:flex;flex-direction:column;line-height:1.3;}'
			. '.romcommerce-perk-text{font-size:.875em;opacity:.8;}'
			. '.romcommerce-payment-methods-heading{margin:0 0 6px;font-weight:600;}'
			. '.romcommerce-payment-methods-image{max-width:100%;height:auto;display:block;}';

		if ( '' !== $this->icon_color() ) {
			$css .= '.romcommerce-perk-icon{color:' . $this->icon_color() . ';}';
		}

		if ( '' !== $this->text_color() ) {
			$css .= '.romcommerce-perk-body,.romcommerce-payment-methods-heading{color:' . $this->text_color() . ';}';
		}

		return $css;
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above via SettingsForm::verify().
			$posted = isset( $_POST['rc_badges'] ) && is_array( $_POST['rc_badges'] ) ? wp_unslash( $_POST['rc_badges'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized below.
			$badges = array();
			foreach ( array_slice( $posted, 0, self::MAX_BADGES ) as $badge ) {
				$badge    = is_array( $badge ) ? $badge : array();
				$badges[] = self::clean_badge(
					array(
						'icon'          => sanitize_key( (string) ( $badge['icon'] ?? '' ) ),
						'icon_type'     => sanitize_key( (string) ( $badge['icon_type'] ?? '' ) ),
						'icon_media_id' => absint( $badge['icon_media_id'] ?? 0 ),
						'icon_svg'      => CustomIcon::sanitize_svg( (string) ( $badge['icon_svg'] ?? '' ) ),
						'icon_dashicon' => CustomIcon::sanitize_dashicon( (string) ( $badge['icon_dashicon'] ?? '' ) ),
						'title'         => sanitize_text_field( (string) ( $badge['title'] ?? '' ) ),
						'text'          => sanitize_text_field( (string) ( $badge['text'] ?? '' ) ),
					)
				);
			}

			Settings::set_enabled( self::ID, isset( $_POST['rc_enabled'] ) );
			Settings::update(
				self::ID,
				array(
					'badges'            => $badges,
					'show_on_product'   => isset( $_POST['rc_show_on_product'] ),
					'icon_color'        => (string) sanitize_hex_color( wp_unslash( (string) ( $_POST['rc_icon_color'] ?? '' ) ) ),
					'text_color'        => (string) sanitize_hex_color( wp_unslash( (string) ( $_POST['rc_text_color'] ?? '' ) ) ),
					'payment_heading'   => sanitize_text_field( wp_unslash( (string) ( $_POST['rc_payment_heading'] ?? '' ) ) ),
					'payment_image_id'  => absint( wp_unslash( $_POST['rc_payment_image_id'] ?? 0 ) ),
					'payment_in_footer' => isset( $_POST['rc_payment_in_footer'] ),
				)
			);

			$area = isset( $_POST['rc_area'] ) && is_array( $_POST['rc_area'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['rc_area'] ) ) : array();
			Placement::save_choices( self::ID, $this->placements(), $area );
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
		}

		$settings = Settings::get( self::ID );
		$rows     = array_slice( array_pad( $this->badges(), self::MAX_BADGES, self::clean_badge( array() ) ), 0, self::MAX_BADGES );

		echo '<p>' . esc_html__( 'Trust and service badges under Add to Cart, plus a payment-methods widget for the site footer. Use [romcommerce_perks] and [romcommerce_payment_methods] to show them anywhere else.', 'romcommerce' ) . '</p>';
		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Module', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ' . esc_html__( 'Enable', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Product page', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_show_on_product" value="1"' . checked( (bool) ( $settings['show_on_product'] ?? true ), true, false ) . '> ' . esc_html__( 'Show the badges on product pages', 'romcommerce' ) . '</label>';
		$product = Placement::find( $this->placements(), 'product' );
		if ( array() !== $product ) {
			echo '<p><label for="rc-perks-area">' . esc_html__( 'Position', 'romcommerce' ) . '</label> ';
			Placement::area_field( 'rc_area[product]', $product, 'rc-perks-area' );
			echo '</p>';
		}
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="rc-perks-color">' . esc_html__( 'Icon colour', 'romcommerce' ) . '</label></th><td>';
		echo '<input type="text" id="rc-perks-color" name="rc_icon_color" value="' . esc_attr( $this->icon_color() ) . '" placeholder="#303f9f" class="small-text" style="width:8em;">';
		echo '<p class="description">' . esc_html__( 'A hex colour such as #303f9f. Leave blank to use the theme\'s text colour.', 'romcommerce' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="rc-perks-text-color">' . esc_html__( 'Text colour', 'romcommerce' ) . '</label></th><td>';
		echo '<input type="text" id="rc-perks-text-color" name="rc_text_color" value="' . esc_attr( $this->text_color() ) . '" placeholder="#1e1e1e" class="small-text" style="width:8em;">';
		echo '<p class="description">' . esc_html__( 'For the badge titles, texts and the payment-methods heading. Set it when the badges sit on a dark or coloured section; leave blank to use the theme\'s text colour.', 'romcommerce' ) . '</p></td></tr>';

		$image_id = $this->payment_image_id();
		echo '<tr><th scope="row">' . esc_html__( 'Payment methods', 'romcommerce' ) . '</th><td>';
		echo '<p><label for="rc-payment-heading">' . esc_html__( 'Heading', 'romcommerce' ) . '</label><br><input type="text" id="rc-payment-heading" class="regular-text" name="rc_payment_heading" value="' . esc_attr( (string) ( $settings['payment_heading'] ?? __( 'Metode de plată', 'romcommerce' ) ) ) . '"></p>';
		echo '<p><span id="rc-payment-preview" style="display:block;margin:6px 0;">';
		if ( $image_id > 0 ) {
			echo wp_get_attachment_image( $image_id, 'medium', false, array( 'style' => 'max-width:240px;height:auto;display:block;' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image() escapes its own output.
		}
		echo '</span><input type="hidden" id="rc-payment-image" name="rc_payment_image_id" value="' . esc_attr( (string) $image_id ) . '">';
		$picker = 'romcommerceOpenMediaPicker(' . wp_json_encode( 'rc-payment-image' ) . ',' . wp_json_encode( 'rc-payment-preview' ) . ')';
		echo '<button type="button" class="button" onclick="' . esc_attr( $picker ) . '">' . esc_html__( 'Choose image', 'romcommerce' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'An image of the payment methods you accept, such as your card processor\'s logo banner. Use only logos you are allowed to display.', 'romcommerce' ) . '</p>';
		echo '<p><label><input type="checkbox" name="rc_payment_in_footer" value="1"' . checked( (bool) ( $settings['payment_in_footer'] ?? true ), true, false ) . '> ' . esc_html__( 'Show it in the site footer', 'romcommerce' ) . '</label></p>';
		echo '</td></tr>';

		echo '</tbody></table>';

		echo '<div class="rc-cat-head"><span class="rc-tile"></span><h3>' . esc_html__( 'Badges', 'romcommerce' ) . '</h3><span class="rc-rule"></span></div>';
		echo '<p class="description">' . esc_html__( 'Up to six. A badge without a title is not shown. Each badge can use an icon from the built-in set, an image from your media library, a custom SVG, or a dashicon (WordPress\' built-in icon set).', 'romcommerce' ) . '</p>';
		echo '<div class="rc-item-grid">';
		foreach ( $rows as $index => $badge ) {
			$name = 'rc_badges[' . $index . ']';
			$key  = 'perk-' . $index;
			/* translators: %d: badge number */
			$label = sprintf( __( 'Badge %d', 'romcommerce' ), $index + 1 );

			echo '<div class="rc-item' . ( '' !== $badge['title'] ? ' is-enabled' : '' ) . '">';
			echo '<div class="rc-item-head"><span class="rc-tile"></span><h4>' . esc_html( $label ) . '</h4></div>';
			echo '<div class="rc-item-body">';
			echo '<p><label>' . esc_html__( 'Title', 'romcommerce' ) . '<br><input type="text" class="regular-text" maxlength="60" name="' . esc_attr( $name . '[title]' ) . '" value="' . esc_attr( $badge['title'] ) . '"></label></p>';
			echo '<p><label>' . esc_html__( 'Text', 'romcommerce' ) . '<br><input type="text" class="regular-text" maxlength="80" name="' . esc_attr( $name . '[text]' ) . '" value="' . esc_attr( $badge['text'] ) . '"></label></p>';

			$builtin = '<select name="' . esc_attr( $name . '[icon]' ) . '" aria-label="' . esc_attr( $label . ': ' . __( 'Built-in icon', 'romcommerce' ) ) . '"><option value="">' . esc_html__( 'No icon', 'romcommerce' ) . '</option>';
			foreach ( Icons::labels() as $icon_key => $icon_label ) {
				$builtin .= '<option value="' . esc_attr( $icon_key ) . '"' . selected( $badge['icon'], $icon_key, false ) . '>' . esc_html( $icon_label ) . '</option>';
			}
			$builtin .= '</select>';
			IconPicker::fields( $key, $name, $badge, __( 'Built-in icon', 'romcommerce' ), $builtin );

			echo '</div></div>';
		}
		echo '</div>';
		IconPicker::dashicon_grid();

		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';
	}
}
