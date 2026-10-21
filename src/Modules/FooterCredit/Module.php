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
 * "Show some love": an opt-in line at the bottom of the site footer saying the
 * shop runs on RomCommerce, linking to romcommerce.ro.
 *
 * WordPress.org guideline 10: a plugin may only add a credit or link to the
 * public site with the user's explicit permission. So this is off until the
 * merchant switches it on here, nothing else ever switches it on (activation,
 * updates, onboarding, other modules), it unlocks nothing, and Lite never
 * asks the merchant to turn it on. The link is rel="nofollow" as a site-wide
 * footer link.
 */
final class Module implements ModuleInterface, HasSettingsUi, HasPlacements {

	private const ID = 'footer-credit';

	private const URL = 'https://romcommerce.ro/';

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
				'active' => true,
			),
		);
	}

	public function enqueue_assets(): void {
		wp_register_script( 'romcommerce-footer-credit', false, array(), ROMCOMMERCE_VERSION, true );
		wp_enqueue_script( 'romcommerce-footer-credit' );
		wp_add_inline_script( 'romcommerce-footer-credit', Placement::footer_relocation_script( 'romcommerce-footer-credit' ) );
	}

	public function render(): void {
		$link = '<a href="' . esc_url( self::URL ) . '" rel="nofollow">RomCommerce</a>';

		echo '<p id="romcommerce-footer-credit" data-rc-footer-order="' . esc_attr( (string) Placement::footer_rank( self::ID ) ) . '" style="margin:0;padding:8px 16px;font-size:12px;text-align:' . esc_attr( $this->alignment() ) . ';">';
		/* translators: %s: link to romcommerce.ro */
		$credit = sprintf( __( 'Magazin realizat cu %s', 'romcommerce' ), $link );
		echo wp_kses(
			$credit,
			array(
				'a' => array(
					'href' => array(),
					'rel'  => array(),
				),
			)
		);
		echo '</p>';
	}

	private function alignment(): string {
		$alignment = (string) ( Settings::get( self::ID )['alignment'] ?? 'center' );

		return in_array( $alignment, self::ALIGNMENTS, true ) ? $alignment : 'center';
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above via SettingsForm::verify().
			$alignment = sanitize_key( wp_unslash( (string) ( $_POST['rc_alignment'] ?? 'center' ) ) );
			Settings::set_enabled( self::ID, isset( $_POST['rc_enabled'] ) );
			Settings::update( self::ID, array( 'alignment' => in_array( $alignment, self::ALIGNMENTS, true ) ? $alignment : 'center' ) );
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'If you like RomCommerce, you can add a small "Magazin realizat cu RomCommerce" line to the bottom of your site footer, linking to romcommerce.ro. It is entirely optional, off unless you switch it on, and nothing in RomCommerce depends on it.', 'romcommerce' ) . '</p>';
		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Footer credit', 'romcommerce' ) . '</th><td><label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ' . esc_html__( 'Show the RomCommerce credit in my site footer', 'romcommerce' ) . '</label></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Alignment', 'romcommerce' ) . '</th><td>';
		Placement::position_field(
			'rc_alignment',
			array(
				'center' => __( 'Center', 'romcommerce' ),
				'left'   => __( 'Left', 'romcommerce' ),
				'right'  => __( 'Right', 'romcommerce' ),
			),
			$this->alignment()
		);
		echo '</td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';
	}
}
