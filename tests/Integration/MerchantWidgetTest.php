<?php
/**
 * @package RomCommerce
 */

declare(strict_types=1);

namespace RomCommerce\Tests\Integration;

final class MerchantWidgetTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$GLOBALS['wp_scripts'] = null;
	}

	public function tear_down(): void {
		$GLOBALS['wp_scripts'] = null;
		parent::tear_down();
	}

	/** @param array<string, mixed> $settings */
	private function footer( array $settings = array() ): string {
		update_option( 'romcommerce_merchant_widget', $settings );
		do_action( 'wp_enqueue_scripts' );

		return get_echo( 'wp_print_footer_scripts' );
	}

	public function test_by_default_the_page_adds_googles_script_itself_after_the_first_interaction(): void {
		$footer = $this->footer();

		$this->assertDoesNotMatchRegularExpression( '#<script[^>]*merchantwidget\.js#', $footer );
		$this->assertStringContainsString( '"https:\/\/www.gstatic.com\/shopping\/merchant\/merchantwidget.js"', $footer );
		$this->assertStringContainsString( 'window.addEventListener(t,load,{passive:true})', $footer );
		$this->assertStringContainsString( 'window.merchantwidget.start({"position":"RIGHT_BOTTOM","region":"RO"})', $footer );
	}

	public function test_without_load_later_the_footer_carries_googles_script_and_the_start_call(): void {
		$footer = $this->footer( array( 'load_later' => false ) );

		// WordPress before 6.3 quotes script attributes with ' rather than ".
		$this->assertMatchesRegularExpression( '#src=["\']https://www\.gstatic\.com/shopping/merchant/merchantwidget\.js["\']#', $footer );
		$this->assertMatchesRegularExpression( '#id=["\']romcommerce-merchant-widget-js["\']#', $footer );
		$this->assertStringContainsString( 'window.merchantwidget.start({"position":"RIGHT_BOTTOM","region":"RO"})', $footer );
		$this->assertStringNotContainsString( 'merchantwidget.js?ver=', $footer );
	}

	/** WordPress drops a delayed strategy when the script has an "after" inline script; ours must keep it. */
	public function test_without_load_later_googles_script_stays_deferred_where_wordpress_supports_it(): void {
		if ( version_compare( get_bloginfo( 'version' ), '6.3', '<' ) ) {
			$this->markTestSkipped( 'Script loading strategies arrived in WordPress 6.3.' );
		}

		$this->assertMatchesRegularExpression( '/<script[^>]*\sdefer[\s>=][^>]*merchantwidget\.js|<script[^>]*merchantwidget\.js[^>]*\sdefer[\s>=]/', $this->footer( array( 'load_later' => false ) ) );
	}
}
