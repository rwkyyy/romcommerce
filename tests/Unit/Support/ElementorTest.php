<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Support;

use RomCommerce\Support\Elementor;
use RomCommerce\Tests\TestCase;

final class ElementorTest extends TestCase {

	/**
	 * @dataProvider conditions
	 *
	 * @param array<string, mixed> $conditions
	 */
	public function test_targets_products_reads_elementor_pros_single_template_conditions( array $conditions, bool $expected ): void {
		self::assertSame( $expected, Elementor::targets_products( $conditions ) );
	}

	/** @return array<string, array{0: array<string, mixed>, 1: bool}> */
	public static function conditions(): array {
		return array(
			'all products'           => array( array( 'single' => array( 36617 => array( 'include/product' ) ) ), true ),
			'one product category'   => array( array( 'single' => array( 12 => array( 'include/product/in_product_cat/5' ) ) ), true ),
			'entire site'            => array( array( 'single' => array( 12 => array( 'include/general' ) ) ), true ),
			'all singular'           => array( array( 'single' => array( 12 => array( 'include/singular' ) ) ), true ),
			'singular products'      => array( array( 'single' => array( 12 => array( 'include/singular/product' ) ) ), true ),
			'posts only'             => array( array( 'single' => array( 12 => array( 'include/singular/post' ) ) ), false ),
			'products excluded'      => array( array( 'single' => array( 12 => array( 'exclude/product' ) ) ), false ),
			'header and footer only' => array(
				array(
					'header' => array( 3 => array( 'include/general' ) ),
					'footer' => array( 4 => array( 'include/general' ) ),
				),
				false,
			),
			'nothing stored'         => array( array(), false ),
		);
	}
}
