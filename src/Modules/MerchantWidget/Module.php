<?php

declare(strict_types=1);

namespace RomCommerce\Modules\MerchantWidget;

use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasPlacements;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;
use RomCommerce\Support\Placement;

defined( 'ABSPATH' ) || exit;

/**
 * Google Merchant Center store widget: Google's own hosted badge script,
 * started with the merchant's position and optional spacing overrides. Google
 * finds the account from the page's domain (the account's website claim,
 * which this module neither creates nor checks). The optional Merchant Center
 * ID only filters: an ID whose account didn't claim this domain hides the
 * badge, it can't borrow another domain's rating (verified 2026-10-14, see
 * docs/decisions.md).
 *
 * Also Google Customer Reviews' survey opt-in on the order confirmation page
 * (SurveyOptIn), which needs the ID. The widget and the opt-in are the only
 * visitor-side requests to a third party in Lite, so readme.txt's "External
 * services" section discloses both.
 */
final class Module implements ModuleInterface, HasSettingsUi, HasPlacements {

	private const ID = 'merchant-widget';

	private const SCRIPT_URL = 'https://www.gstatic.com/shopping/merchant/merchantwidget.js';

	private const POSITIONS = array( 'RIGHT_BOTTOM', 'LEFT_BOTTOM' );

	/**
	 * Google's documented defaults. An override is passed to start() only when
	 * it differs, so Google stays free to retune its own defaults.
	 *
	 * @var array<string, int>
	 */
	private const MARGIN_DEFAULTS = array(
		'sideMargin'         => 36,
		'bottomMargin'       => 36,
		'mobileSideMargin'   => 16,
		'mobileBottomMargin' => 46,
	);

	private const MARGIN_MAX = 300;

	/**
	 * Romanian stores by default (owner, 2026-10-14): left blank, Google picks the
	 * region from the visitor's location and hides the badge from visitors
	 * outside a country where the store has a rating. A merchant who clears the
	 * field and saves gets Google's blank default.
	 */
	private const DEFAULT_REGION = 'RO';

	/** Merchant Center account IDs are plain digits; anything longer than this is not one. */
	private const MERCHANT_ID_MAX_DIGITS = 20;

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Google Merchant Center Widget', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Declared for the Placements map only: Google's script draws the badge
	 * itself, so there is no slot hook to wire.
	 *
	 * @return array<int, array{key: string, slot: string, active: bool}>
	 */
	public function placements(): array {
		return array(
			array(
				'key'    => 'widget',
				'slot'   => 'fixed',
				'active' => true,
			),
		);
	}

