<?php

declare(strict_types=1);

namespace RomCommerce\Modules\FrequentlyBoughtTogether;

use RomCommerce\Admin\ProSection;
use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasPlacements;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;
use RomCommerce\Support\Elementor;
use RomCommerce\Support\Placement;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * "Frequently bought together" (Lite: picked by hand per product; Pro computes
 * the sets from the store's orders). The merchant picks up to four products
 * or variations under Linked Products; the product page shows them with the
 * current product as a bundle: a checkbox each, the combined price, and one
 * button that adds everything ticked to the cart. No bundle discount (that
 * belongs to promotion rules).
 *
 * Its own product meta, never WooCommerce's upsell/cross-sell lists, so the
 * two never fight. Only items that can be bought right now are shown: in
 * stock, purchasable, and for variations, every attribute set.
 *
 * Page builders that replace WooCommerce's product template (Elementor Pro's
 * Theme Builder) never run most product hooks, so the bundle also has a
 * shortcode and falls back to the bottom of the product page; it renders at
 * most once per product per page whichever path reaches it first.
 */
final class Module implements ModuleInterface, HasSettingsUi, HasPlacements {

	private const ID = 'frequently-bought-together';

	public const META_KEY = '_romcommerce_fbt_ids';

	private const FIELD = 'romcommerce_fbt_ids';

	private const MAX_PICKS = 4;

	public const SHORTCODE = 'romcommerce_bought_together';

	/**
	 * Slots never offered: the two inside the add-to-cart form (the bundle is
	 * a form of its own, and forms can't nest) and the two inside the product
	 * meta line, which themes style as small inline text.
	 */
	private const EXCLUDED_SLOTS = array( 'product_before_button', 'add_to_cart', 'product_meta_start', 'product_meta_end' );

	/** The fallback runs late on the bottom slot, after anything else placed there. */
	private const FALLBACK_PRIORITY = 99;

	/** @var array<int, true> Product ids whose bundle this request has already printed. */
	private array $rendered = array();

	private bool $assets_added = false;

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Frequently Bought Together', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		add_action( 'woocommerce_product_options_related', array( $this, 'render_product_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product_field' ) );
		// wp_loaded, as WooCommerce's own add-to-cart form handler: the cart and
		// session are loaded and nothing has been sent yet, so it can redirect.
		add_action( 'wp_loaded', array( $this, 'handle_add' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
		// Placement hooks wire on init, not here — see HasPlacements.
		add_action( 'init', array( $this, 'register_placements' ) );
	}

	/**
	 * The chosen slot, plus the bottom of the product page as a fallback for
	 * templates that never reach it. The fallback is woocommerce_after_single_product,
	 * which classic templates, block themes and Elementor Pro's product
	 * templates all fire; render() skips it once the bundle is out. The
	 * merchant can switch the fallback off (on by default).
	 */
	public function register_placements(): void {
		$fallback = $this->fallback_enabled();

		foreach ( $this->placements() as $placement ) {
			Placement::hook( $placement['slot'], array( $this, 'render' ), $placement['priority'] ?? null );

			if ( $fallback && 'product_bottom' !== $placement['slot'] ) {
				Placement::hook( 'product_bottom', array( $this, 'render' ), self::FALLBACK_PRIORITY );
			}
		}
	}

	/**
	 * Below the summary by default; see EXCLUDED_SLOTS for the spots not offered.
	 *
	 * @return array<int, array{key: string, slot: string, active: bool, choices: array<int, string>}>
	 */
	public function placements(): array {
		return Placement::resolve(
			self::ID,
			array(
				array(
					'key'     => 'product',
					'slot'    => 'product_after_summary',
					'active'  => true,
					'choices' => array_values( array_diff( Placement::surface_slots( 'product' ), self::EXCLUDED_SLOTS ) ),
				),
			)
		);
	}

	public function render_product_field(): void {
		global $thepostid;

		$product_id = (int) $thepostid;

		echo '<p class="form-field"><label for="' . esc_attr( self::FIELD ) . '">' . esc_html__( 'Frequently bought together', 'romcommerce' ) . '</label>';
		echo '<select class="wc-product-search" multiple="multiple" style="width:50%;" id="' . esc_attr( self::FIELD ) . '" name="' . esc_attr( self::FIELD ) . '[]" data-placeholder="' . esc_attr__( 'Search for a product…', 'romcommerce' ) . '" data-action="woocommerce_json_search_products_and_variations" data-exclude="' . esc_attr( (string) $product_id ) . '" data-limit="30">';
		foreach ( self::stored_ids( $product_id ) as $id ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				echo '<option value="' . esc_attr( (string) $id ) . '" selected="selected">' . esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ) . '</option>';
			}
		}
		echo '</select> ';
		echo wc_help_tip( __( 'Up to four products or variations shown with this one as a bundle on the product page, with one button to add them all to the cart. Pick a specific variation for variable products.', 'romcommerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_help_tip() escapes its own output.
		echo '</p>';
	}

	/**
	 * WooCommerce verifies its own woocommerce_meta_nonce and the edit_product
	 * capability before firing this hook, so its $_POST is the checked form.
	 */
	public function save_product_field( WC_Product $product ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by WooCommerce before woocommerce_admin_process_product_object fires; see the docblock.
		$posted = isset( $_POST[ self::FIELD ] ) ? array_map( 'absint', (array) wp_unslash( $_POST[ self::FIELD ] ) ) : array();
		$ids    = self::clean_ids( $posted, (int) $product->get_id() );

		if ( array() === $ids ) {
			$product->delete_meta_data( self::META_KEY );
			return;
		}

		$product->update_meta_data( self::META_KEY, $ids );
	}

	/**
	 * Unique, positive, not the product itself, at most $limit, in the
	 * merchant's order.
	 *
	 * @param array<int, int> $ids
	 * @return array<int, int>
	 */
	public static function clean_ids( array $ids, int $product_id, int $limit = self::MAX_PICKS ): array {
		$clean = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 && $id !== $product_id && ! in_array( $id, $clean, true ) ) {
				$clean[] = $id;
			}
		}

