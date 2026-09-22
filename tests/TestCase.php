<?php
/**
 * Base test case: wires Brain Monkey's per-test setUp/tearDown so WordPress
 * and WooCommerce functions can be stubbed with Brain\Monkey\Functions
 * instead of requiring a live WordPress install (this project only tests
 * against real WP/WC on Pallas — see CLAUDE.md).
 *
 * @package RomCommerce
 */

declare(strict_types=1);

namespace RomCommerce\Tests;

use Brain\Monkey;
use Mockery;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		// Mockery::close() (called by Monkey\tearDown()) verifies expectations
		// but doesn't count as a PHPUnit assertion, so Mockery-only tests are
		// flagged "risky: no assertions performed" without this.
		if ( null !== Mockery::getContainer() ) {
			$this->addToAssertionCount( Mockery::getContainer()->mockery_getExpectationCount() );
		}

		Monkey\tearDown();
		parent::tearDown();
	}
}
