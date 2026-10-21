<?php

declare(strict_types=1);

namespace RomCommerce\Modules\MultiAddress;

use RomCommerce\Support\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a "choose a saved address" selector above the checkout shipping form
 * for logged-in customers with saved addresses, and fills the shipping fields
 * from the chosen one via a small vanilla script (no build step). Only touches
 * fields that exist and dispatches change events so WooCommerce reacts.
 *
 * On the block checkout the same list sits above the shipping address block
 * and fills it through WooCommerce's cart data store instead, which is what
 * the block form renders from.
 */
final class Checkout {

	public function register(): void {
		add_action( 'woocommerce_before_checkout_shipping_form', array( $this, 'render_selector' ) );
		Blocks::insert( 'woocommerce/checkout-shipping-address-block', 'before', array( $this, 'render_block_selector' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function render_selector(): void {
		$addresses = $this->eligible_addresses();
		if ( empty( $addresses ) ) {
			return;
		}

		echo '<p class="form-row form-row-wide" id="romcommerce-saved-address-row">';
		echo '<label for="romcommerce-saved-address">' . esc_html__( 'Alegeți o adresă salvată', 'romcommerce' ) . '</label>';
		echo '<select id="romcommerce-saved-address" class="select">';
		echo '<option value="">' . esc_html__( '— adresă nouă —', 'romcommerce' ) . '</option>';
		foreach ( $addresses as $id => $address ) {
			$label = ( $address['label'] ?? '' ) !== '' ? $address['label'] : AddressBook::summary( $address );
			echo '<option value="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';
		echo '<p class="description" style="margin-top:-8px;">' . esc_html__( 'Selectarea unei adrese salvate completează adresa de livrare și activează automat "Livrare la altă adresă" dacă nu era deja bifată.', 'romcommerce' ) . '</p>';
	}

	public function render_block_selector(): void {
		$addresses = $this->eligible_addresses();

		if ( empty( $addresses ) ) {
			return;
		}

		echo '<div class="wc-block-components-select-input romcommerce-saved-address-block" style="margin-bottom:16px;">';
		echo '<label for="romcommerce-saved-address-block" style="display:block;margin-bottom:4px;">' . esc_html__( 'Alegeți o adresă salvată', 'romcommerce' ) . '</label>';
		// The address id rides on data-rc-address: WooCommerce rebuilds this
		// markup through React on hydration, which drops <option value>.
		echo '<select id="romcommerce-saved-address-block" style="width:100%;">';
		echo '<option data-rc-address="">' . esc_html__( '— adresă nouă —', 'romcommerce' ) . '</option>';
		foreach ( $addresses as $id => $address ) {
			$label = ( $address['label'] ?? '' ) !== '' ? $address['label'] : AddressBook::summary( $address );
			echo '<option data-rc-address="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select></div>';
	}

	public function enqueue_assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		$addresses = $this->eligible_addresses();
		if ( empty( $addresses ) ) {
			return;
		}

		$deps = has_block( 'woocommerce/checkout' ) ? array( 'wp-data', 'wc-blocks-data-store' ) : array();
		wp_register_script( 'romcommerce-multi-address-checkout', false, $deps, ROMCOMMERCE_VERSION, true );
		wp_enqueue_script( 'romcommerce-multi-address-checkout' );
		wp_add_inline_script( 'romcommerce-multi-address-checkout', $this->script_js( $addresses ) );
	}

	/** @return array<string, array<string, string>> */
	private function eligible_addresses(): array {
		if ( ! is_user_logged_in() ) {
			return array();
		}

		return AddressBook::all( get_current_user_id() );
	}

	/** @param array<string, array<string, string>> $addresses */
	private function script_js( array $addresses ): string {
		$map = (string) wp_json_encode( $addresses );

		return '(function(){'
			. 'var data=' . $map . ';'
			. 'var fields=["first_name","last_name","company","country","state","city","postcode","address_1","address_2","phone"];'
			. 'var sel=document.getElementById("romcommerce-saved-address");'
			. 'if(sel){sel.addEventListener("change",function(){'
			. 'var addr=data[sel.value];if(!addr){return;}'
			. 'var diff=document.getElementById("ship-to-different-address-checkbox");'
			. 'if(diff&&!diff.checked){diff.checked=true;diff.dispatchEvent(new Event("change",{bubbles:true}));}'
			. 'fields.forEach(function(key){'
			. 'var el=document.getElementById("shipping_"+key);if(!el||typeof addr[key]==="undefined"){return;}'
			. 'el.value=addr[key];el.dispatchEvent(new Event("change",{bubbles:true}));'
			. '});'
			. '});}'
			// Delegated: the block checkout's React tree may re-create the select.
			. 'document.addEventListener("change",function(e){'
			. 'if(!e.target||e.target.id!=="romcommerce-saved-address-block"){return;}'
			. 'var opt=e.target.options[e.target.selectedIndex],addr=opt?data[opt.getAttribute("data-rc-address")]:null,d=window.wp&&window.wp.data;if(!addr||!d){return;}'
			. 'var next={};fields.forEach(function(key){next[key]=typeof addr[key]==="undefined"?"":addr[key];});'
			. 'd.dispatch("wc/store/cart").setShippingAddress(next);'
			. '});'
			. '})();';
	}
}
