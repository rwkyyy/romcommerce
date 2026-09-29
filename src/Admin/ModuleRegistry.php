<?php

declare(strict_types=1);

namespace RomCommerce\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Static nav metadata for every RomCommerce feature, Lite and Pro alike —
 * the sole source of nav copy/placement (modules don't duplicate it). Pro-only
 * entries describe Pro's features in plain text only, never referencing
 * RomCommerce\Pro\* — this file ships in Lite.
 *
 * Keys here are the canonical module ids: whenever a module is actually
 * built, its ModuleInterface::id() must return the matching key so Page can
 * find it via ModuleLoader::get() and replace the placeholder with the live
 * settings pane.
 */
final class ModuleRegistry {

	public const CATEGORIES = array(
		'compliance-legal'         => 'Compliance & Legal',
		'checkout-billing'         => 'Checkout & Billing',
		'orders-fulfilment'        => 'Orders & Fulfilment',
		'storefront-merchandising' => 'Storefront & Merchandising',
		'admin-branding'           => 'Admin & Branding',
	);

	private const MODULES = array(
		// Compliance & Legal
		'sal-pictogram'           => array(
			'tier'        => 'lite',
			'category'    => 'compliance-legal',
			'label'       => 'SAL Pictogram',
			'description' => 'Displays the official ANPC SAL pictogram on the homepage, linking to the SAL dispute-resolution platform.',
		),
		'legal-guarantee-notice'  => array(
			'tier'        => 'lite',
			'category'    => 'compliance-legal',
			'label'       => 'Legal Guarantee Notice',
			'description' => 'Displays the EU-mandated harmonised notice on the legal guarantee of conformity, plus the optional EU GARAN durability label. Required EU-wide from 27 September 2026.',
		),
		'price-history'           => array(
			'tier'        => 'lite',
			'category'    => 'compliance-legal',
			'label'       => 'Price History',
			'description' => 'Logs every price change and shows the lowest price of the last 30 days next to any sale price, per the Omnibus Directive.',
		),
		'pf-pj-billing'           => array(
			'tier'        => 'lite',
			'category'    => 'compliance-legal',
			'label'       => 'PF/PJ Billing Fields',
			'description' => 'Individual vs. company checkout billing fields, compatible with the Romanian invoicing-plugin ecosystem.',
		),
		'vat-id-validation'       => array(
			'tier'        => 'lite',
			'category'    => 'compliance-legal',
			'label'       => 'VAT ID Validation',
			'description' => 'CUI/CIF format and checksum validation at checkout.',
		),
		'maof'                    => array(
			'tier'        => 'lite',
			'category'    => 'compliance-legal',
			'label'       => 'MAOF',
			'description' => 'Flags possible related orders and duplicate active orders for staff review.',
		),
		'compliance-checklist'    => array(
			'tier'        => 'pro',
			'category'    => 'compliance-legal',
			'label'       => 'Compliance Checklist',
			'description' => 'A guided audit of your shop\'s legal and trust readiness, with auto-checks and links to fix failing items.',
		),
		'seap-badge'              => array(
			'tier'        => 'pro',
			'category'    => 'compliance-legal',
			'label'       => 'SEAP Badge',
			'description' => 'Displays the SEAP registration badge where required.',
		),

		// Checkout & Billing
		'counties-postcodes'      => array(
			'tier'        => 'lite',
			'category'    => 'checkout-billing',
			'label'       => 'Counties & Postcodes',
			'description' => 'Romanian county and postcode base data for checkout address fields.',
		),
		'address-autocomplete'    => array(
			'tier'        => 'pro',
			'category'    => 'checkout-billing',
			'label'       => 'Address Autocomplete',
			'description' => 'Bidirectional postcode/street autocomplete at checkout, sourced from a RomCommerce-operated Romanian address dataset.',
		),
		'phone-validation'        => array(
			'tier'        => 'pro',
			'category'    => 'checkout-billing',
			'label'       => 'Phone Validation',
			'description' => 'International phone number validation with a country-selector UI at checkout.',
		),
		'email-verification'      => array(
			'tier'        => 'pro',
			'category'    => 'checkout-billing',
			'label'       => 'Email Verification',
			'description' => 'Checks whether a checkout email address is deliverable, without blocking submission.',
		),
		'hide-voucher'            => array(
			'tier'        => 'lite',
			'category'    => 'checkout-billing',
			'label'       => 'Hide Voucher at Checkout',
			'description' => 'Hides the coupon/voucher field from the checkout page.',
		),
		'coupon-cleanup'          => array(
			'tier'        => 'lite',
			'category'    => 'checkout-billing',
			'label'       => 'Coupon Cleanup',
			'description' => 'Automatically trashes expired coupons daily, with an option to permanently empty the coupon trash after a set number of days.',
		),
		'emergency-delivery-fees' => array(
			'tier'        => 'pro',
			'category'    => 'checkout-billing',
			'label'       => 'Emergency Delivery Fees',
			'description' => 'Surcharges for expedited or out-of-schedule delivery.',
		),
		'deposits-custom-pricing' => array(
			'tier'        => 'pro',
			'category'    => 'checkout-billing',
			'label'       => 'Deposits & Custom Pricing',
			'description' => 'Partial-payment deposits and custom per-order pricing.',
		),
		'gift-wrap'               => array(
			'tier'        => 'pro',
			'category'    => 'checkout-billing',
			'label'       => 'Gift Wrap',
			'description' => 'An add-on gift-wrap option at checkout.',
		),

		// Orders & Fulfilment
		'order-confirmation'      => array(
			'tier'        => 'pro',
			'category'    => 'orders-fulfilment',
			'label'       => 'Order Confirmation',
			'description' => 'One-click order confirmation by email and QR code, so customers confirm orders without a phone call.',
		),
		'qr-payments'             => array(
			'tier'        => 'pro',
			'category'    => 'orders-fulfilment',
			'label'       => 'QR Payments / POS',
			'description' => 'QR codes for in-store, POS-created order confirmation and payment.',
		),
		'courier-nomenclature'    => array(
			'tier'        => 'pro',
			'category'    => 'orders-fulfilment',
			'label'       => 'Courier Nomenclature',
			'description' => 'Standardised courier/locality naming for shipping labels.',
		),
		'pickup-store'            => array(
			'tier'        => 'pro',
			'category'    => 'orders-fulfilment',
			'label'       => 'Pickup Store Locations',
			'description' => 'Defines physical pickup locations for orders.',
		),
		'reserve-for-pickup'      => array(
			'tier'        => 'pro',
			'category'    => 'orders-fulfilment',
			'label'       => 'Reserve for Pickup',
			'description' => 'A lightweight reservation flow for customers to hold a product for in-store pickup.',
		),
		'multi-address'           => array(
			'tier'        => 'lite',
			'category'    => 'orders-fulfilment',
			'label'       => 'Multiple Delivery Addresses',
			'description' => 'Lets customers save more than one delivery address on their account.',
		),

		// Storefront & Merchandising
		'free-shipping-bar'       => array(
			'tier'        => 'lite',
			'category'    => 'storefront-merchandising',
			'label'       => 'Free Shipping Bar',
			'description' => 'A progress indicator showing how much more to spend for free shipping.',
		),
		'sale-category-sync'      => array(
			'tier'        => 'lite',
			'category'    => 'storefront-merchandising',
			'label'       => 'Sale Category Sync',
			'description' => 'Keeps a "Sale" product category in sync with products currently on sale.',
		),
		'related-products'        => array(
			'tier'        => 'lite',
			'category'    => 'storefront-merchandising',
			'label'       => 'Related Products',
			'description' => 'Rule-based control over which related products are shown.',
		),
		'review-page'             => array(
			'tier'        => 'lite',
			'category'    => 'storefront-merchandising',
			'label'       => 'Multi-Product Review Page',
			'description' => 'Lets a customer review every product from a past order on one page.',
		),
		'whatsapp-fab'            => array(
			'tier'        => 'lite',
			'category'    => 'storefront-merchandising',
			'label'       => 'WhatsApp / Contact FAB',
			'description' => 'A floating multi-channel contact button, with contextual WhatsApp deep-links.',
		),
		'clear-cart'              => array(
			'tier'        => 'lite',
			'category'    => 'storefront-merchandising',
			'label'       => 'Clear Cart',
			'description' => 'A one-click "empty cart" action.',
		),
		'perks-shop'              => array(
			'tier'        => 'pro',
			'category'    => 'storefront-merchandising',
			'label'       => 'Perks Shop',
			'description' => 'Trust and service badges shown near the Add to Cart button.',
		),

		// Admin & Branding
		'admin-branding'          => array(
			'tier'        => 'lite',
			'category'    => 'admin-branding',
			'label'       => 'Admin & Login Branding',
			'description' => 'Your site logo on the login screen and one brand colour across wp-admin, the login screen, and buttons. Pro adds a custom login background image with a gradient overlay.',
		),
	);

	/** @return array<string, array{tier: string, category: string, label: string, description: string}> */
	public static function all(): array {
		return self::MODULES;
	}

	/**
	 * Modules in a category, Lite (free) first then Pro — the free features a
	 * merchant can actually use lead; the Pro upsells sit after them. Stable
	 * within each tier (union preserves insertion order), so no PHP 8 sort
	 * stability assumption.
	 *
	 * @return array<string, array{tier: string, category: string, label: string, description: string}>
	 */
	public static function in_category( string $category ): array {
		$lite = array();
		$pro  = array();

		foreach ( self::MODULES as $id => $module ) {
			if ( $module['category'] !== $category ) {
				continue;
			}

			if ( 'pro' === $module['tier'] ) {
				$pro[ $id ] = $module;
			} else {
				$lite[ $id ] = $module;
			}
		}

		return $lite + $pro;
	}

	/** @return array{tier: string, category: string, label: string, description: string}|null */
	public static function get( string $module_id ): ?array {
		return self::MODULES[ $module_id ] ?? null;
	}
}
