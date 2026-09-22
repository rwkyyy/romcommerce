<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Support;

use RomCommerce\Support\Validators;
use RomCommerce\Tests\TestCase;

final class ValidatorsTest extends TestCase {

	/** @dataProvider cnpProvider */
	public function test_cnp( string $value, bool $expected ): void {
		self::assertSame( $expected, Validators::cnp( $value ) );
	}

	/** @return array<string, array{0: string, 1: bool}> */
	public static function cnpProvider(): array {
		return array(
			'valid male 1990'         => array( '1900101221140', true ),
			'valid female 1985'       => array( '2850315221140', true ),
			'wrong length'            => array( '123456789', false ),
			'leading zero disallowed' => array( '0900101221140', false ),
			'month out of range'      => array( '1901301221140', false ),
			'day out of range'        => array( '1900199221140', false ),
			'bad checksum'            => array( '1900101221141', false ),
			'non digit separators are stripped' => array( '190-010-122-114-0', true ),
		);
	}

	/** @dataProvider cuiProvider */
	public function test_cui( string $value, bool $expected ): void {
		self::assertSame( $expected, Validators::cui( $value ) );
	}

	/** @return array<string, array{0: string, 1: bool}> */
	public static function cuiProvider(): array {
		return array(
			'valid with RO prefix'    => array( 'RO18547290', true ),
			'valid without prefix'    => array( '18547290', true ),
			'valid lowercase ro'      => array( 'ro18547290', true ),
			'valid with spaces'       => array( ' RO 18547290 ', true ),
			'bad checksum'            => array( 'RO18547291', false ),
			'too short'               => array( '1', false ),
			'too long'                => array( '12345678901', false ),
		);
	}

	public function test_normalize_cui_strips_prefix_and_non_digits(): void {
		self::assertSame( '18547290', Validators::normalize_cui( 'RO 18547290' ) );
		self::assertSame( '18547290', Validators::normalize_cui( 'ro18547290' ) );
		self::assertSame( '18547290', Validators::normalize_cui( '18547290' ) );
	}

	/** @dataProvider ibanProvider */
	public function test_iban( string $value, bool $expected ): void {
		self::assertSame( $expected, Validators::iban( $value ) );
	}

	/** @return array<string, array{0: string, 1: bool}> */
	public static function ibanProvider(): array {
		return array(
			'valid RO IBAN'          => array( 'RO49AAAA1B31007593840000', true ),
			'valid with spaces'      => array( 'RO49 AAAA 1B31 0075 9384 0000', true ),
			'valid lowercase'        => array( 'ro49aaaa1b31007593840000', true ),
			'valid GB IBAN'          => array( 'GB29NWBK60161331926819', true ),
			'bad checksum'           => array( 'RO49AAAA1B31007593840001', false ),
			'wrong format'           => array( 'not-an-iban', false ),
			'too short'              => array( 'RO49AAAA', false ),
		);
	}
}
