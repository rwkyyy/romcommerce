<?php

declare(strict_types=1);

namespace RomCommerce\Modules\MultiAddress;

defined( 'ABSPATH' ) || exit;

/**
 * Per-customer saved delivery addresses, stored as one user-meta array keyed by
 * a generated id. WooCommerce natively keeps only a single shipping address, so
 * this is an additive store alongside it — never overwrites WC's own address.
 */
final class AddressBook {

	private const META = 'romcommerce_delivery_addresses';

	private const MAX = 20;

	/** Mirrors WooCommerce's own required shipping fields; company/address_2/phone/label stay optional there too. */
	public const REQUIRED = array( 'first_name', 'last_name', 'country', 'address_1', 'city', 'postcode' );

	/** @return array<string, array<string, string>> */
	public static function all( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::META, true );

		return is_array( $stored ) ? $stored : array();
	}

	/** @return array<string, string>|null */
	public static function get( int $user_id, string $id ): ?array {
		$all = self::all( $user_id );

		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * @param array<string, string> $address
	 * @return string the id it was stored under ('' if the limit was hit)
	 */
	public static function save( int $user_id, array $address, string $id = '' ): string {
		$all = self::all( $user_id );

		if ( '' === $id ) {
			if ( count( $all ) >= self::MAX ) {
				return '';
			}
			$id = uniqid( 'addr_' );
		}

		$all[ $id ] = self::sanitize( $address );
		update_user_meta( $user_id, self::META, $all );

		return $id;
	}

	public static function delete( int $user_id, string $id ): void {
		$all = self::all( $user_id );
		unset( $all[ $id ] );
		update_user_meta( $user_id, self::META, $all );
	}

	/** @return array<string, string> field key => label */
	public static function fields(): array {
		return array(
			'label'      => __( 'Etichetă (ex. Acasă, Birou)', 'romcommerce' ),
			'first_name' => __( 'Prenume', 'romcommerce' ),
			'last_name'  => __( 'Nume', 'romcommerce' ),
			'company'    => __( 'Firmă (opțional)', 'romcommerce' ),
			'country'    => __( 'Țară', 'romcommerce' ),
			'state'      => __( 'Județ', 'romcommerce' ),
			'city'       => __( 'Oraș', 'romcommerce' ),
			'postcode'   => __( 'Cod poștal', 'romcommerce' ),
			'address_1'  => __( 'Adresă', 'romcommerce' ),
			'address_2'  => __( 'Adresă (rând 2, opțional)', 'romcommerce' ),
			'phone'      => __( 'Telefon (opțional)', 'romcommerce' ),
		);
	}

	/**
	 * @param array<string, string> $input
	 * @return array<string, string>
	 */
	public static function sanitize( array $input ): array {
		$clean = array();
		foreach ( array_keys( self::fields() ) as $key ) {
			$clean[ $key ] = isset( $input[ $key ] ) ? sanitize_text_field( (string) $input[ $key ] ) : '';
		}

		return $clean;
	}

	/** @param array<string, string> $address */
	public static function summary( array $address ): string {
		$parts = array_filter(
			array(
				$address['address_1'] ?? '',
				$address['city'] ?? '',
				$address['state'] ?? '',
				$address['postcode'] ?? '',
			)
		);

		return implode( ', ', $parts );
	}
}