	/**
	 * The start call is its own src-less handle rather than an "after" inline
	 * script on Google's handle: WordPress 6.3+ drops the defer strategy from
	 * any script that carries an "after" inline script.
	 */
	public function enqueue_assets(): void {
		if ( is_admin() ) {
			return;
		}

		$settings = Settings::get( self::ID );
		if ( ! empty( $settings['survey'] ) ) {
			( new SurveyOptIn(
				$this->sanitize_merchant_id( (string) ( $settings['merchant_id'] ?? '' ) ),
				(int) ( $settings['delivery_days'] ?? SurveyOptIn::DELIVERY_DAYS_DEFAULT ),
				(string) ( $settings['survey_style'] ?? '' )
			) )->enqueue();
		}

		if ( ! (bool) ( $settings['show_widget'] ?? true ) ) {
			return;
		}

		wp_enqueue_script( 'romcommerce-merchant-widget', self::SCRIPT_URL, array(), null, $this->script_args() ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google's hosted script; a version query would only defeat its own caching.

		wp_register_script( 'romcommerce-merchant-widget-start', false, array(), ROMCOMMERCE_VERSION, true );
		wp_enqueue_script( 'romcommerce-merchant-widget-start' );
		wp_add_inline_script( 'romcommerce-merchant-widget-start', $this->start_script_js( $this->start_config() ) );
	}

	/**
	 * WordPress before 6.3 reads the fifth argument as a plain in-footer flag,
	 * so the array still lands the script in the footer there, just not deferred.
	 *
	 * @return array{in_footer: bool, strategy: string}
	 */
	private function script_args(): array {
		return array(
			'in_footer' => true,
			'strategy'  => 'defer',
		);
	}

	/**
	 * start() may run from three places because the script can arrive three
	 * ways: deferred (runs before DOMContentLoaded), blocking on WordPress
	 * before 6.3 (already loaded by the time this runs), or delayed by an
	 * optimisation plugin (its own load event). The flag keeps it to one call.
	 *
	 * @param array<string, int|string> $config
	 */
	private function start_script_js( array $config ): string {
		return '(function(){'
			. 'var started=false;'
			// start() throws if the widget already exists, e.g. a theme or another plugin started it too.
			. 'function go(){if(started||!window.merchantwidget){return;}started=true;try{window.merchantwidget.start(' . wp_json_encode( $config ) . ');}catch(e){}}'
			. 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",go);}else{go();}'
			. 'var el=document.getElementById("romcommerce-merchant-widget-js");if(el){el.addEventListener("load",go);}'
			. '})();';
	}

	/** @return array<string, int|string> */
	private function start_config(): array {
		$settings = Settings::get( self::ID );
		$config   = array( 'position' => $this->position() );

		foreach ( self::MARGIN_DEFAULTS as $key => $default ) {
			$value = $this->sanitize_margin( $settings[ $key ] ?? null );
			if ( null !== $value && $default !== $value ) {
				$config[ $key ] = $value;
			}
		}

		$region = $this->sanitize_region( (string) ( $settings['region'] ?? self::DEFAULT_REGION ) );
		if ( '' !== $region ) {
			$config['region'] = $region;
		}

		$merchant_id = $this->sanitize_merchant_id( (string) ( $settings['merchant_id'] ?? '' ) );
		if ( '' !== $merchant_id ) {
			$config['merchant_id'] = $merchant_id;
		}

		return $config;
	}

	private function position(): string {
		$position = (string) ( Settings::get( self::ID )['position'] ?? '' );

		return in_array( $position, self::POSITIONS, true ) ? $position : 'RIGHT_BOTTOM';
	}

	/**
	 * @param mixed $value Stored or posted value; '' and non-numbers mean "use Google's default".
	 */
	private function sanitize_margin( $value ): ?int {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		return max( 0, min( self::MARGIN_MAX, (int) $value ) );
	}

	/** Google publishes no region list, so only the two-letter country-code shape is accepted. */
	private function sanitize_region( string $region ): string {
		$region = strtoupper( trim( $region ) );

		return 1 === preg_match( '/^[A-Z]{2}$/', $region ) ? $region : '';
	}

	/**
	 * Accepts the bare ID or Merchant Center's whole snippet pasted as-is,
	 * since merchants copy the snippet. Kept as a string: IDs can exceed a
	 * 32-bit PHP integer.
	 */
	private function sanitize_merchant_id( string $raw ): string {
		if ( 1 === preg_match( '/merchant_id\W*(\d+)/i', $raw, $match ) || 1 === preg_match( '/^\s*(\d+)\s*$/', $raw, $match ) ) {
			$id = ltrim( $match[1], '0' );

			return ( '' !== $id && strlen( $id ) <= self::MERCHANT_ID_MAX_DIGITS ) ? $id : '';
		}

		return '';
	}

	/** The contact button sharing the bottom-right corner is the one collision this plugin can see. */
	private function collides_with_contact_button(): bool {
		if ( 'RIGHT_BOTTOM' !== $this->position() || ! Settings::is_enabled( 'whatsapp-fab' ) ) {
			return false;
		}

		return 'right' === ( Settings::get( 'whatsapp-fab' )['position'] ?? 'left' );
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// SettingsForm::verify() has confirmed the nonce and capability on the line above.
			// phpcs:disable WordPress.Security.NonceVerification.Missing
			$settings = array(
				'position'      => ( isset( $_POST['rc_position'] ) && 'LEFT_BOTTOM' === sanitize_text_field( wp_unslash( $_POST['rc_position'] ) ) ) ? 'LEFT_BOTTOM' : 'RIGHT_BOTTOM',
				'region'        => isset( $_POST['rc_region'] ) ? $this->sanitize_region( sanitize_text_field( wp_unslash( $_POST['rc_region'] ) ) ) : '',
				'merchant_id'   => isset( $_POST['rc_merchant_id'] ) ? $this->sanitize_merchant_id( sanitize_textarea_field( wp_unslash( $_POST['rc_merchant_id'] ) ) ) : '',
				'show_widget'   => isset( $_POST['rc_show_widget'] ),
				'survey'        => isset( $_POST['rc_survey'] ),
				'delivery_days' => isset( $_POST['rc_delivery_days'] ) ? max( 1, min( SurveyOptIn::DELIVERY_DAYS_MAX, absint( wp_unslash( $_POST['rc_delivery_days'] ) ) ) ) : SurveyOptIn::DELIVERY_DAYS_DEFAULT,
				'survey_style'  => ( isset( $_POST['rc_survey_style'] ) && in_array( sanitize_text_field( wp_unslash( $_POST['rc_survey_style'] ) ), SurveyOptIn::STYLES, true ) ) ? sanitize_text_field( wp_unslash( $_POST['rc_survey_style'] ) ) : 'CENTER_DIALOG',
			);
			foreach ( array_keys( self::MARGIN_DEFAULTS ) as $key ) {
				$margin           = isset( $_POST['rc_margin'][ $key ] ) ? $this->sanitize_margin( sanitize_text_field( wp_unslash( $_POST['rc_margin'][ $key ] ) ) ) : null;
				$settings[ $key ] = null === $margin ? '' : $margin;
			}
			$this->handle_save( isset( $_POST['rc_enabled'] ), $settings );
			// phpcs:enable WordPress.Security.NonceVerification.Missing
		}

		echo '<p>' . esc_html__( 'Shows Google\'s store widget, with your Merchant Center store rating, as a badge in a corner of every page.', 'romcommerce' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Before you turn it on: this website must already be claimed and verified in your Google Merchant Center account. This module does not create, claim or verify anything in Merchant Center, and the badge only shows what Google has for your store.', 'romcommerce' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'The widget loads a script from Google\'s servers for every visitor. Mention it in your privacy policy and, if you use one, your cookie banner.', 'romcommerce' ) . '</p>';

		if ( $this->is_enabled() && $this->collides_with_contact_button() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The contact button is also in the bottom-right corner, so the two may overlap. Move one of them to the bottom left.', 'romcommerce' ) . '</p></div>';
		}

		$settings = Settings::get( self::ID );

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Module', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ';
		echo esc_html__( 'Enable', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Store badge', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_show_widget" value="1"' . checked( (bool) ( $settings['show_widget'] ?? true ), true, false ) . '> ';
		echo esc_html__( 'Show the store widget with your rating on every page', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Position', 'romcommerce' ) . '</th><td>';
		Placement::position_field(
			'rc_position',
			array(
				'RIGHT_BOTTOM' => __( 'Bottom right', 'romcommerce' ),
				'LEFT_BOTTOM'  => __( 'Bottom left', 'romcommerce' ),
			),
			$this->position()
		);
		echo '<p class="description">' . esc_html__( 'Bottom right is Google\'s default. Pick the corner that live-chat or contact buttons don\'t use. On phones, Google always centres the widget.', 'romcommerce' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="rc-merchant-id">' . esc_html__( 'Merchant Center ID', 'romcommerce' ) . '</label></th><td>';
		echo '<input type="text" id="rc-merchant-id" name="rc_merchant_id" value="' . esc_attr( $this->sanitize_merchant_id( (string) ( $settings['merchant_id'] ?? '' ) ) ) . '" class="regular-text" inputmode="numeric" placeholder="123456789">';
		echo '<p class="description">' . esc_html__( 'Optional for the badge, required for the review survey below. Paste the ID, or the whole widget code from Merchant Center and the ID is taken from it. Left blank, Google finds your account from this site\'s address. If you fill it in, it must be the account that claimed this site\'s domain, or the badge does not show. An account can\'t show its rating on another domain.', 'romcommerce' ) . '</p></td></tr>';

		echo '</tbody></table>';

		echo '<details><summary>' . esc_html__( 'Advanced', 'romcommerce' ) . '</summary>';
		echo '<p class="description">' . esc_html__( 'Distances from the edge of the screen, in pixels. Leave a field blank to use Google\'s default.', 'romcommerce' ) . '</p>';
		echo '<table class="form-table"><tbody>';
		foreach ( $this->margin_labels() as $key => $label ) {
			$stored = $this->sanitize_margin( $settings[ $key ] ?? null );
			echo '<tr><th scope="row"><label for="rc-margin-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
			echo '<input type="number" min="0" max="' . esc_attr( (string) self::MARGIN_MAX ) . '" step="1" class="small-text" id="rc-margin-' . esc_attr( $key ) . '" name="rc_margin[' . esc_attr( $key ) . ']" value="' . esc_attr( null === $stored ? '' : (string) $stored ) . '" placeholder="' . esc_attr( (string) self::MARGIN_DEFAULTS[ $key ] ) . '">';
			echo '</td></tr>';
		}
		echo '<tr><th scope="row"><label for="rc-region">' . esc_html__( 'Region', 'romcommerce' ) . '</label></th><td>';
		echo '<input type="text" id="rc-region" name="rc_region" value="' . esc_attr( $this->sanitize_region( (string) ( $settings['region'] ?? self::DEFAULT_REGION ) ) ) . '" class="small-text" maxlength="2" placeholder="RO">';
		echo '<p class="description">' . esc_html__( 'A two-letter country code. RO shows your Romanian rating to every visitor. Left blank, Google uses the visitor\'s location instead, so visitors from a country where your store has no rating see no widget.', 'romcommerce' ) . '</p></td></tr>';
		echo '</tbody></table></details>';

		$this->render_survey_settings( $settings );

		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';
	}

	/** @param array<string, mixed> $settings */
	private function render_survey_settings( array $settings ): void {
		$survey = ! empty( $settings['survey'] );

		echo '<h3>' . esc_html__( 'Google Customer Reviews survey', 'romcommerce' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'After an order, Google\'s own dialog on the order confirmation page asks the customer whether they want a short survey by e-mail after delivery. Google Customer Reviews must be switched on in your Merchant Center account, and the Merchant Center ID above is required. It only shows on HTTPS pages.', 'romcommerce' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Google runs Customer Reviews only in some countries, and its list (October 2026) doesn\'t include Romania. Check that your Merchant Center offers it before relying on it.', 'romcommerce' ) . ' <a href="https://support.google.com/merchants/answer/7125187" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Google\'s country list', 'romcommerce' ) . '</a></p>';
		echo '<p class="description">' . esc_html__( 'When the dialog shows, the customer\'s browser sends Google the order number, the customer\'s e-mail address, the delivery country, the estimated delivery date and the products\' GTINs (when set). Mention it in your privacy policy. Google asks that every customer sees the dialog.', 'romcommerce' ) . '</p>';

		if ( $survey && '' === $this->sanitize_merchant_id( (string) ( $settings['merchant_id'] ?? '' ) ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The survey needs your Merchant Center ID, so it doesn\'t show yet.', 'romcommerce' ) . '</p></div>';
		}

		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Survey', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_survey" value="1"' . checked( $survey, true, false ) . '> ';
		echo esc_html__( 'Show Google\'s survey opt-in on the order confirmation page', 'romcommerce' ) . '</label></td></tr>';

		$days = max( 1, min( SurveyOptIn::DELIVERY_DAYS_MAX, (int) ( $settings['delivery_days'] ?? SurveyOptIn::DELIVERY_DAYS_DEFAULT ) ) );
		echo '<tr><th scope="row"><label for="rc-delivery-days">' . esc_html__( 'Days until delivery', 'romcommerce' ) . '</label></th><td>';
		echo '<input type="number" id="rc-delivery-days" name="rc_delivery_days" min="1" max="' . esc_attr( (string) SurveyOptIn::DELIVERY_DAYS_MAX ) . '" class="small-text" value="' . esc_attr( (string) $days ) . '">';
		echo '<p class="description">' . esc_html__( 'Days after the order. Google sends the survey after this date, so set it to when most of your orders have arrived.', 'romcommerce' ) . '</p></td></tr>';

		$style  = (string) ( $settings['survey_style'] ?? 'CENTER_DIALOG' );
		$labels = array(
			'CENTER_DIALOG'       => __( 'Centre of the screen (Google\'s default)', 'romcommerce' ),
			'BOTTOM_RIGHT_DIALOG' => __( 'Bottom right', 'romcommerce' ),
			'BOTTOM_LEFT_DIALOG'  => __( 'Bottom left', 'romcommerce' ),
			'TOP_RIGHT_DIALOG'    => __( 'Top right', 'romcommerce' ),
			'TOP_LEFT_DIALOG'     => __( 'Top left', 'romcommerce' ),
			'BOTTOM_TRAY'         => __( 'Bar along the bottom', 'romcommerce' ),
		);
		echo '<tr><th scope="row"><label for="rc-survey-style">' . esc_html__( 'Dialog position', 'romcommerce' ) . '</label></th><td><select id="rc-survey-style" name="rc_survey_style">';
		foreach ( $labels as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $style, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '</tbody></table>';
	}

	/** @return array<string, string> */
	private function margin_labels(): array {
		return array(
			'sideMargin'         => __( 'Side margin', 'romcommerce' ),
			'bottomMargin'       => __( 'Bottom margin', 'romcommerce' ),
			'mobileSideMargin'   => __( 'Side margin on phones', 'romcommerce' ),
			'mobileBottomMargin' => __( 'Bottom margin on phones', 'romcommerce' ),
		);
	}

	/**
	 * @param array<string, int|string> $settings Sanitized position, region and margins ('' = Google's default).
	 */
	private function handle_save( bool $enabled, array $settings ): void {
		Settings::set_enabled( self::ID, $enabled );
		Settings::update( self::ID, $settings );

		echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
	}
}
