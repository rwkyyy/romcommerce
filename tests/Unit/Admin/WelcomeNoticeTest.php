<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Admin;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use RomCommerce\Admin\WelcomeNotice;
use RomCommerce\Tests\TestCase;

final class WelcomeNoticeTest extends TestCase {

	public function test_saving_a_module_dismisses_the_notice(): void {
		$notice = new WelcomeNotice();

		Actions\expectAdded( 'romcommerce/module_toggled' )->once()->with( array( $notice, 'dismiss' ) );

		$notice->register();
	}

	public function test_dismiss_writes_the_option_once(): void {
		Functions\expect( 'get_option' )->once()->with( 'romcommerce_welcome_notice_dismissed' )->andReturn( false );
		Functions\expect( 'update_option' )->once()->with( 'romcommerce_welcome_notice_dismissed', true, false );

		( new WelcomeNotice() )->dismiss();
	}

	public function test_dismiss_skips_the_write_when_already_dismissed(): void {
		Functions\expect( 'get_option' )->once()->with( 'romcommerce_welcome_notice_dismissed' )->andReturn( '1' );
		Functions\expect( 'update_option' )->never();

		( new WelcomeNotice() )->dismiss();
	}
}
