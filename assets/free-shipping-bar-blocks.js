/**
 * Keeps the free shipping bar current on the block cart and checkout, where
 * the cart changes without a page load. Same sum as the PHP render: displayed
 * subtotal minus the discount excluding tax, from the Store API totals (minor
 * units). Config comes from window.romcommerceFreeShippingBar.
 *
 * @package RomCommerce
 */
( function () {
	var data = window.wp && window.wp.data;
	var priceFormat = window.wc && window.wc.priceFormat;
	var config = window.romcommerceFreeShippingBar;

	if ( ! data || ! priceFormat || ! config ) {
		return;
	}

	function apply() {
		var store = data.select( 'wc/store/cart' );
		var totals = store ? store.getCartTotals() : null;

		if ( ! totals || ! totals.currency_code ) {
			return;
		}

		var total = parseInt( totals.total_items, 10 ) + ( config.inclTax ? parseInt( totals.total_items_tax, 10 ) : 0 ) - parseInt( totals.total_discount, 10 );
		total = Math.max( 0, total );

		document.querySelectorAll( '.romcommerce-fsb[data-rc-threshold]' ).forEach( function ( bar ) {
			var threshold = parseInt( bar.getAttribute( 'data-rc-threshold' ), 10 );
			if ( ! threshold ) {
				return;
			}

			var text = total >= threshold
				? config.done
				: config.left.replace( '%s', priceFormat.formatPrice( threshold - total, priceFormat.getCurrencyFromPriceResponse( totals ) ) );

			var message = bar.querySelector( '.romcommerce-fsb-message' );
			if ( message && message.textContent !== text ) {
				message.textContent = text;
			}

			var fill = bar.querySelector( '.romcommerce-fsb-fill' );
			if ( fill ) {
				fill.style.width = Math.min( 100, Math.floor( ( total / threshold ) * 100 ) ) + '%';
			}
		} );
	}

	data.subscribe( apply );
	apply();
}() );
