<?php

declare(strict_types=1);

namespace RomCommerce\Admin;

use RomCommerce\Modules\HasPlacements;
use RomCommerce\Plugin;
use RomCommerce\Support\Placement;

defined( 'ABSPATH' ) || exit;

/**
 * The central Placements screen: pick a storefront surface (product / cart /
 * checkout / site-wide) and see every enabled module that renders there,
 * drawn on a monochrome wireframe where the only colour is the red marquee of
 * an occupied slot. Read-only by design — each occupant deep-links to its own
 * module pane to change anything — with one exception: the footer is the only
 * slot two modules can share, so its stacking order is editable here, since it
 * belongs to no single module's settings.
 *
 * Data comes entirely from each module's HasPlacements::placements(), the same
 * declaration that wires its live hooks, so this map cannot drift from what a
 * shopper actually sees.
 */
final class PlacementMap {

	private const CAPABILITY = 'manage_woocommerce';

	/**
	 * Stage elements per surface, in document order. 'slot' entries resolve to a
	 * red marquee (occupied) or a dashed outline (available); the footer and the
	 * fixed-corner element on the 'site' surface render after the stage. A
	 * 'columns' element nests sub-columns (each its own element list), so the
	 * product surface reads as the familiar gallery-left / summary-right PDP.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private const SCENES = array(
		'product'  => array(
			array(
				'type' => 'columns',
				'cols' => array(
					array(
						array( 'type' => 'gallery' ),
					),
					// The summary column, where both product-surface slots land —
					// product_page_summary after the price, add_to_cart after the
					// button — in WooCommerce's own top-to-bottom summary order.
					array(
						array(
							'type' => 'line',
							'w'    => 78,
							'big'  => true,
						),
						array(
							'type' => 'line',
							'w'    => 28,
						),
						array(
							'type' => 'slot',
							'id'   => 'product_page_summary',
						),
						array(
							'type' => 'line',
							'w'    => 94,
						),
						array(
							'type' => 'line',
							'w'    => 82,
						),
						array( 'type' => 'button' ),
						array(
							'type' => 'slot',
							'id'   => 'add_to_cart',
						),
						array(
							'type' => 'line',
							'w'    => 44,
						),
						array(
							'type' => 'line',
							'w'    => 58,
						),
					),
				),
			),
		),
		'cart'     => array(
			array(
				'type' => 'line',
				'w'    => 38,
				'big'  => true,
			),
			array( 'type' => 'row' ),
			array( 'type' => 'row' ),
			array( 'type' => 'row' ),
			array(
				'type' => 'slot',
				'id'   => 'cart',
			),
			array(
				'type'  => 'button',
				'align' => 'right',
			),
		),
		'checkout' => array(
			array(
				'type' => 'line',
				'w'    => 34,
				'big'  => true,
			),
			array(
				'type' => 'slot',
				'id'   => 'checkout_review',
			),
			array(
				'type' => 'line',
				'w'    => 88,
			),
			array(
				'type' => 'line',
				'w'    => 66,
			),
			array(
				'type' => 'slot',
				'id'   => 'checkout_submit',
			),
			array(
				'type' => 'button',
				'full' => true,
			),
		),
		'site'     => array(
			array(
				'type' => 'line',
				'w'    => 80,
			),
			array(
				'type' => 'line',
				'w'    => 92,
			),
			array(
				'type' => 'line',
				'w'    => 54,
			),
			array(
				'type' => 'line',
				'w'    => 72,
			),
		),
	);

	public function render(): void {
		$map = $this->gather();

		$this->maybe_handle_reorder( $this->footer_order_ids( $map ) );

		$surface = $this->current_surface();

		echo '<div class="rc-pm">';
		$this->render_tabs( $map, $surface );

		echo '<div class="rc-pm-body">';
		$this->render_scene( $surface, $map );
		$this->render_sidebar( $surface, $map );
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Every enabled, placement-aware module's active placements, grouped
	 * surface => slot => occupants.
	 *
	 * @return array<string, array<string, array<int, array{id: string, title: string, category: string}>>>
	 */
	private function gather(): array {
		$by_surface = array();

		foreach ( Plugin::instance()->modules()->all() as $id => $module ) {
			if ( ! $module instanceof HasPlacements || ! $module->is_enabled() ) {
				continue;
			}

			$meta = ModuleRegistry::get( $id );

			foreach ( $module->placements() as $placement ) {
				if ( empty( $placement['active'] ) ) {
					continue;
				}

				$slot = (string) $placement['slot'];
				if ( ! Placement::slot_exists( $slot ) ) {
					continue;
				}

				$by_surface[ Placement::slot_surface( $slot ) ][ $slot ][] = array(
					'id'       => $id,
					'title'    => $module->title(),
					'category' => null !== $meta ? $meta['category'] : '',
				);
			}
		}

		return $by_surface;
	}

