<?php

declare(strict_types=1);

namespace RomCommerce\Modules\StoreReport;

defined( 'ABSPATH' ) || exit;

/**
 * The report email's body: plain tables with inline styles, which survive
 * every mail client, wrapped in WooCommerce's own email header and footer by
 * Module::send(). Aggregates only: product, category, payment method and
 * channel names, never a customer's details.
 *
 * With a comparison, every section carries it in the form that reads best
 * there: totals get the change and its percentage, top lists the earlier
 * value plus what entered or dropped out, shares the change in percentage
 * points.
 */
final class Report {

	public const SECTIONS = array( 'orders', 'completed', 'processing', 'waiting', 'aov', 'products', 'categories', 'payments', 'sources' );

	public const TOP = 5;

	public const TOP_PAYMENTS = 3;

	/** Whether a rise is good news, which only picks the colour. */
	public const UP_GOOD = 1;

	public const NEUTRAL = 0;

	public const UP_BAD = -1;

	private const CELL = 'padding:6px 8px;border-bottom:1px solid #e5e5e5;text-align:left;vertical-align:top;';

	private const NUM = 'padding:6px 8px;border-bottom:1px solid #e5e5e5;text-align:right;vertical-align:top;white-space:nowrap;';

	/** Unlike NUM, may wrap before the percentage, so a long money change still fits a phone screen. */
	private const DELTA = 'padding:6px 8px;border-bottom:1px solid #e5e5e5;text-align:right;vertical-align:top;';

	private const HEAD = 'padding:6px 8px;border-bottom:1px solid #e5e5e5;vertical-align:bottom;font-size:12px;font-weight:normal;color:#636363;';

	private const NOTE = 'font-size:12px;color:#636363;';

	private const NBSP = "\u{00A0}";

	/** Slice colours in rank order; the last one is for "Other". */
	private const SLICE_COLOURS = array( '#303F9F', '#D32F2F', '#F9A825', '#9E9E9E' );

	/**
	 * @param array<string, mixed>      $now         Stats::result() for the period reported on.
	 * @param Period|null               $compared    The comparison period, if any.
	 * @param array<string, mixed>|null $before      Stats::result() for the comparison period, if any.
	 * @param array<int, string>        $sections    Which of SECTIONS to include.
	 * @param bool                      $attribution Whether WooCommerce records order sources.
	 */
	public static function html( array $now, ?Period $compared, ?array $before, array $sections, bool $attribution ): string {
		$html = '';

		if ( null !== $compared && null !== $before ) {
			if ( (int) $before['orders'] > 0 ) {
				/* translators: %s: date range compared with, e.g. 28 Sep – 4 Oct 2026 */
				$html .= '<p>' . esc_html( sprintf( __( 'Changes are against %s.', 'romcommerce' ), $compared->label() ) ) . '</p>';
			} else {
				/* translators: %s: date range compared with, e.g. 28 Sep – 4 Oct 2026 */
				$html  .= '<p>' . esc_html( sprintf( __( 'No orders in %s to compare with.', 'romcommerce' ), $compared->label() ) ) . '</p>';
				$before = null;
			}
		}

		$html .= self::orders( $now, $before, $sections );

		if ( in_array( 'aov', $sections, true ) ) {
			$html .= self::heading( __( 'Sales', 'romcommerce' ) ) . self::table_open();
			$html .= self::total_row( __( 'Sales after refunds', 'romcommerce' ), self::money( (float) $now['sales'] ), '', null === $before ? null : self::change( (float) $now['sales'], (float) $before['sales'], true ) );
			$html .= self::total_row( __( 'Average order value', 'romcommerce' ), self::money( (float) $now['aov'] ), '', null === $before ? null : self::change( (float) $now['aov'], (float) $before['aov'], true ) );
			$html .= '</table>';
		}

		$said = array();
		$none = __( 'No orders in this period.', 'romcommerce' );
		$paid = 0 === (int) $now['orders'] ? $none : __( 'No paid orders in this period.', 'romcommerce' );

		if ( in_array( 'products', $sections, true ) ) {
			$html .= self::section( __( 'Top products', 'romcommerce' ), self::ranking( $now['products'], null === $before ? null : $before['products'], 'qty' ), $paid, $said );
		}

		if ( in_array( 'categories', $sections, true ) ) {
			$html .= self::section( __( 'Top categories', 'romcommerce' ), self::ranking( $now['categories'], null === $before ? null : $before['categories'], 'total' ), $paid, $said );
		}

		if ( in_array( 'payments', $sections, true ) ) {
			$html .= self::section( __( 'Payment methods', 'romcommerce' ), self::payment_share( $now['payments'], null === $before ? null : $before['payments'] ), $none, $said );
		}

		if ( in_array( 'sources', $sections, true ) && $attribution ) {
			$channels = self::shares( $now['channels'], (int) $now['orders'], null === $before ? null : $before['channels'], null === $before ? 0 : (int) $before['orders'], __( 'Channel', 'romcommerce' ) );
			if ( '' !== $channels ) {
				$campaigns = self::shares( $now['campaigns'], (int) $now['orders'], null === $before ? null : $before['campaigns'], null === $before ? 0 : (int) $before['orders'], __( 'Campaign', 'romcommerce' ) );
				$channels .= '' !== $campaigns ? $campaigns : self::note( __( 'No orders came from a tagged campaign (utm_campaign).', 'romcommerce' ) );
			}
			$html .= self::section( __( 'Order sources', 'romcommerce' ), $channels, $none, $said );
		}

		return $html;
	}

