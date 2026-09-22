/**
 * RomCommerce admin app — AJAX layer.
 *
 * Progressive enhancement over the server-rendered single-page admin app: the
 * markup already works with plain links and form posts, and this script only
 * intercepts them to swap the rail + canvas in place instead of reloading the
 * whole wp-admin chrome. Every request carries the app nonce; the server
 * re-checks capability and nonce on each call. Any failure (expired nonce,
 * network error) falls back to the native navigation or form submit, so the
 * screen degrades to the non-AJAX flow rather than breaking.
 *
 * No build step, no dependencies — plain ES5-compatible DOM APIs.
 */
(function () {
	'use strict';

	var cfg = window.romcommerceAdmin;
	if ( ! cfg || ! cfg.ajaxUrl ) {
		return;
	}

	var app = document.querySelector( '[data-rc-app]' );
	if ( ! app ) {
		return;
	}

	var NAV_KEYS = [ 'tab', 'category', 'module' ];

	function rail() {
		return app.querySelector( '.rc-rail' );
	}

	function canvas() {
		return app.querySelector( '.rc-canvas' );
	}

	function setBusy( on ) {
		app.classList.toggle( 'is-loading', !! on );
	}

	/** Nav state (tab/category/module) from an arbitrary URL, as form fields. */
	function navParams( href ) {
		var url    = new URL( href, window.location.origin );
		var params = new URLSearchParams();
		NAV_KEYS.forEach( function ( key ) {
			var value = url.searchParams.get( key );
			if ( value ) {
				params.set( key, value );
			}
		} );
		return params;
	}

	function isInAppLink( a ) {
		if ( ! a || a.target === '_blank' ) {
			return false;
		}
		var href = a.getAttribute( 'href' ) || '';
		return href.indexOf( 'page=romcommerce' ) !== -1 && a.href.indexOf( window.location.origin ) === 0;
	}

	function post( body ) {
		body.set( 'action', cfg.action );
		body.set( 'nonce', cfg.nonce );

		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( payload ) {
			if ( ! payload || ! payload.success || ! payload.data ) {
				throw new Error( 'romcommerce: unsuccessful response' );
			}
			return payload.data;
		} );
	}

	function swap( data, pushUrl ) {
		var currentRail = rail();
		if ( currentRail ) {
			currentRail.outerHTML = data.rail;
		}
		canvas().innerHTML = data.canvas;

		if ( pushUrl && data.url ) {
			window.history.pushState( { rc: true }, '', data.url );
		}

		var target = canvas();
		if ( target ) {
			target.focus();
		}
	}

	function navigate( href, push ) {
		setBusy( true );
		post( navParams( href ) ).then( function ( data ) {
			swap( data, push );
		} ).catch( function () {
			window.location.href = href;
		} ).then( function () {
			setBusy( false );
		} );
	}

	function submitForm( form, submitter ) {
		setBusy( true );

		var body = new FormData( form );

		// FormData(form) alone never includes a submit button's name/value —
		// browsers only add that during a native submit. Since several panes
		// (Sale Category Sync's create/resync, Review Page's create-page)
		// branch server-side on which named submit button was pressed, losing
		// it here makes those buttons silently no-op through the AJAX path.
		if ( submitter && submitter.name ) {
			body.set( submitter.name, submitter.value );
		}

		navParams( window.location.href ).forEach( function ( value, key ) {
			if ( ! body.has( key ) ) {
				body.set( key, value );
			}
		} );

		post( body ).then( function ( data ) {
			swap( data, false );
		} ).catch( function () {
			form.submit();
		} ).then( function () {
			setBusy( false );
		} );
	}

	app.addEventListener( 'click', function ( event ) {
		if ( event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
			return;
		}

		var link = event.target.closest( 'a' );
		if ( ! link || ! app.contains( link ) || ! isInAppLink( link ) ) {
			return;
		}

		event.preventDefault();
		navigate( link.href, true );
	} );

	app.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! ( form instanceof HTMLFormElement ) || ! app.contains( form ) ) {
			return;
		}

		event.preventDefault();
		submitForm( form, event.submitter );
	} );

	window.addEventListener( 'popstate', function () {
		navigate( window.location.href, false );
	} );

	// Exposed globally (not via delegation) because the WhatsApp/Contact FAB
	// pane's icon controls are rendered with inline onchange/onclick attributes
	// — the canvas they live in gets replaced with innerHTML on every AJAX
	// navigation, which never executes <script> tags placed inside it, so any
	// handler they call has to already exist on window before that markup
	// arrives. This script tag itself is outside the swapped containers and
	// only runs once, which is what makes that safe.
	window.romcommerceToggleIcon = function ( select, id ) {
		[ 'media', 'svg', 'dashicon' ].forEach( function ( type ) {
			var panel = document.getElementById( 'rc-icon-' + id + '-' + type );
			if ( panel ) {
				panel.style.display = ( select.value === type ) ? '' : 'none';
			}
		} );
	};

	window.romcommerceOpenMediaPicker = function ( inputId, previewId ) {
		if ( ! window.wp || ! wp.media ) {
			return;
		}

		var frame = wp.media( {
			title: ( cfg.mediaTitle || 'Choose image' ),
			multiple: false
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			var input      = document.getElementById( inputId );
			var preview    = document.getElementById( previewId );

			if ( input ) {
				input.value = attachment.id;
			}
			if ( preview ) {
				var src = ( attachment.sizes && attachment.sizes.thumbnail ) ? attachment.sizes.thumbnail.url : attachment.url;
				preview.innerHTML = '';
				var img = document.createElement( 'img' );
				img.src = src;
				img.width = 32;
				img.height = 32;
				img.style.objectFit = 'contain';
				preview.appendChild( img );
			}
		} );

		frame.open();
	};
}());
