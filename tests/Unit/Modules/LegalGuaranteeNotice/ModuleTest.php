<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\LegalGuaranteeNotice;

use Brain\Monkey\Functions;
use Mockery;
use ReflectionClass;
use RomCommerce\Modules\LegalGuaranteeNotice\Module;
use RomCommerce\Tests\Support\InvokesPrivateMethods;
use RomCommerce\Tests\TestCase;
use WC_Product;

final class ModuleTest extends TestCase {

	use InvokesPrivateMethods;

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
	}

	/**
	 * 2026-09-24/25 saw this threshold change three times in one day (2 →
	 * 2.5 → 3 → back to 2.5) before landing on "the smallest value above the
	 * 2-year legal-guarantee floor that half-year granularity can express" —
	 * see docs/decisions.md. Pinning the constant directly means a future
	 * edit has to touch this test deliberately, not drift silently.
	 */
	public function test_garan_min_years_threshold_is_two_point_five(): void {
		self::assertSame( 2.5, $this->classConstant( Module::class, 'GARAN_MIN_YEARS' ) );
	}

	/** @dataProvider sanitizeYearsProvider */
	public function test_sanitize_garan_years_snaps_to_nearest_half( string $raw, float $expected ): void {
		self::assertSame( $expected, $this->invokePrivate( new Module(), 'sanitize_garan_years', array( $raw ) ) );
	}

	/** @return array<string, array{0: string, 1: float}> */
	public static function sanitizeYearsProvider(): array {
		return array(
			'blank'                     => array( '', 0.0 ),
			'negative'                  => array( '-1', 0.0 ),
			'zero'                      => array( '0', 0.0 ),
			'already a whole number'    => array( '3', 3.0 ),
			'already a half'            => array( '2.5', 2.5 ),
			'rounds down to nearest half' => array( '2.1', 2.0 ),
			'rounds up to nearest half'   => array( '2.3', 2.5 ),
			'non-numeric bypass attempt'  => array( '4,2', 4.0 ),
		);
	}

	/** @dataProvider formatYearsProvider */
	public function test_format_garan_years_uses_comma_decimal( float $years, string $expected ): void {
		self::assertSame( $expected, $this->invokePrivate( new Module(), 'format_garan_years', array( $years ) ) );
	}

	/** @return array<string, array{0: float, 1: string}> */
	public static function formatYearsProvider(): array {
		return array(
			'whole number has no decimal' => array( 3.0, '3' ),
			'half has a comma, not a dot' => array( 2.5, '2,5' ),
			'another half'                => array( 4.5, '4,5' ),
		);
	}

	public function test_garan_years_reads_the_product_meta(): void {
		$product = Mockery::mock( WC_Product::class );
		$product->shouldReceive( 'get_meta' )
			->once()
			->with( '_romcommerce_garan_years', true )
			->andReturn( '2.5' );

		self::assertSame( 2.5, $this->invokePrivate( new Module(), 'garan_years', array( $product ) ) );
	}

	/**
	 * The actual 2026-09-25 bug: two SVGs (the nested badge and the full
	 * label) inlined on the same page shared unscoped "cls-N" class names, so
	 * the full label's near-black text-fill rule silently overrode the
	 * nested badge's white background rule. garan_svg() now namespaces every
	 * render with an incrementing suffix — this asserts consecutive renders
	 * never reuse the same class/id namespace, which is the actual condition
	 * that caused the collision.
	 */
	public function test_garan_svg_namespaces_every_render_uniquely(): void {
		$module = new Module();

		$first  = $this->invokePrivate( $module, 'garan_svg', array( 'garan-nested-label.svg', 3.0 ) );
		$second = $this->invokePrivate( $module, 'garan_svg', array( 'garan-full-label.svg', 3.0 ) );

		self::assertNotSame( '', $first );
		self::assertNotSame( '', $second );

		self::assertMatchesRegularExpression( '/-rc\d+"/', $first );
		self::assertMatchesRegularExpression( '/-rc\d+"/', $second );

		preg_match( '/-rc(\d+)"/', $first, $first_match );
		preg_match( '/-rc(\d+)"/', $second, $second_match );

		self::assertNotSame(
			$first_match[1],
			$second_match[1],
			'Two renders in the same request must not share the same cls-N/id namespace suffix.'
		);
	}

	public function test_garan_svg_substitutes_the_xx_placeholder_with_formatted_years(): void {
		$svg = $this->invokePrivate( new Module(), 'garan_svg', array( 'garan-nested-label.svg', 2.5 ) );

		self::assertStringNotContainsString( '>XX<', $svg );
		self::assertStringContainsString( '>2,5<', $svg );
	}

	public function test_garan_svg_returns_empty_string_for_an_unreadable_file(): void {
		self::assertSame( '', $this->invokePrivate( new Module(), 'garan_svg', array( 'does-not-exist.svg', 3.0 ) ) );
	}

	public function test_trigger_text_falls_back_to_the_default_when_the_setting_is_blank(): void {
		Functions\expect( 'get_option' )->andReturn( array() );

		self::assertSame(
			'Drepturile tale privind garanția legală',
			$this->invokePrivate( new Module(), 'trigger_text', array() )
		);
	}

	public function test_trigger_text_uses_the_custom_value_when_set(): void {
		Functions\expect( 'get_option' )->andReturn( array( 'trigger_text' => 'Drepturile mele' ) );

		self::assertSame( 'Drepturile mele', $this->invokePrivate( new Module(), 'trigger_text', array() ) );
	}

	/**
	 * Absent must mean true, not false — existing installs already show the
	 * blue trigger, and an absent setting is what every install has until it
	 * saves the form once.
	 */
	public function test_trigger_recommended_color_defaults_to_true_when_unset(): void {
		Functions\expect( 'get_option' )->andReturn( array() );

		self::assertTrue( $this->invokePrivate( new Module(), 'trigger_recommended_color', array() ) );
	}

	public function test_trigger_recommended_color_respects_an_explicit_false(): void {
		Functions\expect( 'get_option' )->andReturn( array( 'trigger_recommended_color' => false ) );

		self::assertFalse( $this->invokePrivate( new Module(), 'trigger_recommended_color', array() ) );
	}

	public function test_garan_brand_returns_the_manual_field_when_not_using_the_woo_brand(): void {
		$product = Mockery::mock( WC_Product::class );
		$product->shouldReceive( 'get_meta' )->with( '_romcommerce_garan_brand_use_woo', true )->andReturn( 'no' );
		$product->shouldReceive( 'get_meta' )->with( '_romcommerce_garan_brand', true )->andReturn( 'Acme' );

		self::assertSame( 'Acme', $this->invokePrivate( new Module(), 'garan_brand', array( $product ) ) );
	}

	public function test_garan_brand_pulls_from_the_woocommerce_brand_taxonomy_when_checked(): void {
		Functions\when( 'taxonomy_exists' )->justReturn( true );
		Functions\when( 'get_the_terms' )->justReturn( array( (object) array( 'name' => 'Acme Woo' ) ) );

		$product = Mockery::mock( WC_Product::class );
		$product->shouldReceive( 'get_meta' )->with( '_romcommerce_garan_brand_use_woo', true )->andReturn( 'yes' );
		$product->shouldReceive( 'get_id' )->andReturn( 42 );

		self::assertSame( 'Acme Woo', $this->invokePrivate( new Module(), 'garan_brand', array( $product ) ) );
	}

	public function test_garan_brand_is_empty_when_using_woo_brand_but_none_is_assigned(): void {
		Functions\when( 'taxonomy_exists' )->justReturn( true );
		Functions\when( 'get_the_terms' )->justReturn( false );

		$product = Mockery::mock( WC_Product::class );
		$product->shouldReceive( 'get_meta' )->with( '_romcommerce_garan_brand_use_woo', true )->andReturn( 'yes' );
		$product->shouldReceive( 'get_id' )->andReturn( 42 );

		self::assertSame( '', $this->invokePrivate( new Module(), 'garan_brand', array( $product ) ) );
	}

	/**
	 * The checkbox can be 'yes' from before the merchant's WooCommerce version
	 * ever had Brands, or after disabling the feature — taxonomy_exists() is
	 * the real gate, not the stored checkbox value alone.
	 */
	public function test_garan_brand_falls_back_to_the_manual_field_when_the_brand_taxonomy_is_unavailable(): void {
		Functions\when( 'taxonomy_exists' )->justReturn( false );

		$product = Mockery::mock( WC_Product::class );
		$product->shouldReceive( 'get_meta' )->with( '_romcommerce_garan_brand_use_woo', true )->andReturn( 'yes' );
		$product->shouldReceive( 'get_meta' )->with( '_romcommerce_garan_brand', true )->andReturn( 'Manual Brand' );

		self::assertSame( 'Manual Brand', $this->invokePrivate( new Module(), 'garan_brand', array( $product ) ) );
	}
}
