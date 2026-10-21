<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\MerchantWidget;

use Brain\Monkey\Functions;
use RomCommerce\Modules\MerchantWidget\Module;
use RomCommerce\Tests\Support\InvokesPrivateMethods;
use RomCommerce\Tests\TestCase;

final class ModuleTest extends TestCase {

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
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	/** @param array<string, mixed> $settings */
	private function settings( array $settings ): void {
		$this->options['romcommerce_merchant_widget'] = $settings;
	}

	/** @return array<string, int|string> */
	private function config(): array {
		return $this->invokePrivate( new Module(), 'start_config' );
	}

	/** A merchant who only switched the module on gets Google's own snippet, set to Romania. */
	public function test_a_fresh_install_starts_the_widget_with_googles_defaults_and_romania(): void {
		self::assertSame(
			array(
				'position' => 'RIGHT_BOTTOM',
				'region'   => 'RO',
			),
			$this->config()
		);
	}

	public function test_a_region_cleared_and_saved_stays_blank(): void {
		$this->settings( array( 'region' => '' ) );

		self::assertArrayNotHasKey( 'region', $this->config() );
	}

	public function test_the_left_corner_is_passed_through(): void {
		$this->settings( array( 'position' => 'LEFT_BOTTOM' ) );

		self::assertSame( 'LEFT_BOTTOM', $this->config()['position'] );
	}

	public function test_an_unknown_stored_position_falls_back_to_googles_default(): void {
		$this->settings( array( 'position' => 'BOTTOM_LEFT' ) );

		self::assertSame( 'RIGHT_BOTTOM', $this->config()['position'] );
	}

	public function test_only_changed_margins_are_passed_to_google(): void {
		$this->settings(
			array(
				'sideMargin'         => 36,
				'bottomMargin'       => '90',
				'mobileSideMargin'   => '',
				'mobileBottomMargin' => 46,
				'region'             => '',
			)
		);

		self::assertSame(
			array(
				'position'     => 'RIGHT_BOTTOM',
				'bottomMargin' => 90,
			),
			$this->config()
		);
	}

	public function test_margins_are_clamped_to_a_sane_range(): void {
		$this->settings(
			array(
				'sideMargin'   => -20,
				'bottomMargin' => 5000,
			)
		);

		$config = $this->config();
		self::assertSame( 0, $config['sideMargin'] );
		self::assertSame( 300, $config['bottomMargin'] );
	}

	/** @dataProvider marginProvider */
	public function test_sanitize_margin( $raw, ?int $expected ): void {
		self::assertSame( $expected, $this->invokePrivate( new Module(), 'sanitize_margin', array( $raw ) ) );
	}

	/** @return array<string, array{0: mixed, 1: int|null}> */
	public static function marginProvider(): array {
		return array(
			'blank means Google default' => array( '', null ),
			'missing means Google default' => array( null, null ),
			'text is rejected'           => array( '12px', null ),
			'a number string is kept'    => array( '24', 24 ),
			'decimals are truncated'     => array( '24.7', 24 ),
			'zero is a real value'       => array( '0', 0 ),
		);
	}

	public function test_a_region_is_uppercased_and_passed_through(): void {
		$this->settings( array( 'region' => ' ro ' ) );

		self::assertSame( 'RO', $this->config()['region'] );
	}

	/** @dataProvider invalidRegionProvider */
	public function test_anything_but_a_two_letter_code_is_dropped( string $region ): void {
		$this->settings( array( 'region' => $region ) );

		self::assertArrayNotHasKey( 'region', $this->config() );
	}

	/** @return array<string, array{0: string}> */
	public static function invalidRegionProvider(): array {
		return array(
			'blank'          => array( '' ),
			'three letters'  => array( 'ROU' ),
			'digits'         => array( '40' ),
			'script attempt' => array( '"});alert(1);//' ),
		);
	}

