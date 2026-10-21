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
		do_action( 'wp_enqueue_scripts' );
	}

	public function tear_down(): void {
		$GLOBALS['wp_scripts'] = null;
		parent::tear_down();
	}

	public function test_the_footer_carries_googles_script_and_the_start_call(): void {
		$footer = get_echo( 'wp_print_footer_scripts' );

		// WordPress before 6.3 quotes script attributes with ' rather than ".
		$this->assertMatchesRegularExpression( '#src=["\']https://www\.gstatic\.com/shopping/merchant/merchantwidget\.js["\']#', $footer );
		$this->assertMatchesRegularExpression( '#id=["\']romcommerce-merchant-widget-js["\']#', $footer );
		$this->assertStringContainsString( 'window.merchantwidget.start({"position":"RIGHT_BOTTOM","region":"RO"})', $footer );
		$this->assertStringNotContainsString( 'merchantwidget.js?ver=', $footer );
	}

	/** WordPress drops a delayed strategy when the script has an "after" inline script; ours must keep it. */
	public function test_googles_script_stays_deferred_where_wordpress_supports_it(): void {
		if ( version_compare( get_bloginfo( 'version' ), '6.3', '<' ) ) {
			$this->markTestSkipped( 'Script loading strategies arrived in WordPress 6.3.' );
		}

		$this->assertMatchesRegularExpression( '/<script[^>]*\sdefer[\s>=][^>]*merchantwidget\.js|<script[^>]*merchantwidget\.js[^>]*\sdefer[\s>=]/', get_echo( 'wp_print_footer_scripts' ) );
	}
}
