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
 * alongside it) don't require in-page display. The trigger's link text and
 * its blue colour are both settings-driven (trigger_text()/
 * trigger_recommended_color()) rather than hardcoded, defaulting to the
 * original text/colour so existing installs don't change on upgrade.
 *
 * GARAN is a distinct obligation from the notice above — it only applies
 * once a guarantee exceeds the 2-year legal-guarantee floor. Per-product
 * eligibility is the "guarantee duration (years)" field (manual attestation
 * — RomCommerce does not validate it); the label renders once that value is
 * GARAN_MIN_YEARS (2.5) or more, with no separate enable checkbox — confirmed
 * against a reference WP.org plugin in the same space
 * (garanlabs-eu-guarantee-notice-durability-labels), see docs/decisions.md.
 * The guidelines permit half-year durations on the "XX" field ("2,5" / "4,5"
 * — comma decimal, no other fraction), so 2.5 is the true floor, not 3;
 * duration is stored/compared as a float and formatted with a comma decimal
 * only when the value isn't a whole number (format_garan_years()).
 *
 * §3.3.1 of the guidelines requires a nested display to "appear in its
 * entirety" on click/hover/tap, so the badge is a <details> element: the
 * summary is the compact nested label, and expanding it reveals the full
 * label. An earlier version of this docblock claimed the full label's
 * Brand/Trademark and Model identifier captions had no safe substitution
 * point — wrong: they're split across several kerned <tspan>s rather than
 * one clean node like "XX" is, but a literal match on that exact tspan
 * sequence substitutes them safely (confirmed against the reference
 * plugin's own source, which does the same). The expanded view prefers a
 * merchant-uploaded, already-compliant full label image when one is set —
 * still the Commission-sanctioned route for a producer's own artwork — and
 * otherwise falls back to the official template with duration and any
 * entered Brand/Model substituted directly into it. Brand can instead be
 * pulled live from WooCommerce's native Product Brands taxonomy
 * (product_brand, core since WC 9.4) via a per-product checkbox, so a
 * merchant who already assigns brands there doesn't have to retype one
 * (garan_brand()); only shown when that taxonomy exists, since brands are an
 * opt-in WooCommerce feature.
 *
 * Both label SVGs are exports from the same design tool with unscoped,
 * auto-generated class/id names ("cls-4", clip-path ids). Inlining more
 * than one instance on a page — the compact badge alongside its own
 * expanded full label, or several products' badges in an archive loop —
 * lets a later <style> block's rule silently override an earlier one using
 * the same class name; garan_svg() namespaces every render to prevent it.
 */
final class Module implements ModuleInterface, HasSettingsUi {

	private const ID = 'legal-guarantee-notice';

	// Guidelines PDF §2.3 URL table (Romanian) and §3.1 (i) GARAN QR target.
	private const NOTICE_URL = 'https://europa.eu/youreurope/garanții';
	private const GARAN_URL  = 'https://europa.eu/youreurope/commercial-guarantee-durability/index.htm';

	// EUR-Lex canonical law texts, distinct from the "Your Europe" consumer
	// explainer pages above — settings-page "More info" links point here.
	private const NOTICE_LAW_URL = 'https://eur-lex.europa.eu/eli/dir/2024/825/oj/eng';
	private const GARAN_LAW_URL  = 'https://eur-lex.europa.eu/eli/reg_impl/2025/1960/oj/eng';

	private const ALIGNMENTS = array( 'left', 'center', 'right', 'none' );

	// See the class docblock for why 2.5, not the notice's own 2-year floor.
	private const GARAN_MIN_YEARS = 2.5;

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Legal Guarantee Notice', 'romcommerce' );
	}

	// Default on: a legal-floor compliance feature, not an opt-in extra.
	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID, true );
	}

	public function boot(): void {
		add_shortcode( 'romcommerce_legal_guarantee_notice', array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_footer_trigger' ) );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render_email_notice' ), 10, 4 );

		if ( $this->show_on_checkout() ) {
			// Priority 5 lands this just above WC core's own terms-and-conditions
			// checkbox, which core hooks onto the same action at priority 10.
			add_action( 'woocommerce_review_order_before_submit', array( $this, 'render_checkout_trigger' ), 5 );
		}

		if ( $this->show_on_product_page() ) {
			add_action( 'woocommerce_single_product_summary', array( $this, 'render_product_page_trigger' ), 15 );
		}

		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_general_tab_fields' ) );
		add_action( 'save_post_product', array( $this, 'save_general_tab_fields' ) );

		if ( $this->garan_enabled() ) {
			add_action( 'woocommerce_single_product_summary', array( $this, 'render_garan_badge' ), 16 );
			add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_garan_product_tab' ) );
			add_action( 'woocommerce_product_data_panels', array( $this, 'render_garan_product_panel' ) );
			add_action( 'save_post_product', array( $this, 'save_garan_meta' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_garan_media_library' ) );
		}
	}

	/**
	 * The product editor's own screen already loads wp.media for the Product
	 * Gallery, but that's a WC internal, not a documented contract — enqueue
	 * it ourselves so the image picker in render_garan_product_panel() keeps
	 * working even if that assumption ever changes.
	 */
	public function enqueue_garan_media_library( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || 'product' !== get_post_type() ) {
			return;
		}

		wp_enqueue_media();
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

	// Default true: existing installs already show the blue trigger, so an
	// absent setting must keep matching current behaviour.
	private function trigger_recommended_color(): bool {
		return (bool) ( Settings::get( self::ID )['trigger_recommended_color'] ?? true );
	}

	private function default_trigger_text(): string {
		return __( 'Drepturile tale privind garanția legală', 'romcommerce' );
	}

	private function trigger_text(): string {
		$text = trim( (string) ( Settings::get( self::ID )['trigger_text'] ?? '' ) );

		return '' !== $text ? $text : $this->default_trigger_text();
	}

	/**
	 * Settings store "{taxonomy}:{term_id}" strings — not just product_cat —
	 * so a shop can exclude by category, tag, a brand plugin's taxonomy, or a
	 * global attribute, whichever taxonomies happen to be attached to
	 * products (see render_settings()).
	 *
	 * @return array<int, array{taxonomy: string, term_id: int}>
	 */
	private function excluded_terms(): array {
		$raw = Settings::get( self::ID )['excluded_terms'] ?? array();

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$parsed = array();

		foreach ( $raw as $value ) {
			$parts = explode( ':', (string) $value, 2 );

			if ( 2 !== count( $parts ) || ! taxonomy_exists( $parts[0] ) ) {
				continue;
			}

			$parsed[] = array(
				'taxonomy' => $parts[0],
				'term_id'  => absint( $parts[1] ),
			);
		}

		return $parsed;
	}

	/**
	 * Only gates the optional inline product-page link — that placement reads
	 * as a per-product statement. The sitewide footer reminder is a general
	 * notice of statutory rights, not a claim about any specific product
	 * (e.g. perishables that can't meaningfully carry a multi-year guarantee),
	 * so it's deliberately excluded from this check. The per-product checkbox
	 * (render_general_tab_fields()) is a one-off exception on top of the
	 * taxonomy list, for a single product that doesn't fit its category/tag.
	 */
	private function product_excluded( WC_Product $product ): bool {
		if ( 'yes' === $product->get_meta( '_romcommerce_lg_excluded', true ) ) {
			return true;
		}

		$by_taxonomy = array();

		foreach ( $this->excluded_terms() as $entry ) {
			$by_taxonomy[ $entry['taxonomy'] ][] = $entry['term_id'];
		}

		foreach ( $by_taxonomy as $taxonomy => $term_ids ) {
			if ( array() !== array_intersect( $term_ids, wc_get_product_term_ids( $product->get_id(), $taxonomy ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A single per-product override, independent of the taxonomy exclusion
	 * list — for the one-off exception within an otherwise-eligible category
	 * (e.g. a single discontinued item in an otherwise-guaranteed range).
	 */
	public function render_general_tab_fields(): void {
		global $post;

		if ( ! $post ) {
			return;
		}

		wp_nonce_field( 'romcommerce_lg_product_save', 'romcommerce_lg_product_nonce' );

		echo '<div class="options_group">';
		woocommerce_wp_checkbox(
			array(
				'id'          => '_romcommerce_lg_excluded',
				'label'       => __( 'Legal guarantee notice', 'romcommerce' ),
				'description' => __( 'Exclude this product from the notice link on its product page, even if its categories/tags aren\'t excluded in the module\'s settings.', 'romcommerce' ),
				'desc_tip'    => true,
			)
		);
		echo '</div>';
	}

	public function save_general_tab_fields( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['romcommerce_lg_product_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['romcommerce_lg_product_nonce'] ) ), 'romcommerce_lg_product_save' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}

		$product = wc_get_product( $post_id );

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$product->update_meta_data( '_romcommerce_lg_excluded', isset( $_POST['_romcommerce_lg_excluded'] ) ? 'yes' : 'no' );
		$product->save();
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
		if ( '' !== $this->page_url() ) {
			wp_register_style( 'romcommerce-legal-guarantee', false, array(), ROMCOMMERCE_VERSION );
			wp_enqueue_style( 'romcommerce-legal-guarantee' );
			wp_add_inline_style( 'romcommerce-legal-guarantee', $this->trigger_styles_css() );

			if ( 'none' !== $this->alignment() ) {
				wp_register_script( 'romcommerce-legal-guarantee', false, array(), ROMCOMMERCE_VERSION, true );
				wp_enqueue_script( 'romcommerce-legal-guarantee' );
				wp_add_inline_script( 'romcommerce-legal-guarantee', $this->relocate_script_js() );
			}
		}

		// Independent of the notice page above — GARAN is its own obligation
		// and renders with or without a notice page configured.
		if ( $this->garan_enabled() ) {
			wp_register_style( 'romcommerce-garan', false, array(), ROMCOMMERCE_VERSION );
			wp_enqueue_style( 'romcommerce-garan' );
			wp_add_inline_style( 'romcommerce-garan', $this->garan_styles_css() );
		}
	}

	private function trigger_styles_css(): string {
		// border-bottom always uses currentColor so it tracks whichever color
		// applies below, instead of needing its own recommended/inherited branch.
		$css = '.romcommerce-lg-trigger{display:inline-flex;align-items:center;gap:6px;font-weight:600;text-decoration:none;border-bottom:1px dotted currentColor;padding-bottom:1px;}'
			. '.romcommerce-lg-trigger svg{width:14px;height:14px;flex:none;}';

		if ( $this->trigger_recommended_color() ) {
			$css .= '.romcommerce-lg-trigger{color:#034ea2;}.romcommerce-lg-trigger:hover,.romcommerce-lg-trigger:focus{color:#023d82;}';
		}

		return $css;
	}

	private function garan_styles_css(): string {
		// max-width is sized for the open state, not the closed one: the full-label SVG's
		// viewBox (269.29 x 283.46) is the EU-mandated 95x100mm minimum expressed in points,
		// so it needs ~359 CSS px (1pt = 4/3px) to render its fixed 9pt/80pt text at true
		// legal size. display:inline-block keeps the closed summary (icon + link) sized to
		// its own short content regardless of this ceiling.
		return '.romcommerce-garan-badge{display:inline-block;max-width:400px;margin:8px 0;background:#eef3fb;border:1px solid #d7deeb;border-radius:10px;padding:10px 12px;}'
			. '.romcommerce-garan-badge summary{list-style:none;cursor:pointer;display:flex;align-items:center;gap:10px;}'
			. '.romcommerce-garan-badge summary::-webkit-details-marker{display:none;}'
			. '.romcommerce-garan-badge .romcommerce-garan-svg{flex:1 1 auto;min-width:0;}'
			. '.romcommerce-garan-badge .romcommerce-garan-svg svg{width:100%;height:auto;display:block;}'
			. '.romcommerce-garan-badge .romcommerce-garan-chevron{flex:none;width:8px;height:8px;border-right:2px solid #5b6472;border-bottom:2px solid #5b6472;transform:rotate(45deg);transition:transform .15s ease;}'
			. '.romcommerce-garan-badge[open] .romcommerce-garan-chevron{transform:rotate(225deg);}'
			. '.romcommerce-garan-badge .romcommerce-garan-panel{margin-top:12px;padding-top:12px;border-top:1px solid #d7deeb;}'
			. '.romcommerce-garan-badge .romcommerce-garan-panel svg,.romcommerce-garan-badge .romcommerce-garan-panel img{width:100%;height:auto;display:block;border-radius:4px;}'
			. '.romcommerce-garan-badge .romcommerce-garan-panel p{margin:10px 0 0;font-size:.85em;color:#5b6472;}'
			. '.romcommerce-garan-badge .romcommerce-garan-panel a{color:#034ea2;font-weight:600;text-decoration:none;}';
	}

	/**
	 * wp_footer fires after most themes' own <footer> markup has already
	 * closed, so the trigger anchor is relocated into the page's actual
	 * footer landmark once it exists — same technique as the SAL pictogram
	 * module. Fails soft: no footer element found leaves it where PHP put it.
	 * Alignment 'none' means the merchant places the link themselves and this
	 * renders nothing.
	 */
	public function render_footer_trigger(): void {
		$url = $this->page_url();

		if ( '' === $url || 'none' === $this->alignment() ) {
			return;
		}

		echo '<div id="romcommerce-lg-anchor" style="text-align:' . esc_attr( $this->alignment() ) . ';">';
		$this->render_inline_link();
		echo '</div>';
	}

	public function render_checkout_trigger(): void {
		$this->render_inline_link();
	}

	public function render_product_page_trigger(): void {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			$product = wc_get_product( get_the_ID() );
		}

		if ( $product instanceof WC_Product && $this->product_excluded( $product ) ) {
			return;
		}

		$this->render_inline_link();
	}

	private function render_inline_link(): void {
		$url = $this->page_url();

		if ( '' === $url ) {
			return;
		}

		echo '<p class="romcommerce-lg-inline"><a class="romcommerce-lg-trigger" href="' . esc_url( $url ) . '">';
		echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v5"></path><circle cx="12" cy="16" r="0.5" fill="currentColor"></circle></svg>';
		echo esc_html( $this->trigger_text() );
		echo '</a></p>';
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

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$years      = $this->garan_years( $product );
		$nested_svg = $years >= self::GARAN_MIN_YEARS ? $this->garan_svg( 'garan-nested-label.svg', $years ) : '';

		if ( '' === $nested_svg ) {
			return;
		}

		echo '<details class="romcommerce-garan-badge">';
		echo '<summary aria-label="' . esc_attr(
			sprintf(
				/* translators: %s: guarantee duration in years, e.g. "3" or "2,5" */
				__( 'Garanție de producător (EU GARAN): %s ani — vezi eticheta completă', 'romcommerce' ),
				$this->format_garan_years( $years )
			)
		) . '">';
		echo '<span class="romcommerce-garan-svg">';
		echo $nested_svg; // phpcs:ignore WordPress.Security.EscapeOutput -- static official artwork; only the year figure is substituted and it is esc_html()'d in garan_svg().
		echo '</span>';
		echo '<span class="romcommerce-garan-chevron" aria-hidden="true"></span>';
		echo '</summary>';
		echo '<div class="romcommerce-garan-panel">';
		echo $this->garan_full_label_content( $product, $years ); // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped field-by-field in garan_full_label_content()/garan_svg().
		echo '<p><a href="' . esc_url( self::GARAN_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Detalii despre garanția de durabilitate', 'romcommerce' ) . '</a></p>';
		echo '</div>';
		echo '</details>';
	}

	private function garan_years( WC_Product $product ): float {
		return (float) $product->get_meta( '_romcommerce_garan_years', true );
	}

	/**
	 * Native WooCommerce Product Brands (taxonomy_exists() guard: only
	 * present from WC 9.4+ and only once the merchant has the feature on) —
	 * an alternative to typing the brand into this module's own field.
	 */
	private function garan_brand( WC_Product $product ): string {
		if ( 'yes' === $product->get_meta( '_romcommerce_garan_brand_use_woo', true ) && taxonomy_exists( 'product_brand' ) ) {
			$terms = get_the_terms( $product->get_id(), 'product_brand' );

			return ( is_array( $terms ) && isset( $terms[0]->name ) ) ? $terms[0]->name : '';
		}

		return trim( (string) $product->get_meta( '_romcommerce_garan_brand', true ) );
	}

	/**
	 * The guidelines allow only whole or half-year durations on the label
	 * itself ("4,1 or 4,2 are not permitted") — snap to the nearest 0.5
	 * regardless of what was posted, so a stored value is always valid to
	 * display even if it arrived some other way than the stepper input.
	 */
	private function sanitize_garan_years( string $raw ): float {
		$years = (float) $raw;

		if ( $years <= 0 ) {
			return 0.0;
		}

		return round( $years * 2 ) / 2;
	}

	/**
	 * The guidelines require a comma decimal, not a period ("2,5", not
	 * "2.5"), and whole numbers shown with no decimal at all ("3", not
	 * "3,0").
	 */
	private function format_garan_years( float $years ): string {
		if ( floor( $years ) === $years ) {
			return (string) (int) $years;
		}

		return str_replace( '.', ',', number_format( $years, 1 ) );
	}

	/**
	 * §3.3.1 of the guidelines requires a nested display to "appear in its
	 * entirety" on click/hover/tap — this is what the <details> wrapper in
	 * render_garan_badge() satisfies. A merchant-supplied image (already
	 * compliant — the Commission expects producers to fill in Brand/Model via
	 * their own design software, not an automated web tool) takes priority;
	 * otherwise this falls back to our own template render with duration and
	 * any entered Brand/Model substituted directly into it (see garan_svg()).
	 */
	private function garan_full_label_content( WC_Product $product, float $years ): string {
		$image_id = absint( $product->get_meta( '_romcommerce_garan_image_id', true ) );

		if ( $image_id > 0 ) {
			$image_url = wp_get_attachment_image_url( $image_id, 'medium' );

			if ( $image_url ) {
				return '<img src="' . esc_url( $image_url ) . '" alt="' . esc_attr__( 'Eticheta EU GARAN', 'romcommerce' ) . '">';
			}
		}

		$brand = $this->garan_brand( $product );
		$model = trim( (string) $product->get_meta( '_romcommerce_garan_model', true ) );

		return $this->garan_svg( 'garan-full-label.svg', $years, $brand, $model );
	}

	/**
	 * Brand/Trademark and Model identifier are each split across several
	 * kerned <tspan>s in the source file (not one clean node like duration's
	 * "XX"), so the match string below is the exact original sequence — taken
	 * from the reference plugin's own source, which substitutes the same way.
	 */
	private function garan_svg( string $filename, float $years, string $brand = '', string $model = '' ): string {
		static $instance = 0;

		$path = ROMCOMMERCE_DIR . '/assets/images/' . $filename;

		if ( ! is_readable( $path ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin asset (not a remote URL).
		$svg = (string) file_get_contents( $path );

		if ( '' === $svg ) {
			return '';
		}

		if ( '' !== $brand ) {
			$svg = str_replace(
				'<tspan x="0" y="0">Brand/</tspan><tspan class="cls-19" x="28.34" y="0">T</tspan><tspan class="cls-22" x="33.61" y="0">rademark</tspan>',
				'<tspan x="0" y="0">' . esc_html( $brand ) . '</tspan>',
				$svg
			);
		}

		if ( '' !== $model ) {
			$svg = str_replace( '<tspan x="0" y="0">Model identifier</tspan>', '<tspan x="0" y="0">' . esc_html( $model ) . '</tspan>', $svg );
		}

		$svg = str_replace( '<tspan x="0" y="0">XX</tspan>', '<tspan x="0" y="0">' . esc_html( $this->format_garan_years( $years ) ) . '</tspan>', $svg );

		// Namespace every render — see the class docblock for why: unscoped
		// "cls-N" classes and clip-path ids collide across instances of this
		// markup on the same page.
		++$instance;
		$suffix = '-rc' . $instance;
		$svg    = (string) preg_replace( '/\bid="([a-zA-Z0-9_-]+)"/', 'id="$1' . $suffix . '"', $svg );
		$svg    = (string) preg_replace( '/url\(#([a-zA-Z0-9_-]+)\)/', 'url(#$1' . $suffix . ')', $svg );
		$svg    = (string) preg_replace( '/\bcls-(\d+)\b/', 'cls-$1' . $suffix, $svg );

		return $svg;
	}

	/**
	 * Lives inside the native WooCommerce "Product data" box (own tab) rather
	 * than a separate sidebar meta box, alongside the rest of the product's
	 * editing surface.
	 */
	public function add_garan_product_tab( array $tabs ): array {
		$tabs['romcommerce_garan'] = array(
			'label'    => __( 'EU GARAN', 'romcommerce' ),
			'target'   => 'romcommerce_garan_panel',
			'class'    => array(),
			'priority' => 85,
		);

		return $tabs;
	}

	/**
	 * No separate enable checkbox — the years value itself is the gate
	 * (render_garan_badge() shows the label once it's >= GARAN_MIN_YEARS),
	 * matching the reference plugin's single-field model. Uses WooCommerce's
	 * own woocommerce_wp_text_input() helper rather than hand-rolled markup —
	 * it lays out correctly inside the Product Data box's .options_group CSS
	 * and auto-reads/writes via get_post_meta() using the field id as the
	 * meta key, so no manual value-fetching is needed here.
	 */
	public function render_garan_product_panel(): void {
		global $post;

		if ( ! $post ) {
			return;
		}

		$product   = wc_get_product( $post->ID );
		$image_id  = $product instanceof WC_Product ? absint( $product->get_meta( '_romcommerce_garan_image_id', true ) ) : 0;
		$image_url = $image_id > 0 ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';

		wp_nonce_field( 'romcommerce_garan_save', 'romcommerce_garan_nonce' );

		echo '<div id="romcommerce_garan_panel" class="panel woocommerce_options_panel">';
		echo '<div class="options_group">';

		woocommerce_wp_text_input(
			array(
				'id'                => '_romcommerce_garan_years',
				'label'             => __( 'Guarantee duration (years)', 'romcommerce' ),
				'desc_tip'          => true,
				'description'       => __( 'Only for a voluntary producer durability guarantee that is free of charge and covers the entire product. The EU GARAN label shows automatically on this product\'s page once this is 2.5 years or more; leave blank or below 2.5 to not show it. Half-year values (2.5, 3.5…) are allowed; anything else is rounded to the nearest half. RomCommerce does not verify eligibility.', 'romcommerce' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '2.5',
					'step' => '0.5',
				),
			)
		);

		if ( taxonomy_exists( 'product_brand' ) ) {
			woocommerce_wp_checkbox(
				array(
					'id'          => '_romcommerce_garan_brand_use_woo',
					'label'       => __( 'Use WooCommerce brand', 'romcommerce' ),
					'desc_tip'    => true,
					'description' => __( 'Use this product\'s assigned WooCommerce Brand instead of the field below.', 'romcommerce' ),
				)
			);
		}

		woocommerce_wp_text_input(
			array(
				'id'          => '_romcommerce_garan_brand',
				'label'       => __( 'Brand / Trademark (optional)', 'romcommerce' ),
				'desc_tip'    => true,
				'description' => __( 'Filled in directly on the official label graphic in place of this field\'s caption. Ignored if "Use WooCommerce brand" above is checked, or if a full label image is uploaded below.', 'romcommerce' ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => '_romcommerce_garan_model',
				'label'       => __( 'Model identifier (optional)', 'romcommerce' ),
				'desc_tip'    => true,
				'description' => __( 'Filled in directly on the official label graphic in place of this field\'s caption. Ignored if a full label image is uploaded below.', 'romcommerce' ),
			)
		);

		echo '<p class="form-field">';
		echo '<label for="romcommerce_garan_image_button">' . esc_html__( 'Full label image (optional)', 'romcommerce' ) . '</label>';
		echo '<span id="romcommerce_garan_image_preview" style="display:block;margin:4px 0;">';
		if ( $image_url ) {
			echo '<img src="' . esc_url( $image_url ) . '" style="max-width:120px;height:auto;display:block;">';
		}
		echo '</span>';
		echo '<input type="hidden" id="romcommerce_garan_image_id" name="_romcommerce_garan_image_id" value="' . esc_attr( (string) $image_id ) . '">';
		echo '<button type="button" class="button" id="romcommerce_garan_image_button">' . esc_html__( 'Select image', 'romcommerce' ) . '</button> ';
		echo '<button type="button" class="button" id="romcommerce_garan_image_remove"' . ( $image_id > 0 ? '' : ' style="display:none;"' ) . '>' . esc_html__( 'Remove', 'romcommerce' ) . '</button>';
		echo '<span class="description" style="display:block;margin-top:6px;">' . esc_html__( 'A producer-supplied, already-compliant full EU GARAN label image (brand/model/duration already filled in). When set, this is shown instead of our auto-generated version when a shopper expands the badge.', 'romcommerce' ) . '</span>';
		echo '</p>';

		echo '</div>';
		echo '</div>';
		echo '<script>' . $this->garan_image_picker_script_js() . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static, self-contained inline JS; the one interpolated string is wp_json_encode()'d.
	}

	private function garan_image_picker_script_js(): string {
		return '(function(){'
			. 'var btn=document.getElementById("romcommerce_garan_image_button");'
			. 'var removeBtn=document.getElementById("romcommerce_garan_image_remove");'
			. 'var input=document.getElementById("romcommerce_garan_image_id");'
			. 'var preview=document.getElementById("romcommerce_garan_image_preview");'
			. 'if(!btn||!input||!preview||typeof wp==="undefined"||!wp.media){return;}'
			. 'var frame;'
			. 'btn.addEventListener("click",function(e){'
			. 'e.preventDefault();'
			. 'if(frame){frame.open();return;}'
			. 'frame=wp.media({title:' . wp_json_encode( __( 'Select GARAN label image', 'romcommerce' ) ) . ',multiple:false});'
			. 'frame.on("select",function(){'
			. 'var a=frame.state().get("selection").first().toJSON();'
			. 'input.value=a.id;'
			. 'preview.innerHTML="<img src=\""+a.url+"\" style=\"max-width:120px;height:auto;display:block;\">";'
			. 'if(removeBtn){removeBtn.style.display="";}'
			. '});'
			. 'frame.open();'
			. '});'
			. 'if(removeBtn){removeBtn.addEventListener("click",function(e){'
			. 'e.preventDefault();'
			. 'input.value="";'
			. 'preview.innerHTML="";'
			. 'removeBtn.style.display="none";'
			. '});}'
			. '})();';
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

		$years         = isset( $_POST['_romcommerce_garan_years'] ) ? $this->sanitize_garan_years( wp_unslash( $_POST['_romcommerce_garan_years'] ) ) : 0.0;
		$brand         = isset( $_POST['_romcommerce_garan_brand'] ) ? sanitize_text_field( wp_unslash( $_POST['_romcommerce_garan_brand'] ) ) : '';
		$brand_use_woo = isset( $_POST['_romcommerce_garan_brand_use_woo'] );
		$model         = isset( $_POST['_romcommerce_garan_model'] ) ? sanitize_text_field( wp_unslash( $_POST['_romcommerce_garan_model'] ) ) : '';
		$image_id      = isset( $_POST['_romcommerce_garan_image_id'] ) ? absint( wp_unslash( $_POST['_romcommerce_garan_image_id'] ) ) : 0;

		$product->update_meta_data( '_romcommerce_garan_years', (string) $years );
		$product->update_meta_data( '_romcommerce_garan_brand', $brand );
		$product->update_meta_data( '_romcommerce_garan_brand_use_woo', $brand_use_woo ? 'yes' : 'no' );
		$product->update_meta_data( '_romcommerce_garan_model', $model );
		$product->update_meta_data( '_romcommerce_garan_image_id', (string) $image_id );
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

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$years = $this->garan_years( $product );

			if ( $years < self::GARAN_MIN_YEARS ) {
				continue;
			}

			$text = sprintf(
				/* translators: 1: product name, 2: guarantee duration in years, e.g. "3" or "2,5" */
				__( '%1$s beneficiază de eticheta EU GARAN — garanție de producător pe durabilitate, gratuită, %2$s ani.', 'romcommerce' ),
				$product->get_name(),
				$this->format_garan_years( $years )
			);

			$brand = $this->garan_brand( $product );
			$model = trim( (string) $product->get_meta( '_romcommerce_garan_model', true ) );

			if ( '' !== $brand ) {
				/* translators: %s: brand or trademark name */
				$text .= ' ' . sprintf( __( 'Brand/Marcă: %s.', 'romcommerce' ), $brand );
			}

			if ( '' !== $model ) {
				/* translators: %s: model identifier */
				$text .= ' ' . sprintf( __( 'Model: %s.', 'romcommerce' ), $model );
			}

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

	private function render_law_link( string $url, string $citation ): void {
		echo ' <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'More info', 'romcommerce' ) . ' ↗ ' . esc_html( $citation ) . '</a>';
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
						'show_on_checkout'          => isset( $_POST['romcommerce_lg_checkout'] ),
						'show_on_product_page'      => isset( $_POST['romcommerce_lg_product_page'] ),
						'excluded_terms'            => isset( $_POST['romcommerce_lg_excluded_terms'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['romcommerce_lg_excluded_terms'] ) ) : array(),
						'alignment'                 => in_array( $alignment, self::ALIGNMENTS, true ) ? $alignment : 'left',
						'garan_enabled'             => isset( $_POST['romcommerce_lg_garan_enabled'] ),
						'page_id'                   => $this->save_page_id( absint( wp_unslash( $_POST['romcommerce_lg_page_id'] ?? 0 ) ) ),
						'trigger_text'              => isset( $_POST['romcommerce_lg_trigger_text'] ) ? sanitize_text_field( wp_unslash( $_POST['romcommerce_lg_trigger_text'] ) ) : '',
						'trigger_recommended_color' => isset( $_POST['romcommerce_lg_recommended_color'] ),
					)
				);

				echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing
		}

		$enabled              = $this->is_enabled();
		$show_on_checkout     = $this->show_on_checkout();
		$show_on_product_page = $this->show_on_product_page();
		$excluded_terms       = $this->excluded_terms();
		$alignment            = $this->alignment();
		$garan_enabled        = $this->garan_enabled();
		$page_id              = (int) ( Settings::get( self::ID )['page_id'] ?? 0 );
		$trigger_text         = $this->trigger_text();
		$recommended_color    = $this->trigger_recommended_color();

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Show notice', 'romcommerce' ) . '</th>';
		echo '<td><label><input type="checkbox" name="romcommerce_lg_enabled" value="1"' . checked( $enabled, true, false ) . '> ';
		echo esc_html__( 'Show the EU legal-guarantee notice sitewide. Mandatory EU-wide from 27 September 2026.', 'romcommerce' ) . '</label>';
		$this->render_law_link( self::NOTICE_LAW_URL, __( 'Directive (EU) 2024/825', 'romcommerce' ) );
		echo '</td></tr>';

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
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: the [romcommerce_legal_guarantee_notice] shortcode */
				__( 'Every notice link points here. Picking a page (new or existing) adds the %s shortcode to it automatically if it\'s missing.', 'romcommerce' ),
				'[romcommerce_legal_guarantee_notice]'
			)
		) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Link text', 'romcommerce' ) . '</th><td>';
		echo '<input type="text" name="romcommerce_lg_trigger_text" value="' . esc_attr( $trigger_text ) . '" class="regular-text">';
		echo '<p class="description">' . esc_html__( 'Shown on the footer, checkout, and product-page links.', 'romcommerce' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Extra placements', 'romcommerce' ) . '</th><td>';
		echo '<label style="display:block;"><input type="checkbox" name="romcommerce_lg_checkout" value="1"' . checked( $show_on_checkout, true, false ) . '> ' . esc_html__( 'Checkout page, above the terms and conditions checkbox', 'romcommerce' ) . '</label>';
		echo '<label style="display:block;"><input type="checkbox" name="romcommerce_lg_product_page" value="1"' . checked( $show_on_product_page, true, false ) . '> ' . esc_html__( 'Single product pages', 'romcommerce' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'The notice already appears in the footer and order emails. These add it to extra spots.', 'romcommerce' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Exclude products', 'romcommerce' ) . '</th><td>';
		$excluded_values = array_map(
			static function ( array $entry ): string {
				return $entry['taxonomy'] . ':' . $entry['term_id'];
			},
			$excluded_terms
		);
		echo '<select name="romcommerce_lg_excluded_terms[]" multiple class="wc-enhanced-select" style="width:100%;max-width:420px;" data-placeholder="' . esc_attr__( 'Search categories, tags, brands…', 'romcommerce' ) . '">';
		foreach ( get_object_taxonomies( 'product', 'objects' ) as $taxonomy ) {
			if ( in_array( $taxonomy->name, array( 'product_type', 'product_visibility' ), true ) ) {
				continue;
			}

			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy->name,
					'hide_empty' => false,
				)
			);

			if ( is_wp_error( $terms ) || array() === $terms ) {
				continue;
			}

			echo '<optgroup label="' . esc_attr( $taxonomy->label ) . '">';
			foreach ( $terms as $term ) {
				$value = $taxonomy->name . ':' . $term->term_id;
				echo '<option value="' . esc_attr( $value ) . '"' . selected( in_array( $value, $excluded_values, true ), true, false ) . '>' . esc_html( $term->name ) . '</option>';
			}
			echo '</optgroup>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Products in these terms skip the product-page link (any taxonomy, not just categories). The footer reminder is unaffected. To exclude a single product without excluding its whole category, use the checkbox on that product\'s General tab.', 'romcommerce' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Alignment', 'romcommerce' ) . '</th><td>';
		$labels = array(
			'left'   => __( 'Left', 'romcommerce' ),
			'center' => __( 'Center', 'romcommerce' ),
			'right'  => __( 'Right', 'romcommerce' ),
			'none'   => __( 'None — I\'ll add the link myself', 'romcommerce' ),
		);
		foreach ( $labels as $value => $label ) {
			echo '<label style="margin-right:16px;"><input type="radio" name="romcommerce_lg_alignment" value="' . esc_attr( $value ) . '"' . checked( $alignment, $value, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Where the footer link sits. "None" skips the automatic footer link; other placements still work.', 'romcommerce' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Link color', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="romcommerce_lg_recommended_color" value="1"' . checked( $recommended_color, true, false ) . '> ' . esc_html__( 'Use recommended color', 'romcommerce' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Unchecked, the link inherits your theme\'s own link color.', 'romcommerce' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'EU GARAN label', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="romcommerce_lg_garan_enabled" value="1"' . checked( $garan_enabled, true, false ) . '> ' . esc_html__( 'Enable for products with a qualifying producer durability guarantee', 'romcommerce' ) . '</label>';
		$this->render_law_link( self::GARAN_LAW_URL, __( 'Reg. (EU) 2025/1960', 'romcommerce' ) );
		echo '<p class="description">' . esc_html__( 'Adds an EU GARAN tab to each product\'s edit screen. The label shows once that product\'s guarantee duration is 2.5 years or more. Separate from the notice above, and optional.', 'romcommerce' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';
	}

	/**
	 * A merchant picking an existing page from the dropdown (rather than the
	 * "Create notice page" button, which writes the shortcode into a page it
	 * creates itself) would otherwise link every trigger at a page with no
	 * shortcode on it — see ensure_shortcode_on_page(). Returns $page_id
	 * unchanged; this is a save-time side effect, not a transform.
	 */
	private function save_page_id( int $page_id ): int {
		if ( $page_id > 0 ) {
			$this->ensure_shortcode_on_page( $page_id );
		}

		return $page_id;
	}

	/**
	 * Idempotent (checked via has_shortcode()), so this is safe to call on
	 * every save regardless of whether the page selection actually changed,
	 * and won't duplicate the shortcode if a merchant already added it by hand.
	 */
	private function ensure_shortcode_on_page( int $page_id ): void {
		$page = get_post( $page_id );

		if ( ! $page instanceof WP_Post || 'page' !== $page->post_type ) {
			return;
		}

		if ( has_shortcode( $page->post_content, 'romcommerce_legal_guarantee_notice' ) ) {
			return;
		}

		wp_update_post(
			array(
				'ID'           => $page_id,
				'post_content' => trim( $page->post_content . "\n\n[romcommerce_legal_guarantee_notice]" ),
			)
		);
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
