<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\OrderPaymentFilter;

use Brain\Monkey\Functions;
use RomCommerce\Modules\OrderPaymentFilter\Module;
use RomCommerce\Tests\TestCase;

final class ModuleTest extends TestCase {

	protected function tearDown(): void {
		unset( $_GET[ Module::QUERY_VAR ] );
		parent::tearDown();
	}

	public function test_the_hpos_query_gets_the_chosen_method(): void {
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		$_GET[ Module::QUERY_VAR ] = 'cod';

		self::assertSame(
			array(
				'status'         => 'all',
				'payment_method' => 'cod',
			),
			( new Module() )->filter_hpos_query( array( 'status' => 'all' ) )
		);
	}

	public function test_no_choice_leaves_the_query_alone(): void {
		self::assertSame( array( 'status' => 'all' ), ( new Module() )->filter_hpos_query( array( 'status' => 'all' ) ) );
	}

	public function test_a_removed_gateway_still_shows_while_it_is_the_filter(): void {
		Functions\when( 'WC' )->justReturn( new class() {
			/** @return null */
			public function payment_gateways() {
				return null;
			}
		} );

		self::assertSame( array( 'old_gateway' => 'old_gateway' ), Module::options( 'old_gateway' ) );
		self::assertSame( array(), Module::options() );
	}
}
