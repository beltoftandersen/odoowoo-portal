/*
 * OdooWoo portal: card "Odoo settings" (order, tax, product sync and stock settings). Plain
 * script. In the browser it starts on every [data-odoowoo-card="odoo-settings"]; under Node
 * (tests) it sets module.exports. It saves through the same shared odoo-settings object as the
 * Odoo connection card (core.odooSettingsStore): Django resets every setting a save leaves out,
 * so the body is always the complete object, never a part of it.
 */
( function () {
	'use strict';

	var G = typeof window !== 'undefined' ? window : globalThis;
	var core = typeof module !== 'undefined' && module.exports ? require( './core.js' ) : G.odoowoo;
	var depsApi = typeof module !== 'undefined' && module.exports ? require( './deps.js' ) : G.odoowoo.depsApi;

	var t = core.translator( {
		letOdooDecide: 'Let Odoo decide from the customer',
		loadPricelists: 'Load pricelists',
		loadingPricelists: 'Loading…',
		pricelistsLoaded: 'Pricelists loaded.',
		stillLoading: 'The settings are still loading. Try again in a moment.',
		saveOdooFirst: 'Save your Odoo connection first.',
	} );

	var fieldOf = core.fieldOf;
	var showFieldError = core.showFieldError;
	var clearFieldErrors = core.clearFieldErrors;

	var store = core.odooSettingsStore;
	var EVENT = 'odoowoo:mappings-saved';
	var LISTS = [ [ 'payment-method-mappings', 'hasPayment' ], [ 'tax-mappings', 'hasTax' ], [ 'price-mappings', 'hasPrice' ] ];

	function refusal( field, message ) {
		return { ok: false, data: undefined, code: 'invalid_value', message: message, field: field, status: 0 };
	}

	function filled( v ) {
		return typeof v === 'string' && v !== '';
	}

	/** Dependencies from the values and the known facts (brandSync is the settings' own brand_sync_allowed). */
	function computeDeps( values, facts ) {
		var v = values || {};

		return depsApi.deps( v, { hasPayment: facts.hasPayment, hasTax: facts.hasTax, hasPrice: facts.hasPrice, brandSync: typeof v.brand_sync_allowed === 'boolean' ? v.brand_sync_allowed : undefined } );
	}

	/**
	 * Reads the three mapping lists (a list with items is "has mappings"). A read that fails
	 * leaves that fact as it was (unknown until the first success). onChange gets the facts.
	 */
	function createChecker( ctx, onChange ) {
		var facts = { hasPayment: undefined, hasTax: undefined, hasPrice: undefined };
		var latest = 0;

		return {
			facts: function () {
				return Object.assign( {}, facts );
			},
			refresh: async function () {
				// Refreshes can overlap (load, then a saved mapping): only the newest one may apply its answers.
				var mine = ++latest;
				var replies = await Promise.all( LISTS.map( function ( l ) {
					return core.api( 'GET', core.dashboardPath( ctx, l[ 0 ] ) );
				} ) );

				if ( mine !== latest ) {
					return Object.assign( {}, facts );
				}

				replies.forEach( function ( reply, i ) {
					if ( reply && reply.ok === true ) {
						facts[ LISTS[ i ][ 1 ] ] = core.listOf( reply.data ).length > 0;
					}
				} );

				if ( typeof onChange === 'function' ) {
					onChange( Object.assign( {}, facts ) );
				}

				return Object.assign( {}, facts );
			},
		};
	}

	/** Runs fn when a mapping editor saved; returns the function that stops it. */
	function onMappingsSaved( target, fn ) {
		target.addEventListener( EVENT, fn );

		return function () {
			target.removeEventListener( EVENT, fn );
		};
	}

	/** Whether the Save button may be on: the viewer may write and the shared object has loaded. */
	function canSave( store_, canWrite ) {
		return canWrite === true && store_.isLoaded();
	}

	/**
	 * Saves the complete shared object. Nothing is sent until it has loaded, or without the
	 * Odoo connection (Django needs url, database, user and, the first time, the password).
	 */
	async function saveSettings( ctx, store_, facts ) {
		if ( ! store_.isLoaded() ) {
			return { reply: refusal( undefined, t( 'stillLoading' ) ) };
		}

		var s = store_.get();

		if ( ! filled( s.odoo_url ) ) {
			return { reply: refusal( 'odoo_url', t( 'saveOdooFirst' ) ) };
		}

		if ( ! filled( s.odoo_db ) ) {
			return { reply: refusal( 'odoo_db', t( 'saveOdooFirst' ) ) };
		}

		if ( ! filled( s.odoo_user ) ) {
			return { reply: refusal( 'odoo_user', t( 'saveOdooFirst' ) ) };
		}

		if ( ! filled( s.odoo_password ) && s.has_odoo_password !== true ) {
			return { reply: refusal( 'odoo_password', t( 'saveOdooFirst' ) ) };
		}

		store_.patch( depsApi.prepare( s, { hasPayment: facts.hasPayment, hasTax: facts.hasTax, hasPrice: facts.hasPrice, brandSync: typeof s.brand_sync_allowed === 'boolean' ? s.brand_sync_allowed : facts.brandSync } ) );

		return { reply: await core.saveObject( core.dashboardPath( ctx, 'odoo-settings' ), store_ ) };
	}

	/** Pricelists on demand: { ok, items } or { ok: false, reply }. Items are {id, name, currency}. */
	async function loadPricelists( ctx ) {
		var reply = await core.api( 'GET', core.dashboardPath( ctx, 'odoo-pricelists' ) );

		if ( ! reply.ok ) {
			return { ok: false, items: [], reply: reply };
		}

		return { ok: true, items: core.listOf( reply.data ).filter( function ( i ) {
			return i && Number.isInteger( i.id ) && typeof i.name === 'string';
		} ), reply: reply };
	}

	/** The picker's options: "let Odoo decide", the saved one by its stored name, then what was loaded. */
	function pricelistOptions( values, items ) {
		var options = [ { value: '', label: t( 'letOdooDecide' ) } ];
		var saved = values && Number.isInteger( values.odoo_order_pricelist_id ) && values.odoo_order_pricelist_id > 0 ? values.odoo_order_pricelist_id : 0;
		var seen = {};

		if ( saved > 0 ) {
			seen[ saved ] = true;
			options.push( { value: String( saved ), label: typeof values.odoo_order_pricelist_name === 'string' && values.odoo_order_pricelist_name !== '' ? values.odoo_order_pricelist_name : String( saved ) } );
		}

		( Array.isArray( items ) ? items : [] ).forEach( function ( i ) {
			if ( ! seen[ i.id ] ) {
				seen[ i.id ] = true;
				options.push( { value: String( i.id ), label: i.name } );
			}
		} );

		return options;
	}

	/** Chooses a pricelist by id ('' = let Odoo decide); an id that is not in the list is not chosen. */
	function choosePricelist( store_, value, items ) {
		var id = Number( value );
		var s = store_.get();
		var found = ( Array.isArray( items ) ? items : [] ).filter( function ( i ) {
			return i.id === id;
		} )[ 0 ];

		if ( found ) {
			store_.patch( { odoo_order_pricelist_id: found.id, odoo_order_pricelist_name: found.name } );
		} else if ( value !== '' && Number.isInteger( s.odoo_order_pricelist_id ) && s.odoo_order_pricelist_id === id ) {
			return;
		} else {
			store_.patch( { odoo_order_pricelist_id: null, odoo_order_pricelist_name: '' } );
		}
	}

	function init( root ) {
		var ctx = { licenseId: Number( root.getAttribute( 'data-license' ) ) || 0, dashboardId: Number( root.getAttribute( 'data-dashboard' ) ) || 0 };
		var canWrite = root.getAttribute( 'data-can-write' ) === '1';
		var form = root.querySelector( 'form' );
		var saveBtn = form.querySelector( '[type="submit"]' );
		var loadBtn = form.querySelector( '[data-action="load-pricelists"]' );
		var statusEl = form.querySelector( '.odoowoo-form-status' );
		var pricelistSel = form.elements.odoo_order_pricelist_id;
		var items = [];
		loadBtn.disabled = ! canWrite;
		var checker = createChecker( ctx, render );
		var lastOptions = '';

		function typed( name ) {
			return form.elements[ name ];
		}

		function render() {
			var s = store.get();
			var d = computeDeps( s, checker.facts() );

			depsApi.BOOLEANS.forEach( function ( name ) {
				var input = typed( name );
				var reason = form.querySelector( '[data-reason-for="' + name + '"]' );
				var off = d.disabled[ name ] !== undefined;

				input.checked = ! off && s[ name ] === true;
				input.disabled = ! canWrite || off;
				reason.textContent = off ? d.disabled[ name ] : '';
				reason.hidden = ! off;
			} );

			Array.prototype.forEach.call( form.querySelectorAll( '[data-field-wrap]' ), function ( wrap ) {
				wrap.hidden = d.hidden.indexOf( wrap.getAttribute( 'data-field-wrap' ) ) !== -1;
			} );

			[ 'capture_stage', 'quantity_settings' ].forEach( function ( name ) {
				typed( name ).value = typeof s[ name ] === 'string' ? s[ name ] : typed( name ).options[ 0 ].value;
				typed( name ).disabled = ! canWrite;
			} );

			[ 'stock_sku_meta_keys', 'stock_units_meta_keys' ].forEach( function ( name ) {
				if ( G.document.activeElement !== typed( name ) ) {
					typed( name ).value = typeof s[ name ] === 'string' ? s[ name ] : '';
				}
				typed( name ).disabled = ! canWrite;
			} );

			var options = pricelistOptions( s, items );
			var key = JSON.stringify( options );

			if ( key !== lastOptions ) {
				lastOptions = key;
				pricelistSel.textContent = '';
				options.forEach( function ( o ) {
					var opt = G.document.createElement( 'option' );

					opt.value = o.value;
					opt.textContent = o.label;
					pricelistSel.appendChild( opt );
				} );
			}

			pricelistSel.value = Number.isInteger( s.odoo_order_pricelist_id ) && s.odoo_order_pricelist_id > 0 ? String( s.odoo_order_pricelist_id ) : '';
			pricelistSel.disabled = ! canWrite;
			saveBtn.disabled = ! canSave( store, canWrite );
		}

		depsApi.BOOLEANS.forEach( function ( name ) {
			typed( name ).addEventListener( 'change', function () {
				var patch = {};

				patch[ name ] = typed( name ).checked;
				store.patch( patch );
			} );
		} );
		[ 'capture_stage', 'quantity_settings', 'stock_sku_meta_keys', 'stock_units_meta_keys' ].forEach( function ( name ) {
			typed( name ).addEventListener( 'input', function () {
				var patch = {};

				patch[ name ] = typed( name ).value;
				store.patch( patch );
			} );
		} );
		pricelistSel.addEventListener( 'change', function () {
			choosePricelist( store, pricelistSel.value, items );
		} );

		Array.prototype.forEach.call( form.querySelectorAll( '[data-help-for]' ), function ( button ) {
			button.addEventListener( 'click', function () {
				var open = button.parentNode.getAttribute( 'data-open' ) === '1';

				button.parentNode.setAttribute( 'data-open', open ? '0' : '1' );
				button.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
			} );
			button.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Escape' ) {
					button.parentNode.setAttribute( 'data-open', '0' );
					button.setAttribute( 'aria-expanded', 'false' );
				}
			} );
		} );

		loadBtn.addEventListener( 'click', function () {
			var label = loadBtn.textContent;

			loadBtn.disabled = true;
			loadBtn.textContent = t( 'loadingPricelists' );
			core.status( statusEl, 'saving', t( 'loadingPricelists' ) );
			loadPricelists( ctx ).then( function ( out ) {
				loadBtn.disabled = ! canWrite;
				loadBtn.textContent = label;

				if ( out.ok ) {
					items = out.items;
					core.status( statusEl, 'saved', t( 'pricelistsLoaded' ) );
					lastOptions = '';
					render();
				} else if ( core.notConfigured( out.reply ) ) {
					core.status( statusEl, 'error', '' );
					core.showNotConfigured( statusEl, out.reply, root );
				} else {
					core.status( statusEl, 'error', core.errorText( out.reply ) );
				}
			} );
		} );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			clearFieldErrors( form );
			core.submit( saveBtn, statusEl, async function () {
				var out = await saveSettings( ctx, store, checker.facts() );

				return out.reply;
			} ).then( function ( reply ) {
				if ( reply.ok !== true ) {
					showFieldError( form, reply );
				}

				if ( core.notConfigured( reply ) ) {
					core.status( statusEl, 'error', '' );
					core.showNotConfigured( statusEl, reply, root );
				}

				render();
			} );
		} );

		store.subscribe( render );
		onMappingsSaved( G.document, function () {
			checker.refresh();
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

				render();
			} );
			checker.refresh();
		} );
	}

	var api_ = { createChecker: createChecker, onMappingsSaved: onMappingsSaved, computeDeps: computeDeps, saveSettings: saveSettings, canSave: canSave, loadPricelists: loadPricelists, pricelistOptions: pricelistOptions, choosePricelist: choosePricelist, fieldOf: fieldOf, showFieldError: showFieldError, store: store };

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api_;
	}

	if ( typeof window !== 'undefined' ) {
		window.odoowoo = Object.assign( window.odoowoo || {}, { odooSettings: api_ } );

		var start = function () {
			Array.prototype.forEach.call( window.document.querySelectorAll( '[data-odoowoo-card="odoo-settings"]' ), init );
		};

		if ( window.document.readyState === 'loading' ) {
			window.document.addEventListener( 'DOMContentLoaded', start );
		} else {
			start();
		}
	}
}() );
