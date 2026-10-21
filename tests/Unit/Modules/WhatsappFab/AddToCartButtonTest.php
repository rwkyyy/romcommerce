<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\WhatsappFab;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use RomCommerce\Modules\WhatsappFab\Module;
use RomCommerce\Tests\Support\InvokesPrivateMethods;
use RomCommerce\Tests\TestCase;
use WC_Product;

/**
 * The WhatsApp button under Add to Cart: when it shows, what its link says,
 * and that existing FAB installs see no change until the merchant opts in.
 */
final class AddToCartButtonTest extends TestCase {

	use InvokesPrivateMethods;

	/** @var array<string, mixed> */
	private $options = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\stubEscapeFunctions();
		Functions\stubTranslationFunctions();
		Functions\when( 'get_option' )->alias(
			function ( string $name, $default = false ) {
				return $this->options[ $name ] ?? $default;
			}
		);
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'esc_url' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['product'] );
		parent::tearDown();
	}

	/** @param array<string, mixed> $settings */
	private function settings( array $settings ): void {
		$this->options['romcommerce_whatsapp_fab'] = $settings;
	}

	private function product( string $name = 'Produs test', int $id = 42 ): WC_Product {
		$product = Mockery::mock( WC_Product::class );
		$product->shouldReceive( 'get_name' )->andReturn( $name );
		$product->shouldReceive( 'get_id' )->andReturn( $id );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.ro/product/produs-test/' );

		return $product;
	}

	private function render(): string {
		ob_start();
		( new Module() )->render_atc_button();

		return (string) ob_get_clean();
	}

	/** @return array<string, mixed> */
	private function atc_placement( Module $module ): array {
		foreach ( $module->placements() as $placement ) {
			if ( 'atc' === $placement['key'] ) {
				return $placement;
			}
		}

		self::fail( 'No atc placement declared.' );
	}

	/**
	 * A store that set up the FAB before this button existed has no
	 * atc_button key at all: updating must not add a button to its product pages.
	 */
	public function test_an_existing_fab_install_gets_no_button_after_updating(): void {
		$this->settings(
			array(
				'channels' => array(
					'whatsapp' => array(
						'enabled' => true,
						'value'   => '40712345678',
					),
				),
			)
		);

		self::assertFalse( $this->atc_placement( new Module() )['active'] );
		self::assertSame( '', $this->render() );
	}

	public function test_the_button_needs_a_whatsapp_number(): void {
		$this->settings(
			array(
				'atc_button' => true,
				'channels'   => array( 'whatsapp' => array( 'value' => '  ' ) ),
			)
		);

		self::assertFalse( $this->atc_placement( new Module() )['active'] );
		self::assertSame( '', $this->render() );
	}

	public function test_a_number_with_only_punctuation_counts_as_missing(): void {
		$this->settings(
			array(
				'atc_button' => true,
				'channels'   => array( 'whatsapp' => array( 'value' => '+ ( ) -' ) ),
			)
		);

		self::assertFalse( $this->atc_placement( new Module() )['active'] );
	}

	/** The merchant may want the inline button without the floating hub. */
	public function test_the_button_shows_without_the_whatsapp_channel_enabled_in_the_fab(): void {
		$this->settings(
			array(
				'atc_button' => true,
				'channels'   => array(
					'whatsapp' => array(
						'enabled' => false,
						'value'   => '+40 712 345 678',
					),
				),
			)
		);
		$GLOBALS['product'] = $this->product();

		$module = new Module();
		self::assertTrue( $this->atc_placement( $module )['active'] );
		self::assertFalse( $module->placements()[0]['active'], 'The FAB itself stays off: no channel is enabled.' );

		$html = $this->render();
		self::assertStringContainsString( 'href="https://wa.me/40712345678?text=', $html );
		self::assertStringContainsString( 'data-rc-wa-number="40712345678"', $html );
	}

	public function test_the_message_names_the_product_and_links_to_it(): void {
		$this->settings(
			array(
				'atc_button' => true,
				'channels'   => array( 'whatsapp' => array( 'value' => '40712345678' ) ),
			)
		);
		$GLOBALS['product'] = $this->product( 'Rochie de vară' );

		$html = $this->render();

		self::assertStringContainsString( '?text=' . rawurlencode( 'Rochie de vară - https://example.ro/product/produs-test/' ), $html );
		self::assertStringContainsString( 'data-rc-wa-base="Rochie de vară - https://example.ro/product/produs-test/"', $html );
	}

	/**
	 * [product_page] renders a product's Add to Cart form inside an ordinary
	 * page: the message must name that product, not the page around it.
	 */
	public function test_a_product_embedded_in_another_page_is_named_instead_of_the_page(): void {
		$this->settings(
			array(
				'atc_button' => true,
				'channels'   => array( 'whatsapp' => array( 'value' => '40712345678' ) ),
			)
		);
		Functions\when( 'is_product' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_the_title' )->justReturn( 'Pagina de promoții' );
		$GLOBALS['product'] = $this->product( 'Produs embed' );

		$html = $this->render();

		self::assertStringContainsString( 'Produs embed - ', $html );
		self::assertStringNotContainsString( 'Pagina de promoții', $html );
	}

	public function test_the_hello_message_and_product_link_are_used_when_set(): void {
		$this->settings(
			array(
				'atc_button' => true,
				'channels'   => array(
					'whatsapp' => array(
						'value'            => '40712345678',
						'hello_message'    => 'Bună, am o întrebare despre:',
						'append_page_link' => true,
					),
				),
			)
		);
		$GLOBALS['product'] = $this->product();

		self::assertStringContainsString(
			'data-rc-wa-base="Bună, am o întrebare despre: https://example.ro/product/produs-test/"',
			$this->render()
		);
	}

	public function test_the_default_label_is_used_when_none_is_set(): void {
		$this->settings(
			array(
				'atc_button' => true,
				'atc_label'  => '   ',
				'channels'   => array( 'whatsapp' => array( 'value' => '40712345678' ) ),
			)
		);
		$GLOBALS['product'] = $this->product();

		self::assertStringContainsString( '<span>Ask on WhatsApp</span>', $this->render() );
	}

	public function test_a_custom_label_is_escaped(): void {
		Functions\when( 'esc_html' )->alias(
			static function ( string $text ): string {
				return htmlspecialchars( $text, ENT_QUOTES );
			}
		);
		$this->settings(
			array(
				'atc_button' => true,
				'atc_label'  => '<script>alert(1)</script>Scrie-ne',
				'channels'   => array( 'whatsapp' => array( 'value' => '40712345678' ) ),
			)
		);
		$GLOBALS['product'] = $this->product();

		$html = $this->render();

		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringContainsString( 'Scrie-ne', $html );
	}

	public function test_nothing_renders_in_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$this->settings(
			array(
				'atc_button' => true,
				'channels'   => array( 'whatsapp' => array( 'value' => '40712345678' ) ),
			)
		);

		self::assertSame( '', $this->render() );
	}

	public function test_the_button_defaults_to_directly_after_the_add_to_cart_button(): void {
		$placement = $this->atc_placement( new Module() );

		self::assertSame( 'add_to_cart', $placement['slot'] );
		self::assertContains( 'add_to_cart', $placement['choices'] );
	}

	public function test_the_merchant_can_move_the_button(): void {
		$this->options['romcommerce_placement_choices'] = array( 'whatsapp-fab' => array( 'atc' => 'product_after_add_to_cart' ) );

		self::assertSame( 'product_after_add_to_cart', $this->atc_placement( new Module() )['slot'] );
	}

	public function test_a_stored_slot_outside_the_choices_is_ignored(): void {
		$this->options['romcommerce_placement_choices'] = array( 'whatsapp-fab' => array( 'atc' => 'checkout_bottom' ) );

		self::assertSame( 'add_to_cart', $this->atc_placement( new Module() )['slot'] );
	}

	public function test_an_active_button_is_wired_to_the_add_to_cart_hook(): void {
		$this->settings(
			array(
				'atc_button' => true,
				'channels'   => array( 'whatsapp' => array( 'value' => '40712345678' ) ),
			)
		);
		$module = new Module();

		Actions\expectAdded( 'woocommerce_after_add_to_cart_button' )->once()->with( array( $module, 'render_atc_button' ), 10 );

		$module->register_placements();
	}

	public function test_an_inactive_button_is_not_wired(): void {
		$this->settings( array( 'channels' => array( 'whatsapp' => array( 'value' => '40712345678' ) ) ) );

		Actions\expectAdded( 'woocommerce_after_add_to_cart_button' )->never();

		( new Module() )->register_placements();
	}

	public function test_an_invalid_stored_colour_falls_back_to_the_readable_default(): void {
		$this->settings( array( 'atc_color' => 'red; background:url(x)' ) );

		self::assertSame( '#25D366', $this->invokePrivate( new Module(), 'atc_color' ) );
	}

	public function test_a_valid_stored_colour_reaches_the_stylesheet(): void {
		$this->settings( array( 'atc_color' => '#1a2b3c' ) );
		$module = new Module();

		self::assertStringContainsString(
			'background:#1a2b3c;',
			$this->invokePrivate( $module, 'atc_styles_css', array( $this->invokePrivate( $module, 'atc_color' ), 'rounded' ) )
		);
	}

	public function test_the_button_text_is_black_on_the_default_green_and_white_on_a_dark_colour(): void {
		$module = new Module();

		self::assertSame( '#000000', $this->invokePrivate( $module, 'atc_text_color', array( '#25D366' ) ) );
		self::assertSame( '#ffffff', $this->invokePrivate( $module, 'atc_text_color', array( '#075E54' ) ) );
		self::assertSame( '#ffffff', $this->invokePrivate( $module, 'atc_text_color', array( '#123' ) ) );
	}

	public function test_the_button_shape_sets_its_corners_and_an_unknown_one_stays_rounded(): void {
		$this->settings( array( 'atc_shape' => 'pill' ) );
		$module = new Module();
		self::assertSame( 'pill', $this->invokePrivate( $module, 'atc_shape' ) );
		self::assertStringContainsString( 'border-radius:999px;', $this->invokePrivate( $module, 'atc_styles_css', array( '#25D366', 'pill' ) ) );
		self::assertStringContainsString( 'border-radius:0;', $this->invokePrivate( $module, 'atc_styles_css', array( '#25D366', 'square' ) ) );

		$this->settings( array( 'atc_shape' => 'circle' ) );
		self::assertSame( 'rounded', $this->invokePrivate( new Module(), 'atc_shape' ) );
	}

	public function test_the_button_assets_load_only_on_product_pages(): void {
		$this->settings(
			array(
				'atc_button' => true,
				'channels'   => array( 'whatsapp' => array( 'value' => '40712345678' ) ),
			)
		);
		Functions\when( 'is_product' )->justReturn( false );
		Functions\expect( 'wp_enqueue_style' )->never();
		Functions\expect( 'wp_enqueue_script' )->never();

		( new Module() )->enqueue_assets();
	}

	public function test_a_product_page_gets_the_button_style_and_the_variation_script(): void {
		$this->settings(
			array(
				'atc_button' => true,
				'channels'   => array( 'whatsapp' => array( 'value' => '40712345678' ) ),
			)
		);
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'wp_register_style' )->justReturn( true );
		Functions\when( 'wp_add_inline_style' )->justReturn( true );
		Functions\when( 'wp_add_inline_script' )->justReturn( true );
		Functions\expect( 'wp_enqueue_style' )->once()->with( 'romcommerce-whatsapp-atc' );
		Functions\expect( 'wp_register_script' )->once()->with( 'romcommerce-whatsapp-variation', false, array( 'jquery' ), 'test', true );
		Functions\expect( 'wp_enqueue_script' )->once()->with( 'romcommerce-whatsapp-variation' );

		( new Module() )->enqueue_assets();
	}

	/** The FAB's WhatsApp link kept following the chosen variation before the button existed. */
	public function test_the_variation_script_still_loads_for_a_fab_only_whatsapp_link(): void {
		$this->settings(
			array(
				'channels' => array(
					'whatsapp' => array(
						'enabled' => true,
						'value'   => '40712345678',
					),
				),
			)
		);
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'wp_register_style' )->justReturn( true );
		Functions\when( 'wp_register_script' )->justReturn( true );
		Functions\when( 'wp_enqueue_style' )->justReturn( true );
		Functions\when( 'wp_add_inline_style' )->justReturn( true );
		Functions\when( 'wp_add_inline_script' )->justReturn( true );
		Functions\expect( 'wp_enqueue_script' )->twice()->with( Mockery::anyOf( 'romcommerce-whatsapp-variation', 'romcommerce-whatsapp-fab' ) );

		( new Module() )->enqueue_assets();
	}

	public function test_the_variation_script_updates_and_resets_every_whatsapp_link(): void {
		$js = $this->invokePrivate( new Module(), 'variation_script_js' );

		self::assertStringContainsString( '"found_variation"', $js );
		self::assertStringContainsString( '"reset_data"', $js );
		self::assertStringContainsString( '$("[data-rc-wa-number]")', $js );
	}
}