	/**
	 * Total orders, then the statuses they split into. Cancelled, refunded and
	 * failed orders are a row of their own whenever either period has any, so
	 * the rows below the total add up to it.
	 *
	 * @param array<string, mixed>      $now
	 * @param array<string, mixed>|null $before
	 * @param array<int, string>        $sections
	 */
	private static function orders( array $now, ?array $before, array $sections ): string {
		$rows = array(
			'orders'     => array( __( 'Total orders', 'romcommerce' ), self::UP_GOOD ),
			'completed'  => array( __( 'Completed', 'romcommerce' ), self::UP_GOOD ),
			'processing' => array( __( 'Processing', 'romcommerce' ), self::UP_GOOD ),
			'waiting'    => array( __( 'On hold / waiting', 'romcommerce' ), self::NEUTRAL ),
		);
		$rows = array_intersect_key( $rows, array_flip( $sections ) );

		if ( array() === $rows ) {
			return '';
		}

		if ( isset( $rows['orders'] ) && ( (int) $now['closed'] > 0 || ( null !== $before && (int) $before['closed'] > 0 ) ) ) {
			$rows['closed'] = array( __( 'Cancelled, refunded or failed', 'romcommerce' ), self::UP_BAD );
		}

		$html = self::heading( __( 'Orders', 'romcommerce' ) ) . self::table_open();
		foreach ( $rows as $key => $row ) {
			$note = '';
			if ( 'waiting' === $key ) {
				$parts = array();
				foreach ( $now['waiting_by_status'] as $status => $count ) {
					$parts[] = wc_get_order_status_name( (string) $status ) . ': ' . number_format_i18n( (int) $count );
				}
				$note = implode( ' · ', $parts );
			}

			$html .= self::total_row(
				$row[0],
				esc_html( number_format_i18n( (int) $now[ $key ] ) ),
				$note,
				null === $before ? null : self::change( (float) $now[ $key ], (float) $before[ $key ], false, $row[1] )
			);
		}

		return $html . '</table>';
	}

