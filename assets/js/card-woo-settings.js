/*
 * OdooWoo portal: card "WooCommerce settings" (stock update, close orders, order prefix).
 * Plain script. In the browser it starts on every [data-odoowoo-card="woo-settings"]; under Node
 * (tests) it sets module.exports. It shares one woo-keys object with the connection card
 * (core.wooKeysStore): Django resets what a save leaves out, so every save sends it all.
 */
( function () {
	'use strict';

	var G = typeof window !== 'undefined' ? window : globalThis;
	var core = typeof module !== 'undefined' && module.exports ? require( './core.js' ) : G.odoowoo;

	var t = core.translator( {
		selectPrefix: 'Select an order prefix',
		prefixesPartial: 'Other licenses could not be checked. A prefix that is already taken will be refused when you save.',
		prefixTaken: '%s (already used by another of your dashboards)',
		saveWooFirst: 'Save your WooCommerce connection first.',
	} );

	var store = core.wooKeysStore;
	var LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

	/**
	 * The prefix select: a placeholder, then AA to ZZ (676). A prefix used by another dashboard is
	 * disabled and says so (no dashboard name). `used` may include this dashboard's own prefix
	 * (Django's list does): it is never marked. A saved prefix outside AA to ZZ stays selectable.
	 */
	function prefixOptions( used, own ) {
		var taken = {};
		var mine = typeof own === 'string' ? own.toUpperCase() : '';

		( Array.isArray( used ) ? used : [] ).forEach( function ( p ) {
			if ( typeof p === 'string' && p !== '' ) {
				taken[ p.toUpperCase() ] = true;
			}
		} );

		var options = [ { value: '', label: t( 'selectPrefix' ), disabled: false } ];
		var seen = false;

		function add( value ) {
			var blocked = taken[ value ] === true && value !== mine;

			options.push( { value: value, label: blocked ? core.fill( t( 'prefixTaken' ), value ) : value, disabled: blocked } );
		}

		for ( var i = 0; i < 26; i++ ) {
			for ( var j = 0; j < 26; j++ ) {
				var code = LETTERS[ i ] + LETTERS[ j ];

				seen = seen || code === mine;
				add( code );
			}
		}

		if ( mine !== '' && ! seen ) {
			add( mine );
		}

		return options;
	}

	/** The note under the select when some dashboards could not be checked, else ''. */
	function prefixNote( state ) {
		return state && state.used_prefixes_partial === true ? t( 'prefixesPartial' ) : '';
	}

	/**
	 * Saves the whole object, but only once the connection is saved: Django needs the key, URL
	 * and secret in every save.
	 */
	async function saveSettings( ctx, store_ ) {
		if ( ! store_.complete() ) {
			return { reply: core.notLoadedReply() };
		}

		var s = store_.get();

		if ( typeof s.consumer_key !== 'string' || s.consumer_key === '' || typeof s.woocommerce_url !== 'string' || s.woocommerce_url === '' || s.has_consumer_secret !== true ) {
			return { reply: { ok: false, data: undefined, code: 'woocommerce_not_configured', message: t( 'saveWooFirst' ), field: undefined, status: 0 } };
		}

		return { reply: await core.saveObject( core.dashboardPath( ctx, 'woo-keys' ), store_ ) };
	}

	function init( root ) {
		var ctx = { licenseId: Number( root.getAttribute( 'data-license' ) ) || 0, dashboardId: Number( root.getAttribute( 'data-dashboard' ) ) || 0 };
		var canWrite = root.getAttribute( 'data-can-write' ) === '1';
		var form = root.querySelector( 'form' );
		var stock = form.elements.enable_stock_update;
		var close = form.elements.change_status_delivery;
		var select = form.elements.prefix;
		var saveBtn = form.querySelector( '[type="submit"]' );
		var statusEl = form.querySelector( '.odoowoo-form-status' );
		var note = form.querySelector( '.odoowoo-prefix-note' );
		var built = '';

		function render() {
			var s = store.get();
			var odd = typeof s.prefix === 'string' && ! /^[A-Za-z]{2}$/.test( s.prefix ) ? s.prefix : '';
			var key = JSON.stringify( [ s.used_prefixes, odd ] );

			if ( key !== built ) {
				built = key;
				select.textContent = '';
				prefixOptions( s.used_prefixes, s.prefix ).forEach( function ( o ) {
					var opt = G.document.createElement( 'option' );

					opt.value = o.value;
					opt.textContent = o.label;
					opt.disabled = o.disabled;
					select.appendChild( opt );
				} );
			}

			note.textContent = prefixNote( s );
			note.hidden = note.textContent === '';
			select.value = typeof s.prefix === 'string' ? s.prefix.toUpperCase() : '';
			stock.checked = s.enable_stock_update !== false;
			close.checked = s.change_status_delivery !== false;
		}

		[ stock, close, select ].forEach( function ( input ) {
			input.disabled = ! canWrite;
		} );
		saveBtn.disabled = ! canWrite;
		stock.addEventListener( 'change', function () {
			store.patch( { enable_stock_update: stock.checked } );
		} );
		close.addEventListener( 'change', function () {
			store.patch( { change_status_delivery: close.checked } );
		} );
		select.addEventListener( 'change', function () {
			store.patch( { prefix: select.value } );
		} );
		store.subscribe( render );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			core.submit( saveBtn, statusEl, async function () {
				var out = await saveSettings( ctx, store );

				return out.reply;
			} ).then( function ( reply ) {
				if ( core.notConfigured( reply ) ) {
					core.status( statusEl, 'error', '' );
					core.showNotConfigured( statusEl, reply, root );
				}
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

	var api_ = { prefixOptions: prefixOptions, prefixNote: prefixNote, saveSettings: saveSettings, store: store };

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api_;
	}

	if ( typeof window !== 'undefined' ) {
		window.odoowoo = Object.assign( window.odoowoo || {}, { wooSettings: api_, prefixOptions: prefixOptions } );

		var start = function () {
			Array.prototype.forEach.call( window.document.querySelectorAll( '[data-odoowoo-card="woo-settings"]' ), init );
		};

		if ( window.document.readyState === 'loading' ) {
			window.document.addEventListener( 'DOMContentLoaded', start );
		} else {
			start();
		}
	}
}() );