	private function render_tabs( array $map, string $active ): void {
		echo '<div class="rc-pm-tabs">';

		foreach ( Placement::surfaces() as $sid => $label ) {
			$count = $this->surface_count( $map, $sid );
			$cls   = 'rc-pm-tab' . ( $sid === $active ? ' is-active' : '' );

			echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( $this->surface_url( $sid ) ) . '">';
			echo esc_html( $label );
			echo ' <span class="rc-pm-count">' . esc_html( (string) $count ) . '</span>';
			echo '</a>';
		}

		echo '</div>';
	}

	private function render_scene( string $surface, array $map ): void {
		echo '<div class="rc-pm-scene' . ( 'site' === $surface ? ' is-site' : '' ) . '">';
		echo '<div class="rc-pm-bar"></div>';

		echo '<div class="rc-pm-stage">';
		foreach ( self::SCENES[ $surface ] ?? array() as $element ) {
			$this->render_element( $surface, $element, $map );
		}
		echo '</div>';

		if ( 'site' === $surface ) {
			echo '<div class="rc-pm-footerstrip">';
			$this->render_footer_band( $map );
			echo '</div>';

			if ( ! empty( $map['site']['fixed'] ) ) {
				$title = $map['site']['fixed'][0]['title'];
				echo '<div class="rc-pm-fab" title="' . esc_attr( $title ) . '">' . esc_html__( 'FAB', 'romcommerce' ) . '</div>';
			}
		}

		echo '</div>';
	}

	/** @param array<string, mixed> $element */
	private function render_element( string $surface, array $element, array $map ): void {
		$type = (string) ( $element['type'] ?? '' );

		if ( 'line' === $type ) {
			$cls = 'rc-pm-line' . ( ! empty( $element['big'] ) ? ' is-big' : '' );
			echo '<div class="' . esc_attr( $cls ) . '" style="width:' . esc_attr( (string) ( $element['w'] ?? 100 ) ) . '%;"></div>';
			return;
		}

		if ( 'gallery' === $type ) {
			echo '<div class="rc-pm-gallery">';
			echo '<div class="rc-pm-img is-main"></div>';
			echo '<div class="rc-pm-thumbs">';
			for ( $i = 0; $i < 4; $i++ ) {
				echo '<div class="rc-pm-thumb"></div>';
			}
			echo '</div>';
			echo '</div>';
			return;
		}

		if ( 'columns' === $type ) {
			echo '<div class="rc-pm-cols">';
			foreach ( (array) ( $element['cols'] ?? array() ) as $col ) {
				echo '<div class="rc-pm-col">';
				foreach ( (array) $col as $child ) {
					$this->render_element( $surface, (array) $child, $map );
				}
				echo '</div>';
			}
			echo '</div>';
			return;
		}

		if ( 'row' === $type ) {
			echo '<div class="rc-pm-rowline"></div>';
			return;
		}

		if ( 'button' === $type ) {
			$cls = 'rc-pm-btn';
			if ( 'right' === ( $element['align'] ?? '' ) ) {
				$cls .= ' is-right';
			}
			if ( ! empty( $element['full'] ) ) {
				$cls .= ' is-full';
			}
			echo '<div class="' . esc_attr( $cls ) . '"></div>';
			return;
		}

		if ( 'slot' === $type ) {
			$slot      = (string) $element['id'];
			$occupants = $map[ $surface ][ $slot ] ?? array();
			$this->render_slot_band( $slot, $occupants );
		}
	}

