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

	var NAV_KEYS = [ 'tab', 'category', 'module', 'surface' ];

	function rail() {
		return app.querySelector( '.rc-rail' );
	}

	function canvas() {
		return app.querySelector( '.rc-canvas' );
	}

	function setBusy( on ) {
		app.classList.toggle( 'is-loading', !! on );
	}

	/** Nav state (tab/category/module/surface) from an arbitrary URL, as form fields. */
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

		// wc-enhanced-select.js only auto-inits once, on document ready — any
		// .wc-enhanced-select markup that just arrived via innerHTML needs this
		// re-triggered by hand. Safe to call even when nothing new needs it:
		// the script itself skips elements already marked .enhanced.
		if ( window.jQuery ) {
			window.jQuery( document.body ).trigger( 'wc-enhanced-select-init' );
		}

		linkShortcodes( canvas() );

		if ( pushUrl && data.url ) {
			window.history.pushState( { rc: true }, '', data.url );
		}

		var target = canvas();
		if ( target ) {
			target.focus();
		}
	}

	var SHORTCODE = /\[romcommerce_[a-z_]+\]/g;

	// Shortcodes sit inside translated sentences as plain text, so they're
	// turned into copy buttons here rather than by changing every string.
	function linkShortcodes( root ) {
		if ( ! root || ! document.createTreeWalker ) {
			return;
		}

		var walker = document.createTreeWalker( root, NodeFilter.SHOW_TEXT, {
			acceptNode: function ( node ) {
				var parent = node.parentNode;
				if ( ! parent || parent.closest( 'button, textarea, script, style, .rc-shortcode' ) ) {
					return NodeFilter.FILTER_REJECT;
				}
				SHORTCODE.lastIndex = 0;
				return SHORTCODE.test( node.nodeValue ) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP;
			},
		} );

		var nodes = [];
		while ( walker.nextNode() ) {
			nodes.push( walker.currentNode );
		}

		nodes.forEach( function ( node ) {
			var text = node.nodeValue;
			var fragment = document.createDocumentFragment();
			var last = 0;
			var match;

			SHORTCODE.lastIndex = 0;
			while ( ( match = SHORTCODE.exec( text ) ) ) {
				fragment.appendChild( document.createTextNode( text.slice( last, match.index ) ) );
				fragment.appendChild( shortcodeButton( match[ 0 ] ) );
				last = match.index + match[ 0 ].length;
			}
			fragment.appendChild( document.createTextNode( text.slice( last ) ) );
			node.parentNode.replaceChild( fragment, node );
		} );
	}

	function shortcodeButton( shortcode ) {
		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'rc-shortcode';
		button.setAttribute( 'data-rc-shortcode', shortcode );
		button.setAttribute( 'title', cfg.copyHint || '' );

		var code = document.createElement( 'code' );
		code.textContent = shortcode;
		button.appendChild( code );

		var state = document.createElement( 'span' );
		state.className = 'rc-shortcode-state';
		state.setAttribute( 'aria-live', 'polite' );
		state.textContent = cfg.copyLabel || '';
		button.appendChild( state );

		return button;
	}

	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}

		// wp-admin over plain http has no Clipboard API.
		return new Promise( function ( resolve, reject ) {
			var area = document.createElement( 'textarea' );
			area.value = text;
			area.setAttribute( 'readonly', '' );
			area.style.position = 'fixed';
			area.style.opacity = '0';
			document.body.appendChild( area );
			area.select();
			var ok = false;
			try {
				ok = document.execCommand( 'copy' );
			} catch ( e ) {
				ok = false;
			}
			document.body.removeChild( area );
			if ( ok ) {
				resolve();
			} else {
				reject();
			}
		} );
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
		var button = event.target.closest( '.rc-shortcode' );
		if ( ! button || ! app.contains( button ) ) {
			return;
		}

		var state = button.querySelector( '.rc-shortcode-state' );
		copyText( button.getAttribute( 'data-rc-shortcode' ) ).then( function () {
			button.classList.add( 'is-copied' );
			state.textContent = cfg.copiedLabel || '';
		}, function () {
			state.textContent = cfg.copyFailedLabel || '';
		} ).then( function () {
			window.setTimeout( function () {
				button.classList.remove( 'is-copied' );
				state.textContent = cfg.copyLabel || '';
			}, 2000 );
		} );
	} );

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

	// Exposed globally (not via delegation) because the Floating contact FAB
	// pane's icon controls are rendered with inline onchange/onclick attributes
	// — the canvas they live in gets replaced with innerHTML on every AJAX
	// navigation, which never executes <script> tags placed inside it, so any
	// handler they call has to already exist on window before that markup
	// arrives. This script tag itself is outside the swapped containers and
	// only runs once, which is what makes that safe.
	window.romcommerceToggleIcon = function ( select, id ) {
		[ 'default', 'media', 'svg', 'dashicon' ].forEach( function ( type ) {
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

	// The Floating contact FAB pane's dashicon picker. Delegated from document so it
	// keeps working after AJAX navigation replaces the pane's markup.
	function closeDashiconPicker( picker ) {
		var opener = document.querySelector( '.rc-dashicon-browse[aria-expanded="true"]' );
		picker.hidden = true;
		if ( opener ) {
			opener.setAttribute( 'aria-expanded', 'false' );
		}
		return opener;
	}

	document.addEventListener( 'click', function ( event ) {
		var picker = document.getElementById( 'rc-dashicon-picker' );
		if ( ! picker || ! event.target.closest ) {
			return;
		}

		var browse = event.target.closest( '.rc-dashicon-browse' );
		if ( browse ) {
			var wasOpen = 'true' === browse.getAttribute( 'aria-expanded' );
			closeDashiconPicker( picker );
			if ( wasOpen ) {
				return;
			}
			browse.parentNode.appendChild( picker );
			picker.setAttribute( 'data-rc-target', browse.getAttribute( 'data-rc-dashicon-target' ) );
			picker.hidden = false;
			browse.setAttribute( 'aria-expanded', 'true' );
			picker.querySelector( '.rc-dashicon-search' ).focus();
			return;
		}

		var pick = event.target.closest( '.rc-dashicon-pick' );
		if ( pick && picker.contains( pick ) ) {
			var input = document.getElementById( picker.getAttribute( 'data-rc-target' ) );
			if ( input ) {
				input.value = pick.getAttribute( 'data-rc-dashicon' );
				input.focus();
			}
			closeDashiconPicker( picker );
		}
	} );

	document.addEventListener( 'input', function ( event ) {
		if ( ! event.target.classList || ! event.target.classList.contains( 'rc-dashicon-search' ) ) {
			return;
		}
		var term = event.target.value.toLowerCase().replace( /^dashicons-/, '' ).trim();
		Array.prototype.forEach.call( document.querySelectorAll( '.rc-dashicon-pick' ), function ( button ) {
			button.hidden = '' !== term && -1 === button.getAttribute( 'data-rc-dashicon' ).indexOf( term );
		} );
	} );

	document.addEventListener( 'keydown', function ( event ) {
		var picker = document.getElementById( 'rc-dashicon-picker' );
		if ( 'Escape' === event.key && picker && ! picker.hidden && picker.contains( event.target ) ) {
			event.preventDefault();
			var opener = closeDashiconPicker( picker );
			if ( opener ) {
				opener.focus();
			}
		}
	} );

	linkShortcodes( canvas() );
}());
