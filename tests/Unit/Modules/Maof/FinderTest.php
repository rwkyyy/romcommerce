<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\Maof;

use Brain\Monkey\Functions;
use Mockery;
use RomCommerce\Modules\Maof\Finder;
use RomCommerce\Tests\TestCase;
use WC_Order;

final class FinderTest extends TestCase {

	public function test_normalize_email_lowercases_and_trims(): void {
		self::assertSame( 'buyer@example.ro', Finder::normalize_email( '  Buyer@Example.RO  ' ) );
	}

	/** @dataProvider phoneProvider */
	public function test_normalize_phone( string $input, string $expected ): void {
		self::assertSame( $expected, Finder::normalize_phone( $input ) );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function phoneProvider(): array {
		return array(
			'local leading zero'   => array( '0712345678', '+40712345678' ),
			'already E.164'        => array( '+40712345678', '+40712345678' ),
			'00 international'     => array( '0040712345678', '+40712345678' ),
			'40 without plus'      => array( '40712345678', '+40712345678' ),
			'bare 9-digit number'  => array( '712345678', '+40712345678' ),
			'formatted with spaces and dashes' => array( '07 1234-5678', '+40712345678' ),
			'empty string'         => array( '', '' ),
			'no digits at all'     => array( 'n/a', '' ),
		);
	}

	public function test_related_orders_matches_on_email_and_flags_strong_match(): void {
		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_billing_email' )->andReturn( 'buyer@example.ro' );
		$order->shouldReceive( 'get_billing_phone' )->andReturn( '' );
		$order->shouldReceive( 'get_id' )->andReturn( 1 );
		$order->shouldReceive( 'get_customer_id' )->andReturn( 0 );

		$candidate = Mockery::mock( WC_Order::class );
		$candidate->shouldReceive( 'get_id' )->andReturn( 2 );
		$candidate->shouldReceive( 'get_billing_email' )->andReturn( 'buyer@example.ro' );
		$candidate->shouldReceive( 'get_billing_phone' )->andReturn( '' );
		$candidate->shouldReceive( 'get_customer_id' )->andReturn( 0 );
		$candidate->shouldReceive( 'get_order_number' )->andReturn( '#2' );
		$candidate->shouldReceive( 'get_date_created' )->andReturn( null );
		$candidate->shouldReceive( 'get_status' )->andReturn( 'processing' );

		Functions\expect( 'wc_get_orders' )
			->once()
			->with( Mockery::on( static function ( array $args ): bool {
				return 'buyer@example.ro' === ( $args['billing_email'] ?? null );
			} ) )
			->andReturn( array( $candidate ) );

		Functions\expect( 'wc_get_order_status_name' )
			->with( 'processing' )
			->andReturn( 'Processing' );

		$matches = ( new Finder() )->related_orders( $order );

		self::assertCount( 1, $matches );
		self::assertSame( 2, $matches[0]['order_id'] );
		self::assertSame( 'email', $matches[0]['basis'] );
		self::assertTrue( $matches[0]['active'] );
		self::assertFalse( $matches[0]['strong'] );
	}

	public function test_related_orders_returns_empty_when_order_has_no_identifiers(): void {
		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_billing_email' )->andReturn( '' );
		$order->shouldReceive( 'get_billing_phone' )->andReturn( '' );

		self::assertSame( array(), ( new Finder() )->related_orders( $order ) );
	}
}