	/**
	 * @param string      $label      Plain text.
	 * @param string      $value_html Escaped HTML.
	 * @param string      $note       Plain text, under the label.
	 * @param string|null $change     Escaped HTML; null without a comparison.
	 */
	private static function total_row( string $label, string $value_html, string $note, ?string $change ): string {
		$html = '<tr><td style="' . self::CELL . '">' . esc_html( $label );
		if ( '' !== $note ) {
			$html .= '<br><span style="' . self::NOTE . '">' . esc_html( $note ) . '</span>';
		}
		$html .= '</td><td style="' . self::NUM . '"><strong>' . $value_html . '</strong></td>';
		if ( null !== $change ) {
			$html .= '<td style="' . self::DELTA . '">' . $change . '</td>';
		}

		return $html . '</tr>';
	}

	/**
	 * The earlier value shown is the one the list is ranked by: units for
	 * products, sales for categories.
	 *
	 * @param array<int, array{id: int, name: string, qty: int, total: float}>      $now    Ranked, best first.
	 * @param array<int, array{id: int, name: string, qty: int, total: float}>|null $before Ranked, best first.
	 * @param string                                                                 $key    The ranking measure: qty or total.
	 */
	private static function ranking( array $now, ?array $before, string $key ): string {
		$rows = array_slice( $now, 0, self::TOP );
		if ( array() === $rows ) {
			return '';
		}

		$earlier = array();
		$moves   = array(
			'entered' => array(),
			'left'    => array(),
		);
		if ( null !== $before ) {
			foreach ( $before as $row ) {
				$earlier[ $row['id'] ] = $row;
			}
			$top   = array_slice( $before, 0, self::TOP );
			$moves = array() === $top ? $moves : Compare::movement( array_column( $rows, 'id' ), array_column( $top, 'id' ) );
		}

		$html  = self::table_open() . '<tr><th style="' . self::HEAD . 'text-align:left;"></th>';
		$html .= self::head( __( 'Units', 'romcommerce' ) ) . self::head( __( 'Sales', 'romcommerce' ) );
		if ( null !== $before ) {
			$html .= self::head( 'qty' === $key ? __( 'Units before', 'romcommerce' ) : __( 'Sales before', 'romcommerce' ) );
		}
		$html .= '</tr>';

		foreach ( $rows as $row ) {
			$html .= '<tr><td style="' . self::CELL . '">' . esc_html( $row['name'] ) . self::entered( in_array( $row['id'], $moves['entered'], true ) ) . '</td>';
			$html .= '<td style="' . self::NUM . '">' . esc_html( number_format_i18n( $row['qty'] ) ) . '</td>';
			$html .= '<td style="' . self::NUM . '">' . self::money( $row['total'] ) . '</td>';
			if ( null !== $before ) {
				$was   = $earlier[ $row['id'] ][ $key ] ?? null;
				$html .= '<td style="' . self::NUM . 'color:#636363;">' . ( null === $was ? '–' : ( 'qty' === $key ? esc_html( number_format_i18n( (int) $was ) ) : self::money( (float) $was ) ) ) . '</td>';
			}
			$html .= '</tr>';
		}
		$html .= '</table>';

		$left = array();
		foreach ( $moves['left'] as $move ) {
			$left[] = array(
				'name' => $earlier[ $move['key'] ]['name'] ?? '',
				'rank' => $move['rank'],
			);
		}

		return $html . self::left( $left );
	}

