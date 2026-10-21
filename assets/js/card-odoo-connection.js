/*
 * OdooWoo portal: card "Odoo connection" (URL, database, user, password / API token, verify,
 * save, set up Odoo callbacks). Plain script. In the browser it starts on every
 * [data-odoowoo-card="odoo-connection"]; under Node (tests) it sets module.exports. It shares one
 * odoo-settings object with the Odoo settings card (core.odooSettingsStore): Django resets every
 * setting a save leaves out, so every save sends the complete object.
 */
( function () {
	'use strict';

	var G = typeof window !== 'undefined' ? window : globalThis;
	var core = typeof module !== 'undefined' && module.exports ? require( './core.js' ) : G.odoowoo;

	var t = core.translator( {
		urlRequired: 'Enter the Odoo URL.',
		dbRequired: 'Enter the database.',
		userRequired: 'Enter the user.',
		passwordRequired: 'Enter the password or API token.',
		callbackSet: 'Callback key is set',
		callbackNone: 'No callback key yet',
		verifying: 'Verifying…',
		setup: 'Set up Odoo callbacks',
		settingUp: 'Setting up…',
		callbackConfirmTitle: 'Set up Odoo callbacks',
		callbackConfirmBody: 'This replaces the current callback key.',
		callbackConfirm: 'Replace key',
		callbackDone: 'Odoo callbacks are set up.',
		show: 'Show',
		hide: 'Hide',
	} );

	var store = core.odooSettingsStore;

	function filled( v ) {
		return typeof v === 'string' && v !== '';
	}

	function refusal( field, message ) {
		return { ok: false, data: undefined, code: 'invalid_value', message: message, field: field, status: 0 };
	}

	/** The first missing field as a refusal (before any request), or null. */
	function missing( state ) {
		if ( ! filled( state.odoo_url ) ) {
			return refusal( 'odoo_url', t( 'urlRequired' ) );
		}

		if ( ! filled( state.odoo_db ) ) {
			return refusal( 'odoo_db', t( 'dbRequired' ) );
		}

		if ( ! filled( state.odoo_user ) ) {
			return refusal( 'odoo_user', t( 'userRequired' ) );
		}

		if ( ! filled( state.odoo_password ) && state.has_odoo_password !== true ) {
			return refusal( 'odoo_password', t( 'passwordRequired' ) );
		}

		return null;
	}

	/** The callback key box: whether a key exists, never the key. */
	function callbackBox( flag ) {
		var isSet = flag === true;

		return { isSet: isSet, text: isSet ? t( 'callbackSet' ) : t( 'callbackNone' ), buttonLabel: t( 'setup' ) };
	}

	/** Verify stores nothing. A blank password falls back to the stored one on the server. */
	async function verifyOdoo( ctx, store_ ) {
		var state = store_.get();
		var refused = missing( state );

		if ( refused ) {
			return { reply: refused, view: core.verifyView( refused ) };
		}

		var body = { odoo_url: state.odoo_url, odoo_db: state.odoo_db, odoo_user: state.odoo_user };

		if ( filled( state.odoo_password ) ) {
			body.odoo_password = state.odoo_password;
		}

		var reply = await core.api( 'POST', core.dashboardPath( ctx, 'verify-odoo' ), body );

		return { reply: reply, view: core.verifyView( reply ) };
	}

	/** Saves the complete settings object (the Odoo settings card's edits go with it). */
	async function saveOdoo( ctx, store_ ) {
		var refused = missing( store_.get() );

		if ( refused ) {
			return { reply: refused };
		}

		return { reply: await core.saveObject( core.dashboardPath( ctx, 'odoo-settings' ), store_ ) };
	}

	/**
	 * Each call mints a new key and overwrites the old one in Odoo, so it asks first.
	 * confirmFn resolves true to go on. The key is never returned; the flag is re-read.
	 */
	async function setUpCallbacks( ctx, store_, confirmFn ) {
		var ok = await confirmFn();

		if ( ok !== true ) {
			return { cancelled: true, reply: null };
		}

		var reply = await core.api( 'POST', core.dashboardPath( ctx, 'generate-api-key' ) );

		if ( reply.ok ) {
			var fresh = await core.api( 'GET', core.dashboardPath( ctx, 'odoo-settings' ) );

			if ( fresh.ok ) {
				store_.load( fresh.data );
			}
		}

		return { cancelled: false, reply: reply };
	}

	function init( root ) {
		var ctx = { licenseId: Number( root.getAttribute( 'data-license' ) ) || 0, dashboardId: Number( root.getAttribute( 'data-dashboard' ) ) || 0 };
		var canWrite = root.getAttribute( 'data-can-write' ) === '1';
		var canVerify = root.getAttribute( 'data-can-verify' ) === '1';
		var form = root.querySelector( 'form' );
		var urlIn = form.elements.odoo_url;
		var dbIn = form.elements.odoo_db;
		var userIn = form.elements.odoo_user;
		var passIn = form.elements.odoo_password;
		var verifyBtn = form.querySelector( '[data-action="verify"]' );
		var saveBtn = form.querySelector( '[type="submit"]' );
		var callbackBtn = form.querySelector( '[data-action="callbacks"]' );
		var reveal = form.querySelector( '.odoowoo-reveal' );
		var statusEl = form.querySelector( '.odoowoo-form-status' );
		var helperEl = form.querySelector( '.odoowoo-helper-status' );
		var box = root.querySelector( '.odoowoo-callback-box' );

		function render() {
			var s = store.get();
			var secret = core.secretState( s.has_odoo_password );
			var cb = callbackBox( s.has_middleware_api_key );

			[ [ urlIn, 'odoo_url' ], [ dbIn, 'odoo_db' ], [ userIn, 'odoo_user' ] ].forEach( function ( pair ) {
				if ( G.document.activeElement !== pair[ 0 ] ) {
					pair[ 0 ].value = typeof s[ pair[ 1 ] ] === 'string' ? s[ pair[ 1 ] ] : '';
				}
			} );

			if ( typeof s.odoo_password !== 'string' ) {
				passIn.value = secret.value;
			}

			passIn.setAttribute( 'autocomplete', 'new-password' );
			passIn.setAttribute( 'placeholder', secret.placeholder );
			box.textContent = store.isLoaded() ? cb.text : '';
			box.setAttribute( 'data-key', cb.isSet ? 'set' : 'none' );
			callbackBtn.textContent = cb.buttonLabel;
			callbackBtn.disabled = ! canWrite || ! ( filled( s.odoo_url ) && s.has_odoo_password === true );
		}

		[ urlIn, dbIn, userIn, passIn ].forEach( function ( input ) {
			input.disabled = ! canWrite;
			input.addEventListener( 'input', function () {
				store.patch( { odoo_url: urlIn.value, odoo_db: dbIn.value, odoo_user: userIn.value, odoo_password: passIn.value } );
			} );
		} );
		saveBtn.disabled = ! canWrite;
		verifyBtn.disabled = ! canVerify;
		store.subscribe( render );

		reveal.addEventListener( 'click', function () {
			var shown = passIn.type === 'text';

			passIn.type = shown ? 'password' : 'text';
			reveal.textContent = shown ? t( 'show' ) : t( 'hide' );
			reveal.setAttribute( 'aria-pressed', shown ? 'false' : 'true' );
		} );

		function notConfigured( reply ) {
			if ( core.notConfigured( reply ) ) {
				core.status( statusEl, 'error', '' );
				core.showNotConfigured( statusEl, reply, root );
			}
		}

		verifyBtn.addEventListener( 'click', function () {
			helperEl.textContent = '';
			core.submit( verifyBtn, statusEl, async function () {
				var out = await verifyOdoo( ctx, store );

				if ( out.view.verified ) {
					helperEl.textContent = out.view.message;
					helperEl.setAttribute( 'data-helper', out.view.helper );
				}

				return out.reply;
			}, t( 'verifying' ) );
		} );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			core.submit( saveBtn, statusEl, async function () {
				var out = await saveOdoo( ctx, store );

				return out.reply;
			} );
		} );

		callbackBtn.addEventListener( 'click', function () {
			core.submit( callbackBtn, statusEl, async function () {
				var out = await setUpCallbacks( ctx, store, function () {
					return core.dialog( {
						title: t( 'callbackConfirmTitle' ),
						body: t( 'callbackConfirmBody' ),
						confirm: t( 'callbackConfirm' ),
						primary: true,
					} );
				} );

				if ( out.cancelled ) {
					return { ok: true, cancelled: true, data: {}, status: 0 };
				}

				return out.reply;
			}, t( 'settingUp' ) ).then( function ( reply ) {
				if ( reply.cancelled === true ) {
					// Cancel is not a save: no "Saved".
					statusEl.textContent = '';
					statusEl.removeAttribute( 'data-state' );

					return;
				}

				if ( reply.ok === true ) {
					core.status( statusEl, 'saved', t( 'callbackDone' ) );
				}

				notConfigured( reply );
			} );
		} );

		render();

		if ( ctx.dashboardId <= 0 ) {
			return;
		}

		core.whenVisible( root, function () {
			store.ensure( function () {
				return core.api( 'GET', core.dashboardPath( ctx, 'odoo-settings' ) );
			} ).then( function ( reply ) {
				if ( ! reply.ok ) {
					core.status( statusEl, 'error', core.errorText( reply ) );
				}
			} );
		} );
	}

	var api_ = { callbackBox: callbackBox, verifyOdoo: verifyOdoo, saveOdoo: saveOdoo, setUpCallbacks: setUpCallbacks, store: store, missing: missing };

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api_;
	}

	if ( typeof window !== 'undefined' ) {
		window.odoowoo = Object.assign( window.odoowoo || {}, { odooConnection: api_, callbackBox: callbackBox } );

		var start = function () {
			Array.prototype.forEach.call( window.document.querySelectorAll( '[data-odoowoo-card="odoo-connection"]' ), init );
		};

		if ( window.document.readyState === 'loading' ) {
			window.document.addEventListener( 'DOMContentLoaded', start );
		} else {
			start();
		}
	}
}() );