	/** @param array<int, array{id: string, title: string, category: string}> $occupants */
	private function render_slot_band( string $slot, array $occupants ): void {
		if ( array() === $occupants ) {
			echo '<div class="rc-pm-slot is-available" title="' . esc_attr( Placement::slot_label( $slot ) ) . '">';
			echo '<span class="rc-pm-availlabel">' . esc_html__( 'Open', 'romcommerce' ) . '</span>';
			echo '</div>';
			return;
		}

		echo '<div class="rc-pm-slot is-occupied">';
		foreach ( $occupants as $occupant ) {
			echo '<span class="rc-pm-who">' . esc_html( $occupant['title'] ) . '</span>';
		}
		echo '</div>';
	}

	private function render_footer_band( array $map ): void {
		$occupants = $this->order_footer_occupants( $map['site']['footer'] ?? array() );

		if ( array() === $occupants ) {
			echo '<div class="rc-pm-slot is-available"><span class="rc-pm-availlabel">' . esc_html__( 'Footer — open', 'romcommerce' ) . '</span></div>';
			return;
		}

		$collision = count( $occupants ) > 1 ? ' is-collision' : '';
		echo '<div class="rc-pm-slot is-occupied' . $collision . '">'; // phpcs:ignore WordPress.Security.EscapeOutput -- $collision is a static literal.

		$rank = 1;
		foreach ( $occupants as $occupant ) {
			echo '<span class="rc-pm-who">';
			if ( '' !== $collision ) {
				echo '<span class="rc-pm-rank">' . esc_html( (string) $rank ) . '</span>';
			}
			echo esc_html( $occupant['title'] ) . '</span>';
			++$rank;
		}

		echo '</div>';
	}

	private function render_sidebar( string $surface, array $map ): void {
		$slots = Placement::slots_for_surface( $surface );

		echo '<div class="rc-pm-side">';

		$footer_occupants = 'site' === $surface ? $this->order_footer_occupants( $map['site']['footer'] ?? array() ) : array();
		if ( count( $footer_occupants ) > 1 ) {
			$this->render_order_control( $footer_occupants );
		}

		$count = $this->surface_count( $map, $surface );
		echo '<h3 class="rc-pm-sidehead">' . esc_html(
			sprintf(
				/* translators: %d: number of modules rendering on this surface */
				_n( '%d module here', '%d modules here', $count, 'romcommerce' ),
				$count
			)
		) . '</h3>';

		if ( 0 === $count ) {
			echo '<p class="rc-pm-empty">' . esc_html__( 'No active module renders on this surface yet.', 'romcommerce' ) . '</p>';
			echo '</div>';
			return;
		}

		foreach ( $slots as $slot => $slot_label ) {
			$occupants = $map[ $surface ][ $slot ] ?? array();
			if ( 'footer' === $slot ) {
				$occupants = $this->order_footer_occupants( $occupants );
			}

			foreach ( $occupants as $occupant ) {
				$this->render_side_row( $occupant, $slot_label );
			}
		}

		echo '</div>';
	}

	/**
	 * @param array{id: string, title: string, category: string} $occupant
	 */
	private function render_side_row( array $occupant, string $slot_label ): void {
		echo '<div class="rc-pm-modrow">';
		echo '<div class="rc-pm-modtop"><span class="rc-pm-modname">' . esc_html( $occupant['title'] ) . '</span><span class="rc-pm-pill">' . esc_html__( 'On', 'romcommerce' ) . '</span></div>';
		echo '<p class="rc-pm-where">' . esc_html( $slot_label ) . '</p>';
		echo '<a class="rc-pm-gear" href="' . esc_url( $this->module_url( $occupant['category'], $occupant['id'] ) ) . '">' . esc_html__( 'Open settings →', 'romcommerce' ) . '</a>';
		echo '</div>';
	}

	/** @param array<int, array{id: string, title: string, category: string}> $occupants */
	private function render_order_control( array $occupants ): void {
		echo '<div class="rc-pm-order">';
		echo '<p class="rc-pm-orderhead">' . esc_html__( 'Footer — 2+ modules share this spot', 'romcommerce' ) . '</p>';
		echo '<p class="rc-pm-orderhelp">' . esc_html__( 'Both relocate into your theme footer. Set who sits on top — otherwise their order is undefined.', 'romcommerce' ) . '</p>';

		$last = count( $occupants ) - 1;
		foreach ( $occupants as $index => $occupant ) {
			echo '<div class="rc-pm-orderrow">';
			echo '<span class="rc-pm-ordername">' . esc_html( (string) ( $index + 1 ) ) . '. ' . esc_html( $occupant['title'] ) . '</span>';
			echo '<span class="rc-pm-orderarrows">';
			$this->render_move_button( $occupant['id'], 'up', 0 === $index );
			$this->render_move_button( $occupant['id'], 'down', $index === $last );
			echo '</span>';
			echo '</div>';
		}

		echo '</div>';
	}

