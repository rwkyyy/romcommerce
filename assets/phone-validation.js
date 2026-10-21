/**
 * Phone validation at checkout, on the bundled intl-tel-input. Config comes
 * from window.romcommercePhoneValidation; the server re-checks every number.
 *
 * Classic checkout: a country picker on the billing and shipping phone fields.
 * The number stays in national format when the picked country is the
 * address's own, and is sent in international (E.164) form otherwise, so the
 * server knows which country it belongs to.
 *
 * Block checkout: WooCommerce's React form can't host the picker, so the
 * number is checked on leaving the field against the address's country, and
 * an error goes through WooCommerce's validation store, which shows it under
 * the field and blocks Place Order.
 *
 * @package RomCommerce
 */
( function () {
	var config = window.romcommercePhoneValidation;
	var iti = window.intlTelInput;

	if ( ! config || ! iti || ! iti.utils ) {
		return;
	}

	function isValid( number, country ) {
		return iti.utils.isValidNumberPrecise( number, ( country || config.initialCountry ).toLowerCase() );
	}

	function initClassic( group ) {
		var input = document.getElementById( group + '_phone' );
		var countrySelect = document.getElementById( group + '_country' );

		if ( ! input || input.getAttribute( 'data-rc-phone' ) ) {
			return;
		}
		input.setAttribute( 'data-rc-phone', '1' );

		var options = {
			initialCountry: ( ( countrySelect && countrySelect.value ) || config.initialCountry ).toLowerCase(),
			separateDialCode: false,
			numberDisplayFormat: 'NATIONAL',
		};
		if ( config.onlyCountries.length ) {
			options.onlyCountries = config.onlyCountries;
		}
		var instance = iti( input, options );
		var row = input.closest( '.form-row' );

		function markInvalid( invalid ) {
			if ( row ) {
				row.classList.toggle( 'woocommerce-invalid', invalid );
				row.classList.toggle( 'woocommerce-invalid-phone', invalid );
			}
		}

		input.addEventListener( 'blur', function () {
			markInvalid( '' !== input.value.trim() && ! instance.isValidNumberPrecise() );
		} );

		if ( countrySelect && window.jQuery ) {
			// WooCommerce swaps the country select for selectWoo, which fires
			// jQuery's change, not a native one.
			window.jQuery( countrySelect ).on( 'change', function () {
				if ( '' === input.value.trim() && countrySelect.value ) {
					instance.setSelectedCountry( countrySelect.value.toLowerCase() );
				}
			} );
		}

		if ( window.jQuery ) {
			window.jQuery( 'form.checkout' ).on( 'checkout_place_order', function () {
				var selected = instance.getSelectedCountry();
				var addressCountry = countrySelect ? countrySelect.value : config.initialCountry;

				if ( '' !== input.value.trim() && selected && selected.iso2.toUpperCase() !== addressCountry ) {
					input.value = instance.getNumber( 'E164' );
				}

				return true;
			} );
		}
	}

	initClassic( 'billing' );
	initClassic( 'shipping' );

	document.addEventListener( 'focusout', function ( event ) {
		var match = event.target && event.target.id ? /^(billing|shipping)-phone$/.exec( event.target.id ) : null;
		var data = window.wp && window.wp.data;

		if ( ! match || ! data ) {
			return;
		}

		var group = match[ 1 ];
		var customer = data.select( 'wc/store/cart' ).getCustomerData();
		var address = customer && customer[ group + 'Address' ];
		var key = group + '_phone';
		var value = event.target.value.trim();
		var validation = data.dispatch( 'wc/store/validation' );

		if ( '' === value || isValid( value, address && address.country ) ) {
			validation.clearValidationError( key );
			return;
		}

		var errors = {};
		errors[ key ] = { message: config.invalid, hidden: false };
		validation.setValidationErrors( errors );
	} );
}() );
