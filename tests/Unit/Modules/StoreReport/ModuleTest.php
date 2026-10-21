<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\StoreReport;

use Brain\Monkey\Functions;
use RomCommerce\Modules\StoreReport\Module;
use RomCommerce\Tests\TestCase;

final class ModuleTest extends TestCase {

	public function test_recipients_keep_valid_addresses_only_once(): void {
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'is_email' )->alias(
			static function ( string $email ): bool {
				return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
			}
		);

		self::assertSame(
			array( 'ana@magazin.ro', 'ion@magazin.ro' ),
			Module::parse_recipients( "ana@magazin.ro, ion@magazin.ro;\nnot-an-email ana@magazin.ro" )
		);
	}
}
