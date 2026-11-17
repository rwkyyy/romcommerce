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
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'WC' )->justReturn( self::gateways( array() ) );

		self::assertSame( array( 'old_gateway' => 'old_gateway' ), Module::options( 'old_gateway' ) );
		self::assertSame( array(), Module::options() );
	}

	public function test_only_switched_on_gateways_are_listed_by_default(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\expect( 'get_transient' )->never();
		Functions\when( 'WC' )->justReturn(
			self::gateways(
				array(
					'cod'  => self::gateway( 'yes', 'Ramburs' ),
					'bacs' => self::gateway( 'no', 'Transfer bancar' ),
				)
			)
		);

		self::assertSame( array( 'cod' => 'Ramburs' ), Module::options() );
	}

	public function test_order_history_adds_switched_off_and_removed_methods(): void {
		Functions\when( 'get_option' )->justReturn( array( 'listed' => Module::LIST_HISTORY ) );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'get_transient' )->justReturn(
			array(
				'bacs'    => 12,
				'netopia' => 40,
			)
		);
		Functions\when( 'wc_get_order' )->justReturn( false );
		Functions\when( 'WC' )->justReturn(
			self::gateways(
				array(
					'cod'  => self::gateway( 'yes', 'Ramburs' ),
					'bacs' => self::gateway( 'no', 'Transfer bancar' ),
				)
			)
		);

		self::assertSame(
			array(
				'cod'     => 'Ramburs',
				'bacs'    => 'Transfer bancar',
				'netopia' => 'netopia',
			),
			Module::options()
		);
	}

	/** @param array<string, object> $list */
	private static function gateways( array $list ): object {
		return new class( $list ) {
			/** @var array<string, object> */
			private $list;

			/** @param array<string, object> $list */
			public function __construct( array $list ) {
				$this->list = $list;
			}

			/** @return object|null */
			public function payment_gateways() {
				if ( array() === $this->list ) {
					return null;
				}

				return new class( $this->list ) {
					/** @var array<string, object> */
					private $list;

					/** @param array<string, object> $list */
					public function __construct( array $list ) {
						$this->list = $list;
					}

					/** @return array<string, object> */
					public function payment_gateways(): array {
						return $this->list;
					}
				};
			}
		};
	}

	private static function gateway( string $enabled, string $title ): object {
		return new class( $enabled, $title ) {
			/** @var string */
			public $enabled;

			/** @var string */
			private $title;

			public function __construct( string $enabled, string $title ) {
				$this->enabled = $enabled;
				$this->title   = $title;
			}

			public function get_title(): string {
				return $this->title;
			}

			public function get_method_title(): string {
				return '';
			}
		};
	}
}
