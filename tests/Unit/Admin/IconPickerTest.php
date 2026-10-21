<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Admin;

use RomCommerce\Admin\IconPicker;
use RomCommerce\Tests\TestCase;

final class IconPickerTest extends TestCase {

	public function test_dashicon_names_come_from_the_core_stylesheet(): void {
		$css = ".dashicons-before:before {\n}\n.dashicons-menu:before {\n\tcontent: \"\\f333\";\n}\n.dashicons-format-links:before,\n.dashicons-admin-links:before {\n}\n.dashicons-menu:before {\n}";

		self::assertSame(
			array( 'dashicons-menu', 'dashicons-format-links', 'dashicons-admin-links' ),
			IconPicker::dashicon_slugs( $css )
		);
	}
}