	/**
	 * @param array<string|int, int>      $now          Orders per name, most first.
	 * @param int                         $total        Orders in the period.
	 * @param array<string|int, int>|null $before       Orders per name in the earlier period.
	 * @param int                         $before_total Orders in the earlier period.
	 * @param string                      $label        Header of the name column.
	 */
	private static function shares( array $now, int $total, ?array $before, int $before_total, string $label ): string {
		$rows = array_slice( $now, 0, self::TOP, true );
		if ( array() === $rows || $total <= 0 ) {
			return '';
		}

		$moves = array(
			'entered' => array(),
			'left'    => array(),
		);
		if ( null !== $before && array() !== $before ) {
			$moves = Compare::movement( array_keys( $rows ), array_keys( array_slice( $before, 0, self::TOP, true ) ) );
		}

		$html = self::table_open() . '<tr>' . self::head( $label, 'left' ) . self::head( __( 'Orders', 'romcommerce' ) ) . self::head( __( 'Share', 'romcommerce' ) );
		if ( null !== $before ) {
			$html .= self::head( __( 'Change', 'romcommerce' ) );
		}
		$html .= '</tr>';

		foreach ( $rows as $name => $count ) {
			$html .= '<tr><td style="' . self::CELL . '">' . esc_html( (string) $name ) . self::entered( in_array( (string) $name, array_map( 'strval', $moves['entered'] ), true ) ) . '</td>';
			$html .= '<td style="' . self::NUM . '">' . esc_html( number_format_i18n( $count ) ) . '</td>';
			$html .= '<td style="' . self::NUM . '">' . esc_html( self::share( Compare::share( $count, $total ) ) ) . '</td>';
			if ( null !== $before ) {
				$html .= '<td style="' . self::DELTA . '">' . self::points( Compare::points( $count, $total, (float) ( $before[ $name ] ?? 0 ), $before_total ) ) . '</td>';
			}
			$html .= '</tr>';
		}
		$html .= '</table>';

		$left = array();
		foreach ( $moves['left'] as $move ) {
			$left[] = array(
				'name' => (string) $move['key'],
				'rank' => $move['rank'],
			);
		}

		return $html . self::left( $left );
	}

	/**
	 * The top payment methods and their share of the period's orders, as a
	 * stacked bar plus a table. A bar, not a pie chart: mail clients strip
	 * SVG and canvas, and a chart image would need either an outside service
	 * (order data leaving the site) or GD plus an embedded attachment that many
	 * clients block by default. Table cells with a background colour render in
	 * every client, Outlook included; the table carries the same numbers, so
	 * the bar is decoration and the colours are never the only cue.
	 *
	 * "Other" is compared with the earlier share of every method outside this
	 * period's top ones, so its change adds up with the named rows' to zero.
	 *
	 * @param array<string, int>      $counts Orders per payment method title, most first.
	 * @param array<string, int>|null $before The same for the earlier period.
	 */
	private static function payment_share( array $counts, ?array $before ): string {
		$slices = self::slices( $counts, self::TOP_PAYMENTS );
		if ( array() === $slices ) {
			return '';
		}

		$total        = (int) array_sum( $counts );
		$before_total = null === $before ? 0 : (int) array_sum( $before );
		$before_named = 0;

		$html = '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;margin:0 0 8px;"><tr>';
		foreach ( $slices as $index => $slice ) {
			if ( $slice['width'] > 0 ) {
				$colour = self::slice_colour( $index, $slice['other'] );
				$html  .= '<td width="' . (int) $slice['width'] . '%" height="16" bgcolor="' . $colour . '" style="width:' . (int) $slice['width'] . '%;height:16px;padding:0;border:0;background:' . $colour . ';font-size:0;line-height:0;">&nbsp;</td>';
			}
			if ( ! $slice['other'] && null !== $before ) {
				$before_named += (int) ( $before[ $slice['name'] ] ?? 0 );
			}
		}
		$html .= '</tr></table>';

		$html .= self::table_open() . '<tr><th style="' . self::HEAD . 'text-align:left;"></th>' . self::head( __( 'Orders', 'romcommerce' ) ) . self::head( __( 'Share', 'romcommerce' ) );
		if ( null !== $before ) {
			$html .= self::head( __( 'Change', 'romcommerce' ) );
		}
		$html .= '</tr>';

		foreach ( $slices as $index => $slice ) {
			$name = $slice['other']
				/* translators: %d: number of payment methods grouped together */
				? sprintf( _n( 'Other (%d method)', 'Other (%d methods)', $slice['methods'], 'romcommerce' ), $slice['methods'] )
				: $slice['name'];

			$html .= '<tr><td style="' . self::CELL . '"><span style="display:inline-block;width:10px;height:10px;margin-right:6px;background:' . self::slice_colour( $index, $slice['other'] ) . ';"></span>' . esc_html( $name ) . '</td>';
			$html .= '<td style="' . self::NUM . '">' . esc_html( number_format_i18n( $slice['count'] ) ) . '</td>';
			$html .= '<td style="' . self::NUM . '"><strong>' . esc_html( self::share( $slice['percent'] ) ) . '</strong></td>';
			if ( null !== $before ) {
				$was   = $slice['other'] ? $before_total - $before_named : (int) ( $before[ $slice['name'] ] ?? 0 );
				$html .= '<td style="' . self::DELTA . '">' . self::points( Compare::points( $slice['count'], $total, $was, $before_total ) ) . '</td>';
			}
			$html .= '</tr>';
		}

		return $html . '</table>';
	}

