<?php

declare(strict_types=1);

namespace RomCommerce\Modules\AdminBranding;

use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * White-labels wp-login and wp-admin: the site logo (the 'custom_logo' theme
 * mod — set under Appearance → Customize → Site Identity on classic themes,
 * or via the Site Logo block on block themes, which core syncs into the same
 * theme mod; Settings → General only holds the separate Site Icon) replaces
 * the WordPress logo on the login screen, and a single merchant-chosen brand
 * colour (darker/lighter shades derived automatically) replaces WP's default
 * blue across the login screen, the admin menu, the admin bar, and primary
 * buttons. One module, Lite and Pro alike — Pro's login background image +
 * gradient overlay is a section of this same settings pane, rendered here as
 * disabled fields with an upgrade link unless Pro hooks
 * 'romcommerce/admin_branding/background_fields' to replace them with live
 * ones (WP.org constraint 4 in CLAUDE.md: render-only teaser fields, zero Pro
 * logic in Lite).
 *
 * The login logo also has an explicit media-library override: themes that
 * never declared add_theme_support( 'custom-logo' ) don't expose a Logo
 * control at all, leaving nothing for the theme-mod path to read.
 */
final class Module implements ModuleInterface, HasSettingsUi {

	private const ID = 'admin-branding';

	private const DEFAULT_COLOR = '#303F9F';

	private const PRO_URL = 'https://romcommerce.ro/pro';

	private const FIELD_STYLES = array( 'rounded', 'square', 'underline' );

	private const DEFAULT_FIELD_STYLE = 'rounded';

	private const BUTTON_SHAPES = array( 'rounded', 'square' );

	private const DEFAULT_BUTTON_SHAPE = 'rounded';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Admin & Login Branding', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		add_filter( 'login_headerurl', array( $this, 'login_header_url' ) );
		add_filter( 'login_headertext', array( $this, 'login_header_text' ) );
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue_login_styles' ) );
		add_action( 'admin_head', array( $this, 'render_admin_head_styles' ) );

