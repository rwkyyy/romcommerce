<?php
/**
 * Plugin Name:       RomCommerce
 * Plugin URI:        https://romcommerce.ro
 * Description:       Romanian commerce layer for WooCommerce: modular tools for compliance, checkout localisation, and Romanian-market essentials.
 * Version:           0.6
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * Author:        Uprise Team
 * Author URI:    https://uprise.ro
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       romcommerce
 * Domain Path:       /languages
 *
 * @package RomCommerce
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'ROMCOMMERCE_VERSION', '0.6' );
define( 'ROMCOMMERCE_FILE', __FILE__ );
define( 'ROMCOMMERCE_DIR', __DIR__ );

require_once __DIR__ . '/src/autoload.php';

register_activation_hook( __FILE__, array( \RomCommerce\Installer::class, 'activate' ) );

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		// 'Requires Plugins: woocommerce' is only enforced on WP 6.5+, so the guard must live in code.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'RomCommerce requires WooCommerce to be installed and active.', 'romcommerce' );
					echo '</p></div>';
				}
			);

			return;
		}

		\RomCommerce\Plugin::instance()->boot();
	},
	10
);
