<?php

declare(strict_types=1);

namespace RomCommerce\Modules\MerchantWidget;

use DateTimeImmutable;
use DateTimeZone;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Google Customer Reviews survey opt-in on the order confirmation page:
 * Google's own dialog asks the customer whether they want a survey e-mail
 * after delivery. Only the customer's browser talks to Google, and it sends
 * the order number, the customer's e-mail address, the delivery country, the
 * estimated delivery date and the products' GTINs when set (Google's
 * documented fields, support.google.com/merchants/answer/7106244).
 *
 * Google's languages for the dialog don't include Romanian, so no language is
 * set and Google picks its own.
 */
final class SurveyOptIn {

	private const SCRIPT_URL = 'https://apis.google.com/js/platform.js?onload=romcommerceRenderOptIn';

	public const STYLES = array( 'CENTER_DIALOG', 'BOTTOM_RIGHT_DIALOG', 'BOTTOM_LEFT_DIALOG', 'TOP_RIGHT_DIALOG', 'TOP_LEFT_DIALOG', 'BOTTOM_TRAY' );

	public const DELIVERY_DAYS_DEFAULT = 5;

	public const DELIVERY_DAYS_MAX = 60;

	/**
	 * Seconds after the order during which the confirmation page asks. Reopened
	 * later, from the order e-mail or My account, it doesn't ask again.
	 */
	private const FRESH_FOR = 3600;

	private string $merchant_id;

	private int $delivery_days;

	private string $style;

	public function __construct( string $merchant_id, int $delivery_days, string $style ) {
		$this->merchant_id   = $merchant_id;
		$this->delivery_days = max( 1, min( self::DELIVERY_DAYS_MAX, $delivery_days ) );
		$this->style         = in_array( $style, self::STYLES, true ) ? $style : 'CENTER_DIALOG';
	}

	/** Google only accepts the opt-in on HTTPS pages. */
	public function enqueue(): void {
		if ( ! is_ssl() ) {
			return;
		}

		$order = self::received_order( time() );
		if ( null === $order ) {
			return;
		}

		$config = $this->config( $order );
		if ( array() === $config ) {
			return;
		}

		// The callback must exist before Google's async script can call it, so it goes in the head.
		wp_register_script( 'romcommerce-survey-optin', false, array(), ROMCOMMERCE_VERSION, false );
		wp_enqueue_script( 'romcommerce-survey-optin' );
		wp_add_inline_script( 'romcommerce-survey-optin', self::script_js( $config ) );

		// phpcs:disable WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google's hosted script; a version query would only defeat its own caching.
		wp_enqueue_script(
			'romcommerce-survey-optin-google',
			self::SCRIPT_URL,
			array(),
			null,
			array(
				'in_footer' => true,
				'strategy'  => 'async',
			)
		);
		// phpcs:enable WordPress.WP.EnqueuedResourceParameters.MissingVersion
	}

	/**
	 * The order this confirmation page belongs to, when the link's order key
	 * matches (the same credential WooCommerce's own thank-you page checks, so
	 * changing the number in the address can't reveal another customer's
	 * e-mail), the order wasn't abandoned, and it was placed moments ago.
	 */
	public static function received_order( int $now ): ?WC_Order {
		if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only page view; the order key itself is checked below.
		$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$order = wc_get_order( absint( get_query_var( 'order-received' ) ) );

		if ( ! $order instanceof WC_Order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			return null;
		}

		if ( $order->has_status( array( 'pending', 'failed', 'cancelled', 'refunded' ) ) ) {
			return null;
		}

		$created = $order->get_date_created();

		return ( null !== $created && $now - $created->getTimestamp() <= self::FRESH_FOR ) ? $order : null;
	}

	/** @return array<string, mixed> Empty when the order lacks something Google requires. */
	public function config( WC_Order $order ): array {
		$email   = (string) $order->get_billing_email();
		$country = strtoupper( '' !== $order->get_shipping_country() ? $order->get_shipping_country() : $order->get_billing_country() );
		$created = $order->get_date_created();

		if ( '' === $this->merchant_id || ! is_email( $email ) || 1 !== preg_match( '/^[A-Z]{2}$/', $country ) || 'ZZ' === $country || null === $created ) {
			return array();
		}

		$config = array(
			// Google's snippet passes a number. Merchant Center IDs fit a 64-bit integer; a 32-bit PHP build sends the digits as a string.
			'merchant_id'             => PHP_INT_SIZE >= 8 ? (int) $this->merchant_id : $this->merchant_id,
			'order_id'                => (string) $order->get_order_number(),
			'email'                   => $email,
			'delivery_country'        => $country,
			'estimated_delivery_date' => self::delivery_date( $created->getTimestamp(), $this->delivery_days, wp_timezone() ),
			'opt_in_style'            => $this->style,
		);

		$gtins = self::gtins( $order );
		if ( array() !== $gtins ) {
			$config['products'] = array_map(
				static function ( string $gtin ): array {
					return array( 'gtin' => $gtin );
				},
				$gtins
			);
		}

		return $config;
	}

	public static function delivery_date( int $timestamp, int $days, DateTimeZone $timezone ): string {
		return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone )->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
	}

	/** @param array<string, mixed> $config */
	public static function script_js( array $config ): string {
		// JSON_HEX_TAG: the order number is filterable text printed inside a <script> tag.
		return 'window.romcommerceRenderOptIn=function(){window.gapi.load("surveyoptin",function(){window.gapi.surveyoptin.render(' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP ) . ');});};';
	}

	/**
	 * WooCommerce's own GTIN/EAN field (9.2+); products without one are left
	 * out, as Google allows.
	 *
	 * @return array<int, string>
	 */
	private static function gtins( WC_Order $order ): array {
		$gtins = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			if ( ! $product instanceof WC_Product || ! method_exists( $product, 'get_global_unique_id' ) ) {
				continue;
			}

			$gtin = (string) preg_replace( '/\D/', '', (string) $product->get_global_unique_id() );
			if ( 1 === preg_match( '/^\d{8,14}$/', $gtin ) ) {
				$gtins[] = $gtin;
			}
		}

		return array_values( array_unique( $gtins ) );
	}
}
