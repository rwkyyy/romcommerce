<?php

declare(strict_types=1);

namespace RomCommerce\Modules\Maof;

use WC_Order;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * MAOF's admin-only surfaces: an orders-list column flagging active duplicates,
 * and an order-edit review meta box listing possible related orders. Everything
 * here is capability-restricted (manage_woocommerce) and never leaves the admin
 * — no customer-facing REST, e-mails or exports, per the GDPR gate.
 */
final class Admin {

	private const COLUMN = 'romcommerce_maof';

	private Finder $finder;

	public function __construct( Finder $finder ) {
		$this->finder = $finder;
	}

	public function register(): void {
		// Legacy post-based orders table.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_legacy_column' ), 10, 2 );

		// HPOS orders table.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ) );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'render_hpos_column' ), 10, 2 );

		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 10, 2 );
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function add_column( array $columns ): array {
		$columns[ self::COLUMN ] = __( 'MAOF', 'romcommerce' );

		return $columns;
	}

	public function render_legacy_column( string $column, int $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$order = wc_get_order( $post_id );
		if ( $order instanceof WC_Order ) {
			$this->render_indicator( $order );
		}
	}

	/**
	 * @param string $column
	 * @param mixed  $order
	 */
	public function render_hpos_column( $column, $order ): void {
		if ( self::COLUMN !== $column || ! $order instanceof WC_Order ) {
			return;
		}

		$this->render_indicator( $order );
	}

	private function render_indicator( WC_Order $order ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$count = $this->finder->active_duplicate_count( $order );
		if ( $count < 1 ) {
			echo '<span aria-hidden="true">—</span>';
			return;
		}

		echo '<span title="' . esc_attr__( 'Posibile comenzi asociate: verificare manuală necesară', 'romcommerce' ) . '" style="display:inline-block;padding:2px 8px;border-radius:3px;background:#f0a202;color:#1e1e1e;font-weight:600;">';
		echo esc_html(
			sprintf(
				/* translators: %d: number of other active orders */
				_n( '%d activă', '%d active', $count, 'romcommerce' ),
				$count
			)
		);
		echo '</span>';
	}

	/**
	 * @param string $post_type
	 * @param mixed  $post_or_order
	 */
	public function add_meta_box( $post_type, $post_or_order ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$screen = $post_or_order instanceof WP_Post ? 'shop_order' : wc_get_page_screen_id( 'shop-order' );

		if ( 'shop_order' !== $post_type && wc_get_page_screen_id( 'shop-order' ) !== $post_type ) {
			return;
		}

		add_meta_box(
			'romcommerce_maof',
			__( 'MAOF: posibile comenzi asociate', 'romcommerce' ),
			array( $this, 'render_meta_box' ),
			$screen,
			'side',
			'default'
		);
	}

	/**
	 * @param mixed $post_or_order
	 */
	public function render_meta_box( $post_or_order ): void {
		$order = $post_or_order instanceof WP_Post ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$matches = $this->finder->related_orders( $order );

		if ( empty( $matches ) ) {
			echo '<p>' . esc_html__( 'Nicio comandă asociată în ultimele 12 luni.', 'romcommerce' ) . '</p>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'Posibile comenzi asociate: verificare manuală, nu o confirmare.', 'romcommerce' ) . '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Comandă', 'romcommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'romcommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Potrivire', 'romcommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $matches as $match ) {
			$edit = get_edit_post_link( $match['order_id'] );
			$date = $match['timestamp'] > 0 ? wp_date( (string) get_option( 'date_format' ), $match['timestamp'] ) : '';

			echo '<tr' . ( $match['active'] ? ' style="background:#fff4d6;"' : '' ) . '>';
			echo '<td>';
			if ( $edit ) {
				echo '<a href="' . esc_url( $edit ) . '">#' . esc_html( $match['number'] ) . '</a>';
			} else {
				echo '#' . esc_html( $match['number'] );
			}
			if ( '' !== $date ) {
				echo '<br><small>' . esc_html( $date ) . '</small>';
			}
			echo '</td>';
			echo '<td>' . esc_html( $match['status_label'] ) . '</td>';
			echo '<td>' . esc_html( $this->match_label( $match['basis'], $match['strong'] ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		if ( $this->has_weak_match( $matches ) ) {
			echo '<p class="description">' . esc_html__( 'Potrivirile slabe (un singur identificator) nu sunt o dovadă.', 'romcommerce' ) . '</p>';
		}
	}

	/** @param array<int, array{basis: string, active: bool, strong: bool, order_id: int, number: string, status_label: string, timestamp: int}> $matches */
	private function has_weak_match( array $matches ): bool {
		foreach ( $matches as $match ) {
			if ( ! $match['strong'] ) {
				return true;
			}
		}

		return false;
	}

	private function match_label( string $basis, bool $strong ): string {
		$strength = $strong ? __( 'puternică', 'romcommerce' ) : __( 'slabă', 'romcommerce' );

		if ( 'both' === $basis ) {
			$by = __( 'e-mail + telefon', 'romcommerce' );
		} elseif ( 'email' === $basis ) {
			$by = __( 'e-mail', 'romcommerce' );
		} else {
			$by = __( 'telefon', 'romcommerce' );
		}

		return $by . ' (' . $strength . ')';
	}
}
