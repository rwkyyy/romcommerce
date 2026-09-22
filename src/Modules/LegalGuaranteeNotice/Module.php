<?php

declare(strict_types=1);

namespace RomCommerce\Modules\LegalGuaranteeNotice;

use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * EU harmonised notice on the legal guarantee of conformity, plus the
 * companion EU GARAN label for a voluntary producer durability guarantee —
 * Directive (EU) 2024/825 + Commission Implementing Regulation (EU) 2025/1960,
 * mandatory EU-wide from 27 September 2026. Artwork is the Commission's own
 * official RO/RGB files, unmodified, sourced from its publications page
 * (SVG.zip / "GARAN label for website.zip"); originals kept at
 * resources-check/legal-guarantee-notice/ alongside the practical guidelines
 * PDF this module's placements are based on.
 *
 * The notice is a sitewide general reminder, not a per-product requirement —
 * unlike the SAL pictogram this renders on every front-end page, not just the
 * homepage, since the notice must be reachable before purchase from anywhere
 * in the shop. A footer trigger links to a dedicated page (create/select
 * pattern borrowed from the Review Page module) showing the official artwork
 * plus the same clickable link the guidelines require alongside it (the RO
 * short URL from the guidelines PDF, §2.3). A modal was considered — the
 * guidelines' own worked examples show "appears on first click" as one
 * illustration, but say explicitly these are not the only compliant option —
 * and rejected as unnecessary JS/CSS for something checked rarely; the
 * binding rules (RGB colour, legible at default size, a clickable link
 * alongside it) don't require in-page display.
 *
 * GARAN is opt-in per product (manual attestation — RomCommerce does not
 * validate eligibility) and renders only the compact "nested label" form.
 * The full label's Brand/Trademark and Model identifier fields are split
 * across many individual <tspan> elements in the official SVG (Illustrator's
 * export), which makes safely rewriting them from PHP unreliable, so the
 * full-label click-to-expand variant the guidelines describe for nested
 * displays is intentionally not built — see docs/decisions.md.
 */
final class Module implements ModuleInterface, HasSettingsUi {

	private const ID = 'legal-guarantee-notice';

	// Guidelines PDF §2.3 URL table (Romanian) and §3.1 (i) GARAN QR target.
	private const NOTICE_URL = 'https://europa.eu/youreurope/garanții';
	private const GARAN_URL  = 'https://europa.eu/youreurope/commercial-guarantee-durability/index.htm';

