/*
 * OdooWoo portal: card "WooCommerce connection" (store URL and keys, verify, save, transfer
 * webhooks). Plain script. In the browser it starts on every [data-odoowoo-card="woo-connection"];
 * under Node (tests) it sets module.exports. Text from Django is written with textContent only.
 * The card shares one woo-keys object with the WooCommerce settings card (core.wooKeysStore),
 * because Django resets what a save leaves out.
 */
( function () {
	'use strict';

	var G = typeof window !== 'undefined' ? window : globalThis;
	var core = typeof module !== 'undefined' && module.exports ? require( './core.js' ) : G.odoowoo;

	var t = core.translator( {
		keyRequired: 'Enter the consumer key.',
		urlRequired: 'Enter the store URL.',
		secretRequired: 'Enter the consumer secret.',
		verifying: 'Verifying…',
		transferring: 'Transferring…',
		badgeSaved: 'Saved',
		badgeVerified: 'Verified',
		badgeWebhooks: 'Webhooks transferred',
		show: 'Show',
		hide: 'Hide',
		loadFailed: 'The connection could not be read.',
		saveFirst: 'Save your changes first.',
	} );

	var store = core.wooKeysStore;

	function refusal( field, message ) {
		return { ok: false, data: undefined, code: 'invalid_value', message: message, field: field, status: 0 };
	}

	function filled( v ) {
		return typeof v === 'string' && v !== '';
	}

	/**
	 * The first missing field, as a refusal (before any request), or null. A secret is needed
	 * unless one is already saved (flag true).
	 */
	function missing( state ) {
		if ( ! filled( state.consumer_key ) ) {
			return refusal( 'consumer_key', t( 'keyRequired' ) );
		}

		if ( ! filled( state.woocommerce_url ) ) {
			return refusal( 'woocommerce_url', t( 'urlRequired' ) );
		}

		if ( ! filled( state.consumer_secret ) && state.has_consumer_secret !== true ) {
			return refusal( 'consumer_secret', t( 'secretRequired' ) );
		}

		return null;
	}

	/** Verify (which also saves on the server), then one re-read so the saved flag is Django's. */
	async function verifyWoo( ctx, store_ ) {
		var state = store_.get();
		var refused = missing( state );

		if ( refused ) {
			return { reply: refused, view: core.verifyView( refused, true ) };
		}

		var body = { consumer_key: state.consumer_key, woocommerce_url: state.woocommerce_url };

		if ( filled( state.consumer_secret ) ) {
			body.consumer_secret = state.consumer_secret;
		}

		var reply = await core.api( 'POST', core.dashboardPath( ctx, 'verify-woocommerce' ), body );
		var view = core.verifyView( reply, true );

		if ( view.refresh ) {
			var fresh = await core.api( 'GET', core.dashboardPath( ctx, 'woo-keys' ) );

			if ( fresh.ok ) {
				store_.load( fresh.data );
			}

			store_.dropSecrets();
		}

		return { reply: reply, view: view };
	}

	/** Saves the whole woo-keys object (connection and settings together). */
	async function saveWoo( ctx, store_ ) {
		if ( ! store_.complete() ) {
			return { reply: core.notLoadedReply() };
		}

		var refused = missing( store_.get() );

		if ( refused ) {
			return { reply: refused };
		}

		return { reply: await core.saveObject( core.dashboardPath( ctx, 'woo-keys' ), store_ ) };
	}

	/**
	 * Transfer webhooks needs saved keys (Django reads its own copy), and no unsaved changes: what
	 * is typed is not what Django would use. `reason` is shown only for the unsaved case.
	 */
	function transferState( state, dirty ) {
		var s = state || {};

		if ( dirty === true ) {
			return { enabled: false, reason: t( 'saveFirst' ) };
		}

		return { enabled: filled( s.consumer_key ) && filled( s.woocommerce_url ) && s.has_consumer_secret === true, reason: '' };
	}

	/** Django keeps no "webhooks transferred" flag: the badge exists only after this page's own success. */
	function webhookBadge( transferredThisSession ) {
		return transferredThisSession === true;
	}

	async function transferWebhooks( ctx, store_ ) {
		var allowed = transferState( store_.get(), store_.dirty() );

		if ( ! allowed.enabled ) {
			return { transferred: false, reply: allowed.reason === '' ? null : { ok: false, data: undefined, code: 'unsaved_changes', message: allowed.reason, field: undefined, status: 0 } };
		}

		var reply = await core.api( 'POST', core.dashboardPath( ctx, 'inject-woo-webhooks' ) );

		return { transferred: reply.ok === true, reply: reply };
	}

	function init( root ) {
		var ctx = { licenseId: Number( root.getAttribute( 'data-license' ) ) || 0, dashboardId: Number( root.getAttribute( 'data-dashboard' ) ) || 0 };
		var canWrite = root.getAttribute( 'data-can-write' ) === '1';
		var canVerify = root.getAttribute( 'data-can-verify' ) === '1';
		var form = root.querySelector( 'form' );
		var urlIn = form.elements.woocommerce_url;
		var keyIn = form.elements.consumer_key;
		var secretIn = form.elements.consumer_secret;
		var verifyBtn = form.querySelector( '[data-action="verify"]' );
		var saveBtn = form.querySelector( '[type="submit"]' );
		var transferBtn = form.querySelector( '[data-action="transfer"]' );
		var reveal = form.querySelector( '.odoowoo-reveal' );
		var statusEl = form.querySelector( '.odoowoo-form-status' );
		var badges = {
			saved: form.querySelector( '[data-badge="saved"]' ),
			verified: form.querySelector( '[data-badge="verified"]' ),
			webhooks: form.querySelector( '[data-badge="webhooks"]' ),
		};
		var unsavedNote = form.querySelector( '.odoowoo-unsaved-note' );
		var transferred = false;
		var verified = false;

		badges.saved.textContent = t( 'badgeSaved' );
		badges.verified.textContent = t( 'badgeVerified' );
		badges.webhooks.textContent = t( 'badgeWebhooks' );

		function render() {
			var s = store.get();
			var secret = core.secretState( s.has_consumer_secret );

			if ( G.document.activeElement !== urlIn ) {
				urlIn.value = typeof s.woocommerce_url === 'string' ? s.woocommerce_url : '';
			}

			if ( G.document.activeElement !== keyIn ) {
				keyIn.value = typeof s.consumer_key === 'string' ? s.consumer_key : '';
			}

			if ( typeof s.consumer_secret !== 'string' ) {
				secretIn.value = secret.value;
			}

			secretIn.setAttribute( 'autocomplete', 'new-password' );
			secretIn.setAttribute( 'placeholder', secret.placeholder );
			badges.saved.hidden = ! ( s.has_consumer_secret === true && filled( s.consumer_key ) );
			badges.verified.hidden = ! verified;
			badges.webhooks.hidden = ! webhookBadge( transferred );
			var tr = transferState( s, store.dirty() );

			transferBtn.disabled = ! canWrite || ! tr.enabled;
			unsavedNote.textContent = tr.reason;
			unsavedNote.hidden = tr.reason === '';
		}

		[ urlIn, keyIn, secretIn ].forEach( function ( input ) {
			input.disabled = ! canWrite;
			input.addEventListener( 'input', function () {
				verified = false;
				store.patch( { woocommerce_url: urlIn.value, consumer_key: keyIn.value, consumer_secret: secretIn.value } );
			} );
		} );
		saveBtn.disabled = ! canWrite;
		verifyBtn.disabled = ! canVerify;
		store.subscribe( render );

		reveal.addEventListener( 'click', function () {
			var shown = secretIn.type === 'text';

			secretIn.type = shown ? 'password' : 'text';
			reveal.textContent = shown ? t( 'show' ) : t( 'hide' );
			reveal.setAttribute( 'aria-pressed', shown ? 'false' : 'true' );
		} );

		function show( reply ) {
			if ( core.notConfigured( reply ) ) {
				core.status( statusEl, 'error', '' );
				core.showNotConfigured( statusEl, reply, root );
			}
		}

		verifyBtn.addEventListener( 'click', function () {
			core.submit( verifyBtn, statusEl, async function () {
				var out = await verifyWoo( ctx, store );

				verified = out.view.verified;
				render();

				return out.reply;
			}, t( 'verifying' ) ).then( function ( reply ) {
				if ( reply.ok === true ) {
					core.status( statusEl, 'saved', t( 'badgeVerified' ) );
				}
			} );
		} );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			core.submit( saveBtn, statusEl, async function () {
				var out = await saveWoo( ctx, store );

				render();

				return out.reply;
			} ).then( show );
		} );

		transferBtn.addEventListener( 'click', function () {
			core.submit( transferBtn, statusEl, async function () {
				var out = await transferWebhooks( ctx, store );

				transferred = out.transferred;
				render();

				return out.reply || refusal( undefined, core.text( 'generic' ) );
			}, t( 'transferring' ) ).then( function ( reply ) {
				if ( reply.ok === true ) {
					core.status( statusEl, 'saved', t( 'badgeWebhooks' ) );
				}

				show( reply );
			} );
		} );

		render();

		if ( ctx.dashboardId <= 0 ) {
			return;
		}

		core.whenVisible( root, function () {
			store.ensure( function () {
				return core.api( 'GET', core.dashboardPath( ctx, 'woo-keys' ) );
			} ).then( function ( reply ) {
				if ( ! reply.ok ) {
					core.status( statusEl, 'error', core.errorText( reply ) );
				}
			} );
		} );
	}

	var api_ = { verifyWoo: verifyWoo, saveWoo: saveWoo, transferState: transferState, webhookBadge: webhookBadge, transferWebhooks: transferWebhooks, store: store, missing: missing };

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api_;
	}

	if ( typeof window !== 'undefined' ) {
		window.odoowoo = Object.assign( window.odoowoo || {}, { wooConnection: api_ } );

		var start = function () {
			Array.prototype.forEach.call( window.document.querySelectorAll( '[data-odoowoo-card="woo-connection"]' ), init );
		};

		if ( window.document.readyState === 'loading' ) {
			window.document.addEventListener( 'DOMContentLoaded', start );
		} else {
			start();
		}
	}
}() );
