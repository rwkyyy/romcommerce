<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit;

use RomCommerce\Admin\ModuleRegistry;
use RomCommerce\ModuleLoader;
use RomCommerce\ModuleRegistrar;
use RomCommerce\Tests\TestCase;

/**
 * Guards against whole classes of bug recurring, rather than a single
 * instance of one — each check here is a static rule from CLAUDE.md's
 * "Boundary rules" / "Architecture principles" sections, or a rule inferred
 * from a specific incident logged in docs/decisions.md. These read source
 * files on disk directly; they don't need WordPress or WooCommerce stubbed.
 */
final class ArchitectureRulesTest extends TestCase {

	/** @return array<int, string> absolute paths of every .php file under src/ */
	private function phpFilesUnderSrc(): array {
		$src = dirname( __DIR__, 2 ) . '/src';

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $src, \FilesystemIterator::SKIP_DOTS ) );

		$files = array();
		foreach ( $iterator as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		return $files;
	}

	/**
	 * Returns the body of the first `function $name(...): void { ... }`
	 * found in $contents (brace-matched, not regex-bounded), or null if the
	 * method isn't present in this file.
	 */
	private function extractVoidMethodBody( string $contents, string $name ): ?string {
		if ( ! preg_match( '/function\s+' . preg_quote( $name, '/' ) . '\s*\([^)]*\)\s*:\s*void\s*\{/', $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$start  = $matches[0][1] + strlen( $matches[0][0] );
		$depth  = 1;
		$length = strlen( $contents );

		for ( $i = $start; $i < $length; $i++ ) {
			if ( '{' === $contents[ $i ] ) {
				++$depth;
			} elseif ( '}' === $contents[ $i ] ) {
				--$depth;
				if ( 0 === $depth ) {
					return substr( $contents, $start, $i - $start );
				}
			}
		}

		return null;
	}

	/**
	 * CLAUDE.md boundary rule 1: "Lite must never require or reference a
	 * RomCommerce\Pro\* class." This is the WP.org rule and what keeps the
	 * Pro upsell honest — a real reference here would ship a hard dependency
	 * on a plugin most Lite users don't have. Comment-only lines (the two
	 * that document this very rule, in autoload.php and ModuleRegistry.php)
	 * are excluded deliberately.
	 */
	public function test_lite_never_references_the_pro_namespace_outside_comments(): void {
		$violations = array();

		foreach ( $this->phpFilesUnderSrc() as $file ) {
			foreach ( file( $file ) as $line_number => $line ) {
				$trimmed = ltrim( $line );

				if ( '' === $trimmed || '*' === $trimmed[0] || '/' === $trimmed[0] ) {
					continue;
				}

				if ( false !== strpos( $line, 'RomCommerce\\Pro\\' ) ) {
					$violations[] = $file . ':' . ( $line_number + 1 );
				}
			}
		}

		self::assertSame( array(), $violations, 'Lite referenced RomCommerce\\Pro\\* outside a comment: ' . implode( ', ', $violations ) );
	}

	/**
	 * The 2026-07-25 incident: a CPT assigned 'manage_woocommerce' (a plain
	 * role capability with no object id) directly to the singular edit_post/
	 * read_post/delete_post meta-capability slots while map_meta_cap was
	 * true. WordPress then treated 'manage_woocommerce' itself as an
	 * object-scoped meta capability everywhere, silently breaking every
	 * other current_user_can('manage_woocommerce') check in wp-admin —
	 * including WooCommerce's own Settings/Status/Payments menus. No CPT is
	 * registered in Lite today (the module that triggered this was removed
	 * 2026-08-20), so this currently passes vacuously — it exists to catch
	 * the same mistake the moment a CPT is reintroduced.
	 */
	public function test_no_post_type_registration_assigns_a_shared_capability_to_a_singular_meta_cap(): void {
		$violations = array();

		foreach ( $this->phpFilesUnderSrc() as $file ) {
			$contents = (string) file_get_contents( $file );

			if ( false === strpos( $contents, 'register_post_type(' ) ) {
				continue;
			}

			foreach ( array( 'edit_post', 'read_post', 'delete_post' ) as $singular_cap ) {
				if ( preg_match( "/'" . $singular_cap . "'\\s*=>\\s*'manage_woocommerce'/", $contents ) ) {
					$violations[] = $file . " assigns 'manage_woocommerce' to the singular '" . $singular_cap . "' meta capability";
				}
			}
		}

		self::assertSame( array(), $violations, implode( ', ', $violations ) );
	}

	/**
	 * ModuleInterface::boot() is documented as "register hooks only —
	 * WooCommerce state is not reliably ready at plugins_loaded." A boot()
	 * body that reads WC/order data directly (rather than from inside a
	 * hooked callback) violates that on every request, not just when the
	 * hook fires.
	 */
	public function test_module_boot_methods_only_register_hooks(): void {
		$forbidden = array( 'wc_get_order(', 'wc_get_orders(', 'get_posts(', 'get_option(', 'get_user_meta(' );
		$violations = array();

		foreach ( $this->phpFilesUnderSrc() as $file ) {
			if ( false === strpos( $file, '/Modules/' ) ) {
				continue;
			}

			$body = $this->extractVoidMethodBody( (string) file_get_contents( $file ), 'boot' );

			if ( null === $body ) {
				continue;
			}

			foreach ( $forbidden as $call ) {
				if ( false !== strpos( $body, $call ) ) {
					$violations[] = $file . " calls {$call} directly inside boot()";
				}
			}
		}

		self::assertSame( array(), $violations, implode( ', ', $violations ) );
	}

	/**
	 * Every Lite-tier id in the admin nav (Admin\ModuleRegistry, the single
	 * source of nav copy) must correspond to exactly one module actually
	 * registered by ModuleRegistrar, and vice versa — otherwise a typo'd id
	 * either silently strands a built module behind the "not built yet"
	 * placeholder, or an orphaned nav entry never resolves to anything.
	 */
	public function test_module_registry_and_module_registrar_agree_on_every_lite_module_id(): void {
		$loader = new ModuleLoader();
		( new ModuleRegistrar() )->register_modules( $loader );

		$registered_ids = array_keys( $loader->all() );
		sort( $registered_ids );

		$registry_lite_ids = array_keys(
			array_filter(
				ModuleRegistry::all(),
				static function ( array $entry ): bool {
					return 'lite' === $entry['tier'];
				}
			)
		);
		sort( $registry_lite_ids );

		self::assertSame( $registry_lite_ids, $registered_ids );
	}
}