	/** @dataProvider merchantIdProvider */
	public function test_the_merchant_id_is_read_from_the_id_or_the_pasted_snippet( string $raw, string $expected ): void {
		self::assertSame( $expected, $this->invokePrivate( new Module(), 'sanitize_merchant_id', array( $raw ) ) );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function merchantIdProvider(): array {
		return array(
			'bare id'         => array( ' 759980522 ', '759980522' ),
			'pasted snippet'  => array( "merchantwidget.start({\n // REQUIRED FIELDS\n merchant_id: 759980522,\n position: POSITION,", '759980522' ),
			'blank'           => array( '', '' ),
			'zero'            => array( '0', '' ),
			'words'           => array( 'my store', '' ),
			'script attempt'  => array( '1"});alert(1);//', '' ),
			'absurdly long'   => array( str_repeat( '9', 21 ), '' ),
		);
	}

	public function test_a_merchant_id_is_passed_to_google_only_when_set(): void {
		self::assertArrayNotHasKey( 'merchant_id', $this->config() );

		$this->settings( array( 'merchant_id' => '759980522' ) );
		self::assertSame( '759980522', $this->config()['merchant_id'] );
	}

	/**
	 * The start call must survive the three ways the script can arrive
	 * (deferred, blocking on WordPress < 6.3, delayed by an optimiser) and
	 * still call start() only once.
	 */
	public function test_the_start_script_covers_every_load_order_and_runs_once(): void {
		$js = $this->invokePrivate( new Module(), 'start_script_js', array( array( 'position' => 'LEFT_BOTTOM' ) ) );

		self::assertStringContainsString( 'try{window.merchantwidget.start({"position":"LEFT_BOTTOM"});}catch(e){}', $js );
		self::assertStringContainsString( '"DOMContentLoaded"', $js );
		self::assertStringContainsString( 'getElementById("romcommerce-merchant-widget-js")', $js );
		self::assertStringContainsString( 'if(started||!window.merchantwidget){return;}started=true;', $js );
	}

	public function test_googles_script_is_deferred_in_the_footer_and_the_start_call_is_a_separate_handle(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\expect( 'wp_enqueue_script' )->once()->with(
			'romcommerce-merchant-widget',
			'https://www.gstatic.com/shopping/merchant/merchantwidget.js',
			array(),
			null,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		Functions\expect( 'wp_register_script' )->once()->with( 'romcommerce-merchant-widget-start', false, array(), 'test', true );
		Functions\expect( 'wp_enqueue_script' )->once()->with( 'romcommerce-merchant-widget-start' );
		Functions\expect( 'wp_add_inline_script' )->once()->with( 'romcommerce-merchant-widget-start', \Mockery::type( 'string' ) );

		( new Module() )->enqueue_assets();
	}

	public function test_nothing_is_enqueued_in_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\expect( 'wp_enqueue_script' )->never();

		( new Module() )->enqueue_assets();
	}

	public function test_the_widget_sits_in_the_fixed_corner_slot_on_the_placements_map(): void {
		$placements = ( new Module() )->placements();

		self::assertCount( 1, $placements );
		self::assertSame( 'fixed', $placements[0]['slot'] );
		self::assertTrue( $placements[0]['active'] );
	}

	/** @dataProvider collisionProvider */
	public function test_collision_warning( string $widget, bool $fab_on, string $fab_position, bool $expected ): void {
		$this->settings( array( 'position' => $widget ) );
		$this->options['romcommerce_core']         = array( 'enabled_modules' => array( 'whatsapp-fab' => $fab_on ) );
		$this->options['romcommerce_whatsapp_fab'] = array( 'position' => $fab_position );

		self::assertSame( $expected, $this->invokePrivate( new Module(), 'collides_with_contact_button' ) );
	}

	/** @return array<string, array{0: string, 1: bool, 2: string, 3: bool}> */
	public static function collisionProvider(): array {
		return array(
			'both bottom right'                  => array( 'RIGHT_BOTTOM', true, 'right', true ),
			'contact button on its default left' => array( 'RIGHT_BOTTOM', true, 'left', false ),
			'widget moved left'                  => array( 'LEFT_BOTTOM', true, 'right', false ),
			'contact button module off'          => array( 'RIGHT_BOTTOM', false, 'right', false ),
		);
	}
}