	/**
	 * The first $top entries, then everything else summed into one "other"
	 * slice. Widths are whole percentages summing to exactly 100 (largest
	 * remainder), with every slice at least 1 wide so a small one stays
	 * visible in the bar.
	 *
	 * @param array<string, int> $counts Most first.
	 * @return array<int, array{name: string, count: int, percent: float, width: int, other: bool, methods: int}>
	 */
	public static function slices( array $counts, int $top ): array {
		$counts = array_filter( $counts );
		$total  = array_sum( $counts );
		if ( $total <= 0 ) {
			return array();
		}

		$slices = array();
		foreach ( array_slice( $counts, 0, $top, true ) as $name => $count ) {
			$slices[] = array(
				'name'    => (string) $name,
				'count'   => (int) $count,
				'other'   => false,
				'methods' => 1,
			);
		}
		$rest = array_slice( $counts, $top, null, true );
		if ( array() !== $rest ) {
			$slices[] = array(
				'name'    => '',
				'count'   => (int) array_sum( $rest ),
				'other'   => true,
				'methods' => count( $rest ),
			);
		}

		$widths     = array();
		$remainders = array();
		foreach ( $slices as $index => $slice ) {
			$exact                = 100 * $slice['count'] / $total;
			$widths[ $index ]     = max( 1, (int) floor( $exact ) );
			$remainders[ $index ] = $exact - floor( $exact );
		}
		arsort( $remainders );
		$gap = 100 - array_sum( $widths );
		foreach ( array_keys( $remainders ) as $index ) {
			if ( $gap <= 0 ) {
				break;
			}
			++$widths[ $index ];
			--$gap;
		}
		while ( $gap < 0 ) {
			$widest = (int) array_search( max( $widths ), $widths, true );
			--$widths[ $widest ];
			++$gap;
		}

		foreach ( $slices as $index => $slice ) {
			$slices[ $index ]['percent'] = round( 100 * $slice['count'] / $total, 1 );
			$slices[ $index ]['width']   = $widths[ $index ];
		}

		return $slices;
	}

	private static function slice_colour( int $index, bool $other ): string {
		$last = count( self::SLICE_COLOURS ) - 1;

		return $other ? self::SLICE_COLOURS[ $last ] : self::SLICE_COLOURS[ min( $index, $last - 1 ) ];
	}

	/**
	 * "▲ +15 (+19%)" or "▼ −120,00 lei (−8.0%)": the arrow, the sign and the
	 * numbers carry the direction on their own, for colour-blind readers and
	 * clients that drop colours; the colour only adds whether it is good news.
	 * No percentage from a zero start.
	 */
	public static function change( float $now, float $before, bool $money, int $polarity = self::UP_GOOD ): string {
		$change = Compare::change( $now, $before );
		$diff   = $change['diff'];

		if ( abs( $diff ) < 0.005 ) {
			return self::tone( '= ' . __( 'no change', 'romcommerce' ), self::NEUTRAL );
		}

		$up   = $diff > 0;
		$sign = $up ? '+' : '−';
		$text = ( $up ? '▲' : '▼' ) . self::NBSP . $sign . ( $money ? self::money_text( abs( $diff ) ) : number_format_i18n( abs( $diff ) ) );
		if ( null !== $change['percent'] ) {
			$text .= ' (' . $sign . self::percent( abs( $change['percent'] ) ) . ')';
		}

		return self::tone( $text, $up ? $polarity : -$polarity );
	}

