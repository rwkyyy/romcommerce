<?php

declare(strict_types=1);

namespace RomCommerce\Modules\PfPjBilling;

use RomCommerce\Support\Blocks;
use RomCommerce\Settings;
use WC_Order;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The PF/PJ fields on the block checkout, through WooCommerce's Additional
 * Checkout Fields API. The company checkbox and fields use the "contact"
 * location, right after the email and before the addresses, so a company
 * fills in its details first (owner, 2026-10-10); WooCommerce also saves
 * contact fields to a logged-in customer's account, which prefills them next
 * time. The CNP stays in the "order" location, kept on the order only and
 * never synced to the account (elevated protection under Law 190/2018).
 *
 * WooCommerce also stores each field under its own `_wc_*` order meta and
 * shows them on the order screen and emails; the consolidated
 * Fields::META_KEY entry the invoicing integrations read is written on top.
 */
final class BlockCheckout {

	private const MODULE_ID = 'pf-pj-billing';

	public function register(): void {
		add_action( 'woocommerce_init', array( $this, 'register_fields' ) );
		add_action( 'woocommerce_blocks_validate_location_contact_fields', array( $this, 'validate_company' ), 10, 2 );
		add_action( 'woocommerce_blocks_validate_location_order_fields', array( $this, 'validate_cnp' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'save' ), 10, 2 );
	}

