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
		notLoaded: 'We could not read your settings, so nothing was saved. Reload the page and try again.',
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

	var TIMEOUT = 30000;

	/** fetch() that gives up after 30 s (independent of any connection setting on the server). */
	async function timedFetch( url, opts ) {
		var controller = typeof G.AbortController === 'function' ? new G.AbortController() : null;
		var timer = controller ? G.setTimeout( function () { controller.abort(); }, TIMEOUT ) : null;

		if ( controller ) {
			opts = Object.assign( {}, opts, { signal: controller.signal } );
		}

		try {
			var res = await G.fetch( url, opts );
			var raw = await res.text();

			return { res: res, raw: raw };
		} finally {
			if ( timer !== null ) {
				G.clearTimeout( timer );
			}
		}
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
			var got = await timedFetch( String( cfg().rest || '' ) + String( path ).replace( /^\/+/, '' ), opts );

			res = got.res;
			raw = got.raw;
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

		// Every success from the portal's REST routes is {ok: true, data: <reply>} (P Routes::respond).
		if ( res.ok && isObject( data ) && data.ok === true && 'data' in data ) {
			data = data.data;
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
			var got = await timedFetch( String( cfg().nonceUrl || '' ), { credentials: 'same-origin' } );
			var res = got.res;
			var raw = got.raw.trim();

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
	async function submit( button, statusEl, requestFn, busyLabel ) {
		var label = typeof busyLabel === 'string' && busyLabel !== '' ? busyLabel : text( 'saving' );
		var release = busy( button, label );
		var reply;

		status( statusEl, 'saving', label );

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

	/** Is this a link the app may follow: an http(s) URL or a path of this site (never "//host", never a script URL)? */
	function safeUrl( url ) {
		return typeof url === 'string' && /^(https?:\/\/|\/(?!\/))/.test( url );
	}

	/** Fills %s (the first value), %1$s and %2$s. A function replacer, so "$&" in a value stays text. */
	function fill( template ) {
		var values = Array.prototype.slice.call( arguments, 1 );

		return String( template )
			.replace( /%(\d)\$s/g, function ( m, n ) { return String( values[ Number( n ) - 1 ] ); } )
			.replace( /%s/g, function () { return String( values[ 0 ] ); } );
	}

	/** Shows Django's message and a link to the Connections tab. DOM glue. */
	function showNotConfigured( container, reply, root ) {
		var url = root && root.getAttribute ? root.getAttribute( 'data-connections-url' ) : '';

		container.textContent = '';

		var p = G.document.createElement( 'p' );
		p.textContent = reply.message + ' ';
		container.appendChild( p );

		if ( safeUrl( url ) ) {
			var a = G.document.createElement( 'a' );
			a.setAttribute( 'href', url );
			a.textContent = text( 'goToConnections' );
			p.appendChild( a );
		}
	}

	/**
	 * Where a Tab press must send focus to keep it inside a dialog, or null when the browser's own
	 * move stays inside. items: the focusable nodes in order.
	 */
	function trapTarget( items, active, shift, inside ) {
		if ( ! items.length ) {
			return null;
		}

		if ( ! inside ) {
			return items[ 0 ];
		}

		if ( shift && active === items[ 0 ] ) {
			return items[ items.length - 1 ];
		}

		if ( ! shift && active === items[ items.length - 1 ] ) {
			return items[ 0 ];
		}

		return null;
	}

	/**
	 * A modal dialog: role=dialog, focus trap, Esc, focus returns to the opener.
	 * Resolves true on confirm, false on cancel or Esc. DOM glue. Options: title, body, confirm
	 * (label), primary (a normal rather than a danger button), content (a node under the text,
	 * e.g. a form), accept (called on confirm; returns false, or a promise of false, to stay
	 * open), focus (the node to focus first; Cancel by default).
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
			confirm.className = options.primary === true ? 'odoowoo-button' : 'odoowoo-button odoowoo-button--danger';
			confirm.textContent = options.confirm || 'OK';

			actions.appendChild( cancel );
			actions.appendChild( confirm );
			box.appendChild( title );
			box.appendChild( body );

			if ( options.content ) {
				box.appendChild( options.content );
			}

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
					var items = Array.prototype.filter.call(
						box.querySelectorAll( 'button, input, select, textarea, a[href], [tabindex]' ),
						function ( n ) { return ! n.disabled && n.getAttribute( 'tabindex' ) !== '-1' && n.offsetParent !== null; }
					);
					var target = trapTarget( items, doc.activeElement, e.shiftKey, box.contains( doc.activeElement ) );

					if ( target ) {
						e.preventDefault();
						target.focus();
					}
				}
			}

			cancel.addEventListener( 'click', function () { close( false ); } );
			confirm.addEventListener( 'click', function () {
				if ( typeof options.accept !== 'function' ) {
					close( true );
					return;
				}

				// accept() may be async; false keeps the dialog open (it shows its own message).
				confirm.disabled = true;
				Promise.resolve( options.accept() ).then( function ( ok ) {
					confirm.disabled = false;

					if ( ok !== false ) {
						close( true );
					}
				}, function () {
					confirm.disabled = false;
				} );
			} );
			overlay.addEventListener( 'mousedown', function ( e ) {
				if ( e.target === overlay ) {
					close( false );
				}
			} );
			doc.addEventListener( 'keydown', onKey, true );
			doc.body.appendChild( overlay );
			( options.focus && typeof options.focus.focus === 'function' ? options.focus : cancel ).focus();
		} );
	}


	/** A text lookup for a card: boot-data strings first, then the card's English fallbacks. */
	function translator( fallback ) {
		return function ( key ) {
			var i18n = ( G.odoowooPortal && G.odoowooPortal.i18n ) || {};

			if ( typeof i18n[ key ] === 'string' && i18n[ key ] !== '' ) {
				return i18n[ key ];
			}

			return typeof fallback[ key ] === 'string' ? fallback[ key ] : '';
		};
	}

	/** The field a Django refusal names, or ''. */
	function fieldOf( reply ) {
		return reply && typeof reply.field === 'string' ? reply.field : '';
	}

	function clearFieldErrors( form ) {
		Array.prototype.forEach.call( form.querySelectorAll( '[data-error-for]' ), function ( place ) {
			place.textContent = '';
			place.hidden = true;
		} );
		Array.prototype.forEach.call( form.querySelectorAll( '[aria-invalid]' ), function ( input ) {
			input.removeAttribute( 'aria-invalid' );
		} );
	}

	/**
	 * Marks the input a refusal names and shows its message in the place next to it. Returns true
	 * when there was such a place (otherwise the caller shows the message itself). DOM glue.
	 */
	function showFieldError( form, reply ) {
		clearFieldErrors( form );

		var field = fieldOf( reply );
		var place = field !== '' ? form.querySelector( '[data-error-for="' + field + '"]' ) : null;

		if ( ! place ) {
			return false;
		}

		// The place may name the input it belongs to (data-input), when that is not the field's own name.
		var name = place.getAttribute( 'data-input' ) || field;
		var input = form.elements ? form.elements[ name ] : form.querySelector( '[name="' + name + '"]' );

		if ( input ) {
			input.setAttribute( 'aria-invalid', 'true' );

			if ( place.id ) {
				input.setAttribute( 'aria-describedby', place.id );
			}
		}

		place.textContent = typeof reply.message === 'string' ? reply.message : '';
		place.hidden = false;

		return true;
	}

	/** The REST path of a route of one dashboard: licenses/<l>/dashboards/<d>/<route>. */
	function dashboardPath( ctx, route ) {
		return 'licenses/' + encodeURIComponent( String( ctx.licenseId ) ) + '/dashboards/' + encodeURIComponent( String( ctx.dashboardId ) ) + '/' + route;
	}

	var VERIFY = {
		verifiedOk: 'Connection verified.',
		helperInstalled: 'Odoo connection verified. The OdooWoo Helper module is installed.',
		helperMissing: 'Odoo connection verified, but the OdooWoo Helper module is not installed.',
	};
	var vt = translator( VERIFY );

	/**
	 * What a verify reply means. The text of a success is the portal's own (from the boolean
	 * helper_installed, never Django's wording); a refusal shows Django's message. `refresh` is
	 * true for a verify that also saved on the server (WooCommerce): the card re-reads the keys.
	 */
	function verifyView( reply, saves ) {
		if ( ! reply || reply.ok !== true ) {
			return { verified: false, refresh: false, helper: 'unknown', message: errorText( reply ) };
		}

		var flag = isObject( reply.data ) ? reply.data.helper_installed : undefined;
		var helper = flag === true ? 'installed' : ( flag === false ? 'missing' : 'unknown' );
		var message = helper === 'installed' ? vt( 'helperInstalled' ) : ( helper === 'missing' ? vt( 'helperMissing' ) : vt( 'verifiedOk' ) );

		return { verified: true, refresh: saves === true, helper: helper, message: message };
	}

	/**
	 * What a password-like field shows. Django never sends the secret, only a has_* flag, read as
	 * true only when it is the boolean true (a new dashboard's reply has no flag at all).
	 */
	function secretState( flag ) {
		var saved = flag === true;

		return { value: '', saved: saved, placeholder: saved ? text( 'secretSaved' ) : '' };
	}

	/**
	 * One in-page copy of an object that Django saves whole (it resets every omitted field on a
	 * save): the last read as `saved`, the typed changes as `edits`, listeners for the cards that
	 * show it. Secrets are only ever edits: they are left out of the body when blank, kept
	 * byte-exact otherwise, and forgotten once saved. opts: fields (the body's keys), secrets
	 * (field names), optional (fields Django treats as unchanged when absent: a save may go
	 * without them), flags (secret name => has_* name, set locally if a re-read fails).
	 */
	function createStore( opts ) {
		var fields = ( opts && opts.fields ) || [];
		var secrets = ( opts && opts.secrets ) || [];
		var optional = ( opts && opts.optional ) || [];
		var flags = ( opts && opts.flags ) || {};
		var saved = {};
		var edits = {};
		var listeners = [];
		var loaded = false;
		var pending = null;

		function notify() {
			listeners.slice().forEach( function ( fn ) {
				try {
					fn();
				} catch ( e ) {
					// A card's listener must not break the others.
				}
			} );
		}

		function cleanRead( data ) {
			var out = {};

			if ( isObject( data ) ) {
				Object.keys( data ).forEach( function ( k ) {
					if ( secrets.indexOf( k ) === -1 ) {
						out[ k ] = data[ k ];
					}
				} );
			}

			return out;
		}

		var store = {
			get: function () {
				return Object.assign( {}, saved, edits );
			},
			load: function ( data ) {
				saved = cleanRead( data );
				loaded = true;

				// An edit that now equals what is saved is no edit any more.
				Object.keys( edits ).forEach( function ( k ) {
					if ( secrets.indexOf( k ) === -1 && edits[ k ] === saved[ k ] ) {
						delete edits[ k ];
					}
				} );
				notify();
			},
			patch: function ( changes ) {
				Object.keys( changes || {} ).forEach( function ( k ) {
					var v = changes[ k ];

					if ( secrets.indexOf( k ) !== -1 ) {
						if ( typeof v === 'string' && v !== '' ) {
							edits[ k ] = v;
						} else {
							delete edits[ k ];
						}
					} else if ( v === saved[ k ] ) {
						delete edits[ k ];
					} else {
						edits[ k ] = v;
					}
				} );
				notify();
			},
			dirty: function () {
				return Object.keys( edits ).length > 0;
			},
			/** The complete body: every field with its value, a blank secret left out. */
			body: function () {
				var all = store.get();
				var out = {};

				fields.forEach( function ( f ) {
					if ( secrets.indexOf( f ) !== -1 ) {
						if ( typeof all[ f ] === 'string' && all[ f ] !== '' ) {
							out[ f ] = all[ f ];
						}
					} else if ( all[ f ] !== undefined ) {
						out[ f ] = all[ f ];
					}
				} );

				return out;
			},
			/** After a save: the fresh read when there is one, else what was typed becomes the saved copy. */
			commit: function ( fresh ) {
				var typed = Object.assign( {}, edits );

				if ( isObject( fresh ) ) {
					saved = cleanRead( fresh );
				} else {
					saved = cleanRead( Object.assign( {}, saved, typed ) );
					secrets.forEach( function ( k ) {
						if ( typed[ k ] !== undefined && flags[ k ] ) {
							saved[ flags[ k ] ] = true;
						}
					} );
				}

				edits = {};
				loaded = true;
				notify();
			},
			/** Forgets typed secrets (they were saved by another call). */
			dropSecrets: function () {
				secrets.forEach( function ( k ) {
					delete edits[ k ];
				} );
				notify();
			},
			subscribe: function ( fn ) {
				listeners.push( fn );
			},
			isLoaded: function () {
				return loaded;
			},
			/** Read, and every non-secret field present: only then is a whole-object save safe. */
			complete: function () {
				var all = store.get();

				return loaded && fields.every( function ( f ) {
					return secrets.indexOf( f ) !== -1 || optional.indexOf( f ) !== -1 || all[ f ] !== undefined;
				} );
			},
			/** Reads once for every card of the page: the loader is called only by the first. */
			ensure: function ( loader ) {
				if ( loaded ) {
					return Promise.resolve( { ok: true, data: store.get(), cached: true } );
				}

				if ( pending === null ) {
					pending = Promise.resolve( loader() ).then( function ( reply ) {
						pending = null;

						if ( reply && reply.ok === true ) {
							store.load( reply.data );
						}

						return reply;
					}, function ( e ) {
						pending = null;
						throw e;
					} );
				}

				return pending;
			},
		};

		return store;
	}

	/**
	 * The refusal for a save before the object was read (or after its read failed): Django resets
	 * what a save omits, so a partial body would wipe the other card's values.
	 */
	function notLoadedReply() {
		return failure( 'settings_not_loaded', text( 'notLoaded' ), 0 );
	}

	/**
	 * Saves a whole object: PUT its complete body, then re-read it so the flags are Django's.
	 * Resolves the PUT's reply. A failed save changes nothing in the store.
	 */
	async function saveObject( path, store ) {
		if ( ! store.complete() ) {
			return notLoadedReply();
		}

		var reply = await api( 'PUT', path, store.body() );

		if ( ! reply.ok ) {
			return reply;
		}

		var fresh = await api( 'GET', path );

		store.commit( fresh.ok ? fresh.data : null );

		return reply;
	}

	var wooKeysStore = createStore( {
		fields: [ 'consumer_key', 'woocommerce_url', 'consumer_secret', 'prefix', 'enable_stock_update', 'change_status_delivery' ],
		secrets: [ 'consumer_secret' ],
		flags: { consumer_secret: 'has_consumer_secret' },
	} );

	var odooSettingsStore = createStore( {
		fields: [ 'odoo_url', 'odoo_db', 'odoo_user', 'odoo_password', 'enable_auto_payment', 'enable_auto_payment_cn', 'enable_set_invoice_status', 'enable_reset_all_deliveries', 'enable_set_deliveries_to_waiting', 'enable_auto_complete_deliveries', 'odoo_order_pricelist_id', 'odoo_order_pricelist_name', 'capture_stage', 'confirm_pending_orders', 'price_includes_tax', 'order_incl_tax', 'quantity_settings', 'enable_brand_sync', 'enable_category_sync', 'enable_product_name_sync', 'enable_tag_sync', 'enable_image_sync', 'enable_price_sync', 'enable_barcode_sync', 'stock_sku_meta_keys', 'stock_units_meta_keys' ],
		secrets: [ 'odoo_password' ],
		// Django: absent means unchanged (views.py:753-759), so a read without them still allows a save.
		optional: [ 'stock_sku_meta_keys', 'stock_units_meta_keys' ],
		flags: { odoo_password: 'has_odoo_password' },
	} );

	var api_ = {
		api: api,
		notConfigured: notConfigured,
		listOf: listOf,
		whenVisible: whenVisible,
		busy: busy,
		status: status,
		errorText: errorText,
		showNotConfigured: showNotConfigured,
		dialog: dialog,
		submit: submit,
		text: text,
		translator: translator,
		safeUrl: safeUrl,
		fill: fill,
		dashboardPath: dashboardPath,
		fieldOf: fieldOf,
		showFieldError: showFieldError,
		clearFieldErrors: clearFieldErrors,
		verifyView: verifyView,
		secretState: secretState,
		createStore: createStore,
		saveObject: saveObject,
		notLoadedReply: notLoadedReply,
		wooKeysStore: wooKeysStore,
		odooSettingsStore: odooSettingsStore,
		trapTarget: trapTarget,
	};

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api_;
	}

	if ( typeof window !== 'undefined' ) {
		window.odoowoo = Object.assign( window.odoowoo || {}, api_ );
	}
}() );
