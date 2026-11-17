<?php

declare(strict_types=1);

namespace RomCommerce\Modules\FooterCredit;

use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasPlacements;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;
use RomCommerce\Support\Placement;

defined( 'ABSPATH' ) || exit;

/**
 * "Show some love": an opt-in line at the bottom of the site footer and/or the
 * login page saying the shop runs on RomCommerce, linking to romcommerce.ro.
 *
 * WordPress.org guideline 10: a plugin may only add a credit or link to the
 * public site with the user's explicit permission. So this is off until the
 * merchant switches it on here, nothing else ever switches it on (activation,
 * updates, onboarding, other modules), it unlocks nothing, and Lite never
 * asks the merchant to turn it on. The module switch is that permission:
 * the footer and login page switches below it come pre-ticked but show
 * nothing until it is on. The link is rel="nofollow" as a site-wide link.
 */
final class Module implements ModuleInterface, HasSettingsUi, HasPlacements {

	private const ID = 'footer-credit';

	private const FOOTER_URL = 'https://romcommerce.ro/?utm_source=plugin&utm_medium=footer';

	private const LOGIN_URL = 'https://romcommerce.ro/?utm_source=plugin&utm_medium=login';

	private const ALIGNMENTS = array( 'center', 'left', 'right' );

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Show Some Love', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		// Placement hooks wire on init, not here — see HasPlacements.
		add_action( 'init', array( $this, 'register_placements' ) );
		add_action( 'login_footer', array( $this, 'render_login' ) );
	}

	public function register_placements(): void {
		foreach ( $this->placements() as $placement ) {
			if ( $placement['active'] ) {
				Placement::hook( $placement['slot'], array( $this, 'render' ) );
			}
		}
	}

	/** @return array<int, array{key: string, slot: string, active: bool}> */
	public function placements(): array {
		return array(
			array(
				'key'    => 'footer',
				'slot'   => 'footer',
				'active' => $this->footer_on(),
			),
		);
	}

	public function enqueue_assets(): void {
		if ( ! $this->footer_on() ) {
			return;
		}

		wp_register_script( 'romcommerce-footer-credit', false, array(), ROMCOMMERCE_VERSION, true );
		wp_enqueue_script( 'romcommerce-footer-credit' );
		wp_add_inline_script( 'romcommerce-footer-credit', Placement::footer_relocation_script( 'romcommerce-footer-credit' ) );
	}

	public function render(): void {
		echo '<p id="romcommerce-footer-credit" data-rc-footer-order="' . esc_attr( (string) Placement::footer_rank( self::ID ) ) . '" style="margin:0;padding:8px 16px;font-size:12px;text-align:' . esc_attr( $this->alignment() ) . ';">';
		$this->print_credit( self::FOOTER_URL, '' );
		echo '</p>';
	}

	public function render_login(): void {
		// Core leaves every link off the interim login (the session-expired
		// dialog inside wp-admin) so it can't navigate away; same here.
		if ( ! $this->login_on() || ! empty( $GLOBALS['interim_login'] ) ) {
			return;
		}

		// Own white box and text colour: it sits outside the login card, on
		// whatever background the login page has (grey, the brand gradient, or
		// Pro's background image). display:table centres it in core's block
		// layout and Admin & Login Branding's flex column alike.
		echo '<p id="romcommerce-login-credit" style="display:table;margin:16px auto;padding:6px 12px;background:#fff;color:#50575e;font-size:12px;line-height:1.5;text-align:center;">';
		$this->print_credit( self::LOGIN_URL, 'color:inherit;text-decoration:underline;' );
		echo '</p>';
	}

	private function print_credit( string $url, string $link_style ): void {
		$style = '' !== $link_style ? ' style="' . esc_attr( $link_style ) . '"' : '';
		$link  = '<a href="' . esc_url( $url ) . '" rel="nofollow"' . $style . '>RomCommerce</a>';

		/* translators: %s: link to romcommerce.ro */
		$credit = sprintf( __( 'Comerț online mai simplu cu %s', 'romcommerce' ), $link );
		echo wp_kses(
			$credit,
			array(
				'a' => array(
					'href'  => array(),
					'rel'   => array(),
					'style' => array(),
				),
			)
		);
	}

	// Stores that switched the credit on before the login page option existed
	// have no footer flag; their switch meant the footer.
	private function footer_on(): bool {
		return (bool) ( Settings::get( self::ID )['footer'] ?? true );
	}

	private function login_on(): bool {
		return (bool) ( Settings::get( self::ID )['login'] ?? false );
	}

	// Pre-ticked for a merchant switching the credit on for the first time. A
	// store already running it from before the login option existed reads
	// unticked, so an update never adds the login credit on its own.
	private function login_checked(): bool {
		$settings = Settings::get( self::ID );
		if ( array_key_exists( 'login', $settings ) ) {
			return (bool) $settings['login'];
		}

		return ! $this->is_enabled();
	}

	private function alignment(): string {
		$alignment = (string) ( Settings::get( self::ID )['alignment'] ?? 'center' );

		return in_array( $alignment, self::ALIGNMENTS, true ) ? $alignment : 'center';
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above via SettingsForm::verify().
			$alignment = sanitize_key( wp_unslash( (string) ( $_POST['rc_alignment'] ?? 'center' ) ) );
			$enabled   = isset( $_POST['rc_enabled'] );
			$footer    = isset( $_POST['rc_footer'] );
			$login     = isset( $_POST['rc_login'] );
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			Settings::set_enabled( self::ID, $enabled );
			Settings::update(
				self::ID,
				array(
					'footer'    => $footer,
					'login'     => $login,
					'alignment' => in_array( $alignment, self::ALIGNMENTS, true ) ? $alignment : 'center',
				)
			);
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'If you like RomCommerce, you can add a small "Comerț online mai simplu cu RomCommerce" line to the bottom of your site footer, your login page, or both, linking to romcommerce.ro. It is entirely optional, off unless you switch it on, and nothing in RomCommerce depends on it.', 'romcommerce' ) . '</p>';
		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Module', 'romcommerce' ) . '</th><td><label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ' . esc_html__( 'Enable', 'romcommerce' ) . '</label></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Footer credit', 'romcommerce' ) . '</th><td><label><input type="checkbox" name="rc_footer" value="1"' . checked( $this->footer_on(), true, false ) . '> ' . esc_html__( 'Show the RomCommerce credit in my site footer', 'romcommerce' ) . '</label></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Alignment', 'romcommerce' ) . '</th><td>';
		Placement::position_field(
			'rc_alignment',
			array(
				'left'   => __( 'Left', 'romcommerce' ),
				'center' => __( 'Center', 'romcommerce' ),
				'right'  => __( 'Right', 'romcommerce' ),
			),
			$this->alignment()
		);
		echo '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Login page credit', 'romcommerce' ) . '</th><td><label><input type="checkbox" name="rc_login" value="1"' . checked( $this->login_checked(), true, false ) . '> ' . esc_html__( 'Show the RomCommerce credit at the bottom of the login page', 'romcommerce' ) . '</label></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';
	}
}