		return array_slice( $clean, 0, $limit );
	}

	/** @return array<int, int> */
	private static function stored_ids( int $product_id ): array {
		$stored = get_post_meta( $product_id, self::META_KEY, true );

		return is_array( $stored ) ? self::clean_ids( array_map( 'intval', $stored ), $product_id ) : array();
	}

	/**
	 * The picks a shopper can buy right now.
	 *
	 * @return array<int, WC_Product>
	 */
	private function buyable_picks( WC_Product $product ): array {
		$picks = array();

		foreach ( self::stored_ids( (int) $product->get_id() ) as $id ) {
			$pick = wc_get_product( $id );
			if ( $pick instanceof WC_Product && self::buyable( $pick ) ) {
				$picks[] = $pick;
			}
		}

		return $picks;
	}

	/** In stock, purchasable, published, a simple product or a fully specified variation. */
	public static function buyable( WC_Product $product ): bool {
		if ( ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return false;
		}

		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( ! $parent || 'publish' !== $parent->get_status() ) {
				return false;
			}

			foreach ( wc_get_product_variation_attributes( (int) $product->get_id() ) as $value ) {
				if ( '' === (string) $value ) {
					return false;
				}
			}

			return true;
		}

		return $product->is_type( 'simple' ) && 'publish' === $product->get_status();
	}

	public function render(): void {
		global $product;

		if ( ! is_product() || ! $product instanceof WC_Product ) {
			return;
		}

		echo $this->bundle_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in bundle_html().
	}

	/**
	 * [romcommerce_bought_together] — the bundle wherever the merchant puts it,
	 * e.g. an Elementor Shortcode widget in a product template. Optional
	 * id="123" shows another product's bundle; otherwise the current product.
	 *
	 * @param array<string, mixed>|string $atts
	 */
	public function shortcode( $atts = array() ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), (array) $atts, self::SHORTCODE );
		$id   = absint( $atts['id'] );

		global $product;
		$target = $id > 0 ? wc_get_product( $id ) : ( $product instanceof WC_Product ? $product : wc_get_product( (int) get_the_ID() ) );

		if ( ! $target instanceof WC_Product ) {
			return '';
		}

		$this->add_assets();

		return $this->bundle_html( $target );
	}

	/** The bundle for $product, or '' when it has nothing to offer or is already on the page. */
	private function bundle_html( WC_Product $product ): string {
		$product_id = (int) $product->get_id();
		if ( isset( $this->rendered[ $product_id ] ) ) {
			return '';
		}

		$picks = $this->buyable_picks( $product );
		if ( array() === $picks ) {
			return '';
		}

		$this->rendered[ $product_id ] = true;

		ob_start();
		$this->print_bundle( $product, $picks );

		return (string) ob_get_clean();
	}

	/** @param array<int, WC_Product> $picks */
	private function print_bundle( WC_Product $product, array $picks ): void {
		$settings = Settings::get( self::ID );
		$current  = $product->is_type( 'variable' ) ? null : ( self::buyable( $product ) ? $product : false );
		$rows     = array();

		if ( null === $current ) {
			$rows[] = $this->row( $product, true );
		} elseif ( false !== $current ) {
			$rows[] = $this->row( $product, false );
		}
		foreach ( $picks as $pick ) {
			$rows[] = $this->row( $pick, false );
		}

		$heading = (string) ( $settings['heading'] ?? __( 'Cumpărate des împreună', 'romcommerce' ) );
		$button  = (string) ( $settings['button'] ?? '' );
		$button  = '' !== $button ? $button : __( 'Adaugă-le pe toate în coș', 'romcommerce' );

		echo '<section class="romcommerce-fbt" data-rc-fbt>';
		if ( '' !== $heading ) {
			echo '<h2 class="romcommerce-fbt-heading">' . esc_html( $heading ) . '</h2>';
		}
		echo '<form method="post" action="' . esc_url( (string) get_permalink( $product->get_id() ) ) . '">';
		echo '<input type="hidden" name="rc_fbt_product" value="' . esc_attr( (string) $product->get_id() ) . '">';

		if ( (bool) ( $settings['images'] ?? true ) ) {
			echo '<div class="romcommerce-fbt-images" aria-hidden="true">';
			foreach ( $rows as $index => $row ) {
				if ( $index > 0 ) {
					echo '<span class="romcommerce-fbt-plus">+</span>';
				}
				echo $row['image']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by WC_Product::get_image(), which escapes its own output.
			}
			echo '</div>';
		}

		echo '<ul class="romcommerce-fbt-list">';
		foreach ( $rows as $row ) {
			echo $row['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in row().
		}
		echo '</ul>';

		echo '<p class="romcommerce-fbt-total">' . esc_html__( 'Total:', 'romcommerce' ) . ' <strong data-rc-fbt-total aria-live="polite">' . wp_kses_post( wc_price( $this->checked_total( $rows ) ) ) . '</strong></p>';
		// wp-element-button: block themes style buttons through it; classic themes through .button.
		echo '<button type="submit" class="button alt wp-element-button romcommerce-fbt-button" name="rc_fbt_add" value="1">' . esc_html( $button ) . '</button>';
		echo '</form></section>';
	}

	/**
	 * @return array{html: string, image: string, price: float, checked: bool}
	 */
	private function row( WC_Product $item, bool $needs_variation ): array {
		$price = $needs_variation ? 0.0 : (float) wc_get_price_to_display( $item );
		$html  = '<li class="romcommerce-fbt-item"' . ( $needs_variation ? ' data-rc-fbt-current' : '' ) . '><label>';
		$html .= '<input type="checkbox" name="rc_fbt[]" value="' . esc_attr( $needs_variation ? '' : (string) $item->get_id() ) . '" data-price="' . esc_attr( (string) $price ) . '"' . ( $needs_variation ? ' disabled' : ' checked' ) . '> ';
		$html .= '<span class="romcommerce-fbt-name">' . esc_html( wp_strip_all_tags( $item->get_name() ) ) . '</span></label> ';

		if ( $needs_variation ) {
			$html .= '<span class="romcommerce-fbt-price romcommerce-fbt-hint">' . esc_html__( 'Alegeți o variantă mai sus', 'romcommerce' ) . '</span>';
		} else {
			$html .= '<span class="romcommerce-fbt-price">' . wp_kses_post( $item->get_price_html() ) . '</span>';
		}

		return array(
			'html'    => $html . '</li>',
			'image'   => $item->get_image( 'woocommerce_gallery_thumbnail' ),
			'price'   => $price,
			'checked' => ! $needs_variation,
		);
	}

	/** @param array<int, array{html: string, image: string, price: float, checked: bool}> $rows */
	private function checked_total( array $rows ): float {
		$total = 0.0;
		foreach ( $rows as $row ) {
			$total += $row['checked'] ? $row['price'] : 0.0;
		}

		return $total;
	}

	/**
	 * Adds every ticked item to the cart, then goes where WooCommerce's own
	 * Add to Cart would: the cart page, or back to the product. No nonce, as
	 * with WooCommerce's add-to-cart: it adds to the visitor's own cart only,
	 * and a nonce would break on cached product pages.
	 */
	public function handle_add(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- deliberately nonce-free like WooCommerce's add to cart; see the docblock.
		if ( ! isset( $_POST['rc_fbt_add'] ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$ids        = isset( $_POST['rc_fbt'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['rc_fbt'] ) ) : array();
		$product_id = isset( $_POST['rc_fbt_product'] ) ? absint( wp_unslash( $_POST['rc_fbt_product'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$added = $this->add_to_cart( $ids );

		if ( array() !== $added ) {
			wc_add_to_cart_message( $added, true );
		}

		$back = $product_id > 0 ? (string) get_permalink( $product_id ) : wc_get_page_permalink( 'shop' );
		wp_safe_redirect( array() !== $added && 'yes' === get_option( 'woocommerce_cart_redirect_after_add' ) ? wc_get_cart_url() : $back );
		exit;
	}

	/**
	 * Adds each buyable item once, through WooCommerce's own validation.
	 *
	 * @param array<int, int> $ids Products and variations.
	 * @return array<int, int> Product id => quantity added, as wc_add_to_cart_message() takes it.
	 */
	public function add_to_cart( array $ids ): array {
		$added = array();

		// The picks plus the product itself.
		foreach ( self::clean_ids( $ids, 0, self::MAX_PICKS + 1 ) as $id ) {
			$item = wc_get_product( $id );
			if ( ! $item instanceof WC_Product || ! self::buyable( $item ) ) {
				continue;
			}

			$parent_id    = $item->is_type( 'variation' ) ? (int) $item->get_parent_id() : $id;
			$variation_id = $item->is_type( 'variation' ) ? $id : 0;
			$attributes   = $variation_id > 0 ? wc_get_product_variation_attributes( $variation_id ) : array();

			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own check, applied as its add-to-cart form does.
			if ( ! apply_filters( 'woocommerce_add_to_cart_validation', true, $parent_id, 1, $variation_id, $attributes ) ) {
				continue;
			}

			if ( false !== WC()->cart->add_to_cart( $parent_id, 1, $variation_id, $attributes ) ) {
				$added[ $parent_id ] = ( $added[ $parent_id ] ?? 0 ) + 1;
			}
		}

		return $added;
	}

	public function enqueue_assets(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$this->add_assets();
	}

	/**
	 * Also called from the shortcode, which can run outside product pages
	 * and after wp_head: WordPress then prints the style and script in the
	 * footer. The flag keeps the inline code from being added twice.
	 */
	private function add_assets(): void {
		if ( $this->assets_added ) {
			return;
		}
		$this->assets_added = true;

		wp_register_style( 'romcommerce-fbt', false, array(), ROMCOMMERCE_VERSION );
		wp_enqueue_style( 'romcommerce-fbt' );
		wp_add_inline_style( 'romcommerce-fbt', $this->styles_css() );

		wp_register_script( 'romcommerce-fbt', false, array(), ROMCOMMERCE_VERSION, true );
		wp_enqueue_script( 'romcommerce-fbt' );
		wp_add_inline_script( 'romcommerce-fbt', 'window.romcommerceFbt=' . wp_json_encode( $this->price_format() ) . ';' . $this->script_js() );
	}

	/** @return array<string, mixed> WooCommerce's price format, for the live total. */
	private function price_format(): array {
		return array(
			'decimals' => wc_get_price_decimals(),
			'decimal'  => wc_get_price_decimal_separator(),
			'thousand' => wc_get_price_thousand_separator(),
			'format'   => html_entity_decode( get_woocommerce_price_format(), ENT_QUOTES ),
			'symbol'   => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES ),
		);
	}

	/**
	 * Keeps the total and the button in step with the ticks, and, for a
	 * variable product, lets the current product join the bundle once the
	 * shopper picks a variation in WooCommerce's form (jQuery events, as
	 * WooCommerce's variation script fires them).
	 */
	private function script_js(): string {
		return '(function(){'
			. 'var f=window.romcommerceFbt;'
			. 'function money(n){var p=n.toFixed(f.decimals).split(".");p[0]=p[0].replace(/\B(?=(\d{3})+(?!\d))/g,f.thousand);'
			. 'return f.format.split("%1$s").join(f.symbol).split("%2$s").join(p.join(f.decimal));}'
			. 'function update(box){var sum=0,any=false;'
			. 'box.querySelectorAll("input[name=\'rc_fbt[]\']").forEach(function(c){if(c.checked&&!c.disabled&&c.value){sum+=parseFloat(c.getAttribute("data-price"))||0;any=true;}});'
			. 'var t=box.querySelector("[data-rc-fbt-total]");if(t){t.textContent=money(sum);}'
			. 'var b=box.querySelector(".romcommerce-fbt-button");if(b){b.disabled=!any;}}'
			. 'document.addEventListener("change",function(e){var box=e.target.closest?e.target.closest("[data-rc-fbt]"):null;if(box){update(box);}});'
			. 'document.querySelectorAll("[data-rc-fbt]").forEach(update);'
			. 'if(window.jQuery){'
			. 'window.jQuery(document).on("found_variation",".variations_form",function(ev,v){'
			. 'document.querySelectorAll("[data-rc-fbt-current]").forEach(function(row){var c=row.querySelector("input");'
			. 'var ok=v&&v.variation_id&&v.is_purchasable&&v.is_in_stock;'
			. 'c.value=ok?v.variation_id:"";c.setAttribute("data-price",ok?v.display_price:0);c.disabled=!ok;c.checked=!!ok;'
			. 'var h=row.querySelector(".romcommerce-fbt-hint");if(h){h.hidden=!!ok;}'
			. 'update(row.closest("[data-rc-fbt]"));});});'
			. 'window.jQuery(document).on("reset_data",".variations_form",function(){'
			. 'document.querySelectorAll("[data-rc-fbt-current]").forEach(function(row){var c=row.querySelector("input");'
			. 'c.value="";c.disabled=true;c.checked=false;var h=row.querySelector(".romcommerce-fbt-hint");if(h){h.hidden=false;}'
			. 'update(row.closest("[data-rc-fbt]"));});});}'
			. '})();';
	}

	private function styles_css(): string {
		return '.romcommerce-fbt{margin:2em 0;clear:both;}'
			. '.romcommerce-fbt-heading{margin:0 0 .75em;}'
			. '.romcommerce-fbt-images{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:0 0 1em;}'
			. '.romcommerce-fbt-images img{width:80px;height:80px;object-fit:cover;margin:0;}'
			. '.romcommerce-fbt-plus{font-size:1.5em;opacity:.6;}'
			. '.romcommerce-fbt-list{list-style:none;margin:0 0 1em;padding:0;}'
			. '.romcommerce-fbt-item{display:flex;flex-wrap:wrap;justify-content:space-between;gap:4px 16px;padding:6px 0;border-bottom:1px solid rgba(0,0,0,.08);margin:0;}'
			. '.romcommerce-fbt-item label{display:flex;gap:8px;align-items:baseline;margin:0;}'
			. '.romcommerce-fbt-hint{font-size:.875em;opacity:.75;}'
			. '.romcommerce-fbt-total{margin:0 0 .75em;}'
			. '.romcommerce-fbt-button[disabled]{opacity:.5;cursor:not-allowed;}';
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above via SettingsForm::verify().
			Settings::set_enabled( self::ID, isset( $_POST['rc_enabled'] ) );
			Settings::update(
				self::ID,
				array(
					'heading'  => sanitize_text_field( wp_unslash( (string) ( $_POST['rc_heading'] ?? '' ) ) ),
					'button'   => sanitize_text_field( wp_unslash( (string) ( $_POST['rc_button'] ?? '' ) ) ),
					'images'   => isset( $_POST['rc_images'] ),
					'fallback' => isset( $_POST['rc_fallback'] ),
				)
			);
			$area = isset( $_POST['rc_area'] ) && is_array( $_POST['rc_area'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['rc_area'] ) ) : array();
			Placement::save_choices( self::ID, $this->placements(), $area );
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
		}

		$settings = Settings::get( self::ID );

		echo '<p>' . esc_html__( 'Shows products that go well together as a bundle on the product page: each with a checkbox, the combined price, and one button that adds them all to the cart. Pick them per product under Product data → Linked Products → Frequently bought together.', 'romcommerce' ) . '</p>';

		if ( $this->is_enabled() && ! $this->any_product_has_picks() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'No product has bundle products picked yet, so nothing shows on the storefront. Open a product, then Product data → Linked Products → Frequently bought together.', 'romcommerce' ) . '</p></div>';
		}

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Module', 'romcommerce' ) . '</th><td><label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ' . esc_html__( 'Enable', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row"><label for="rc-fbt-heading">' . esc_html__( 'Heading', 'romcommerce' ) . '</label></th><td><input type="text" id="rc-fbt-heading" class="regular-text" name="rc_heading" value="' . esc_attr( (string) ( $settings['heading'] ?? __( 'Cumpărate des împreună', 'romcommerce' ) ) ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="rc-fbt-button">' . esc_html__( 'Button', 'romcommerce' ) . '</label></th><td><input type="text" id="rc-fbt-button" class="regular-text" name="rc_button" value="' . esc_attr( (string) ( $settings['button'] ?? '' ) ) . '" placeholder="' . esc_attr__( 'Adaugă-le pe toate în coș', 'romcommerce' ) . '"></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Images', 'romcommerce' ) . '</th><td><label><input type="checkbox" name="rc_images" value="1"' . checked( (bool) ( $settings['images'] ?? true ), true, false ) . '> ' . esc_html__( 'Show the product images above the list', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row"><label for="rc-fbt-area">' . esc_html__( 'Position', 'romcommerce' ) . '</label></th><td>';
		Placement::area_field( 'rc_area[product]', Placement::find( $this->placements(), 'product' ), 'rc-fbt-area' );
		echo '<p><label><input type="checkbox" name="rc_fallback" value="1"' . checked( $this->fallback_enabled(), true, false ) . '> ' . esc_html__( 'If a product page never reaches this position, show the bundle at the bottom of the product page instead', 'romcommerce' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'To put it anywhere else, use the shortcode [romcommerce_bought_together].', 'romcommerce' ) . '</p>';
		if ( Elementor::active() ) {
			echo '<p class="description">' . esc_html__( 'Product pages built with Elementor Pro: choose Below the Add to Cart form (needs Elementor\'s Add to Cart widget in the template), or add an Elementor Shortcode widget with [romcommerce_bought_together] where you want the bundle.', 'romcommerce' ) . '</p>';
		}
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';

		ProSection::open( __( 'Bundles from your orders, with variations and options', 'romcommerce' ) );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Products', 'romcommerce' ) . '</th><td>';
		echo '<label style="margin-right:16px;"><input type="radio" disabled> ' . esc_html__( 'Picked by hand on each product', 'romcommerce' ) . '</label>';
		echo '<label><input type="radio" checked disabled> ' . esc_html__( 'Worked out from your orders, daily, weekly or monthly', 'romcommerce' ) . '</label>';
		echo '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Options in the bundle', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" checked disabled> ' . esc_html__( 'Shoppers pick each bundled product\'s variation and required options in the bundle itself', 'romcommerce' ) . '</label>';
		echo '</td></tr>';
		echo '</tbody></table>';
		ProSection::close();
	}

	private function fallback_enabled(): bool {
		return (bool) ( Settings::get( self::ID )['fallback'] ?? true );
	}

	/** One indexed lookup, admin pane only: whether the merchant has picked bundles anywhere yet. */
	private function any_product_has_picks(): bool {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_key'       => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- LIMIT 1 on an indexed meta_key, settings pane only.
			)
		);

		return array() !== $ids;
	}
}
