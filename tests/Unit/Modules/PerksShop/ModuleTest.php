<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\PerksShop;

use Brain\Monkey\Functions;
use RomCommerce\Modules\PerksShop\Icons;
use RomCommerce\Modules\PerksShop\Module;
use RomCommerce\Tests\TestCase;

final class ModuleTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
	}

	public function test_without_saved_badges_the_three_defaults_show(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$titles = array_column( ( new Module() )->badges(), 'title' );

		self::assertSame( array( 'Livrare rapidă', 'Retur în 14 zile', 'Plată securizată' ), $titles );
	}

	public function test_saved_badges_skip_rows_without_a_title(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'badges' => array(
					array(
						'icon'  => 'gift',
						'title' => 'Cadou',
						'text'  => '',
					),
					array(
						'icon'  => 'truck',
						'title' => '  ',
						'text'  => 'ignored',
					),
				),
			)
		);

		self::assertSame(
			array(
				array(
					'icon'          => 'gift',
					'icon_type'     => 'default',
					'icon_media_id' => 0,
					'icon_svg'      => '',
					'icon_dashicon' => '',
					'title'         => 'Cadou',
					'text'          => '',
				),
			),
			( new Module() )->badges()
		);
	}

	public function test_clean_badge_keeps_a_custom_icon_choice_and_drops_an_unknown_type(): void {
		$badge = Module::clean_badge(
			array(
				'icon_type'     => 'dashicon',
				'icon_dashicon' => 'dashicons-cart',
				'title'         => 'Coș',
			)
		);
		self::assertSame( 'dashicon', $badge['icon_type'] );
		self::assertSame( 'dashicons-cart', $badge['icon_dashicon'] );

		self::assertSame( 'default', Module::clean_badge( array( 'icon_type' => 'emoji' ) )['icon_type'] );
	}

	public function test_clean_badge_drops_an_unknown_icon(): void {
		self::assertSame( '', Module::clean_badge( array( 'icon' => '<script>' ) )['icon'] );
		self::assertSame( 'lock', Module::clean_badge( array( 'icon' => 'lock' ) )['icon'] );
	}

	public function test_every_icon_has_a_label_and_renders(): void {
		foreach ( array_keys( Icons::labels() ) as $key ) {
			self::assertStringStartsWith( '<svg', Icons::svg( $key ), $key );
		}
		self::assertSame( '', Icons::svg( 'nope' ) );
	}
}
