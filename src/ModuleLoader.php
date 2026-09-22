<?php

declare(strict_types=1);

namespace RomCommerce;

use RomCommerce\Modules\ModuleInterface;

defined( 'ABSPATH' ) || exit;

final class ModuleLoader {

	/** @var array<string, ModuleInterface> */
	private array $modules = array();

	public function register( ModuleInterface $module ): void {
		$this->modules[ $module->id() ] = $module;
	}

	public function boot(): void {
		foreach ( $this->modules as $module ) {
			if ( $module->is_enabled() ) {
				$module->boot();
			}
		}
	}

	public function get( string $id ): ?ModuleInterface {
		return $this->modules[ $id ] ?? null;
	}

	/** @return array<string, ModuleInterface> */
	public function all(): array {
		return $this->modules;
	}
}
