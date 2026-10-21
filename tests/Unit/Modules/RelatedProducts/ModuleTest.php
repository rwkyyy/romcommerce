<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Modules\RelatedProducts;

use RomCommerce\Modules\RelatedProducts\Module;
use RomCommerce\Tests\TestCase;

final class ModuleTest extends TestCase {

	/** @return array<string, mixed> */
	private function collection( string $collection ): array {
		return array(
			'blockName' => 'woocommerce/product-collection',
			'attrs'     => array(
				'collection'    => $collection,
				'query'         => array(
					'perPage'   => 5,
					'relatedBy' => array(
						'categories' => true,
						'tags'       => true,
					),
				),
				'displayLayout' => array(
					'type'    => 'flex',
					'columns' => 5,
				),
			),
		);
	}

	public function test_recognises_the_related_collection_and_the_legacy_block(): void {
		self::assertTrue( Module::is_related_block( $this->collection( 'woocommerce/product-collection/related' ) ) );
		self::assertTrue( Module::is_related_block( array( 'blockName' => 'woocommerce/related-products' ) ) );
		self::assertFalse( Module::is_related_block( $this->collection( 'woocommerce/product-collection/featured' ) ) );
	}

	public function test_writes_the_rules_into_the_block_attributes(): void {
		$block = Module::with_rules( $this->collection( 'woocommerce/product-collection/related' ), true, false, 3, 2 );

		self::assertSame(
			array(
				'categories' => true,
				'tags'       => false,
			),
			$block['attrs']['query']['relatedBy']
		);
		self::assertSame( 3, $block['attrs']['query']['perPage'] );
		self::assertSame( 2, $block['attrs']['displayLayout']['columns'] );
		self::assertSame( 'flex', $block['attrs']['displayLayout']['type'] );
	}

	public function test_fills_in_missing_attributes(): void {
		$block = Module::with_rules( array( 'blockName' => 'woocommerce/product-collection' ), false, true, 4, 4 );

		self::assertSame( 4, $block['attrs']['query']['perPage'] );
		self::assertSame( 4, $block['attrs']['displayLayout']['columns'] );
	}
}
