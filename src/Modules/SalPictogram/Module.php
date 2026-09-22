<?php

declare(strict_types=1);

namespace RomCommerce\Modules\SalPictogram;

use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;

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
final class Module implements ModuleInterface, HasSettingsUi {

	private const ID = 'sal-pictogram';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'SAL Pictogram', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	private const ALIGNMENTS = array( 'left', 'center', 'right' );

	public function boot(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_pictogram' ) );
	}

	/**
	 * wp_footer fires after the theme's own <footer> markup has already closed,
	 * so a plain echo there always lands outside it. A tiny relocation script
	 * moves the rendered pictogram into the page's actual footer landmark once
	 * it exists in the DOM — the only theme-agnostic way to land inside an
	 * arbitrary theme's footer without a template override. Fails soft: no
	 * footer element found leaves the pictogram exactly where PHP put it.
	 */
	public function enqueue_assets(): void {
		if ( ! is_front_page() ) {
			return;
		}

		wp_register_script( 'romcommerce-sal-pictogram', false, array(), ROMCOMMERCE_VERSION, true );
		wp_enqueue_script( 'romcommerce-sal-pictogram' );
		wp_add_inline_script( 'romcommerce-sal-pictogram', $this->relocate_script_js() );
	}

	public function render_pictogram(): void {
		if ( ! is_front_page() ) {
			return;
		}

		$src = plugins_url( 'assets/images/sal-pictogram.png', ROMCOMMERCE_FILE );

		echo '<div id="romcommerce-sal-pictogram" style="text-align:' . esc_attr( $this->alignment() ) . ';">';
		echo '<a href="https://reclamatiisal.anpc.ro" target="_blank" rel="noopener noreferrer" class="romcommerce-sal-pictogram" style="display:inline-block;">';
		echo '<img src="' . esc_url( $src ) . '" alt="' . esc_attr__( 'Soluționarea Alternativă a Litigiilor (ANPC)', 'romcommerce' ) . '" width="250" style="width:250px;height:auto;">';
		echo '</a>';
		echo '</div>';
	}

	private function relocate_script_js(): string {
		return '(function(){'
			. 'var el=document.getElementById("romcommerce-sal-pictogram");if(!el){return;}'
			. 'var footer=document.querySelector("footer[role=\'contentinfo\']")||document.getElementById("colophon")||document.querySelector("footer");'
			. 'if(footer){footer.appendChild(el);}'
			. '})();';
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
		$labels = array(
			'left'   => __( 'Left', 'romcommerce' ),
			'center' => __( 'Center', 'romcommerce' ),
			'right'  => __( 'Right', 'romcommerce' ),
		);
		foreach ( $labels as $value => $label ) {
			echo '<label style="margin-right:16px;"><input type="radio" name="romcommerce_sal_alignment" value="' . esc_attr( $value ) . '"' . checked( $alignment, $value, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Where the pictogram sits inside your site\'s footer.', 'romcommerce' ) . '</p>';
		echo '</td></tr></tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';
	}
}
