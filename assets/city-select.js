/**
 * Locality list for Romanian addresses. Config comes from
 * window.romcommerceCitySelect; in list-only mode the server re-checks the
 * city when the order is placed.
 *
 * Built for slow phones: each county's list is a static JSON file fetched the
 * first time that county is chosen and kept in memory, and a search shows at
 * most LIMIT matches, so a county's 600+ localities never become 600+ DOM
 * nodes.
 *
 * Classic checkout and My account: the city input becomes a select running
 * WooCommerce's own selectWoo, searched in memory through a local ajax
 * transport. Any other country gets the plain input back.
 *
 * Block checkout: WooCommerce's React form can't host selectWoo, so the city
 * input gets a native datalist of the county's localities when focused.
 *
 * @package RomCommerce
 */
( function () {
	var config = window.romcommerceCitySelect;

	if ( ! config || ! window.fetch ) {
		return;
	}

	var LIMIT = 50;
	var cache = {};

	function normalize( text ) {
		var value = String( text ).toLowerCase().replace( /[\s-]+/g, ' ' );

		return value.normalize ? value.normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ) : value;
	}

	/** @return {Promise<Array<Array<string>>>} [ normalized, name ] pairs. */
	function load( county ) {
		if ( ! cache[ county ] ) {
			cache[ county ] = window.fetch( config.url + encodeURIComponent( county ) + '.json?ver=' + encodeURIComponent( config.version ) )
				.then( function ( response ) {
					return response.ok ? response.json() : [];
				} )
				.then( function ( names ) {
					return names.map( function ( name ) {
						return [ normalize( name ), name ];
					} );
				} )
				.catch( function () {
					delete cache[ county ];
					return [];
				} );
		}

		return cache[ county ];
	}

	/** Names starting with the term first, then a later word starting with it, then any match. */
	function search( list, term ) {
		var query = normalize( term ).trim();
		var starts = [];
		var words = [];
		var inside = [];

		if ( '' === query ) {
			return list.slice( 0, LIMIT );
		}

		for ( var i = 0; i < list.length && starts.length < LIMIT; i++ ) {
			var at = list[ i ][ 0 ].indexOf( query );
			if ( 0 === at ) {
				starts.push( list[ i ] );
			} else if ( at > 0 ) {
				( ' ' === list[ i ][ 0 ].charAt( at - 1 ) ? words : inside ).push( list[ i ] );
			}
		}

		return starts.concat( words, inside ).slice( 0, LIMIT );
	}

	function initClassic( $ ) {
		function toSelect( input ) {
			var select = document.createElement( 'select' );
			select.id = input.id;
			select.name = input.name;
			select.className = 'romcommerce-city-select';
			select.appendChild( new Option( input.value, input.value, true, true ) );
			input.parentNode.replaceChild( select, input );
			$( select ).data( 'rcInput', input );

			return $( select );
		}

		function toInput( $select ) {
			var input = $select.data( 'rcInput' );
			input.value = $select.val() || '';
			if ( $select.data( 'select2' ) ) {
				$select.selectWoo( 'destroy' );
			}
			$select[ 0 ].parentNode.replaceChild( input, $select[ 0 ] );
		}

		function enhance( $select, county ) {
			$select.selectWoo( {
				width: '100%',
				placeholder: county ? config.i18n.placeholder : config.i18n.countyFirst,
				tags: ! config.listOnly,
				insertTag: function ( data, tag ) {
					data.push( tag );
				},
				ajax: {
					delay: 100,
					transport: function ( params, success ) {
						var term = ( params.data && params.data.term ) || '';
						load( county ).then( function ( list ) {
							success( {
								results: search( list, term ).map( function ( entry ) {
									return { id: entry[ 1 ], text: entry[ 1 ] };
								} ),
							} );
						} );

						return { abort: function () {} };
					},
				},
				language: {
					noResults: function () {
						return config.i18n.noResults;
					},
					searching: function () {
						return config.i18n.searching;
					},
				},
			} );
		}

		function sync( group ) {
			var field = document.getElementById( group + '_city' );
			if ( ! field ) {
				return;
			}

			if ( 'RO' !== $( '#' + group + '_country' ).val() ) {
				if ( 'SELECT' === field.tagName && $( field ).data( 'rcInput' ) ) {
					toInput( $( field ) );
				}
				return;
			}

			var county = $( '#' + group + '_state' ).val() || '';
			var $select = 'SELECT' === field.tagName ? $( field ) : toSelect( field );
			var previous = $select.data( 'rcCounty' );

			if ( previous === county && $select.data( 'select2' ) ) {
				return;
			}

			if ( $select.data( 'select2' ) ) {
				$select.selectWoo( 'destroy' );
			}
			$select.data( 'rcCounty', county ).prop( 'disabled', '' === county );
			enhance( $select, county );

			// A city picked under another county is almost never right under this one.
			if ( undefined !== previous && $select.val() ) {
				load( county ).then( function ( list ) {
					var current = normalize( $select.val() || '' );
					var found = list.some( function ( entry ) {
						return entry[ 0 ] === current;
					} );
					if ( ! found ) {
						$select.empty().append( new Option( '', '' ) ).val( '' ).trigger( 'change' );
					}
				} );
			}
		}

		function syncAll() {
			sync( 'billing' );
			sync( 'shipping' );
		}

		syncAll();
		$( document.body ).on( 'country_to_state_changed', syncAll );
		$( document.body ).on( 'change', '#billing_state, #shipping_state', syncAll );
	}

	function initBlocks() {
		var wanted = {};

		document.addEventListener( 'focusin', function ( event ) {
			var match = event.target && event.target.id ? /^(billing|shipping)-city$/.exec( event.target.id ) : null;
			var data = window.wp && window.wp.data;
			var store = match && data ? data.select( 'wc/store/cart' ) : null;

			if ( ! store ) {
				return;
			}

			var input = event.target;
			var group = match[ 1 ];
			var customer = store.getCustomerData();
			var address = customer && customer[ group + 'Address' ];
			var county = address && 'RO' === address.country ? String( address.state || '' ) : '';
			var listId = 'romcommerce-cities-' + group;

			if ( '' === county ) {
				wanted[ group ] = '';
				input.removeAttribute( 'list' );
				return;
			}

			if ( wanted[ group ] === county && input.getAttribute( 'list' ) === listId ) {
				return;
			}
			wanted[ group ] = county;

			load( county ).then( function ( list ) {
				if ( wanted[ group ] !== county ) {
					return;
				}

				var datalist = document.getElementById( listId );
				if ( ! datalist ) {
					datalist = document.createElement( 'datalist' );
					datalist.id = listId;
					document.body.appendChild( datalist );
				}

				var fragment = document.createDocumentFragment();
				list.forEach( function ( entry ) {
					var option = document.createElement( 'option' );
					option.value = entry[ 1 ];
					fragment.appendChild( option );
				} );
				datalist.textContent = '';
				datalist.appendChild( fragment );
				input.setAttribute( 'list', listId );
			} );
		} );
	}

	if ( window.jQuery && window.jQuery.fn.selectWoo ) {
		window.jQuery( function () {
			initClassic( window.jQuery );
		} );
	}
	initBlocks();
}() );
