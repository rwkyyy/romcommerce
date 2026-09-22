<?php

declare(strict_types=1);

namespace RomCommerce\Modules\PfPjBilling;

defined( 'ABSPATH' ) || exit;

/**
 * Synthesizes the legacy postmeta keys the Romanian invoicing ecosystem reads
 * (`_billing_facturare_*` and `_av_facturare_*`) on demand from our single
 * consolidated meta entry — the same interoperability trick the reference
 * plugin uses, without duplicating storage.
 *
 * This only fires for classic postmeta order storage; under HPOS an order id is
 * not a post, so raw get_post_meta() on it returns nothing regardless. HPOS
 * tools use the WC CRUD layer, and the named invoicing plugins are covered
 * directly by Integrations.
 */
final class MetaShim {

	private static bool $loading = false;

	public function register(): void {
		add_filter( 'get_post_metadata', array( $this, 'filter' ), 10, 4 );
	}

	/**
	 * @param mixed  $value
	 * @param int    $object_id
	 * @param string $meta_key
	 * @param bool   $single
	 * @return mixed
	 */
	public function filter( $value, $object_id, $meta_key, $single ) {
		if ( null !== $value || self::$loading ) {
			return $value;
		}

		$field = $this->field_for_key( $meta_key );
		if ( null === $field ) {
			return $value;
		}

		if ( 'shop_order' !== get_post_type( $object_id ) ) {
			return $value;
		}

		self::$loading = true;
		$order         = wc_get_order( $object_id );
		self::$loading = false;

		if ( ! $order ) {
			return $value;
		}

		$billing = $order->get_meta( Fields::META_KEY );
		if ( ! is_array( $billing ) ) {
			return $value;
		}

		$synth = isset( $billing[ $field ] ) ? (string) $billing[ $field ] : '';

		return $single ? $synth : array( $synth );
	}

	private function field_for_key( string $meta_key ): ?string {
		$map = array(
			'_billing_facturare_cnp'        => 'cnp',
			'_billing_facturare_cui'        => 'cui',
			'_billing_facturare_nr_reg_com' => 'reg_com',
			'_billing_facturare_nume_banca' => 'bank',
			'_billing_facturare_iban'       => 'iban',
			'_av_facturare_cnp'             => 'cnp',
			'_av_facturare_cui'             => 'cui',
			'_av_facturare_nr_reg_com'      => 'reg_com',
			'_av_facturare_nume_banca'      => 'bank',
			'_av_facturare_iban'            => 'iban',
		);

		return $map[ $meta_key ] ?? null;
	}
}