		if ( $this->frontend_select2_enabled() ) {
			add_action( 'wp_head', array( $this, 'render_frontend_select2_styles' ) );
		}
	}

	public function login_header_url(): string {
		return home_url( '/' );
	}

	public function login_header_text(): string {
		return get_bloginfo( 'name' );
	}

	/**
	 * wp-login.php enqueues the core 'login' stylesheet (and the admin-schemes
	 * handle carrying --wp-admin-theme-color) before firing
	 * login_enqueue_scripts, so a plain echoed <style> block would print
	 * earlier in <head> than those core stylesheets and lose every
	 * unqualified property to them. Inline-attaching to 'login' guarantees we
	 * print immediately after it, so no !important is needed.
	 */
	public function enqueue_login_styles(): void {
		$base     = $this->base_color();
		$darker10 = $this->darken( $base, 0.1 );
		$darker20 = $this->darken( $base, 0.2 );
		$lighter  = $this->lighten( $base, 0.35 );

		$css = $this->login_logo_css() . $this->login_layout_css( $base, $darker10, $darker20, $lighter, $this->field_style(), $this->button_shape() ) . $this->login_notice_2fa_css( $base, $darker10, $darker20 );

		/**
		 * Pro filters this to append its background-image + gradient overlay
		 * rule for body.login, cascading after (and so overriding) the plain
		 * background set above — see the class docblock. Lite has no idea
		 * whether anything is hooked in.
		 */
		$css .= (string) apply_filters( 'romcommerce/admin_branding/login_css', '', $this );

		wp_add_inline_style( 'login', $css );
	}

	/**
	 * Two leftover WordPress-blue accents ignore a plain :root override on the
	 * login page: the 2FA interim screen loads the signed-in user's admin
	 * colour scheme, whose body.admin-color-* selectors set
	 * --wp-admin-theme-color to blue at higher specificity than :root. Kept
	 * separate from render_admin_head_styles() below since this fires during
	 * login_enqueue_scripts and admin_head never runs on wp-login.php.
	 */
	public function render_admin_head_styles(): void {
		$base     = $this->base_color();
		$darker10 = $this->darken( $base, 0.1 );
		$darker20 = $this->darken( $base, 0.2 );
		$lighter  = $this->lighten( $base, 0.35 );

		echo '<style>' . $this->admin_brand_css( $base, $darker10, $darker20, $lighter ) . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin_brand_css() escapes every colour value it interpolates.
	}

	/**
	 * Off by default — the rest of this module's CSS only ever touches
	 * wp-admin/wp-login, but a brand colour on select2's highlighted option is
	 * just as relevant to a shopper-facing select2 field (e.g. checkout's
	 * country/state selects), which is a bigger surface to opt into than
	 * wp-admin, so it's a separate setting (frontend_select2_enabled(), gated
	 * in boot()) rather than always-on.
	 */
	public function render_frontend_select2_styles(): void {
		$base = $this->base_color();
		$text = esc_html( $this->contrast_text_color( $base ) );
		$base = esc_html( $base );

		echo '<style>' . $this->select2_highlight_css( $base, $text, '' ) . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- select2_highlight_css() only interpolates the two escaped hex colours above.
	}

	private function base_color(): string {
		$color = (string) ( Settings::get( self::ID )['color'] ?? '' );

		return $this->is_hex_color( $color ) ? $color : self::DEFAULT_COLOR;
	}

	private function is_hex_color( string $color ): bool {
		return (bool) preg_match( '/^#[A-Fa-f0-9]{6}$/', $color );
	}

	private function frontend_select2_enabled(): bool {
		return (bool) ( Settings::get( self::ID )['frontend_select2'] ?? false );
	}

	/**
	 * Default unchecked: the theme-mod path (Logo, not Site Icon/favicon —
	 * see the class docblock) already covers most setups, so this override
	 * only kicks in once a merchant opts in, for themes that never declared
	 * add_theme_support( 'custom-logo' ) and so have no Logo control at all.
	 */
	private function use_custom_logo(): bool {
		return (bool) ( Settings::get( self::ID )['use_custom_logo'] ?? false );
	}

	private function custom_logo_attachment_id(): int {
		return absint( Settings::get( self::ID )['logo_id'] ?? 0 );
	}

	private function field_style(): string {
		$style = (string) ( Settings::get( self::ID )['field_style'] ?? '' );

		return in_array( $style, self::FIELD_STYLES, true ) ? $style : self::DEFAULT_FIELD_STYLE;
	}

	// Independent of field_style() — a merchant can pair an underline field
	// style with a square button, for instance.
	private function button_shape(): string {
		$shape = (string) ( Settings::get( self::ID )['button_shape'] ?? '' );

		return in_array( $shape, self::BUTTON_SHAPES, true ) ? $shape : self::DEFAULT_BUTTON_SHAPE;
	}

	/**
	 * Fails soft (empty string) when the override is on but no image has
	 * been chosen yet, rather than falling back to the theme mod — checking
	 * the box is itself a decision to stop relying on it.
	 */
	private function login_logo_url(): string {
		if ( $this->use_custom_logo() ) {
			$logo_id = $this->custom_logo_attachment_id();

			return $logo_id > 0 ? (string) wp_get_attachment_image_url( $logo_id, 'full' ) : '';
		}

		$custom_logo_id = get_theme_mod( 'custom_logo' );

		return $custom_logo_id ? (string) wp_get_attachment_image_url( (int) $custom_logo_id, 'full' ) : '';
	}

	/** @return array{0: int, 1: int, 2: int} */
	private function rgb_components( string $hex ): array {
		return array(
			hexdec( substr( $hex, 1, 2 ) ),
			hexdec( substr( $hex, 3, 2 ) ),
			hexdec( substr( $hex, 5, 2 ) ),
		);
	}

	private function darken( string $hex, float $ratio ): string {
		list( $r, $g, $b ) = $this->rgb_components( $hex );

		return sprintf( '#%02x%02x%02x', (int) round( $r * ( 1 - $ratio ) ), (int) round( $g * ( 1 - $ratio ) ), (int) round( $b * ( 1 - $ratio ) ) );
	}

	private function lighten( string $hex, float $ratio ): string {
		list( $r, $g, $b ) = $this->rgb_components( $hex );

		return sprintf( '#%02x%02x%02x', (int) round( $r + ( 255 - $r ) * $ratio ), (int) round( $g + ( 255 - $g ) * $ratio ), (int) round( $b + ( 255 - $b ) * $ratio ) );
	}

	/** For the --*--rgb custom properties WordPress core pairs with each --wp-admin-theme-color. */
	private function hex_to_rgb_triplet( string $hex ): string {
		return implode( ', ', $this->rgb_components( $hex ) );
	}

	/**
	 * White menu text (WP core's own default) is unreadable once a merchant
	 * picks a light brand colour — YIQ perceived-brightness formula (ITU-R
	 * BT.601), same threshold (128 of 255) as W3C's own worked example for
	 * this exact problem.
	 */
	private function contrast_text_color( string $hex ): string {
		list( $r, $g, $b ) = $this->rgb_components( $hex );
		$yiq               = ( $r * 299 + $g * 587 + $b * 114 ) / 1000;

		return $yiq >= 128 ? '#000000' : '#ffffff';
	}

	private function login_logo_css(): string {
		$logo_url = $this->login_logo_url();

		if ( ! $logo_url ) {
			return '';
		}

		return '
			.login h1 a,
			h1.wp-login-logo a {
				background-image: url( ' . esc_url( $logo_url ) . ' );
				background-size: contain;
				background-position: center;
				background-repeat: no-repeat;
				width: 100%;
				max-width: 320px;
				height: 90px;
				margin: 0 auto 1.5rem;
			}
		';
	}

	/**
	 * The "Remember Me" checkbox sits flush against #loginform's own left
	 * edge, same as the text fields login_field_style_css() fixes, so the
	 * generic body.login :focus outline above gets clipped there the same
	 * way (see that method's docblock for the full explanation) — same
	 * inset-shadow fix, just not style-dependent so it lives here instead.
	 */
	private function login_layout_css( string $base, string $darker10, string $darker20, string $lighter, string $field_style, string $button_shape ): string {
		$base     = esc_html( $base );
		$darker10 = esc_html( $darker10 );
		$darker20 = esc_html( $darker20 );
		$lighter  = esc_html( $lighter );

		return '
			.language-switcher { display: none; }

			:root {
				--wp-admin-theme-color: ' . $base . ';
				--wp-admin-theme-color-darker-10: ' . $darker10 . ';
				--wp-admin-theme-color-darker-20: ' . $darker20 . ';
				--wp-admin-border-color-focus: ' . $lighter . ';
			}

			body.login *,
			body.login *:before,
			body.login *:after {
				box-sizing: border-box;
			}

			body.login {
				display: flex;
				flex-direction: column;
				align-items: center;
				justify-content: center;
				min-height: 100vh;
				gap: 1rem;
				background: linear-gradient( 135deg, ' . $base . ' 0%, ' . $darker20 . ' 100% );
			}

			body.login :focus {
				outline: .125rem solid ' . $darker20 . ';
				outline-offset: .125rem;
			}

			body.login input[type="checkbox"]:focus {
				outline: none;
				box-shadow: inset 0 0 0 .0625rem ' . $darker20 . ';
			}

			#login {
				width: 100%;
				max-width: 26rem;
				margin: 0;
				padding: 3rem 2.5rem;
				background: #fff;
				border-radius: .75rem;
				box-shadow: 0 1.25rem 2.5rem rgba( 0, 0, 0, .18 );
			}

			.login form {
				display: flex;
				flex-direction: column;
				justify-content: space-around;
				flex-wrap: nowrap;
				row-gap: 24px;
			}

			#loginform {
				margin-top: 1.5rem;
				background: transparent;
				border: 0;
				box-shadow: none;
				padding: 0;
			}

			#loginform label {
				font-weight: 600;
				color: ' . $darker20 . ';
			}

			.login form .input,
			.login input[type=password],
			.login input[type=text] {
				margin: 0;
			}

			#user_pass {
				border: 0;
				box-shadow: none;
				background: transparent;
			}

			.login .wp-hide-pw .dashicons {
				color: ' . $darker20 . ';
			}
			' . $this->login_field_style_css( $field_style, $button_shape, $base, $darker10, $darker20 ) . '

			#login a {
				color: ' . $darker20 . ';
			}

			#nav,
			#backtoblog {
				text-align: center;
				margin: 1rem 0 0;
			}

			#nav a,
			#backtoblog a {
				text-decoration: underline;
				text-decoration-thickness: .0625rem;
			}

			#nav a:hover,
			#backtoblog a:hover {
				text-decoration-thickness: .125rem;
			}
		';
	}

	/**
	 * 'rounded' field style + 'rounded' button shape reproduce this module's
	 * original fixed look (the only look that existed before either selector
	 * was added), kept as the default so upgrading installs render unchanged.
	 * $button_shape is independent of $style — a merchant can pair underline
	 * fields with a square button.
	 *
	 * Focus handling is shared across all three field styles, not just
	 * underline, and fixes a real rendering bug (reported against a live
	 * site, luxenza.ro, 2026-10-02): #loginform gives these fields no
	 * horizontal slack — each fills its row's full width — so the generic
	 * body.login :focus rule's *outline* (painted outside the border box)
	 * had nowhere to paint into on the left/right and got clipped there,
	 * while still showing top/bottom where the fields' own row-gap leaves
	 * room. An inset box-shadow paints inside the border box instead, so it
	 * can't be clipped regardless of how tight the fit is. The password
	 * field needs its own :focus-within on .wp-pwd on top of that: its
	 * visible border lives on that wrapper, but core leaves the #user_pass
	 * input it wraps border-free (so the eye-icon toggle can share one box
	 * with it), and the wrapper itself is never the thing the browser
	 * actually focuses.
	 */
	private function login_field_style_css( string $style, string $button_shape, string $base, string $darker10, string $darker20 ): string {
		if ( 'square' === $style ) {
			$field_box = 'border: .0625rem solid #ccc; border-radius: 0;';
		} elseif ( 'underline' === $style ) {
			$field_box = 'border: 0; border-bottom: .125rem solid #ccc; border-radius: 0; background: transparent; padding-left: 0; padding-right: 0;';
		} else {
			$field_box = 'border: .0625rem solid #ccc; border-radius: .375rem;';
		}

		$button_radius = 'square' === $button_shape ? '0' : '.375rem';

		// No outline (box-shadow instead) — see the method docblock for why.
		$field_focus = 'underline' === $style
			? '
				body.login #user_login:focus,
				body.login .wp-pwd:focus-within {
					outline: none;
					border-bottom-color: ' . $darker20 . ';
				}
			'
			: '
				body.login #user_login:focus,
				body.login .wp-pwd:focus-within {
					outline: none;
					border-color: ' . $darker20 . ';
					box-shadow: inset 0 0 0 .0625rem ' . $darker20 . ';
				}
			';

		return '
			#user_login,
			.login .wp-pwd {
				' . $field_box . '
				box-shadow: none;
			}

			#user_pass:focus {
				outline: none;
			}
			' . $field_focus . '

			#wp-submit {
				width: 100%;
				min-height: 3rem;
				border-radius: ' . $button_radius . ';
				font-weight: 600;
				background: ' . $base . ';
				border-color: ' . $darker10 . ';
				color: #fff;
			}

			#wp-submit:hover,
			#wp-submit:focus {
				background: ' . $darker10 . ';
				border-color: ' . $darker20 . ';
			}
		';
	}

	/**
	 * .message/.notice/.success (e.g. the "You are now logged out." banner)
	 * carry a hard-coded blue left border in core; mirroring core's own
	 * selectors here means we win at equal specificity, printing after the
	 * 'login' stylesheet. The html body.login block re-declares the theme-
	 * color vars (with matching --*--rgb) at higher specificity than the
	 * admin colour scheme the 2FA interim screen loads for the signed-in
	 * user, and the .two-factor-email-resend rules put the 2FA Verify/Resend
	 * buttons on one row instead of core's default stacked layout — the 2FA
	 * form reuses #loginform, so it's scoped by its unique form name instead.
	 */
	private function login_notice_2fa_css( string $base, string $darker10, string $darker20 ): string {
		$rgb        = $this->hex_to_rgb_triplet( $base );
		$rgb_dark10 = $this->hex_to_rgb_triplet( $darker10 );
		$rgb_dark20 = $this->hex_to_rgb_triplet( $darker20 );

		$base     = esc_html( $base );
		$darker10 = esc_html( $darker10 );
		$darker20 = esc_html( $darker20 );

		return '
			.login .message,
			.login .notice,
			.login .success {
				border-left-color: ' . $base . ';
			}

			html body.login {
				--wp-admin-theme-color: ' . $base . ';
				--wp-admin-theme-color--rgb: ' . esc_html( $rgb ) . ';
				--wp-admin-theme-color-darker-10: ' . $darker10 . ';
				--wp-admin-theme-color-darker-10--rgb: ' . esc_html( $rgb_dark10 ) . ';
				--wp-admin-theme-color-darker-20: ' . $darker20 . ';
				--wp-admin-theme-color-darker-20--rgb: ' . esc_html( $rgb_dark20 ) . ';
			}

			.login form[name="validate_2fa_form"] {
				flex-flow: row wrap;
				align-items: center;
				column-gap: .75rem;
			}

			.login form[name="validate_2fa_form"] > p {
				flex: 1 1 100%;
				margin: 0;
			}

			.login form[name="validate_2fa_form"] .submit,
			.login form[name="validate_2fa_form"] .two-factor-email-resend {
				flex: 1 1 0;
			}

			.login form[name="validate_2fa_form"] .submit .button,
			.login form[name="validate_2fa_form"] .two-factor-email-resend .button {
				width: 100%;
				margin: 0;
			}
		';
	}

	/**
	 * admin_head fires after the current user's own admin colour-scheme
	 * stylesheet is printed, so plain rules here (no !important) reliably
	 * win regardless of which scheme the user has picked.
	 */
	private function admin_brand_css( string $base, string $darker10, string $darker20, string $lighter ): string {
		$menu_text = esc_html( $this->contrast_text_color( $base ) );
		$base      = esc_html( $base );
		$darker10  = esc_html( $darker10 );
		$darker20  = esc_html( $darker20 );
		$lighter   = esc_html( $lighter );

		return '
			:root {
				--wp-admin-theme-color: ' . $base . ';
				--wp-admin-theme-color-darker-10: ' . $darker10 . ';
				--wp-admin-theme-color-darker-20: ' . $darker20 . ';
				--wp-admin-border-color-focus: ' . $lighter . ';
			}

			#adminmenu li.current a.menu-top,
			#adminmenu li.wp-has-current-submenu a.wp-has-current-submenu,
			#adminmenu li.wp-has-current-submenu.opensub a.wp-has-current-submenu,
			#adminmenu li a.menu-top:focus {
				background: ' . $base . ';
				color: ' . $menu_text . ';
			}

			#adminmenu li.menu-top:hover,
			#adminmenu li.menu-top:hover > a.menu-top,
			#adminmenu a.menu-top:hover,
			#adminmenu li.opensub > a.menu-top,
			#adminmenu a.menu-top:focus {
				background-color: ' . $base . ';
				color: ' . $menu_text . ';
			}

			#adminmenu .wp-submenu a:hover,
			#adminmenu .wp-submenu a:focus,
			#adminmenu .wp-submenu li.current a:hover,
			#adminmenu .wp-submenu li.current a:focus {
				color: ' . $lighter . ';
			}

			#adminmenu .awaiting-mod,
			#adminmenu .menu-counter,
			#adminmenu .update-plugins {
				background-color: ' . $base . ';
				color: ' . $menu_text . ';
			}

			#adminmenu div.wp-menu-image:before {
				color: inherit;
			}

			#wpadminbar .ab-top-menu > li:hover > .ab-item,
			#wpadminbar .ab-top-menu > li > .ab-item:focus,
			#wpadminbar .ab-top-menu > li.hover > .ab-item,
			#wpadminbar .ab-top-menu > li.menupop.hover > .ab-item,
			#wpadminbar.nojq .quicklinks .ab-top-menu > li > .ab-item:focus,
			#wpadminbar.nojs .ab-top-menu > li.menupop:hover > .ab-item,
			#wpadminbar:not(.mobile) .ab-top-menu > li:hover > .ab-item,
			#wpadminbar:not(.mobile) .ab-top-menu > li > .ab-item:focus,
			#wpadminbar .quicklinks .ab-sub-wrapper .menupop.hover > a,
			#wpadminbar .quicklinks .menupop ul li a:focus,
			#wpadminbar .quicklinks .menupop ul li a:focus strong,
			#wpadminbar .quicklinks .menupop ul li a:hover,
			#wpadminbar .quicklinks .menupop ul li a:hover strong,
			#wpadminbar .quicklinks .menupop.hover ul li a:focus,
			#wpadminbar .quicklinks .menupop.hover ul li a:hover,
			#wpadminbar li #adminbarsearch.adminbar-focused:before,
			#wpadminbar li .ab-item:focus .ab-icon:before,
			#wpadminbar li .ab-item:focus:before,
			#wpadminbar li a:focus .ab-icon:before,
			#wpadminbar li.hover .ab-icon:before,
			#wpadminbar li.hover .ab-item:before,
			#wpadminbar li:hover #adminbarsearch:before,
			#wpadminbar li:hover .ab-icon:before,
			#wpadminbar li:hover .ab-item:before,
			#wpadminbar.nojs .quicklinks .menupop:hover ul li a:focus,
			#wpadminbar.nojs .quicklinks .menupop:hover ul li a:hover {
				color: ' . $lighter . ';
			}

			.wp-core-ui .button-primary {
				background: ' . $base . ';
				border-color: ' . $darker10 . ';
			}

			.wp-core-ui .button-primary:hover,
			.wp-core-ui .button-primary:focus {
				background: ' . $darker10 . ';
				border-color: ' . $darker20 . ';
			}
		' . $this->select2_highlight_css( $base, $menu_text, '.wp-admin.wc-wp-version-gte-53' );
	}

	/**
	 * Brands select2's highlighted option and its open dropdown panel (border
	 * + shadow) — everything select2 draws besides the closed widget itself,
	 * which admin_brand_css()'s own .select2-selection rule already covers.
	 * Colours are hardcoded to $base rather than reusing WooCommerce's own
	 * var(--wp-admin-theme-color) rules for these same selectors: that
	 * variable is set by WP core's admin colour-scheme CSS at higher
	 * specificity (body.admin-color-* beats a bare :root), so a merchant on
	 * a non-default scheme would see WP's colour win over ours for anything
	 * consuming the variable. Hardcoding sidesteps that entirely and keeps
	 * this scoped to select2 only — it never touches --wp-admin-theme-color
	 * itself, so nothing else that variable feeds is affected.
	 *
	 * wc-wp-version-gte-53 is a body class WooCommerce adds only in wp-admin
	 * (its own WP 5.3+ compatibility gate for this exact selector), so
	 * $selector_prefix scopes admin_brand_css()'s call to admin. The frontend
	 * body carries no such class, so render_frontend_select2_styles() calls
	 * this with an empty prefix instead of trying to reuse the admin one.
	 */
	private function select2_highlight_css( string $base, string $text, string $selector_prefix ): string {
		$prefix = '' !== $selector_prefix ? $selector_prefix . ' ' : '';

		return '
			' . $prefix . '.select2-container--default .select2-results__option--highlighted[aria-selected],
			' . $prefix . '.select2-container--default .select2-results__option--highlighted[data-selected] {
				background-color: ' . $base . ';
				color: ' . $text . ';
			}

			' . $prefix . '.select2-dropdown {
				border-color: ' . $base . ';
			}

			' . $prefix . '.select2-dropdown--below {
				box-shadow: 0 0 0 1px ' . $base . ', 0 2px 1px rgba(0, 0, 0, .1);
			}
		';
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce and capability verified above via SettingsForm::verify().
			$color = isset( $_POST['rc_color'] ) ? sanitize_text_field( wp_unslash( $_POST['rc_color'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce and capability verified above via SettingsForm::verify().
			$logo_id = isset( $_POST['rc_logo_id'] ) ? absint( wp_unslash( $_POST['rc_logo_id'] ) ) : 0;
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce and capability verified above via SettingsForm::verify().
			$field_style = isset( $_POST['rc_field_style'] ) ? sanitize_key( wp_unslash( $_POST['rc_field_style'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce and capability verified above via SettingsForm::verify().
			$button_shape = isset( $_POST['rc_button_shape'] ) ? sanitize_key( wp_unslash( $_POST['rc_button_shape'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce and capability verified above via SettingsForm::verify().
			$this->handle_save( isset( $_POST['rc_enabled'] ), $color, isset( $_POST['rc_frontend_select2'] ), isset( $_POST['rc_use_custom_logo'] ), $logo_id, $field_style, $button_shape );

			// Lets Pro persist its own background-image/overlay fields from this
			// same verified submission — see the class docblock.
			do_action( 'romcommerce/admin_branding/save', $this );
		}

		echo '<p>' . esc_html__( 'Replaces the WordPress logo on the login screen with your site logo (Appearance → Customize → Site Identity, or the Site Logo block in the Site Editor) and applies one brand colour across wp-admin and the login screen.', 'romcommerce' ) . '</p>';

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Module', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ';
		echo esc_html__( 'Enable', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Custom login logo', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_use_custom_logo" value="1"' . checked( $this->use_custom_logo(), true, false ) . '> ';
		echo esc_html__( 'Use an uploaded image instead of the site logo', 'romcommerce' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'For themes that don\'t support a site logo, so there is no Logo field to set in Appearance → Customize → Site Identity.', 'romcommerce' ) . '</p>';
		$logo_id = $this->custom_logo_attachment_id();
		echo '<p><span id="romcommerce_login_logo_preview" style="display:block;margin:6px 0;">';
		if ( $logo_id > 0 ) {
			echo wp_get_attachment_image( $logo_id, 'medium', false, array( 'style' => 'max-width:240px;height:auto;display:block;' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image() escapes its own output.
		}
		echo '</span>';
		echo '<input type="hidden" id="romcommerce_login_logo_id" name="rc_logo_id" value="' . esc_attr( (string) $logo_id ) . '">';
		$picker_onclick = 'romcommerceOpenMediaPicker(' . wp_json_encode( 'romcommerce_login_logo_id' ) . ',' . wp_json_encode( 'romcommerce_login_logo_preview' ) . ')';
		echo '<button type="button" class="button" onclick="' . esc_attr( $picker_onclick ) . '">' . esc_html__( 'Choose image', 'romcommerce' ) . '</button></p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Brand colour', 'romcommerce' ) . '</th><td>';
		echo '<input type="color" name="rc_color" value="' . esc_attr( $this->base_color() ) . '">';
		echo '<p class="description">' . esc_html__( 'Used for the login button, the wp-admin menu highlight, and admin bar accents. Darker and lighter shades are derived automatically.', 'romcommerce' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Login style', 'romcommerce' ) . '</th><td>';
		echo '<select name="rc_field_style">';
		$field_style_labels = array(
			'rounded'   => __( 'Rounded corners', 'romcommerce' ),
			'square'    => __( 'Sharp corners', 'romcommerce' ),
			'underline' => __( 'Underline only', 'romcommerce' ),
		);
		foreach ( $field_style_labels as $style_value => $style_label ) {
			echo '<option value="' . esc_attr( $style_value ) . '"' . selected( $this->field_style(), $style_value, false ) . '>' . esc_html( $style_label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Shape of the username/password fields on the login screen.', 'romcommerce' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Button shape', 'romcommerce' ) . '</th><td>';
		echo '<select name="rc_button_shape">';
		$button_shape_labels = array(
			'rounded' => __( 'Rounded', 'romcommerce' ),
			'square'  => __( 'Square', 'romcommerce' ),
		);
		foreach ( $button_shape_labels as $shape_value => $shape_label ) {
			echo '<option value="' . esc_attr( $shape_value ) . '"' . selected( $this->button_shape(), $shape_value, false ) . '>' . esc_html( $shape_label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Shape of the login button, chosen independently of the login style above.', 'romcommerce' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Frontend dropdowns', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_frontend_select2" value="1"' . checked( $this->frontend_select2_enabled(), true, false ) . '> ';
		echo esc_html__( 'Also brand WooCommerce\'s select2 dropdowns on the frontend (e.g. checkout country/state fields)', 'romcommerce' ) . '</label></td></tr>';

		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Login background', 'romcommerce' ) . '</h3>';
		$this->render_background_fields();

		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';
	}

	/**
	 * Pro hooks 'romcommerce/admin_branding/background_fields' to echo its
	 * own live fields here instead — see the class docblock. Lite's fallback
	 * below is deliberately handler-free: disabled inputs with no name
	 * attributes, so nothing they contain can ever be submitted or persisted.
	 */
	private function render_background_fields(): void {
		ob_start();
		do_action( 'romcommerce/admin_branding/background_fields', $this );
		$live_fields = trim( (string) ob_get_clean() );

		if ( '' !== $live_fields ) {
			echo $live_fields; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pro's own hook callback is responsible for escaping what it echoes here.
			return;
		}

		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Background image', 'romcommerce' ) . '</th><td>';
		echo '<button type="button" class="button" disabled>' . esc_html__( 'Choose image', 'romcommerce' ) . '</button></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Overlay colour', 'romcommerce' ) . '</th><td>';
		echo '<input type="color" value="#101010" disabled></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Overlay opacity', 'romcommerce' ) . '</th><td>';
		echo '<input type="number" value="55" disabled> %</td></tr>';

		echo '</tbody></table>';
		echo '<p><span class="romcommerce-badge">Pro</span> ' . esc_html__( 'A custom background image with a gradient overlay for the login screen.', 'romcommerce' ) . ' <a href="' . esc_url( self::PRO_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Unlock with RomCommerce Pro', 'romcommerce' ) . '</a></p>';
	}

	private function handle_save( bool $enabled, string $color, bool $frontend_select2, bool $use_custom_logo, int $logo_id, string $field_style, string $button_shape ): void {
		Settings::set_enabled( self::ID, $enabled );

		// Settings::update() replaces the whole option, so start from what's
		// already stored rather than dropping the other key when only one of
		// the two changed (or a posted enum value was invalid).
		$settings = Settings::get( self::ID );

		if ( $this->is_hex_color( $color ) ) {
			$settings['color'] = $color;
		}

		if ( in_array( $field_style, self::FIELD_STYLES, true ) ) {
			$settings['field_style'] = $field_style;
		}

		if ( in_array( $button_shape, self::BUTTON_SHAPES, true ) ) {
			$settings['button_shape'] = $button_shape;
		}

		$settings['frontend_select2'] = $frontend_select2;
		$settings['use_custom_logo']  = $use_custom_logo;
		$settings['logo_id']          = $logo_id;

		Settings::update( self::ID, $settings );

		echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
	}
}
