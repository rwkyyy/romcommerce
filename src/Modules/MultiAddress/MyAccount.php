<?php

declare(strict_types=1);

namespace RomCommerce\Modules\MultiAddress;

defined( 'ABSPATH' ) || exit;

/**
 * "Adrese de livrare" My Account segment: list, add, edit and delete saved
 * delivery addresses. Mutations are handled on template_redirect (before output)
 * so they can set a notice and redirect cleanly; rendering is read-only.
 */
final class MyAccount {

	private const ENDPOINT = 'adrese-livrare';

	public function register(): void {
		add_action( 'init', array( $this, 'add_endpoint' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render' ) );
		add_action( 'template_redirect', array( $this, 'handle_actions' ) );
	}

	public function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * @param array<string, string> $vars
	 * @return array<string, string>
	 */
	public function add_query_var( array $vars ): array {
		$vars[] = self::ENDPOINT;

		return $vars;
	}

	/**
	 * @param array<string, string> $items
	 * @return array<string, string>
	 */
	public function add_menu_item( array $items ): array {
		$new = array();
		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'edit-address' === $key ) {
				$new[ self::ENDPOINT ] = __( 'Adrese de livrare', 'romcommerce' );
			}
		}

		if ( ! isset( $new[ self::ENDPOINT ] ) ) {
			$new[ self::ENDPOINT ] = __( 'Adrese de livrare', 'romcommerce' );
		}

		return $new;
	}

	private function endpoint_url(): string {
		return wc_get_endpoint_url( self::ENDPOINT, '', wc_get_page_permalink( 'myaccount' ) );
	}

	public function handle_actions(): void {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( self::ENDPOINT ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( 0 === $user_id ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- routing dispatch only; handle_save() verifies its own nonce before using any submitted value.
		if ( isset( $_POST['rc_addr_action'] ) && 'save' === $_POST['rc_addr_action'] ) {
			$this->handle_save( $user_id );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing dispatch only; handle_delete() verifies its own nonce before acting.
		if ( isset( $_GET['rc_delete'] ) ) {
			$this->handle_delete( $user_id );
		}
	}

	private function handle_save( int $user_id ): void {
		$id = isset( $_POST['rc_addr_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rc_addr_id'] ) ) : '';

		if ( ! isset( $_POST['rc_addr_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rc_addr_nonce'] ) ), 'rc_addr_save' ) ) {
			wc_add_notice( __( 'Sesiunea a expirat. Încercați din nou.', 'romcommerce' ), 'error' );
			wp_safe_redirect( $this->endpoint_url() );
			exit;
		}

		$input = array();
		foreach ( array_keys( AddressBook::fields() ) as $key ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- AddressBook::save() runs every field through sanitize_text_field() via AddressBook::sanitize() before storage.
			$input[ $key ] = isset( $_POST[ 'rc_addr_' . $key ] ) ? wp_unslash( $_POST[ 'rc_addr_' . $key ] ) : '';
		}

		foreach ( AddressBook::REQUIRED as $key ) {
			if ( '' === trim( (string) $input[ $key ] ) ) {
				wc_add_notice( __( 'Completați toate câmpurile obligatorii (marcate cu *).', 'romcommerce' ), 'error' );
				wp_safe_redirect( $this->endpoint_url() . ( '' !== $id ? '?rc_edit=' . rawurlencode( $id ) : '' ) );
				exit;
			}
		}

		$saved = AddressBook::save( $user_id, $input, $id );

		if ( '' === $saved ) {
			wc_add_notice( __( 'Ați atins numărul maxim de adrese salvate.', 'romcommerce' ), 'error' );
		} else {
			wc_add_notice( __( 'Adresa a fost salvată.', 'romcommerce' ) );
		}

		wp_safe_redirect( $this->endpoint_url() );
		exit;
	}

