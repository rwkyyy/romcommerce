<?php

declare(strict_types=1);

namespace RomCommerce\Modules\SalPictogram;

use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasPlacements;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;
use RomCommerce\Support\Placement;

defined( 'ABSPATH' ) || exit;

/**
 * ANPC SAL pictogram (Ordinul ANPC nr. 449/2022, amended by nr. 270/2026) —
 * the required homepage badge linking to the SAL dispute-resolution
 * platform. docs/legal-compliance-reference.md §0 is the authoritative spec.
 *
 * Sizing: the official artwork is 500x124px (4.03:1), not the 250x50px
 * (5:1) ratio the order describes — only the width attribute is set here so
 * the browser scales height proportionally rather than distorting the
 * artwork. See docs/research-backlog.md D9.
 */
final class Module implements ModuleInterface, HasSettingsUi, HasPlacements {

	private const ID = 'sal-pictogram';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'SAL Pictogram', 'romcommerce' );
	}

	// Default on: a legal-floor compliance feature, not an opt-in extra.
	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID, true );
	}

	private const ALIGNMENTS = array( 'left', 'center', 'right', 'none' );

	public function boot(): void {
		add_shortcode( 'romcommerce_sal_pictogram', array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		// Placement hooks wire on init, not here — see HasPlacements.
		add_action( 'init', array( $this, 'register_placements' ) );
	}

	public function register_placements(): void {
		foreach ( $this->placements() as $placement ) {
			if ( $placement['active'] ) {
				Placement::hook( $placement['slot'], array( $this, 'render_pictogram' ) );
			}
		}
	}

	/**
	 * Footer `active` folds in the alignment 'none' escape hatch, same as
	 * Legal Guarantee Notice: a merchant choosing 'none' is placing the
	 * pictogram themselves via the shortcode, so the Placements map should
	 * show the footer slot as off.
	 *
	 * @return array<int, array{slot: string, active: bool}>
	 */
	public function placements(): array {
		return array(
			array(
				'slot'   => 'footer',
				'active' => 'none' !== $this->alignment(),
			),
		);
	}

	public function enqueue_assets(): void {
		if ( ! is_front_page() || 'none' === $this->alignment() ) {
			return;
		}

		wp_register_script( 'romcommerce-sal-pictogram', false, array(), ROMCOMMERCE_VERSION, true );
		wp_enqueue_script( 'romcommerce-sal-pictogram' );
		wp_add_inline_script( 'romcommerce-sal-pictogram', Placement::footer_relocation_script( 'romcommerce-sal-pictogram' ) );
	}

	public function render_pictogram(): void {
		if ( ! is_front_page() || 'none' === $this->alignment() ) {
			return;
		}

		echo '<div id="romcommerce-sal-pictogram" data-rc-footer-order="' . esc_attr( (string) Placement::footer_rank( self::ID ) ) . '" style="text-align:' . esc_attr( $this->alignment() ) . ';">';
		echo $this->pictogram_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped field-by-field in pictogram_markup().
		echo '</div>';
	}

	/**
	 * [romcommerce_sal_pictogram] — the official artwork + its mandatory link,
	 * for a merchant who wants it somewhere other than the automatic footer
	 * placement (a legal/contact page, a widget, a template part) — typically
	 * paired with alignment set to "None" below, but works regardless of that
	 * setting so it can also be used to duplicate the pictogram elsewhere.
	 * Unlike render_pictogram(), not gated to is_front_page(): the homepage
	 * requirement only constrains the automatic placement, not where a
	 * merchant chooses to additionally show it by hand.
	 */
	public function render_shortcode(): string {
		return $this->pictogram_markup();
	}

	private function pictogram_markup(): string {
		$src = plugins_url( 'assets/images/sal-pictogram.png', ROMCOMMERCE_FILE );

		ob_start();
		echo '<a href="https://reclamatiisal.anpc.ro" target="_blank" rel="noopener noreferrer" class="romcommerce-sal-pictogram" style="display:inline-block;">';
		echo '<img src="' . esc_url( $src ) . '" alt="' . esc_attr__( 'Soluționarea Alternativă a Litigiilor (ANPC)', 'romcommerce' ) . '" width="250" style="width:250px;height:auto;">';
		echo '</a>';

		return (string) ob_get_clean();
	}

	private function alignment(): string {
		$alignment = (string) ( Settings::get( self::ID )['alignment'] ?? 'left' );

		return in_array( $alignment, self::ALIGNMENTS, true ) ? $alignment : 'left';
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			Settings::set_enabled( self::ID, isset( $_POST['romcommerce_sal_enabled'] ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			$alignment = sanitize_key( wp_unslash( $_POST['romcommerce_sal_alignment'] ?? 'left' ) );
			Settings::update(
				self::ID,
				array( 'alignment' => in_array( $alignment, self::ALIGNMENTS, true ) ? $alignment : 'left' )
			);
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
		}

		$enabled   = $this->is_enabled();
		$alignment = $this->alignment();

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody><tr>';
		echo '<th scope="row">' . esc_html__( 'Show pictogram', 'romcommerce' ) . '</th>';
		echo '<td><label><input type="checkbox" name="romcommerce_sal_enabled" value="1"' . checked( $enabled, true, false ) . '> ';
		echo esc_html__( 'Display the official SAL pictogram on the homepage', 'romcommerce' ) . '</label></td>';
		echo '</tr><tr>';
		echo '<th scope="row">' . esc_html__( 'Alignment', 'romcommerce' ) . '</th><td>';
		Placement::position_field(
			'romcommerce_sal_alignment',
			array(
				'left'   => __( 'Left', 'romcommerce' ),
				'center' => __( 'Center', 'romcommerce' ),
				'right'  => __( 'Right', 'romcommerce' ),
				'none'   => __( 'None — I\'ll place it myself', 'romcommerce' ),
			),
			$alignment
		);
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: the [romcommerce_sal_pictogram] shortcode */
				__( 'Where the pictogram sits inside your site\'s footer. "None" skips the automatic footer placement; use the %s shortcode to place it yourself, e.g. in a legal/contact page or a widget.', 'romcommerce' ),
				'[romcommerce_sal_pictogram]'
			)
		) . '</p>';
		echo '</td></tr></tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';
	}
}
