<?php

declare(strict_types=1);

namespace RomCommerce\Modules\PfPjBilling;

defined( 'ABSPATH' ) || exit;

/**
 * Field names, meta key and person-type constants for the PF/PJ billing module,
 * declared once so the checkout, the legacy-key shim, the invoicing
 * integrations and the VAT-ID module all agree. Company name deliberately
 * reuses WooCommerce's native billing_company — we do not invent a duplicate.
 */
final class Fields {

	public const PERSON_TYPE = 'billing_romcommerce_person_type';
	public const CUI         = 'billing_romcommerce_cui';
	public const REG_COM     = 'billing_romcommerce_reg_com';
	public const BANK        = 'billing_romcommerce_bank';
	public const IBAN        = 'billing_romcommerce_iban';
	public const CNP         = 'billing_romcommerce_cnp';

	/**
	 * Block checkout field ids (Additional Checkout Fields API; the CNP in the
	 * "order" location, the rest in "contact", see BlockCheckout). A checkbox
	 * rather than the classic PF/PJ select: unticked is PF, so nobody has to
	 * pick before the form is usable.
	 */
	public const BLOCK_IS_COMPANY = 'romcommerce/is-company';
	public const BLOCK_COMPANY    = 'romcommerce/company';
	public const BLOCK_CUI        = 'romcommerce/cui';
	public const BLOCK_REG_COM    = 'romcommerce/reg-com';
	public const BLOCK_BANK       = 'romcommerce/bank';
	public const BLOCK_IBAN       = 'romcommerce/iban';
	public const BLOCK_CNP        = 'romcommerce/cnp';

	/** Single consolidated order-meta entry holding all PF/PJ data (CRUD-only). */
	public const META_KEY = '_romcommerce_billing';

	public const TYPE_PF = 'pf';
	public const TYPE_PJ = 'pj';

	/**
	 * e-Factura in principle requires a CNP for PF buyers, but a derogation
	 * permits this 13-zero placeholder instead — used under the hood whenever
	 * the real CNP field is off (the default).
	 */
	public const CNP_PLACEHOLDER = '0000000000000';
}
