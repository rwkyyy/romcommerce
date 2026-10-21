<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\PriceHistory;

use Brain\Monkey\Functions;
use Mockery;
use RomCommerce\Modules\PriceHistory\Baseline;
use RomCommerce\Modules\PriceHistory\Module;
use RomCommerce\Tests\TestCase;

final class BaselineTest extends TestCase {

	public function test_nothing_is_scheduled_once_complete(): void {
		Functions\when( 'get_option' )->justReturn( array( 'complete' => true ) );
		Functions\expect( 'wp_schedule_single_event' )->never();

		( new Baseline( new Module() ) )->schedule();
	}

	public function test_a_batch_is_scheduled_until_complete(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->with( Mockery::type( 'int' ), Baseline::HOOK );

		( new Baseline( new Module() ) )->schedule();
	}

	public function test_switching_the_module_off_resets_the_run(): void {
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( Baseline::HOOK );
		Functions\expect( 'delete_option' )->once();

		( new Baseline( new Module() ) )->on_module_toggled( 'price-history', false );
	}

	public function test_other_modules_and_switching_on_leave_it_alone(): void {
		Functions\expect( 'delete_option' )->never();

		( new Baseline( new Module() ) )->on_module_toggled( 'maof', false );
		( new Baseline( new Module() ) )->on_module_toggled( 'price-history', true );
	}
}