	public function register_fields(): void {
		if ( ! Blocks::has_checkout_fields_api() ) {
			return;
		}

		foreach ( $this->fields() as $field ) {
			woocommerce_register_additional_checkout_field( $field );
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function fields(): array {
		$rules   = self::supports_rules();
		$company = $rules ? $this->company_rule() : array();
		$fields  = array(
			array(
				'id'       => Fields::BLOCK_IS_COMPANY,
				'label'    => __( 'Cumpăr ca persoană juridică (firmă)', 'romcommerce' ),
				'location' => 'contact',
				'type'     => 'checkbox',
			),
			$this->text( Fields::BLOCK_COMPANY, __( 'Denumire firmă', 'romcommerce' ), $company, true ),
			$this->text( Fields::BLOCK_CUI, __( 'CUI / CIF', 'romcommerce' ), $company, true ),
			$this->text( Fields::BLOCK_REG_COM, __( 'Nr. Reg. Com.', 'romcommerce' ), $company, false ),
			$this->text( Fields::BLOCK_BANK, __( 'Bancă', 'romcommerce' ), $company, false ),
			$this->text( Fields::BLOCK_IBAN, __( 'IBAN', 'romcommerce' ), $company, false ),
		);

		if ( $this->cnp_enabled() ) {
			$cnp             = $this->text( Fields::BLOCK_CNP, __( 'CNP', 'romcommerce' ), array(), false );
			$cnp['location'] = 'order';
			if ( $rules ) {
				$cnp['hidden'] = $this->is_company_schema();
			}
			$fields[] = $cnp;
		}

		return $fields;
	}

	/**
	 * Conditional `hidden`/`required` rules (JSON Schema against the checkout
	 * document). Without them, on older WooCommerce, the company fields show
	 * for everyone as optional and validate() still enforces the PJ minimum.
	 */
	public static function supports_rules(): bool {
		return class_exists( '\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFieldsSchema\DocumentObject' );
	}

	/**
	 * @param WP_Error             $errors
	 * @param array<string, mixed> $fields Submitted "contact" location values.
	 */
	public function validate_company( $errors, $fields ): void {
		if ( ! $errors instanceof WP_Error || ! is_array( $fields ) ) {
			return;
		}

		foreach ( BillingData::errors( self::values( $fields ), false ) as $code => $message ) {
			$errors->add( $code, $message );
		}
	}

	/**
	 * The CNP arrives with the "order" location, apart from the company box;
	 * for a company it's hidden and so sent empty, which never fails.
	 *
	 * @param WP_Error             $errors
	 * @param array<string, mixed> $fields Submitted "order" location values.
	 */
	public function validate_cnp( $errors, $fields ): void {
		if ( ! $errors instanceof WP_Error || ! is_array( $fields ) || ! $this->cnp_enabled() ) {
			return;
		}

		$values = self::values( $fields );
		foreach ( BillingData::errors(
			array(
				Fields::PERSON_TYPE => Fields::TYPE_PF,
				Fields::CNP         => $values[ Fields::CNP ],
			),
			true
		) as $code => $message ) {
			$errors->add( $code, $message );
		}
	}

	/**
	 * @param WC_Order         $order
	 * @param \WP_REST_Request $request
	 */
	public function save( $order, $request ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// An untouched checkbox isn't sent at all, so a missing key means PF.
		$fields = $request['additional_fields'] ?? array();
		if ( ! is_array( $fields ) ) {
			$fields = array();
		}

		$values = self::values( $fields );
		if ( Fields::TYPE_PJ === $values[ Fields::PERSON_TYPE ] ) {
			$order->set_billing_company( $values['billing_company'] );
		}

		$order->update_meta_data( Fields::META_KEY, BillingData::for_order( $values, $this->cnp_enabled() ) );
	}

	/**
	 * Block field values mapped onto the classic keys BillingData takes.
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, string>
	 */
	public static function values( array $fields ): array {
		$text = static function ( string $key ) use ( $fields ): string {
			return isset( $fields[ $key ] ) && is_scalar( $fields[ $key ] ) ? trim( (string) $fields[ $key ] ) : '';
		};

		return array(
			Fields::PERSON_TYPE => ! empty( $fields[ Fields::BLOCK_IS_COMPANY ] ) ? Fields::TYPE_PJ : Fields::TYPE_PF,
			'billing_company'   => $text( Fields::BLOCK_COMPANY ),
			Fields::CUI         => $text( Fields::BLOCK_CUI ),
			Fields::REG_COM     => $text( Fields::BLOCK_REG_COM ),
			Fields::BANK        => $text( Fields::BLOCK_BANK ),
			Fields::IBAN        => $text( Fields::BLOCK_IBAN ),
			Fields::CNP         => $text( Fields::BLOCK_CNP ),
		);
	}

	private function cnp_enabled(): bool {
		return (bool) ( Settings::get( self::MODULE_ID )['cnp_enabled'] ?? false );
	}

	/**
	 * @param array<string, mixed> $rule Output of company_rule(), or empty for no rules.
	 * @return array<string, mixed>
	 */
	private function text( string $id, string $label, array $rule, bool $required_for_company ): array {
		$field = array(
			'id'       => $id,
			'label'    => $label,
			'location' => 'contact',
			'type'     => 'text',
		);

		if ( ! empty( $rule ) ) {
			$field['hidden'] = $rule['hidden'];
			if ( $required_for_company ) {
				$field['required'] = $rule['required'];
			}
		}

		return $field;
	}

	/** @return array{hidden: array<string, mixed>, required: array<string, mixed>} */
	private function company_rule(): array {
		return array(
			'hidden'   => $this->is_individual_schema(),
			'required' => $this->is_company_schema(),
		);
	}

	/**
	 * Matches when the company box is ticked (contact fields sit under
	 * customer.additional_fields in the checkout document). `required` on both
	 * levels so a request without the field doesn't count as a company.
	 *
	 * @return array<string, mixed>
	 */
	private function is_company_schema(): array {
		return array(
			'customer' => array(
				'required'   => array( 'additional_fields' ),
				'properties' => array(
					'additional_fields' => array(
						'required'   => array( Fields::BLOCK_IS_COMPANY ),
						'properties' => array(
							Fields::BLOCK_IS_COMPANY => array( 'const' => true ),
						),
					),
				),
			),
		);
	}

	/** @return array<string, mixed> */
	private function is_individual_schema(): array {
		return array(
			'customer' => array(
				'not' => $this->is_company_schema()['customer'],
			),
		);
	}
}
