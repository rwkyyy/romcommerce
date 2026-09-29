<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Support;

use ReflectionClass;

/**
 * Several modules keep genuinely pure, side-effect-free logic (parsing,
 * sanitizing, formatting) behind private methods rather than a public seam,
 * since the public API is the module's WP hook surface, not that logic
 * itself. Reflection is the least invasive way to unit-test it without
 * loosening the class's own encapsulation just for tests.
 */
trait InvokesPrivateMethods {

	/** @param array<int, mixed> $args */
	private function invokePrivate( object $object, string $method, array $args = array() ) {
		// No setAccessible() call: it's been a no-op since PHP 8.1 (private
		// methods are reflection-callable by default) and is deprecated
		// outright since PHP 8.5.
		return ( new ReflectionClass( $object ) )->getMethod( $method )->invokeArgs( $object, $args );
	}

	/** @return mixed */
	private function classConstant( string $class, string $constant ) {
		return ( new ReflectionClass( $class ) )->getConstant( $constant );
	}
}
