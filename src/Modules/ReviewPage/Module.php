<?php

declare(strict_types=1);

namespace RomCommerce\Modules\ReviewPage;

use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;
use WC_Order;
use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Multi-product review page: one page where a customer reviews every product
 * from a past order in one sitting instead of visiting each product page. The
 * order key in the URL is the only auth gate (no e-mail challenge) — reviews go
 * straight into WooCommerce's native review store (comments, type=review) so
 * they appear in the normal product-page listings. Rendered by the
 * [romcommerce_review_page] shortcode.
 */
final class Module implements ModuleInterface, HasSettingsUi {

	private const ID = 'review-page';

	private const NONCE_ACTION = 'romcommerce_review_flow';

	/** Reachable for 90 days from the order's completed date (the delivery proxy). */
	private const WINDOW_DAYS = 90;

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Multi-Product Review Page', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		add_shortcode( 'romcommerce_review_page', array( $this, 'render' ) );
	}

	/** @param array<string, mixed>|string $atts */
	public function render( $atts ): string {
		ob_start();
		echo '<div class="romcommerce-review-page">';

		if ( 'yes' !== get_option( 'woocommerce_enable_reviews' ) ) {
			$this->notice( __( 'Recenziile la produse sunt dezactivate în acest magazin.', 'romcommerce' ), 'error' );
			echo '</div>';
			return (string) ob_get_clean();
		}

		$order = $this->resolve_order();

		if ( ! $order instanceof WC_Order ) {
			$this->render_no_order();
		} elseif ( ! $order->has_status( 'completed' ) ) {
			$this->notice( __( 'Această comandă nu este încă disponibilă pentru evaluare.', 'romcommerce' ), 'error' );
		} elseif ( ! $this->within_window( $order ) ) {
			$this->render_expired();
		} else {
			if ( $this->is_submission() && $this->verify_nonce() ) {
				$this->handle_submission( $order );
			}
			$this->render_form( $order );
		}

		echo '</div>';
		return (string) ob_get_clean();
	}

	private function resolve_order(): ?WC_Order {
		$key = '';
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- read-only order lookup; the mutating action is separately gated by verify_nonce().
		if ( isset( $_POST['rc_order_key'] ) ) {
			$key = sanitize_text_field( wp_unslash( $_POST['rc_order_key'] ) );
		} elseif ( isset( $_GET['order_key'] ) ) {
			$key = sanitize_text_field( wp_unslash( $_GET['order_key'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

		if ( '' === $key ) {
			return null;
		}

		$order_id = wc_get_order_id_by_order_key( $key );
		if ( ! $order_id ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		return $order instanceof WC_Order ? $order : null;
	}

	private function within_window( WC_Order $order ): bool {
		$completed = $order->get_date_completed();
		if ( null === $completed ) {
			return false;
		}

		return time() <= ( $completed->getTimestamp() + ( self::WINDOW_DAYS * DAY_IN_SECONDS ) );
	}

	private function is_submission(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only detection; verify_nonce() is the actual gate, checked separately before handle_submission() runs.
		return isset( $_POST['rc_submit_product'] ) || isset( $_POST['rc_submit_all'] );
	}

	private function verify_nonce(): bool {
		return isset( $_POST['rc_nonce'] )
			&& (bool) wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rc_nonce'] ) ), self::NONCE_ACTION );
	}

	private function handle_submission( WC_Order $order ): void {
		$products = $this->order_products( $order );

		$targets = array();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in render() via verify_nonce() before this method runs.
		if ( isset( $_POST['rc_submit_product'] ) ) {
			$targets[] = absint( wp_unslash( $_POST['rc_submit_product'] ) );
		} else {
			$targets = array_keys( $products );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Nonce is verified in render() before handle_submission() runs; each value is sanitised per element here.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ratings = ( isset( $_POST['rc_rating'] ) && is_array( $_POST['rc_rating'] ) ) ? array_map( 'absint', wp_unslash( $_POST['rc_rating'] ) ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$reviews = ( isset( $_POST['rc_review'] ) && is_array( $_POST['rc_review'] ) ) ? array_map( 'sanitize_textarea_field', wp_unslash( $_POST['rc_review'] ) ) : array();

		$saved = 0;
		foreach ( $targets as $product_id ) {
			$product_id = (int) $product_id;
			if ( ! isset( $products[ $product_id ] ) || $this->already_reviewed( $order->get_id(), $product_id ) ) {
				continue;
			}

			$rating  = isset( $ratings[ $product_id ] ) ? (int) $ratings[ $product_id ] : 0;
			$content = isset( $reviews[ $product_id ] ) ? sanitize_textarea_field( (string) $reviews[ $product_id ] ) : '';

			if ( $rating < 1 || $rating > 5 || '' === trim( $content ) ) {
				continue;
			}

			if ( $this->insert_review( $order, $product_id, $rating, $content ) ) {
				++$saved;
			}
		}

		if ( $saved > 0 ) {
			$this->notice(
				sprintf(
					/* translators: %d: number of reviews saved */
					_n( '%d recenzie a fost trimisă. Mulțumim!', '%d recenzii au fost trimise. Mulțumim!', $saved, 'romcommerce' ),
					$saved
				),
				'success'
			);
		} else {
			$this->notice( __( 'Selectați o notă și scrieți o recenzie pentru a trimite.', 'romcommerce' ), 'error' );
		}
	}

	private function insert_review( WC_Order $order, int $product_id, int $rating, string $content ): bool {
		$approved = ( '1' === get_option( 'comment_moderation' ) ) ? 0 : 1;

		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => trim( $order->get_formatted_billing_full_name() ),
				'comment_author_email' => $order->get_billing_email(),
				'comment_content'      => $content,
				'comment_type'         => 'review',
				'comment_parent'       => 0,
				'user_id'              => $order->get_customer_id(),
				'comment_approved'     => $approved,
			)
		);

		if ( ! $comment_id ) {
			return false;
		}

		add_comment_meta( $comment_id, 'rating', $rating, true );
		add_comment_meta( $comment_id, 'verified', 1, true );
		add_comment_meta( $comment_id, 'romcommerce_review_order_id', (string) $order->get_id(), true );

		// Recompute the product's cached rating/count so the new review shows up
		// in the product-page averages immediately.
		if ( class_exists( '\WC_Comments' ) ) {
			\WC_Comments::clear_transients( $product_id );
		}

		return true;
	}

	private function already_reviewed( int $order_id, int $product_id ): bool {
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one-shot dedup check on a single product's comments, not a scale-sensitive query.
		$count = get_comments(
			array(
				'post_id'    => $product_id,
				'type'       => 'review',
				'status'     => 'all',
				'meta_key'   => 'romcommerce_review_order_id',
				'meta_value' => (string) $order_id,
				'count'      => true,
			)
		);
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

		return (int) $count > 0;
	}

	/** @return array<int, WC_Order_Item_Product> unique parent-product id => order item */
	private function order_products( WC_Order $order ): array {
		$products = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product_id = $item->get_product_id();
			if ( $product_id > 0 && ! isset( $products[ $product_id ] ) ) {
				$products[ $product_id ] = $item;
			}
		}

		return $products;
	}

	private function render_form( WC_Order $order ): void {
		$products = $this->order_products( $order );
		if ( empty( $products ) ) {
			$this->notice( __( 'Această comandă nu conține produse care pot fi evaluate.', 'romcommerce' ), 'error' );
			return;
		}

		/* translators: %s: order number */
		echo '<h2>' . esc_html( sprintf( __( 'Evaluați produsele din comanda #%s', 'romcommerce' ), $order->get_order_number() ) ) . '</h2>';

		echo '<form method="post">';
		wp_nonce_field( self::NONCE_ACTION, 'rc_nonce' );
		echo '<input type="hidden" name="rc_order_key" value="' . esc_attr( $order->get_order_key() ) . '">';

		$pending = 0;
		foreach ( $products as $product_id => $item ) {
			echo '<div class="romcommerce-review-item" style="margin:0 0 24px;padding:16px;border:1px solid #e0e0e0;border-radius:4px;">';
			echo '<h3 style="margin-top:0;">' . esc_html( $item->get_name() ) . '</h3>';

			if ( $this->already_reviewed( $order->get_id(), $product_id ) ) {
				echo '<p><em>' . esc_html__( 'Ați evaluat deja acest produs. Vă mulțumim!', 'romcommerce' ) . '</em></p>';
				echo '</div>';
				continue;
			}

			++$pending;

			echo '<p><label>' . esc_html__( 'Notă', 'romcommerce' ) . '<br><select name="rc_rating[' . esc_attr( (string) $product_id ) . ']">';
			echo '<option value="0">' . esc_html__( '— alegeți —', 'romcommerce' ) . '</option>';
			for ( $star = 5; $star >= 1; $star-- ) {
				/* translators: %d: star rating value */
				echo '<option value="' . esc_attr( (string) $star ) . '">' . esc_html( sprintf( _n( '%d stea', '%d stele', $star, 'romcommerce' ), $star ) ) . '</option>';
			}
			echo '</select></label></p>';

			echo '<p><label>' . esc_html__( 'Recenzia dumneavoastră', 'romcommerce' ) . '<br>';
			echo '<textarea name="rc_review[' . esc_attr( (string) $product_id ) . ']" rows="3" class="input-text" style="width:100%;"></textarea></label></p>';

			echo '<p><button type="submit" name="rc_submit_product" value="' . esc_attr( (string) $product_id ) . '" class="button">' . esc_html__( 'Trimiteți recenzia', 'romcommerce' ) . '</button></p>';
			echo '</div>';
		}

		if ( 0 === $pending ) {
			echo '<p>' . esc_html__( 'Ați evaluat toate produsele din această comandă.', 'romcommerce' ) . '</p>';
		} else {
			echo '<p><button type="submit" name="rc_submit_all" value="1" class="button button-primary">' . esc_html__( 'Trimiteți toate recenziile', 'romcommerce' ) . '</button></p>';
		}

		echo '</form>';
	}

	private function render_expired(): void {
		echo '<h2>' . esc_html__( 'Perioada de evaluare a expirat', 'romcommerce' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: review window in days */
				__( 'Produsele pot fi evaluate în %d de zile de la finalizarea comenzii. Această perioadă a expirat.', 'romcommerce' ),
				self::WINDOW_DAYS
			)
		) . '</p>';
	}

	private function render_no_order(): void {
		echo '<h2>' . esc_html__( 'Evaluați produsele', 'romcommerce' ) . '</h2>';

		if ( is_user_logged_in() ) {
			$orders = wc_get_orders(
				array(
					'customer_id' => get_current_user_id(),
					'status'      => array( 'completed' ),
					'limit'       => 20,
					'orderby'     => 'date',
					'order'       => 'DESC',
				)
			);

			$eligible = array_filter(
				$orders,
				function ( $order ): bool {
					return $order instanceof WC_Order && $this->within_window( $order );
				}
			);

			if ( ! empty( $eligible ) ) {
				echo '<p>' . esc_html__( 'Alegeți o comandă pentru a evalua produsele:', 'romcommerce' ) . '</p><ul>';
				foreach ( $eligible as $order ) {
					$url = add_query_arg( 'order_key', $order->get_order_key(), $this->page_url() );
					echo '<li><a href="' . esc_url( $url ) . '">';
					/* translators: 1: order number, 2: order completed date */
					echo esc_html( sprintf( __( 'Comanda #%1$s (%2$s)', 'romcommerce' ), $order->get_order_number(), wc_format_datetime( $order->get_date_completed() ) ) );
					echo '</a></li>';
				}
				echo '</ul>';
				return;
			}
		}

		echo '<p>' . esc_html__( 'Această pagină se deschide dintr-un link de evaluare primit pentru comanda dumneavoastră. Verificați e-mailul comenzii pentru linkul de evaluare.', 'romcommerce' ) . '</p>';
	}

	private function page_url(): string {
		$page_id = (int) ( Settings::get( self::ID )['review_page_id'] ?? 0 );
		if ( $page_id > 0 ) {
			return (string) get_permalink( $page_id );
		}

		return remove_query_arg( array( 'order_key' ) );
	}

	private function notice( string $message, string $type ): void {
		$class = 'error' === $type ? 'woocommerce-error' : 'woocommerce-message';
		echo '<div class="' . esc_attr( $class ) . '">' . esc_html( $message ) . '</div>';
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// SettingsForm::verify() has confirmed the nonce and the manage_woocommerce
			// capability on the line above; the $_POST reads live here inside that guard
			// (not in the handler) so the authorization check is local to the input.
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above via SettingsForm::verify().
			if ( isset( $_POST['rc_create_page'] ) ) {
				$this->handle_create_page();
			} else {
				$this->handle_save(
					isset( $_POST['rc_enabled'] ),
					absint( wp_unslash( $_POST['rc_page_id'] ?? 0 ) )
				);
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing
		}

		$page_id = (int) ( Settings::get( self::ID )['review_page_id'] ?? 0 );

		echo '<p>' . esc_html__( 'A single page where customers review every product from a past order at once. Reviews are saved into WooCommerce\'s native review system.', 'romcommerce' ) . '</p>';

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Module', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ';
		echo esc_html__( 'Enable', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Review page', 'romcommerce' ) . '</th><td>';
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages() escapes its own output.
		wp_dropdown_pages(
			array(
				'name'              => 'rc_page_id',
				'selected'          => $page_id,
				'show_option_none'  => __( '— none —', 'romcommerce' ),
				'option_none_value' => '0',
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p class="description">' . esc_html__( 'The page containing the [romcommerce_review_page] shortcode. Review links point here.', 'romcommerce' ) . '</p>';
		echo '<button type="submit" name="rc_create_page" value="1" class="button">' . esc_html__( 'Create review page', 'romcommerce' ) . '</button>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Eligibility window', 'romcommerce' ) . '</th><td>';
		/* translators: %d: number of days */
		echo esc_html( sprintf( __( '%d days after the order is completed.', 'romcommerce' ), self::WINDOW_DAYS ) );
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';

		echo '<hr><p><span class="romcommerce-badge">Pro</span> ' . esc_html__( 'RomCommerce Pro adds review-page template customization and automatic review-reminder cadence.', 'romcommerce' ) . '</p>';
	}

	private function handle_save( bool $enabled, int $page_id ): void {
		Settings::set_enabled( self::ID, $enabled );
		Settings::update( self::ID, array( 'review_page_id' => $page_id ) );
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
	}

	private function handle_create_page(): void {
		$existing = (int) ( Settings::get( self::ID )['review_page_id'] ?? 0 );
		if ( $existing > 0 && 'page' === get_post_type( $existing ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'A review page is already linked.', 'romcommerce' ) . '</p></div>';
			return;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Evaluați produsele', 'romcommerce' ),
				'post_content' => '[romcommerce_review_page]',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			),
			true
		);

		if ( is_wp_error( $page_id ) || 0 === $page_id ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The review page could not be created.', 'romcommerce' ) . '</p></div>';
			return;
		}

		$settings                   = Settings::get( self::ID );
		$settings['review_page_id'] = (int) $page_id;
		Settings::update( self::ID, $settings );

		echo '<div class="notice notice-success"><p>' . esc_html__( 'Review page created and linked.', 'romcommerce' ) . '</p></div>';
	}
}
