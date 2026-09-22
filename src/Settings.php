<?php

declare(strict_types=1);

namespace RomCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Per-module settings storage. One option per module (romcommerce_{id}, not
 * autoloaded — most modules' config isn't needed on every request) plus one
 * shared core option (romcommerce_core, autoloaded) holding the enabled/
 * disabled flag for every module, since ModuleLoader::boot() needs that for
 * every registered module on every request and N separate non-autoloaded
 * lookups would mean N extra queries per page load.
 */
final class Settings {

	private const CORE_OPTION = 'romcommerce_core';

	/** @return array<string, mixed> */
	public static function get( string $module_id ): array {
		$value = get_option( self::option_key( $module_id ), array() );

		return is_array( $value ) ? $value : array();
	}

	/** @param array<string, mixed> $settings */
	public static function update( string $module_id, array $settings ): void {
		update_option( self::option_key( $module_id ), $settings, false );
	}

	public static function is_enabled( string $module_id, bool $default = true ): bool {
		$core = self::core();

		return (bool) ( $core['enabled_modules'][ $module_id ] ?? $default );
	}

	public static function set_enabled( string $module_id, bool $enabled ): void {
		$core                                  = self::core();
		$core['enabled_modules'][ $module_id ] = $enabled;

		self::update_core( $core );
	}

	/**
	 * Whether an uninstall (plugin delete, not deactivate) should purge the
	 * plugin's user-facing data stores, not just its options. Off by default —
	 * an uninstall a merchant runs for unrelated reasons must not silently
	 * destroy return records or the Omnibus price-history log. uninstall.php
	 * reads the same romcommerce_core key directly (it can't rely on the
	 * autoloader), so the key string here and there must stay in sync.
	 */
	public static function delete_data_on_uninstall(): bool {
		$core = self::core();

		return (bool) ( $core['delete_data_on_uninstall'] ?? false );
	}

	public static function set_delete_data_on_uninstall( bool $enabled ): void {
		$core                             = self::core();
		$core['delete_data_on_uninstall'] = $enabled;

		self::update_core( $core );
	}

	/** @return array<string, mixed> */
	public static function core(): array {
		$value = get_option( self::CORE_OPTION, array() );

		return is_array( $value ) ? $value : array();
	}

	/** @param array<string, mixed> $data */
	public static function update_core( array $data ): void {
		// Explicit autoload=true: this option is read on every request via
		// is_enabled(), so it must stay in the autoloaded alloptions cache.
		update_option( self::CORE_OPTION, $data, true );
	}

	private static function option_key( string $module_id ): string {
		return 'romcommerce_' . str_replace( '-', '_', $module_id );
	}
}
