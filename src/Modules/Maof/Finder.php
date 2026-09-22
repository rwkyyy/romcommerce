<?php

declare(strict_types=1);

namespace RomCommerce\Modules\Maof;

use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Finds possibly-related orders for a given order. Decision-support only —
 * computed per request, never stored as a risk score, never used to take an
 * automatic action. Binding constraints in docs/maof-gdpr-reference.md:
 *
 *  - 12-month lookback, enforced identically here regardless of storage backend
 *    (guaranteed by going through wc_get_orders / WC CRUD, never raw postmeta);
 *  - single-identifier (email-only or phone-only) results are soft warnings;
 *    strong = same WooCommerce customer id, or both email AND phone match;
 *  - phones normalised country-aware so 07… ≡ +407… but unrelated foreign
 *    numbers do not collide — the coarse variant query is re-checked against the
 *    normalised value before a candidate counts as a match.
 */
final class Finder {

	private const LOOKBACK_MONTHS = 12;

	private const ACTIVE_STATUSES = array( 'pending', 'processing', 'on-hold' );

	/** @var array<int, int> per-request memo of active-duplicate counts */
	private array $active_count_cache = array();

	/**
	 * Full related-orders list for the human-review surface (email + phone).
	 *
	 * @return array<int, array{order_id: int, number: string, timestamp: int, status_label: string, basis: string, strong: bool, active: bool}>
	 */
	public function related_orders( WC_Order $order ): array {
		$email = self::normalize_email( $order->get_billing_email() );
		$phone = self::normalize_phone( $order->get_billing_phone() );

		if ( '' === $email && '' === $phone ) {
			return array();
		}

		$candidates = array();

		if ( '' !== $email ) {
			foreach ( $this->query( array( 'billing_email' => $order->get_billing_email() ) ) as $candidate ) {
				$candidates[ $candidate->get_id() ] = $candidate;
			}
		}

		if ( '' !== $phone ) {
			foreach ( $this->phone_variants( $phone ) as $variant ) {
				foreach ( $this->query( array( 'billing_phone' => $variant ) ) as $candidate ) {
					$candidates[ $candidate->get_id() ] = $candidate;
				}
			}
		}

		unset( $candidates[ $order->get_id() ] );

		$matches = array();
		foreach ( $candidates as $candidate ) {
			$email_match = '' !== $email && self::normalize_email( $candidate->get_billing_email() ) === $email;
			$phone_match = '' !== $phone && self::normalize_phone( $candidate->get_billing_phone() ) === $phone;

			// The phone variant query is a coarse pre-filter; only a normalised
			// re-match counts, so unrelated numbers that share a substring drop out.
			if ( ! $email_match && ! $phone_match ) {
				continue;
			}

			$basis         = ( $email_match && $phone_match ) ? 'both' : ( $email_match ? 'email' : 'phone' );
			$same_customer = $order->get_customer_id() > 0 && $candidate->get_customer_id() === $order->get_customer_id();
			$created       = $candidate->get_date_created();

			$matches[] = array(
				'order_id'     => $candidate->get_id(),
				'number'       => $candidate->get_order_number(),
				'timestamp'    => null !== $created ? $created->getTimestamp() : 0,
				'status_label' => wc_get_order_status_name( $candidate->get_status() ),
				'basis'        => $basis,
				'strong'       => $same_customer || 'both' === $basis,
				'active'       => in_array( $candidate->get_status(), self::ACTIVE_STATUSES, true ),
			);
		}

		usort(
			$matches,
			static function ( array $a, array $b ): int {
				return $b['timestamp'] <=> $a['timestamp'];
			}
		);

		return $matches;
	}

	/** Cheap orders-list-column signal: count of other active orders sharing the email. */
	public function active_duplicate_count( WC_Order $order ): int {
		$id = $order->get_id();
		if ( isset( $this->active_count_cache[ $id ] ) ) {
			return $this->active_count_cache[ $id ];
		}

		$email = $order->get_billing_email();
		if ( '' === $email ) {
			$this->active_count_cache[ $id ] = 0;

			return 0;
		}

		$others = $this->query(
			array(
				'billing_email' => $email,
				'status'        => self::ACTIVE_STATUSES,
			)
		);

		$count = 0;
		foreach ( $others as $candidate ) {
			if ( $candidate->get_id() !== $id ) {
				++$count;
			}
		}

		$this->active_count_cache[ $id ] = $count;

		return $count;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<int, WC_Order>
	 */
	private function query( array $args ): array {
		$defaults = array(
			'type'         => 'shop_order',
			'limit'        => 25,
			'date_created' => '>' . ( time() - self::LOOKBACK_MONTHS * MONTH_IN_SECONDS ),
		);

		$orders = wc_get_orders( array_merge( $defaults, $args ) );

		return is_array( $orders ) ? array_filter(
			$orders,
			static function ( $order ): bool {
				return $order instanceof WC_Order;
			}
		) : array();
	}

	public static function normalize_email( string $email ): string {
		return strtolower( trim( $email ) );
	}

	/** Country-aware E.164-ish normalisation biased to RO (07… ≡ +407…). */
	public static function normalize_phone( string $phone ): string {
		$raw = preg_replace( '/[^\d+]/', '', $phone );
		if ( '' === (string) $raw ) {
			return '';
		}

		if ( 0 === strpos( $raw, '+' ) ) {
			return '+' . preg_replace( '/\D/', '', substr( $raw, 1 ) );
		}

		$digits = (string) preg_replace( '/\D/', '', $raw );
		if ( 0 === strpos( $digits, '00' ) ) {
			return '+' . substr( $digits, 2 );
		}
		if ( 0 === strpos( $digits, '0' ) ) {
			return '+40' . substr( $digits, 1 );
		}
		if ( 0 === strpos( $digits, '40' ) && strlen( $digits ) >= 11 ) {
			return '+' . $digits;
		}
		if ( 9 === strlen( $digits ) ) {
			return '+40' . $digits;
		}

		return '+' . $digits;
	}

	/** @return array<int, string> */
	private function phone_variants( string $e164 ): array {
		$digits   = ltrim( $e164, '+' );
		$variants = array( '+' . $digits, $digits, '00' . $digits );

		if ( 0 === strpos( $digits, '40' ) ) {
			$variants[] = '0' . substr( $digits, 2 );
		}

		return array_values( array_unique( $variants ) );
	}
}
