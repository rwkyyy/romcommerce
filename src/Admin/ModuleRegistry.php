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

	/**
	 * Category ids in nav order. Labels live in categories(): a constant
	 * expression can't call __(), and these are shown in the translated admin.
	 */
	private const CATEGORY_IDS = array( 'compliance-legal', 'checkout-billing', 'cart-fees', 'orders-fulfilment', 'products-catalogue', 'reviews-trust', 'admin-branding' );

	private const MODULES = array(
		// Compliance & Legal
		'sal-pictogram'              => array(
			'tier'     => 'lite',
			'category' => 'compliance-legal',
		),
		'legal-guarantee-notice'     => array(
			'tier'     => 'lite',
			'category' => 'compliance-legal',
		),
		'price-history'              => array(
			'tier'     => 'lite',
			'category' => 'compliance-legal',
		),
		'compliance-checklist'       => array(
			'tier'     => 'pro',
			'category' => 'compliance-legal',
		),
		'seap-badge'                 => array(
			'tier'     => 'pro',
			'category' => 'compliance-legal',
		),

		// Checkout & Billing
		'pf-pj-billing'              => array(
			'tier'     => 'lite',
			'category' => 'checkout-billing',
		),
		'vat-id-validation'          => array(
			'tier'     => 'lite',
			'category' => 'checkout-billing',
		),
		'counties-postcodes'         => array(
			'tier'     => 'lite',
			'category' => 'checkout-billing',
		),
		'phone-validation'           => array(
			'tier'     => 'lite',
			'category' => 'checkout-billing',
		),
		'vat-rates'                  => array(
			'tier'     => 'lite',
			'category' => 'checkout-billing',
		),
		'eu-countries'               => array(
			'tier'     => 'lite',
			'category' => 'checkout-billing',
		),
		'address-autocomplete'       => array(
			'tier'     => 'pro',
			'category' => 'checkout-billing',
		),
		'email-verification'         => array(
			'tier'     => 'pro',
			'category' => 'checkout-billing',
		),

		// Cart & Fees
		'cod-fee'                    => array(
			'tier'     => 'lite',
			'category' => 'cart-fees',
		),
		'hide-voucher'               => array(
			'tier'     => 'lite',
			'category' => 'cart-fees',
		),
		'coupon-cleanup'             => array(
			'tier'     => 'lite',
			'category' => 'cart-fees',
		),
		'free-shipping-bar'          => array(
			'tier'     => 'lite',
			'category' => 'cart-fees',
		),
		'clear-cart'                 => array(
			'tier'     => 'lite',
			'category' => 'cart-fees',
		),
		'emergency-delivery-fees'    => array(
			'tier'     => 'pro',
			'category' => 'cart-fees',
		),
		'deposits-custom-pricing'    => array(
			'tier'     => 'pro',
			'category' => 'cart-fees',
		),
		'gift-wrap'                  => array(
			'tier'     => 'pro',
			'category' => 'cart-fees',
		),

		// Orders & Fulfilment
		'maof'                       => array(
			'tier'     => 'lite',
			'category' => 'orders-fulfilment',
		),
		'multi-address'              => array(
			'tier'     => 'lite',
			'category' => 'orders-fulfilment',
		),
		'order-payment-filter'       => array(
			'tier'     => 'lite',
			'category' => 'orders-fulfilment',
		),
		'store-report'               => array(
			'tier'     => 'lite',
			'category' => 'orders-fulfilment',
		),
		'order-confirmation'         => array(
			'tier'     => 'pro',
			'category' => 'orders-fulfilment',
		),
		'qr-payments'                => array(
			'tier'     => 'pro',
			'category' => 'orders-fulfilment',
		),
		'courier-nomenclature'       => array(
			'tier'     => 'pro',
			'category' => 'orders-fulfilment',
		),
		'pickup-store'               => array(
			'tier'     => 'pro',
			'category' => 'orders-fulfilment',
		),
		'reserve-for-pickup'         => array(
			'tier'     => 'pro',
			'category' => 'orders-fulfilment',
		),

		// Products & Catalogue
		'availability-sorting'       => array(
			'tier'     => 'lite',
			'category' => 'products-catalogue',
		),
		'out-of-stock-cleanup'       => array(
			'tier'     => 'lite',
			'category' => 'products-catalogue',
		),
		'sku-generator'              => array(
			'tier'     => 'lite',
			'category' => 'products-catalogue',
		),
		'sale-category-sync'         => array(
			'tier'     => 'lite',
			'category' => 'products-catalogue',
		),
		'recommended-price'          => array(
			'tier'     => 'lite',
			'category' => 'products-catalogue',
		),
		'related-products'           => array(
			'tier'     => 'lite',
			'category' => 'products-catalogue',
		),
		'frequently-bought-together' => array(
			'tier'     => 'lite',
			'category' => 'products-catalogue',
		),

		// Reviews & Trust
		'review-page'                => array(
			'tier'     => 'lite',
			'category' => 'reviews-trust',
		),
		'reviews-showcase'           => array(
			'tier'     => 'lite',
			'category' => 'reviews-trust',
		),
		'reviewer-names'             => array(
			'tier'     => 'lite',
			'category' => 'reviews-trust',
		),
		'perks-shop'                 => array(
			'tier'     => 'lite',
			'category' => 'reviews-trust',
		),
		'merchant-widget'            => array(
			'tier'     => 'lite',
			'category' => 'reviews-trust',
		),
		'whatsapp-fab'               => array(
			'tier'     => 'lite',
			'category' => 'reviews-trust',
		),

		// Admin & Branding
		'admin-branding'             => array(
			'tier'     => 'lite',
			'category' => 'admin-branding',
		),
		// pane_only: switched on only in its own pane, never by WP-CLI or an
		// ability (WordPress.org guideline 10, see FooterCredit\Module).
		'footer-credit'              => array(
			'tier'      => 'lite',
			'category'  => 'admin-branding',
			'pane_only' => true,
		),
	);

	/**
	 * Modules that do one job between them but stay separate, so each can be
	 * switched on alone (owner, 2026-11-09: linked rather than merged). Each
	 * pane links to the others in its set.
	 */
	private const RELATED = array(
		array( 'pf-pj-billing', 'vat-id-validation' ),
		array( 'vat-rates', 'eu-countries' ),
		array( 'hide-voucher', 'coupon-cleanup' ),
		array( 'availability-sorting', 'out-of-stock-cleanup' ),
		array( 'availability-sorting', 'price-history' ),
		array( 'related-products', 'frequently-bought-together' ),
		array( 'review-page', 'reviews-showcase', 'reviewer-names' ),
	);

	/** @return array<string, string> category id => translated label, in nav order. */
	public static function categories(): array {
		$labels = array(
			'compliance-legal'   => __( 'Compliance & Legal', 'romcommerce' ),
			'checkout-billing'   => __( 'Checkout & Billing', 'romcommerce' ),
			'cart-fees'          => __( 'Cart & Fees', 'romcommerce' ),
			'orders-fulfilment'  => __( 'Orders & Fulfilment', 'romcommerce' ),
			'products-catalogue' => __( 'Products & Catalogue', 'romcommerce' ),
			'reviews-trust'      => __( 'Reviews & Trust', 'romcommerce' ),
			'admin-branding'     => __( 'Admin & Branding', 'romcommerce' ),
		);

		return array_intersect_key( $labels, array_flip( self::CATEGORY_IDS ) );
	}

	public static function category_exists( string $category ): bool {
		return in_array( $category, self::CATEGORY_IDS, true );
	}

	/**
	 * Translated display copy, kept out of MODULES for the same reason as
	 * categories(): constants can't call __().
	 *
	 * @return array{label: string, description: string}
	 */
	private static function copy( string $module_id ): array {
		$copy = array(
			'sal-pictogram'              => array(
				'label'       => __( 'SAL Pictogram', 'romcommerce' ),
				'description' => __( 'Displays the official ANPC SAL pictogram on the homepage, linking to the SAL dispute-resolution platform.', 'romcommerce' ),
			),
			'legal-guarantee-notice'     => array(
				'label'       => __( 'Legal Guarantee Notice', 'romcommerce' ),
				'description' => __( 'Displays the EU-mandated harmonised notice on the legal guarantee of conformity, plus the optional EU GARAN durability label. Required EU-wide from 27 September 2026.', 'romcommerce' ),
			),
			'price-history'              => array(
				'label'       => __( 'Price History', 'romcommerce' ),
				'description' => __( 'Logs every price change and, next to any sale price, shows the lowest price from the 30 days before the reduction started (Omnibus reference price).', 'romcommerce' ),
			),
			'pf-pj-billing'              => array(
				'label'       => __( 'PF/PJ Billing Fields', 'romcommerce' ),
				'description' => __( 'Individual vs. company checkout billing fields, compatible with the Romanian invoicing-plugin ecosystem.', 'romcommerce' ),
			),
			'vat-id-validation'          => array(
				'label'       => __( 'VAT ID Validation', 'romcommerce' ),
				'description' => __( 'CUI/CIF format and checksum validation at checkout.', 'romcommerce' ),
			),
			'maof'                       => array(
				'label'       => __( 'MAOF', 'romcommerce' ),
				'description' => __( 'Flags possible related orders and duplicate active orders for staff review.', 'romcommerce' ),
			),
			'reviewer-names'             => array(
				'label'       => __( 'Reviewer Name Privacy', 'romcommerce' ),
				'description' => __( 'Shows product reviewers on your shop by first name and initials (Ion Popescu becomes Ion P.), leaving the full name out of the page.', 'romcommerce' ),
			),
			'compliance-checklist'       => array(
				'label'       => __( 'Compliance Checklist', 'romcommerce' ),
				'description' => __( 'A guided audit of your shop\'s legal and trust readiness, with auto-checks and links to fix failing items.', 'romcommerce' ),
			),
			'seap-badge'                 => array(
				'label'       => __( 'SEAP Badge', 'romcommerce' ),
				'description' => __( 'Displays the SEAP registration badge where required.', 'romcommerce' ),
			),
			'counties-postcodes'         => array(
				'label'       => __( 'Counties & Postcodes', 'romcommerce' ),
				'description' => __( 'Romanian county and postcode base data for checkout address fields, with an optional locality list for the chosen county.', 'romcommerce' ),
			),
			'address-autocomplete'       => array(
				'label'       => __( 'Address Autocomplete', 'romcommerce' ),
				'description' => __( 'Bidirectional postcode/street autocomplete at checkout, sourced from a RomCommerce-operated Romanian address dataset.', 'romcommerce' ),
			),
			'phone-validation'           => array(
				'label'       => __( 'Phone Validation', 'romcommerce' ),
				'description' => __( 'International phone number validation with a country-selector UI at checkout.', 'romcommerce' ),
			),
			'vat-rates'                  => array(
				'label'       => __( 'EU VAT Rates Import', 'romcommerce' ),
				'description' => __( 'Imports EU member states\' VAT rates into WooCommerce\'s tax tables, for cross-border sales under OSS.', 'romcommerce' ),
			),
			'eu-countries'               => array(
				'label'       => __( 'EU Countries', 'romcommerce' ),
				'description' => __( 'A checklist of the EU countries against your selling and shipping locations and shipping zones, with buttons that add the missing ones, including an EU shipping zone.', 'romcommerce' ),
			),
			'email-verification'         => array(
				'label'       => __( 'Email Verification', 'romcommerce' ),
				'description' => __( 'Checks whether a checkout email address is deliverable, without blocking submission.', 'romcommerce' ),
			),
			'hide-voucher'               => array(
				'label'       => __( 'Hide Coupon at Checkout', 'romcommerce' ),
				'description' => __( 'Hides the coupon field from the checkout page.', 'romcommerce' ),
			),
			'coupon-cleanup'             => array(
				'label'       => __( 'Coupon Cleanup', 'romcommerce' ),
				'description' => __( 'Automatically trashes expired coupons daily, with an option to permanently empty the coupon trash after a set number of days.', 'romcommerce' ),
			),
			'cod-fee'                    => array(
				'label'       => __( 'Cash on Delivery Fee', 'romcommerce' ),
				'description' => __( 'Adds a fee to orders paid by cash on delivery, shown as its own line in the order totals before the order is placed.', 'romcommerce' ),
			),
			'emergency-delivery-fees'    => array(
				'label'       => __( 'Emergency Delivery Fees', 'romcommerce' ),
				'description' => __( 'Surcharges for expedited or out-of-schedule delivery.', 'romcommerce' ),
			),
			'deposits-custom-pricing'    => array(
				'label'       => __( 'Deposits & Custom Pricing', 'romcommerce' ),
				'description' => __( 'Partial-payment deposits and custom per-order pricing.', 'romcommerce' ),
			),
			'gift-wrap'                  => array(
				'label'       => __( 'Gift Wrap', 'romcommerce' ),
				'description' => __( 'An add-on gift-wrap option at checkout.', 'romcommerce' ),
			),
			'order-confirmation'         => array(
				'label'       => __( 'Order Confirmation', 'romcommerce' ),
				'description' => __( 'One-click order confirmation by email and QR code, so customers confirm orders without a phone call.', 'romcommerce' ),
			),
			'qr-payments'                => array(
				'label'       => __( 'QR Payments / POS', 'romcommerce' ),
				'description' => __( 'QR codes for in-store, POS-created order confirmation and payment.', 'romcommerce' ),
			),
			'courier-nomenclature'       => array(
				'label'       => __( 'Courier Nomenclature', 'romcommerce' ),
				'description' => __( 'Standardised courier/locality naming for shipping labels.', 'romcommerce' ),
			),
			'pickup-store'               => array(
				'label'       => __( 'Pickup Store Locations', 'romcommerce' ),
				'description' => __( 'Defines physical pickup locations for orders.', 'romcommerce' ),
			),
			'reserve-for-pickup'         => array(
				'label'       => __( 'Reserve for Pickup', 'romcommerce' ),
				'description' => __( 'A lightweight reservation flow for customers to hold a product for in-store pickup.', 'romcommerce' ),
			),
			'multi-address'              => array(
				'label'       => __( 'Multiple Delivery Addresses', 'romcommerce' ),
				'description' => __( 'Lets customers save more than one delivery address on their account.', 'romcommerce' ),
			),
			'store-report'               => array(
				'label'       => __( 'Weekly Store Report', 'romcommerce' ),
				'description' => __( 'A weekly email with your orders by status, average order value, top products and categories, payment methods and order sources, compared with an earlier week.', 'romcommerce' ),
			),
			'order-payment-filter'       => array(
				'label'       => __( 'Payment Method Filter', 'romcommerce' ),
				'description' => __( 'A payment method dropdown on the WooCommerce orders list, next to the date and customer filters.', 'romcommerce' ),
			),
			'sku-generator'              => array(
				'label'       => __( 'SKU Generator', 'romcommerce' ),
				'description' => __( 'Gives products and variations saved without a SKU one, from a prefix and the product ID, name or a running number, and fills in the SKUs missing from existing products.', 'romcommerce' ),
			),
			'free-shipping-bar'          => array(
				'label'       => __( 'Free Shipping Bar', 'romcommerce' ),
				'description' => __( 'A progress indicator showing how much more to spend for free shipping.', 'romcommerce' ),
			),
			'sale-category-sync'         => array(
				'label'       => __( 'Category Sync', 'romcommerce' ),
				'description' => __( 'Keeps a sale category filled with products on sale and a new products category with recently published products.', 'romcommerce' ),
			),
			'recommended-price'          => array(
				'label'       => __( 'Recommended Retail Price', 'romcommerce' ),
				'description' => __( 'Optional manufacturer\'s recommended retail price (RRP) per product and variation, shown on the product page as a separate labelled line, never crossed out.', 'romcommerce' ),
			),
			'related-products'           => array(
				'label'       => __( 'Related Products', 'romcommerce' ),
				'description' => __( 'Rule-based control over which related products are shown.', 'romcommerce' ),
			),
			'availability-sorting'       => array(
				'label'       => __( 'Catalog Sorting', 'romcommerce' ),
				'description' => __( 'Out-of-stock products last and a "Sort by discount" order, each with its own switch.', 'romcommerce' ),
			),
			'out-of-stock-cleanup'       => array(
				'label'       => __( 'Out-of-Stock Product Cleanup', 'romcommerce' ),
				'description' => __( 'Moves published products that have been out of stock and unchanged for the months you choose to the trash, once a day.', 'romcommerce' ),
			),
			'frequently-bought-together' => array(
				'label'       => __( 'Frequently Bought Together', 'romcommerce' ),
				'description' => __( 'Products you pick shown with each product as a bundle, with the combined price and one button to add them all to the cart.', 'romcommerce' ),
			),
			'review-page'                => array(
				'label'       => __( 'Multi-Product Review Page', 'romcommerce' ),
				'description' => __( 'Lets a customer review every product from a past order on one page.', 'romcommerce' ),
			),
			'reviews-showcase'           => array(
				'label'       => __( 'Reviews Showcase', 'romcommerce' ),
				'description' => __( 'Recent product reviews from across your store, as a grid on the shop page.', 'romcommerce' ),
			),
			'whatsapp-fab'               => array(
				'label'       => __( 'Floating contact FAB', 'romcommerce' ),
				'description' => __( 'A floating multi-channel contact button with contextual WhatsApp deep-links, plus an optional WhatsApp button under Add to Cart.', 'romcommerce' ),
			),
			'clear-cart'                 => array(
				'label'       => __( 'Clear Cart', 'romcommerce' ),
				'description' => __( 'A one-click "empty cart" action.', 'romcommerce' ),
			),
			'merchant-widget'            => array(
				'label'       => __( 'Google Merchant Center Widget', 'romcommerce' ),
				'description' => __( 'Google\'s store widget with your Merchant Center store rating, as a badge in a corner of every page, and Google Customer Reviews\' survey opt-in after an order.', 'romcommerce' ),
			),
			'perks-shop'                 => array(
				'label'       => __( 'Perks Shop', 'romcommerce' ),
				'description' => __( 'Trust and service badges near the Add to Cart button, and a payment-methods widget.', 'romcommerce' ),
			),
			'admin-branding'             => array(
				'label'       => __( 'Admin & Login Branding', 'romcommerce' ),
				'description' => __( 'Your site logo on the login screen and one brand colour across wp-admin, the login screen, and buttons.', 'romcommerce' ),
			),
			'footer-credit'              => array(
				'label'       => __( 'Show Some Love', 'romcommerce' ),
				'description' => __( 'An optional "Comerț online mai simplu cu RomCommerce" credit in your site footer, on your login page, or both. Off unless you switch it on.', 'romcommerce' ),
			),
		);

		return $copy[ $module_id ] ?? array(
			'label'       => $module_id,
			'description' => '',
		);
	}

	/** @return array<string, array{tier: string, category: string, label: string, description: string}> */
	public static function all(): array {
		$all = array();
		foreach ( self::MODULES as $id => $module ) {
			$all[ $id ] = $module + self::copy( $id );
		}

		return $all;
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

			$module += self::copy( $id );

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
		return isset( self::MODULES[ $module_id ] ) ? self::MODULES[ $module_id ] + self::copy( $module_id ) : null;
	}

	/** @return list<string> The other module ids in every related set this module is in. */
	public static function related( string $module_id ): array {
		$related = array();
		foreach ( self::RELATED as $set ) {
			if ( in_array( $module_id, $set, true ) ) {
				$related = array_merge( $related, $set );
			}
		}

		return array_values( array_diff( array_unique( $related ), array( $module_id ) ) );
	}

	/** Whether a module may only be switched on from its own settings pane. */
	public static function pane_only( string $module_id ): bool {
		return ! empty( self::MODULES[ $module_id ]['pane_only'] );
	}
}