	private function handle_delete( int $user_id ): void {
		if ( ! isset( $_GET['rc_delete'] ) ) {
			return;
		}

		$id = sanitize_text_field( wp_unslash( $_GET['rc_delete'] ) );

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'rc_addr_delete_' . $id ) ) {
			wc_add_notice( __( 'Acțiune invalidă.', 'romcommerce' ), 'error' );
			wp_safe_redirect( $this->endpoint_url() );
			exit;
		}

		AddressBook::delete( $user_id, $id );
		wc_add_notice( __( 'Adresa a fost ștearsă.', 'romcommerce' ) );
		wp_safe_redirect( $this->endpoint_url() );
		exit;
	}

	public function render(): void {
		$user_id = get_current_user_id();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display switch, not a state-changing action.
		$editing = isset( $_GET['rc_edit'] ) ? sanitize_text_field( wp_unslash( $_GET['rc_edit'] ) ) : '';

		if ( '' !== $editing ) {
			$address = AddressBook::get( $user_id, $editing );
			if ( null !== $address ) {
				$this->render_form( $editing, $address );
				return;
			}
		}

		$this->render_list( $user_id );
		echo '<h3>' . esc_html__( 'Adaugă o adresă', 'romcommerce' ) . '</h3>';
		$this->render_form( '', array() );
	}

	private function render_list( int $user_id ): void {
		$addresses = AddressBook::all( $user_id );

		if ( empty( $addresses ) ) {
			echo '<p>' . esc_html__( 'Nu aveți adrese de livrare salvate.', 'romcommerce' ) . '</p>';
			return;
		}

		echo '<table class="woocommerce-orders-table shop_table"><thead><tr>';
		echo '<th>' . esc_html__( 'Etichetă', 'romcommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Adresă', 'romcommerce' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $addresses as $id => $address ) {
			$edit_url   = add_query_arg( 'rc_edit', $id, $this->endpoint_url() );
			$delete_url = wp_nonce_url( add_query_arg( 'rc_delete', $id, $this->endpoint_url() ), 'rc_addr_delete_' . $id );

			echo '<tr>';
			echo '<td>' . esc_html( $address['label'] ?? '' ) . '</td>';
			echo '<td>' . esc_html( AddressBook::summary( $address ) ) . '</td>';
			echo '<td><a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Editează', 'romcommerce' ) . '</a> | ';
			echo '<a href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Ștergeți această adresă?', 'romcommerce' ) ) . '\');">' . esc_html__( 'Șterge', 'romcommerce' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/** @param array<string, string> $address */
	private function render_form( string $id, array $address ): void {
		echo '<form method="post" action="' . esc_url( $this->endpoint_url() ) . '">';
		wp_nonce_field( 'rc_addr_save', 'rc_addr_nonce' );
		echo '<input type="hidden" name="rc_addr_action" value="save">';
		echo '<input type="hidden" name="rc_addr_id" value="' . esc_attr( $id ) . '">';

		foreach ( AddressBook::fields() as $key => $label ) {
			$value    = isset( $address[ $key ] ) ? $address[ $key ] : '';
			$required = in_array( $key, AddressBook::REQUIRED, true );

			echo '<p class="form-row form-row-wide' . ( $required ? ' validate-required' : '' ) . '">';
			echo '<label for="rc_addr_' . esc_attr( $key ) . '">' . esc_html( $label );
			if ( $required ) {
				echo ' <abbr class="required" title="' . esc_attr__( 'obligatoriu', 'romcommerce' ) . '">*</abbr>';
			}
			echo '</label>';

			if ( 'country' === $key ) {
				$this->render_country_select( $key, $value );
			} else {
				echo '<input type="text" class="input-text" id="rc_addr_' . esc_attr( $key ) . '" name="rc_addr_' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '"' . ( $required ? ' required' : '' ) . '>';
			}

			echo '</p>';
		}

		echo '<p><button type="submit" class="button">' . esc_html__( 'Salvează adresa', 'romcommerce' ) . '</button></p>';
		echo '</form>';
	}

	private function render_country_select( string $key, string $value ): void {
		if ( '' === $value ) {
			$base  = (string) get_option( 'woocommerce_default_country' );
			$value = false !== strpos( $base, ':' ) ? strstr( $base, ':', true ) : $base;
		}

		$countries = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_allowed_countries() : array();

		echo '<select id="rc_addr_' . esc_attr( $key ) . '" name="rc_addr_' . esc_attr( $key ) . '" class="country_select" required>';
		foreach ( $countries as $code => $name ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( $code, $value, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select>';
	}
}
