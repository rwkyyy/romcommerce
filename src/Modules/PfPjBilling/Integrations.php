<?php

declare(strict_types=1);

namespace RomCommerce\Modules\PfPjBilling;

use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Feeds the company data into the three invoicing plugins found wired into the
 * reference plugin's source (SmartBill, Oblio, EasySales) via their own filters.
 * Company fields are only populated for PJ orders. Filter argument shapes follow
 * the reference plugin's wiring; each handler is defensive about the order arg
 * so a differing arg count degrades to a no-op instead of erroring.
 */
final class Integrations {

	public function register(): void {
		add_filter( 'woo_smartbill_data', array( $this, 'smartbill' ), 10, 2 );
		add_filter( 'woocommerce_oblio_invoice_data', array( $this, 'oblio' ), 10, 2 );
		add_filter( 'es_order_info_transform', array( $this, 'easysales' ), 10, 2 );
	}

	/**
	 * @param mixed $data
	 * @param mixed $order
	 * @return mixed
	 */
	public function smartbill( $data, $order = null ) {
		$company = $this->company( $order );
		if ( null === $company || ! is_array( $data ) ) {
			return $data;
		}

		$data['vatCode'] = $company['cui'];
		$data['regCom']  = $company['reg_com'];
		if ( '' !== $company['name'] ) {
			$data['name'] = $company['name'];
		}

		return $data;
	}

	/**
	 * @param mixed $data
	 * @param mixed $order
	 * @return mixed
	 */
	public function oblio( $data, $order = null ) {
		$company = $this->company( $order );
		if ( null === $company || ! is_array( $data ) ) {
			return $data;
		}

		if ( ! isset( $data['client'] ) || ! is_array( $data['client'] ) ) {
			$data['client'] = array();
		}

		$data['client']['cif']  = $company['cui'];
		$data['client']['rc']   = $company['reg_com'];
		$data['client']['iban'] = $company['iban'];
		$data['client']['bank'] = $company['bank'];

		return $data;
	}

	/**
	 * @param mixed $data
	 * @param mixed $order
	 * @return mixed
	 */
	public function easysales( $data, $order = null ) {
		$company = $this->company( $order );
		if ( null === $company || ! is_array( $data ) ) {
			return $data;
		}

		if ( ! isset( $data['customer'] ) || ! is_array( $data['customer'] ) ) {
			$data['customer'] = array();
		}

		$data['customer']['vat_id']              = $company['cui'];
		$data['customer']['registration_number'] = $company['reg_com'];
		$data['customer']['iban']                = $company['iban'];
		$data['customer']['bank']                = $company['bank'];

		return $data;
	}

	/**
	 * @param mixed $order
	 * @return array{cui: string, reg_com: string, iban: string, bank: string, name: string}|null
	 */
	private function company( $order ): ?array {
		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		$billing = $order->get_meta( Fields::META_KEY );
		if ( ! is_array( $billing ) || ( $billing['type'] ?? '' ) !== Fields::TYPE_PJ ) {
			return null;
		}

		return array(
			'cui'     => (string) ( $billing['cui'] ?? '' ),
			'reg_com' => (string) ( $billing['reg_com'] ?? '' ),
			'iban'    => (string) ( $billing['iban'] ?? '' ),
			'bank'    => (string) ( $billing['bank'] ?? '' ),
			'name'    => (string) $order->get_billing_company(),
		);
	}
}
