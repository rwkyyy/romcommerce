<?php
/**
 * PSR-4 autoloader for the RomCommerce\ namespace.
 *
 * Skips RomCommerce\Pro\* — those classes are served by the Pro plugin's own
 * autoloader so the directory boundary between Lite and Pro stays enforceable.
 *
 * @package RomCommerce
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class ): void {
		$prefix     = 'RomCommerce\\';
		$pro_prefix = 'RomCommerce\\Pro\\';

		if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}
		if ( strncmp( $class, $pro_prefix, strlen( $pro_prefix ) ) === 0 ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_file( $file ) ) {
			require $file;
		}
	}
);
