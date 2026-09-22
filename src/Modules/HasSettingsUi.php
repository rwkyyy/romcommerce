<?php

declare(strict_types=1);

namespace RomCommerce\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Optional contract for a ModuleInterface implementation that contributes a
 * settings pane to the RomCommerce admin page. Checked via instanceof, not
 * folded into ModuleInterface itself, because not every module needs one.
 *
 * render_settings() may be called on a module whose boot() never ran —
 * ModuleLoader::register() stores every module regardless of is_enabled(),
 * only ModuleLoader::boot() skips disabled ones. Implementations must not
 * assume boot() executed.
 */
interface HasSettingsUi {

	public function render_settings(): void;
}
