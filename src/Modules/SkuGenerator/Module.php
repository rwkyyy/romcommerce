<?php

declare(strict_types=1);

namespace RomCommerce\Modules\SkuGenerator;

use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * SKUs for products and variations saved without one: a prefix plus the
 * product ID, its name or a running number; variations add their attribute
 * values or ID to the parent's SKU. Existing products are filled in from the
 * pane (Backfill). A SKU already set, typed or generated, is never changed.
 *
 * Generation hooks only the places where products are edited: the product
 * editor, the REST API (which the new product editor and `wp wc` use) and the
 * CSV importer. Not a generic save hook: WooCommerce saves the product when
 * an order reduces its stock, and that runs on customer checkout requests.
 */
final class Module implements ModuleInterface, HasSettingsUi {

	private const ID = 'sku-generator';

	/** @var Generator */
	private $generator;

	/** @var Backfill */
	private $backfill;

	public function __construct() {
		$this->generator = new Generator( self::ID );
		$this->backfill  = new Backfill( $this->generator );
	}

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'SKU Generator', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		// Both admin hooks fire before WooCommerce saves the object, so the SKU
		// goes in with that save.
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'on_admin_product' ) );
		add_action( 'woocommerce_admin_process_variation_object', array( $this, 'on_admin_variation' ) );

		// These fire after the save, once a new product has its ID.
		add_action( 'woocommerce_rest_insert_product_object', array( $this, 'on_saved' ) );
		add_action( 'woocommerce_rest_insert_product_variation_object', array( $this, 'on_saved' ) );
		add_action( 'woocommerce_product_import_inserted_product_object', array( $this, 'on_saved' ) );

		$this->backfill->register();
	}

	public function backfill(): Backfill {
		return $this->backfill;
	}

	/**
	 * Variations added in the editor are saved by AJAX before the product
	 * itself, so a new product's variations wait for this to get its SKU.
	 *
	 * @param WC_Product|mixed $product
	 */
	public function on_admin_product( $product ): void {
		if ( ! $product instanceof WC_Product || ! $this->generator->settings()['auto'] ) {
			return;
		}

		$this->generator->assign( $product );
		$this->generator->fill_variations( $product );
	}

	/** @param WC_Product|mixed $variation */
	public function on_admin_variation( $variation ): void {
		if ( $variation instanceof WC_Product && $this->generator->settings()['auto'] ) {
			$this->generator->assign( $variation );
		}
	}

	/** @param WC_Product|mixed $product */
	public function on_saved( $product ): void {
		if ( ! $product instanceof WC_Product || ! $this->generator->settings()['auto'] ) {
			return;
		}

		if ( $this->generator->assign( $product ) ) {
			$product->save();
		}
		$this->generator->fill_variations( $product );
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// SettingsForm::verify() has confirmed the nonce and the manage_woocommerce
			// capability on the line above; the $_POST reads live here inside that guard
			// (not in the handlers) so the authorization check is local to the input.
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above via SettingsForm::verify().
			if ( isset( $_POST['rc_fill'] ) ) {
				$this->backfill->start();
			} else {
				$this->handle_save(
					isset( $_POST['rc_enabled'] ),
					array(
						'auto'        => isset( $_POST['rc_auto'] ),
						'prefix'      => Generator::sanitize_prefix( sanitize_text_field( wp_unslash( $_POST['rc_prefix'] ?? '' ) ) ),
						'base'        => sanitize_key( wp_unslash( $_POST['rc_base'] ?? '' ) ),
						'digits'      => absint( wp_unslash( $_POST['rc_digits'] ?? 0 ) ),
						'next_number' => absint( wp_unslash( $_POST['rc_next_number'] ?? 1 ) ),
						'variations'  => sanitize_key( wp_unslash( $_POST['rc_variations'] ?? '' ) ),
						'separator'   => sanitize_text_field( wp_unslash( $_POST['rc_separator'] ?? '' ) ),
						'uppercase'   => isset( $_POST['rc_uppercase'] ),
					)
				);
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing
		}

		$settings = $this->generator->settings();
		$example  = $this->generator->example();

		echo '<p>' . esc_html__( 'Gives a SKU to every product and variation saved without one, built from a prefix and the product ID, its name or a running number. A SKU that is already set, typed or generated, is never changed.', 'romcommerce' ) . '</p>';

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Module', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ';
		echo esc_html__( 'Enable', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Automatic', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_auto" value="1"' . checked( $settings['auto'], true, false ) . '> ';
		echo esc_html__( 'Give a SKU to products saved without one', 'romcommerce' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'In the product editor, through the REST API and in CSV imports. Switch it off to fill in SKUs only with the button below.', 'romcommerce' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="rc-sku-prefix">' . esc_html__( 'Prefix', 'romcommerce' ) . '</label></th><td>';
		echo '<input type="text" id="rc-sku-prefix" name="rc_prefix" value="' . esc_attr( $settings['prefix'] ) . '" maxlength="20" class="regular-text" placeholder="' . esc_attr__( 'e.g. RC-', 'romcommerce' ) . '">';
		echo '<p class="description">' . esc_html__( 'Letters, digits and - _ . / only. Leave blank for none.', 'romcommerce' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="rc-sku-base">' . esc_html__( 'Built from', 'romcommerce' ) . '</label></th><td>';
		$this->select(
			'rc_base',
			'rc-sku-base',
			array(
				'id'       => __( 'Product ID', 'romcommerce' ),
				'slug'     => __( 'Product name', 'romcommerce' ),
				'sequence' => __( 'Running number', 'romcommerce' ),
			),
			$settings['base']
		);
		echo '<p class="description">' . esc_html__( 'Product name uses the product\'s URL slug, or its name while it is a draft.', 'romcommerce' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="rc-sku-digits">' . esc_html__( 'Minimum digits', 'romcommerce' ) . '</label></th><td>';
		echo '<input type="number" id="rc-sku-digits" name="rc_digits" value="' . esc_attr( (string) $settings['digits'] ) . '" min="0" max="10" step="1" class="small-text">';
		echo '<p class="description">' . esc_html__( 'Pads the product ID or running number with leading zeros: 5 turns 42 into 00042. 0 for none.', 'romcommerce' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="rc-sku-next">' . esc_html__( 'Next running number', 'romcommerce' ) . '</label></th><td>';
		echo '<input type="number" id="rc-sku-next" name="rc_next_number" value="' . esc_attr( (string) $settings['next_number'] ) . '" min="1" step="1" class="small-text">';
		echo '<p class="description">' . esc_html__( 'Only for "Running number". Goes up by one with each SKU; numbers already used by another product are skipped.', 'romcommerce' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="rc-sku-variations">' . esc_html__( 'Variations', 'romcommerce' ) . '</label></th><td>';
		$this->select(
			'rc_variations',
			'rc-sku-variations',
			array(
				'attributes' => __( 'Parent SKU and attribute values', 'romcommerce' ),
				'id'         => __( 'Parent SKU and variation ID', 'romcommerce' ),
				'none'       => __( 'No SKU (variations show the parent\'s)', 'romcommerce' ),
			),
			$settings['variations']
		);
		echo '<p class="description">' . esc_html__( 'A variation set to "Any" for every attribute gets its variation ID instead.', 'romcommerce' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="rc-sku-separator">' . esc_html__( 'Separator', 'romcommerce' ) . '</label></th><td>';
		$this->select(
			'rc_separator',
			'rc-sku-separator',
			array(
				'-' => '-',
				'_' => '_',
				'.' => '.',
				'/' => '/',
			),
			$settings['separator']
		);
		echo '<p class="description">' . esc_html__( 'Between the parent SKU and the variation\'s part, and between attribute values.', 'romcommerce' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Capital letters', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_uppercase" value="1"' . checked( $settings['uppercase'], true, false ) . '> ';
		echo esc_html__( 'Write product names and attribute values in capitals', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Example', 'romcommerce' ) . '</th><td>';
		echo '<code>' . esc_html( $example['product'] ) . '</code>';
		if ( '' !== $example['variation'] ) {
			/* translators: %s: example variation SKU, such as "RC-42-ROSU-M" */
			echo '<p class="description">' . esc_html( sprintf( __( 'A variation: %s', 'romcommerce' ), $example['variation'] ) ) . '</p>';
		}
		echo '<p class="description">' . esc_html__( 'Product 42, "Tricou bumbac", with a Roșu / M variation. Updates when you save.', 'romcommerce' ) . '</p></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );

		$this->render_fill_section();

		echo '</form>';
	}

	private function render_fill_section(): void {
		echo '<div class="rc-cat-head"><span class="rc-tile"></span><h3>' . esc_html__( 'Existing products', 'romcommerce' ) . '</h3><span class="rc-rule"></span></div>';

		if ( ! $this->is_enabled() ) {
			echo '<p class="description">' . esc_html__( 'Enable the module and save to fill in SKUs on existing products.', 'romcommerce' ) . '</p>';
			return;
		}

		$state   = $this->backfill->state();
		$missing = $this->backfill->count_missing();

		if ( $state['running'] ) {
			/* translators: %d: number of products and variations given a SKU so far */
			echo '<div class="notice notice-info inline"><p>' . esc_html( sprintf( __( 'Filling in SKUs in the background, %d given so far.', 'romcommerce' ), $state['filled'] ) ) . '</p></div>';
		}

		/* translators: %d: number of products and variations without a SKU */
		echo '<p>' . esc_html( sprintf( _n( '%d product or variation has no SKU.', '%d products and variations have no SKU.', $missing, 'romcommerce' ), $missing ) ) . '</p>';

		if ( 0 === $missing && ! $state['running'] ) {
			return;
		}

		echo '<p><button type="submit" name="rc_fill" value="1" class="button button-secondary">' . esc_html( $state['running'] ? __( 'Continue now', 'romcommerce' ) : __( 'Fill in missing SKUs', 'romcommerce' ) ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'Uses the saved settings above, so save any change first. The first products are done right away and the rest in the background; open this page again to see the progress. Products that can\'t get a SKU, such as variations whose parent has none, are left as they are.', 'romcommerce' ) . '</p>';
	}

	/**
	 * @param array{auto: bool, prefix: string, base: string, digits: int, next_number: int, variations: string, separator: string, uppercase: bool} $settings Sanitized; unknown choices fall back to the defaults.
	 */
	private function handle_save( bool $enabled, array $settings ): void {
		Settings::set_enabled( self::ID, $enabled );
		Settings::update(
			self::ID,
			array(
				'auto'        => $settings['auto'],
				'prefix'      => $settings['prefix'],
				'base'        => in_array( $settings['base'], Generator::BASES, true ) ? $settings['base'] : 'id',
				'digits'      => min( 10, $settings['digits'] ),
				'next_number' => max( 1, $settings['next_number'] ),
				'variations'  => in_array( $settings['variations'], Generator::VARIATION_MODES, true ) ? $settings['variations'] : 'attributes',
				'separator'   => in_array( $settings['separator'], Generator::SEPARATORS, true ) ? $settings['separator'] : '-',
				'uppercase'   => $settings['uppercase'],
			)
		);

		echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
	}

	/** @param array<string, string> $choices */
	private function select( string $name, string $id, array $choices, string $current ): void {
		echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '">';
		foreach ( $choices as $value => $label ) {
			echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( $current, (string) $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}
}