	private function render_move_button( string $module_id, string $dir, bool $disabled ): void {
		$arrow = 'up' === $dir ? '&uarr;' : '&darr;';

		echo '<form method="post" class="rc-pm-moveform">';
		wp_nonce_field( 'romcommerce_footer_order', 'rc_footer_nonce' );
		echo '<input type="hidden" name="rc_footer_move" value="' . esc_attr( $module_id ) . '">';
		echo '<input type="hidden" name="rc_footer_dir" value="' . esc_attr( $dir ) . '">';
		echo '<button type="submit" class="rc-pm-move"' . ( $disabled ? ' disabled' : '' ) . '>' . $arrow . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $arrow is a static HTML entity literal.
		echo '</form>';
	}

	/**
	 * Applies an up/down swap to the footer stacking order. Reads $_POST here,
	 * local to the verified nonce + capability check, per the project's
	 * handler-is-POST-free convention.
	 *
	 * @param array<int, string> $ordered_ids Footer occupants in current display order.
	 */
	private function maybe_handle_reorder( array $ordered_ids ): void {
		if ( ! isset( $_POST['rc_footer_move'], $_POST['rc_footer_nonce'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rc_footer_nonce'] ) ), 'romcommerce_footer_order' ) ) {
			return;
		}

		$move = sanitize_key( wp_unslash( $_POST['rc_footer_move'] ) );
		$dir  = isset( $_POST['rc_footer_dir'] ) && 'down' === sanitize_key( wp_unslash( $_POST['rc_footer_dir'] ) ) ? 1 : -1;

		$index = array_search( $move, $ordered_ids, true );
		if ( false === $index ) {
			return;
		}

		$target = (int) $index + $dir;
		if ( $target < 0 || $target >= count( $ordered_ids ) ) {
			return;
		}

		$from = (int) $index;
		$swap = $ordered_ids[ $from ];

		$ordered_ids[ $from ]   = $ordered_ids[ $target ];
		$ordered_ids[ $target ] = $swap;

		Placement::set_footer_order( $ordered_ids );
	}

	/**
	 * @param array<int, array{id: string, title: string, category: string}> $occupants
	 * @return array<int, array{id: string, title: string, category: string}> same list, sorted by footer rank (ties broken by id for 7.4's unstable usort).
	 */
	private function order_footer_occupants( array $occupants ): array {
		usort(
			$occupants,
			static function ( array $a, array $b ): int {
				$rank = Placement::footer_rank( $a['id'] ) - Placement::footer_rank( $b['id'] );

				return 0 !== $rank ? $rank : strcmp( $a['id'], $b['id'] );
			}
		);

		return $occupants;
	}

	/** @return array<int, string> Footer occupant ids in display order. */
	private function footer_order_ids( array $map ): array {
		$ids = array();
		foreach ( $this->order_footer_occupants( $map['site']['footer'] ?? array() ) as $occupant ) {
			$ids[] = $occupant['id'];
		}

		return $ids;
	}

	private function surface_count( array $map, string $surface ): int {
		$count = 0;
		foreach ( $map[ $surface ] ?? array() as $occupants ) {
			$count += count( $occupants );
		}

		return $count;
	}

	private function current_surface(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- read-only nav state; the AJAX path is nonce-verified in Admin\Ajax::handle() before this runs.
		$surface = isset( $_REQUEST['surface'] ) ? sanitize_key( wp_unslash( $_REQUEST['surface'] ) ) : '';

		return Placement::surface_exists( $surface ) ? $surface : 'product';
	}

	private function surface_url( string $surface ): string {
		return add_query_arg(
			array(
				'page'    => 'romcommerce',
				'tab'     => 'placements',
				'surface' => $surface,
			),
			admin_url( 'admin.php' )
		);
	}

	private function module_url( string $category, string $module_id ): string {
		return add_query_arg(
			array(
				'page'     => 'romcommerce',
				'tab'      => 'modules',
				'category' => $category,
				'module'   => $module_id,
			),
			admin_url( 'admin.php' )
		);
	}
}