	private const ALIGNMENTS = array( 'left', 'center', 'right' );

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Legal Guarantee Notice', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		add_shortcode( 'romcommerce_legal_guarantee_notice', array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_footer_trigger' ) );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render_email_notice' ), 10, 4 );

		if ( $this->show_on_checkout() ) {
			add_action( 'woocommerce_checkout_before_order_review', array( $this, 'render_inline_trigger' ) );
		}

		if ( $this->show_on_product_page() ) {
			add_action( 'woocommerce_single_product_summary', array( $this, 'render_inline_trigger' ), 15 );
		}

		if ( $this->garan_enabled() ) {
			add_action( 'woocommerce_single_product_summary', array( $this, 'render_garan_badge' ), 16 );
			add_action( 'add_meta_boxes_product', array( $this, 'add_garan_meta_box' ) );
			add_action( 'save_post_product', array( $this, 'save_garan_meta' ) );
		}
	}

	private function show_on_checkout(): bool {
		return (bool) ( Settings::get( self::ID )['show_on_checkout'] ?? true );
	}

	private function show_on_product_page(): bool {
		return (bool) ( Settings::get( self::ID )['show_on_product_page'] ?? false );
	}

	private function garan_enabled(): bool {
		return (bool) ( Settings::get( self::ID )['garan_enabled'] ?? false );
	}

	private function alignment(): string {
		$alignment = (string) ( Settings::get( self::ID )['alignment'] ?? 'left' );

		return in_array( $alignment, self::ALIGNMENTS, true ) ? $alignment : 'left';
	}

	/**
	 * Empty until the merchant creates or selects the notice page from
	 * settings — fail-soft, matching the Free Shipping Bar's "no resolvable
	 * threshold, nothing renders" pattern, rather than guessing a fallback
	 * destination for a legally-mandated link.
	 */
	private function page_url(): string {
		$page_id = (int) ( Settings::get( self::ID )['page_id'] ?? 0 );

		if ( $page_id > 0 && 'page' === get_post_type( $page_id ) ) {
			return (string) get_permalink( $page_id );
		}

		return '';
	}

	public function enqueue_assets(): void {
		if ( '' === $this->page_url() ) {
			return;
		}

		wp_register_script( 'romcommerce-legal-guarantee', false, array(), ROMCOMMERCE_VERSION, true );
		wp_enqueue_script( 'romcommerce-legal-guarantee' );
		wp_add_inline_script( 'romcommerce-legal-guarantee', $this->relocate_script_js() );
	}

	/**
	 * wp_footer fires after most themes' own <footer> markup has already
	 * closed, so the trigger anchor is relocated into the page's actual
	 * footer landmark once it exists — same technique as the SAL pictogram
	 * module. Fails soft: no footer element found leaves it where PHP put it.
	 */
	public function render_footer_trigger(): void {
		$url = $this->page_url();

		if ( '' === $url ) {
			return;
		}

		echo '<div id="romcommerce-lg-anchor" style="text-align:' . esc_attr( $this->alignment() ) . ';">';
		echo '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Drepturile tale privind garanția legală', 'romcommerce' ) . '</a>';
		echo '</div>';
	}

	public function render_inline_trigger(): void {
		$url = $this->page_url();

		if ( '' === $url ) {
			return;
		}

		echo '<p class="romcommerce-lg-inline"><a href="' . esc_url( $url ) . '">' . esc_html__( 'Drepturile tale privind garanția legală', 'romcommerce' ) . '</a></p>';
	}

	/**
	 * [romcommerce_legal_guarantee_notice] — the settings-created/selected
	 * page's content. Same information the old modal design showed: the
	 * official artwork plus the guidelines' required clickable link, just as
	 * a normal page instead of an overlay.
	 */
	public function render_shortcode(): string {
		$src = plugins_url( 'assets/images/legal-guarantee-notice-ro.svg', ROMCOMMERCE_FILE );

		ob_start();
		echo '<div class="romcommerce-legal-guarantee-notice">';
		echo '<img src="' . esc_url( $src ) . '" alt="' . esc_attr__( 'Notificarea armonizată UE privind garanția legală de conformitate', 'romcommerce' ) . '" style="max-width:520px;width:100%;height:auto;display:block;margin:0 auto;">';
		echo '<p style="text-align:center;"><a href="' . esc_url( self::NOTICE_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Vezi informațiile complete pe portalul Your Europe', 'romcommerce' ) . '</a></p>';
		echo '</div>';

		return (string) ob_get_clean();
	}

	public function render_garan_badge(): void {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			$product = wc_get_product( get_the_ID() );
		}

		if ( ! $product instanceof WC_Product || 'yes' !== $product->get_meta( '_romcommerce_garan_enabled', true ) ) {
			return;
		}

		$years = $this->garan_years( $product );
		$svg   = $years > 0 ? $this->garan_badge_markup( $years ) : '';

		if ( '' === $svg ) {
			return;
		}

		echo '<div class="romcommerce-garan-badge" style="display:inline-block;max-width:220px;margin:8px 0;">';
		echo '<style>.romcommerce-garan-badge svg{width:100%;height:auto;display:block;}</style>';
		echo '<a href="' . esc_url( self::GARAN_URL ) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr(
			sprintf(
				/* translators: %d: guarantee duration in years */
				__( 'Garanție de producător (EU GARAN): %d ani', 'romcommerce' ),
				$years
			)
		) . '">';
		echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput -- static official artwork; only the year figure is substituted and it is absint()-sanitized in garan_years().
		echo '</a>';
		echo '</div>';
	}

	private function garan_years( WC_Product $product ): int {
		return absint( $product->get_meta( '_romcommerce_garan_years', true ) );
	}

	private function garan_badge_markup( int $years ): string {
		$path = ROMCOMMERCE_DIR . '/assets/images/garan-nested-label.svg';

		if ( ! is_readable( $path ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin asset (not a remote URL).
		$svg = (string) file_get_contents( $path );

		if ( '' === $svg ) {
			return '';
		}

		return str_replace( '<tspan x="0" y="0">XX</tspan>', '<tspan x="0" y="0">' . esc_html( (string) $years ) . '</tspan>', $svg );
	}

	public function add_garan_meta_box(): void {
		add_meta_box(
			'romcommerce_garan',
			__( 'EU GARAN Label', 'romcommerce' ),
			array( $this, 'render_garan_meta_box' ),
			'product',
			'side',
			'default'
		);
	}

	public function render_garan_meta_box( WP_Post $post ): void {
		$product = wc_get_product( $post->ID );
		$enabled = $product instanceof WC_Product && 'yes' === $product->get_meta( '_romcommerce_garan_enabled', true );
		$years   = $product instanceof WC_Product ? $this->garan_years( $product ) : 0;

		wp_nonce_field( 'romcommerce_garan_save', 'romcommerce_garan_nonce' );

		echo '<p><label><input type="checkbox" name="romcommerce_garan_enabled" value="1"' . checked( $enabled, true, false ) . '> ' . esc_html__( 'This product has a qualifying EU GARAN guarantee', 'romcommerce' ) . '</label></p>';
		echo '<p><label for="romcommerce_garan_years">' . esc_html__( 'Guarantee duration (years)', 'romcommerce' ) . '</label><br>';
		echo '<input type="number" id="romcommerce_garan_years" name="romcommerce_garan_years" min="3" step="1" value="' . esc_attr( (string) $years ) . '" style="width:100%;"></p>';
		echo '<p class="description">' . esc_html__( 'Only for a voluntary producer durability guarantee that is free of charge, covers the entire product, and lasts more than two years. RomCommerce does not verify eligibility — check only if this product genuinely qualifies.', 'romcommerce' ) . '</p>';
	}

	public function save_garan_meta( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['romcommerce_garan_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['romcommerce_garan_nonce'] ) ), 'romcommerce_garan_save' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}

		$product = wc_get_product( $post_id );

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$enabled = isset( $_POST['romcommerce_garan_enabled'] );
		$years   = isset( $_POST['romcommerce_garan_years'] ) ? absint( wp_unslash( $_POST['romcommerce_garan_years'] ) ) : 0;

		$product->update_meta_data( '_romcommerce_garan_enabled', $enabled ? 'yes' : 'no' );
		$product->update_meta_data( '_romcommerce_garan_years', (string) $years );
		$product->save();
	}

	/**
	 * Fires once per customer order email (woocommerce_email_after_order_table
	 * runs per-email, not per-order-item). Admin emails are skipped — this is
	 * consumer-facing durable-medium information, not a merchant notice.
	 *
	 * @param WC_Order $order
	 * @param bool     $sent_to_admin
	 * @param bool     $plain_text
	 * @param mixed    $email
	 */
	public function render_email_notice( $order, $sent_to_admin, $plain_text, $email ): void {
		if ( $sent_to_admin || ! $order instanceof WC_Order ) {
			return;
		}

		$intro = __( 'Beneficiați de o garanție legală de conformitate de minimum 2 ani pentru bunurile achiziționate în Uniunea Europeană.', 'romcommerce' );

		if ( $plain_text ) {
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text email body, not HTML; esc_html() would turn Romanian punctuation into literal HTML entities. Values are translation strings/a hardcoded URL constant, no user input.
			echo "\n" . __( 'Garanția legală de conformitate', 'romcommerce' ) . "\n";
			echo $intro . "\n";
			echo self::NOTICE_URL . "\n";
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo '<p><strong>' . esc_html__( 'Garanția legală de conformitate', 'romcommerce' ) . '</strong><br>';
			echo esc_html( $intro ) . ' ';
			echo '<a href="' . esc_url( self::NOTICE_URL ) . '">' . esc_html__( 'Detalii', 'romcommerce' ) . '</a></p>';
		}

		if ( $this->garan_enabled() ) {
			$this->render_email_garan_lines( $order, $plain_text );
		}
	}

	private function render_email_garan_lines( WC_Order $order, bool $plain_text ): void {
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();

			if ( ! $product instanceof WC_Product || 'yes' !== $product->get_meta( '_romcommerce_garan_enabled', true ) ) {
				continue;
			}

			$years = $this->garan_years( $product );

			if ( $years <= 0 ) {
				continue;
			}

			$text = sprintf(
				/* translators: 1: product name, 2: guarantee duration in years */
				__( '%1$s beneficiază de eticheta EU GARAN — garanție de producător pe durabilitate, gratuită, %2$d ani.', 'romcommerce' ),
				$product->get_name(),
				$years
			);

			if ( $plain_text ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text email body, not HTML; esc_html() would corrupt it with literal HTML entities.
				echo $text . "\n" . self::GARAN_URL . "\n";
			} else {
				echo '<p>' . esc_html( $text ) . ' <a href="' . esc_url( self::GARAN_URL ) . '">' . esc_html__( 'Detalii', 'romcommerce' ) . '</a></p>';
			}
		}
	}

	private function relocate_script_js(): string {
		return '(function(){'
			. 'var anchor=document.getElementById("romcommerce-lg-anchor");if(!anchor){return;}'
			. 'var footer=document.querySelector("footer[role=\'contentinfo\']")||document.getElementById("colophon")||document.querySelector("footer");'
			. 'if(footer){footer.appendChild(anchor);}'
			. '})();';
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			if ( isset( $_POST['romcommerce_lg_create_page'] ) ) {
				$this->handle_create_page();
			} else {
				Settings::set_enabled( self::ID, isset( $_POST['romcommerce_lg_enabled'] ) );

				$alignment = sanitize_key( wp_unslash( $_POST['romcommerce_lg_alignment'] ?? 'left' ) );

				Settings::update(
					self::ID,
					array(
						'show_on_checkout'     => isset( $_POST['romcommerce_lg_checkout'] ),
						'show_on_product_page' => isset( $_POST['romcommerce_lg_product_page'] ),
						'alignment'            => in_array( $alignment, self::ALIGNMENTS, true ) ? $alignment : 'left',
						'garan_enabled'        => isset( $_POST['romcommerce_lg_garan_enabled'] ),
						'page_id'              => absint( wp_unslash( $_POST['romcommerce_lg_page_id'] ?? 0 ) ),
					)
				);

				echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing
		}

		$enabled              = $this->is_enabled();
		$show_on_checkout     = $this->show_on_checkout();
		$show_on_product_page = $this->show_on_product_page();
		$alignment            = $this->alignment();
		$garan_enabled        = $this->garan_enabled();
		$page_id              = (int) ( Settings::get( self::ID )['page_id'] ?? 0 );

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Show notice', 'romcommerce' ) . '</th>';
		echo '<td><label><input type="checkbox" name="romcommerce_lg_enabled" value="1"' . checked( $enabled, true, false ) . '> ';
		echo esc_html__( 'Display the EU harmonised legal-guarantee notice sitewide (mandatory EU-wide from 27 September 2026)', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Notice page', 'romcommerce' ) . '</th><td>';
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages() escapes its own output.
		wp_dropdown_pages(
			array(
				'name'              => 'romcommerce_lg_page_id',
				'selected'          => $page_id,
				'show_option_none'  => __( '— none —', 'romcommerce' ),
				'option_none_value' => '0',
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo ' <button type="submit" name="romcommerce_lg_create_page" value="1" class="button">' . esc_html__( 'Create notice page', 'romcommerce' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'The page containing the [romcommerce_legal_guarantee_notice] shortcode. All trigger links point here — they render nothing until a page is set.', 'romcommerce' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Extra placements', 'romcommerce' ) . '</th><td>';
		echo '<label style="display:block;"><input type="checkbox" name="romcommerce_lg_checkout" value="1"' . checked( $show_on_checkout, true, false ) . '> ' . esc_html__( 'Also show a link on the checkout page', 'romcommerce' ) . '</label>';
		echo '<label style="display:block;"><input type="checkbox" name="romcommerce_lg_product_page" value="1"' . checked( $show_on_product_page, true, false ) . '> ' . esc_html__( 'Also show a link on single product pages', 'romcommerce' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'The notice is always available site-wide as a general reminder, and is always included in order confirmation emails. These add it to extra high-visibility spots.', 'romcommerce' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Alignment', 'romcommerce' ) . '</th><td>';
		$labels = array(
			'left'   => __( 'Left', 'romcommerce' ),
			'center' => __( 'Center', 'romcommerce' ),
			'right'  => __( 'Right', 'romcommerce' ),
		);
		foreach ( $labels as $value => $label ) {
			echo '<label style="margin-right:16px;"><input type="radio" name="romcommerce_lg_alignment" value="' . esc_attr( $value ) . '"' . checked( $alignment, $value, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Where the sitewide reminder link sits inside your footer.', 'romcommerce' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'EU GARAN label', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="romcommerce_lg_garan_enabled" value="1"' . checked( $garan_enabled, true, false ) . '> ' . esc_html__( 'Enable the EU GARAN label for products with a qualifying producer durability guarantee', 'romcommerce' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Adds a per-product "EU GARAN" box to the product editor. This is a separate, optional obligation from the notice above, and only applies if you (or your producer) offer such a guarantee.', 'romcommerce' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';
	}

	private function handle_create_page(): void {
		$existing = (int) ( Settings::get( self::ID )['page_id'] ?? 0 );

		if ( $existing > 0 && 'page' === get_post_type( $existing ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'A notice page is already linked.', 'romcommerce' ) . '</p></div>';
			return;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Garanția legală de conformitate', 'romcommerce' ),
				'post_content' => '[romcommerce_legal_guarantee_notice]',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			),
			true
		);

		if ( is_wp_error( $page_id ) || 0 === $page_id ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The notice page could not be created.', 'romcommerce' ) . '</p></div>';
			return;
		}

		$settings            = Settings::get( self::ID );
		$settings['page_id'] = (int) $page_id;
		Settings::update( self::ID, $settings );

		echo '<div class="notice notice-success"><p>' . esc_html__( 'Notice page created and linked.', 'romcommerce' ) . '</p></div>';
	}
}