	/** "▲ +2.1 pp": a share moving is neither good nor bad news, so always grey. */
	public static function points( float $points ): string {
		if ( abs( $points ) < 0.05 ) {
			return self::tone( '= ' . __( 'no change', 'romcommerce' ), self::NEUTRAL );
		}

		$text = ( $points > 0 ? '▲ +' : '▼ −' ) . sprintf(
			/* translators: %s: a change in percentage points, e.g. 2.1 */
			__( '%s pp', 'romcommerce' ),
			number_format_i18n( abs( $points ), 1 )
		);

		return self::tone( str_replace( ' ', self::NBSP, $text ), self::NEUTRAL );
	}

	/** Whole percentages from 10 up, one decimal below, so small moves don't read as 0%. */
	public static function percent( float $value ): string {
		return number_format_i18n( $value, abs( $value ) < 10 ? 1 : 0 ) . '%';
	}

	/** One decimal, the same precision as the percentage-point changes beside it. */
	private static function share( float $value ): string {
		return number_format_i18n( $value, 1 ) . '%';
	}

	/** @param string $text Plain text. */
	private static function tone( string $text, int $polarity ): string {
		$colour = self::UP_GOOD === $polarity ? '#2e7d32' : ( self::UP_BAD === $polarity ? '#c62828' : '#636363' );

		return '<span style="color:' . $colour . ';">' . esc_html( $text ) . '</span>';
	}

	private static function money( float $amount ): string {
		return esc_html( self::money_text( $amount ) );
	}

	private static function money_text( float $amount ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}

	private static function entered( bool $entered ): string {
		if ( ! $entered ) {
			return '';
		}

		/* translators: %d: how many rows the list shows, e.g. 5 */
		return '<br><span style="' . self::NOTE . '">' . esc_html( sprintf( __( 'New in the top %d', 'romcommerce' ), self::TOP ) ) . '</span>';
	}

	/** @param array<int, array{name: string, rank: int}> $left */
	private static function left( array $left ): string {
		if ( array() === $left ) {
			return '';
		}

		$names = array();
		foreach ( $left as $row ) {
			/* translators: 1: product, category, channel or campaign name, 2: its rank in the earlier period */
			$names[] = sprintf( __( '%1$s (was #%2$d)', 'romcommerce' ), $row['name'], $row['rank'] );
		}

		return self::note(
			sprintf(
				/* translators: 1: how many rows the list shows, e.g. 5, 2: comma-separated names */
				__( 'Dropped out of the top %1$d: %2$s.', 'romcommerce' ),
				self::TOP,
				implode( ', ', $names )
			)
		);
	}

	/**
	 * A section with its heading, or its "nothing to show" line. Several
	 * sections empty for the same reason say so once, not once each.
	 *
	 * @param array<int, string> $said Messages already shown in this email.
	 */
	private static function section( string $heading, string $body, string $empty, array &$said ): string {
		if ( '' !== $body ) {
			return self::heading( $heading ) . $body;
		}

		if ( in_array( $empty, $said, true ) ) {
			return '';
		}
		$said[] = $empty;

		return self::heading( $heading ) . self::note( $empty );
	}

	private static function heading( string $text ): string {
		return '<h2 style="margin:24px 0 8px;font-size:16px;">' . esc_html( $text ) . '</h2>';
	}

	private static function head( string $text, string $align = 'right' ): string {
		return '<th style="' . self::HEAD . 'text-align:' . ( 'left' === $align ? 'left' : 'right' ) . ';">' . esc_html( $text ) . '</th>';
	}

	private static function table_open(): string {
		return '<table cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;margin:0 0 16px;">';
	}

	private static function note( string $message ): string {
		return '<p style="margin:0 0 16px;color:#636363;">' . esc_html( $message ) . '</p>';
	}
}
