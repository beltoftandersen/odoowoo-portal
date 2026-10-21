/*
 * OdooWoo portal: front-end core. Plain script, no build step. In the browser it attaches to
 * window.odoowoo; under Node (tests) it sets module.exports. Every piece of text from Django
 * is written with textContent, never as HTML.
 */
( function () {
	'use strict';

	var G = typeof window !== 'undefined' ? window : globalThis;

	var SESSION_CODES = [ 'rest_cookie_invalid_nonce', 'odoowoo_portal_login' ];
	var NOT_CONFIGURED = [ 'odoo_not_configured', 'woocommerce_not_configured' ];

	var DEFAULTS = {
		generic: 'Something went wrong on our side. Please try again in a moment.',
		session: 'Please log in again. What you typed is still here.',
		saving: 'Saving…',
		saved: 'Saved',
		secretSaved: 'Saved. Leave blank to keep it.',
		goToConnections: 'Go to Connections',
		cancel: 'Cancel',
	};

	function text( key ) {
		var i18n = ( G.odoowooPortal && G.odoowooPortal.i18n ) || {};
		return typeof i18n[ key ] === 'string' && i18n[ key ] !== '' ? i18n[ key ] : DEFAULTS[ key ];
	}

	function cfg() {
		return G.odoowooPortal || {};
	}

	function failure( code, message, status, field ) {
		return { ok: false, data: undefined, code: code, message: message, field: field, status: status };
	}

	function generic( status ) {
		return failure( 'server_error', text( 'generic' ), status );
	}

	function isObject( v ) {
		return v !== null && typeof v === 'object' && ! Array.isArray( v );
	}

	/** One request, no retry. Never rejects. Returns the reply and whether it was a session refusal. */
	async function once( method, path, body ) {
		var headers = { 'X-WP-Nonce': cfg().nonce || '' };
		var opts = { method: method, headers: headers, credentials: 'same-origin' };

		if ( body !== undefined && body !== null ) {
			headers[ 'Content-Type' ] = 'application/json';
			opts.body = JSON.stringify( body );
		}

		var res, raw;

		try {
			res = await G.fetch( String( cfg().rest || '' ) + String( path ).replace( /^\/+/, '' ), opts );
			raw = await res.text();
		} catch ( e ) {
			return generic( 0 );
		}

		var fresh = res.headers && typeof res.headers.get === 'function' ? res.headers.get( 'X-WP-Nonce' ) : null;

		if ( typeof fresh === 'string' && /^[A-Za-z0-9]{6,}$/.test( fresh ) && G.odoowooPortal ) {
			G.odoowooPortal.nonce = fresh;
		}

		var data;

		try {
			data = JSON.parse( raw );
		} catch ( e ) {
			return generic( res.status );
		}

		if ( res.ok ) {
			if ( data === null || typeof data !== 'object' ) {
				return generic( res.status );
			}

			return { ok: true, data: data, code: undefined, message: undefined, field: undefined, status: res.status };
		}

		if ( isObject( data ) && typeof data.code === 'string' && typeof data.message === 'string' ) {
			return failure( data.code, data.message, res.status, typeof data.field === 'string' && data.field !== '' ? data.field : undefined );
		}

		return generic( res.status );
	}

	/** Fetches a fresh REST nonce (WordPress' rest-nonce action). Returns '' when it will not give one. */
	async function freshNonce() {
		try {
			var res = await G.fetch( String( cfg().nonceUrl || '' ), { credentials: 'same-origin' } );
			var raw = ( await res.text() ).trim();

			return res.ok && /^[A-Za-z0-9]{6,}$/.test( raw ) ? raw : '';
		} catch ( e ) {
			return '';
		}
	}

	/**
	 * Calls the portal's REST API. Resolves {ok, data, code, message, field, status}, never rejects.
	 * A lapsed login or nonce is recognised by its code (Django's own 401/403 arrive as 409): the
	 * nonce is refreshed once and the same request is sent once more.
	 */
	async function api( method, path, body ) {
		var reply = await once( method, path, body );

		if ( reply.ok || SESSION_CODES.indexOf( reply.code ) === -1 ) {
			return reply;
		}

		var nonce = await freshNonce();

		if ( nonce !== '' && G.odoowooPortal ) {
			G.odoowooPortal.nonce = nonce;
			reply = await once( method, path, body );

			if ( reply.ok || SESSION_CODES.indexOf( reply.code ) === -1 ) {
				return reply;
			}
		}

		return failure( 'session', text( 'session' ), reply.status );
	}

	function notConfigured( reply ) {
		return !! reply && reply.ok === false && NOT_CONFIGURED.indexOf( reply.code ) !== -1;
	}

	function listOf( data, bare ) {
		if ( bare === true && Array.isArray( data ) ) {
			return data;
		}

		return isObject( data ) && Array.isArray( data.items ) ? data.items : [];
	}

	function whenVisible( el, fn ) {
		var done = false;
		var run = function () {
			if ( ! done ) {
				done = true;
				fn();
			}
		};

		if ( typeof G.IntersectionObserver !== 'function' ) {
			run();
			return;
		}

		var observer = new G.IntersectionObserver( function ( entries ) {
			if ( entries.some( function ( e ) { return e.isIntersecting; } ) ) {
				observer.disconnect();
				run();
			}
		} );

		observer.observe( el );
	}

	/** Disables a button and marks it busy; returns the function that puts it back. */
	function busy( button, label ) {
		var original = button.textContent;

		button.disabled = true;
		button.setAttribute( 'aria-busy', 'true' );

		if ( typeof label === 'string' && label !== '' ) {
			button.textContent = label;
		}

		return function release() {
			button.disabled = false;
			button.removeAttribute( 'aria-busy' );
			button.textContent = original;
		};
	}

	/** Writes a status line: kind is saving, saved or error. Announced politely; never by colour alone. */
	function status( el, kind, message ) {
		el.setAttribute( 'aria-live', 'polite' );
		el.setAttribute( 'role', 'status' );
		el.setAttribute( 'data-state', kind );
		el.textContent = typeof message === 'string' ? message : '';
	}

	function errorText( reply ) {
		var message = reply && typeof reply.message === 'string' && reply.message !== '' ? reply.message : text( 'generic' );

		return reply && typeof reply.field === 'string' && reply.field !== '' ? reply.field + ': ' + message : message;
	}

	/**
	 * Wraps a save: busy button, "Saving…", then "Saved" only for ok:true, otherwise the message.
	 * The button always comes back. Never rejects. Resolves the reply.
	 */
	async function submit( button, statusEl, requestFn ) {
		var release = busy( button, text( 'saving' ) );
		var reply;

		status( statusEl, 'saving', text( 'saving' ) );

		try {
			reply = await requestFn();
		} catch ( e ) {
			reply = generic( 0 );
		}

		if ( ! reply || typeof reply !== 'object' ) {
			reply = generic( 0 );
		}

		release();

		if ( reply.ok === true ) {
			status( statusEl, 'saved', text( 'saved' ) );
		} else {
			status( statusEl, 'error', errorText( reply ) );
		}

		return reply;
	}

	/** A password field that is saved: blank means keep. */
	function secretField( input, saved ) {
		input.setAttribute( 'autocomplete', 'new-password' );

		if ( saved ) {
			input.value = '';
			input.setAttribute( 'placeholder', text( 'secretSaved' ) );
		}
	}

	/** The value to send: undefined (leave it out) for blank-and-saved, otherwise byte-exact. */
	function secretValue( input, saved ) {
		return saved && input.value === '' ? undefined : input.value;
	}

	/** Shows Django's message and a link to the Connections tab. DOM glue. */
	function showNotConfigured( container, reply, root ) {
		var url = root && root.getAttribute ? root.getAttribute( 'data-connections-url' ) : '';

		container.textContent = '';

		var p = G.document.createElement( 'p' );
		p.textContent = reply.message + ' ';
		container.appendChild( p );

		if ( typeof url === 'string' && /^(https?:\/\/|\/)/.test( url ) ) {
			var a = G.document.createElement( 'a' );
			a.setAttribute( 'href', url );
			a.textContent = text( 'goToConnections' );
			p.appendChild( a );
		}
	}

	/**
	 * A modal dialog: role=dialog, focus trap, Esc, focus returns to the opener.
	 * Resolves true on confirm, false on cancel or Esc. DOM glue.
	 */
	function dialog( options ) {
		var doc = G.document;
		var opener = doc.activeElement;
		var id = 'odoowoo-dialog-' + Math.random().toString( 36 ).slice( 2, 9 );

		return new Promise( function ( resolve ) {
			var overlay = doc.createElement( 'div' );
			overlay.className = 'odoowoo-dialog-overlay';

			var box = doc.createElement( 'div' );
			box.className = 'odoowoo-dialog';
			box.setAttribute( 'role', 'dialog' );
			box.setAttribute( 'aria-modal', 'true' );
			box.setAttribute( 'aria-labelledby', id + '-title' );
			box.setAttribute( 'aria-describedby', id + '-body' );

			var title = doc.createElement( 'h2' );
			title.id = id + '-title';
			title.textContent = options.title || '';

			var body = doc.createElement( 'p' );
			body.id = id + '-body';
			body.textContent = options.body || '';

			var actions = doc.createElement( 'div' );
			actions.className = 'odoowoo-dialog__actions';

			var cancel = doc.createElement( 'button' );
			cancel.type = 'button';
			cancel.className = 'odoowoo-button odoowoo-button--secondary';
			cancel.textContent = text( 'cancel' );

			var confirm = doc.createElement( 'button' );
			confirm.type = 'button';
			confirm.className = 'odoowoo-button odoowoo-button--danger';
			confirm.textContent = options.confirm || 'OK';

			actions.appendChild( cancel );
			actions.appendChild( confirm );
			box.appendChild( title );
			box.appendChild( body );
			box.appendChild( actions );
			overlay.appendChild( box );

			function close( result ) {
				doc.removeEventListener( 'keydown', onKey, true );
				overlay.remove();

				if ( opener && typeof opener.focus === 'function' ) {
					opener.focus();
				}

				resolve( result );
			}

			function onKey( e ) {
				if ( e.key === 'Escape' ) {
					e.preventDefault();
					close( false );
					return;
				}

				if ( e.key === 'Tab' ) {
					var first = cancel;
					var last = confirm;

					if ( e.shiftKey && doc.activeElement === first ) {
						e.preventDefault();
						last.focus();
					} else if ( ! e.shiftKey && doc.activeElement === last ) {
						e.preventDefault();
						first.focus();
					} else if ( ! box.contains( doc.activeElement ) ) {
						e.preventDefault();
						first.focus();
					}
				}
			}

			cancel.addEventListener( 'click', function () { close( false ); } );
			confirm.addEventListener( 'click', function () { close( true ); } );
			overlay.addEventListener( 'mousedown', function ( e ) {
				if ( e.target === overlay ) {
					close( false );
				}
			} );
			doc.addEventListener( 'keydown', onKey, true );
			doc.body.appendChild( overlay );
			cancel.focus();
		} );
	}

	var api_ = {
		api: api,
		notConfigured: notConfigured,
		listOf: listOf,
		whenVisible: whenVisible,
		busy: busy,
		status: status,
		errorText: errorText,
		secretField: secretField,
		secretValue: secretValue,
		showNotConfigured: showNotConfigured,
		dialog: dialog,
		submit: submit,
		text: text,
	};

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api_;
	}

	if ( typeof window !== 'undefined' ) {
		window.odoowoo = Object.assign( window.odoowoo || {}, api_ );
	}
}() );
