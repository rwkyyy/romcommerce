/**
 * Cash on delivery fee: recalculates the checkout totals when the shopper
 * switches between a charged payment method and any other, so the fee line
 * appears or goes before the order is placed. Only a switch across that line
 * costs a request. Config comes from window.romcommerceCodFee.
 *
 * @package RomCommerce
 */
( function () {
	var config = window.romcommerceCodFee;

	if ( ! config ) {
		return;
	}

	function charged( id ) {
		return config.gateways.indexOf( id || '' ) !== -1;
	}

	if ( config.blocks ) {
		var data = window.wp && window.wp.data;
		var checkout = window.wc && window.wc.blocksCheckout;

		if ( ! data || ! checkout || ! checkout.extensionCartUpdate ) {
			return;
		}

		// Starts as the method in the server's session, which the fee is worked
		// out from. Only whether a method is charged matters, so a switch
		// between two uncharged methods leaves the session as it is.
		var synced = config.chosen;

		data.subscribe( function () {
			var store = data.select( 'wc/store/payment' );
			var active = store && store.getActivePaymentMethod ? store.getActivePaymentMethod() : '';

			if ( ! active || active === synced ) {
				return;
			}

			var changes = charged( active ) !== charged( synced );
			synced = active;

			if ( changes ) {
				checkout.extensionCartUpdate( { namespace: config.namespace, data: { method: active } } );
			}
		} );

		return;
	}

	var $ = window.jQuery;

	if ( ! $ ) {
		return;
	}

	var sent = null;

	function checked() {
		return $( 'form.checkout input[name="payment_method"]:checked' ).val() || '';
	}

	$( document.body )
		.on( 'update_checkout', function () {
			sent = checked();
		} )
		// WooCommerce picks another method itself when the chosen one is no
		// longer offered after an update (cash on delivery limited to some
		// shipping methods), without a change event.
		.on( 'updated_checkout', function () {
			if ( null !== sent && charged( checked() ) !== charged( sent ) ) {
				$( document.body ).trigger( 'update_checkout' );
			}
		} )
		.on( 'change', 'form.checkout input[name="payment_method"]', function () {
			if ( charged( this.value ) !== charged( sent ) ) {
				$( document.body ).trigger( 'update_checkout' );
			}
		} );
}() );
