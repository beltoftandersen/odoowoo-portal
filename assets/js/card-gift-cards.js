/*
 * OdooWoo portal: card "Gift cards". Plain script. In the browser it starts on every
 * [data-odoowoo-card="gift-cards"]; under Node (tests) it sets module.exports. Django saves the
 * whole settings object and resets what a save leaves out, so a save sends all of it and only
 * once it was read. Django checks every submitted id against the lists it stored, so the pickers
 * read those stored lists.
 */
( function () {
	'use strict';

	var G = typeof window !== 'undefined' ? window : globalThis;
	var core = typeof module !== 'undefined' && module.exports ? require( './core.js' ) : G.odoowoo;

	var t = core.translator( {
		selectTax: 'Select a sale tax',
		selectJournal: 'Select a journal',
		selectAccount: 'Select an account',
		loadFirst: 'Load taxes, journals and accounts first',
		taxFallback: 'Tax %s',
		journalFallback: 'Journal %s',
		accountFallback: 'Account %s',
		loadingLists: 'Loading…',
		listsLoaded: 'Taxes, journals and accounts loaded.',
	} );

	/** The settings a save sends: the names Django reads, but the expiry tax. */
	var SEND_FIELDS = [
		'enabled', 'enable_expiry_sweep',
		'sale_tax_id', 'redeem_journal_id', 'expiry_journal_id', 'expiry_debit_account_id', 'expiry_credit_account_id', 'expiry_vat_account_id',
		'sale_line_meta_key', 'sale_product_codes', 'sale_tax_name', 'redeem_order_meta_key', 'card_api_path', 'paid_flag_field',
		'redeem_journal_name', 'expiry_api_path', 'expiry_status_values', 'expiry_journal_name', 'expiry_tax_name',
		'legacy_card_cutoff', 'expiry_min_balance',
	];
	/** Django refuses any non-null expiry tax (releases carry no VAT yet), so it is never sent. */
	var NOT_SENT = [ 'expiry_tax_id' ];

	var ACCOUNT_FIELDS = [ 'expiry_debit_account_id', 'expiry_credit_account_id' ];
	var JOURNALS = { redeem: [ 'redeem_journal_id', 'redeem_journal_name' ], expiry: [ 'expiry_journal_id', 'expiry_journal_name' ] };

	function isObject( v ) {
		return v !== null && typeof v === 'object' && ! Array.isArray( v );
	}

	/** A fresh in-page copy of the settings object (one per card). */
	function createStore() {
		return core.createStore( { fields: SEND_FIELDS, secrets: [], flags: {} } );
	}

	function settingsPath( ctx ) {
		return core.dashboardPath( ctx, 'gift-card-settings' );
	}

	function failed( status ) {
		return { ok: false, data: undefined, code: 'server_error', message: core.text( 'generic' ), field: undefined, status: status };
	}

	/** Reads the settings once ({ok, data}); the reply is {settings: {...}}. */
	function ensureLoaded( ctx, store ) {
		return store.ensure( async function () {
			var reply = await core.api( 'GET', settingsPath( ctx ) );

			if ( ! reply.ok ) {
				return reply;
			}

			return isObject( reply.data ) && isObject( reply.data.settings ) ? { ok: true, data: reply.data.settings, status: reply.status } : failed( reply.status );
		} );
	}

	/**
	 * Saves the whole object, then re-reads it. The amount goes as a string (money is never a JSON
	 * number). Refused before anything is sent while the settings were not read. A refused save
	 * changes nothing in the store, so what was typed stays.
	 */
	async function saveSettings( ctx, store ) {
		if ( ! store.complete() ) {
			return core.notLoadedReply();
		}

		var body = store.body();

		NOT_SENT.forEach( function ( name ) {
			delete body[ name ];
		} );

		if ( 'expiry_min_balance' in body && typeof body.expiry_min_balance !== 'string' ) {
			body.expiry_min_balance = body.expiry_min_balance === null || body.expiry_min_balance === undefined ? '' : String( body.expiry_min_balance );
		}

		var reply = await core.api( 'PUT', settingsPath( ctx ), { settings: body } );

		if ( ! reply.ok ) {
			return reply;
		}

		var fresh = await core.api( 'GET', settingsPath( ctx ) );

		store.commit( fresh.ok && isObject( fresh.data ) && isObject( fresh.data.settings ) ? fresh.data.settings : null );

		return reply;
	}

	function listOf( reply, valid ) {
		return core.listOf( reply.data ).filter( valid );
	}

	var isTax = function ( i ) {
		return i && Number.isInteger( i.id ) && typeof i.name === 'string' && typeof i.type_tax_use === 'string';
	};
	var isJournal = function ( i ) {
		return i && Number.isInteger( i.journal_id ) && typeof i.name === 'string';
	};
	var isAccount = function ( i ) {
		return i && Number.isInteger( i.account_id ) && typeof i.name === 'string';
	};

	/** The three stored lists (reads only): { ok: true, taxes, journals, accounts } or { ok: false, step, reply }. */
	async function readStored( ctx ) {
		var out = { ok: true, taxes: [], journals: [], accounts: [] };
		var steps = [
			[ 'taxes', 'stored-odoo-taxes', isTax ],
			[ 'journals', 'saved-odoo-journals', isJournal ],
			[ 'accounts', 'saved-odoo-accounts', isAccount ],
		];

		for ( var i = 0; i < steps.length; i++ ) {
			var reply = await core.api( 'GET', core.dashboardPath( ctx, steps[ i ][ 1 ] ) );

			if ( ! reply.ok ) {
				return { ok: false, step: steps[ i ][ 0 ], reply: reply };
			}

			out[ steps[ i ][ 0 ] ] = listOf( reply, steps[ i ][ 2 ] );
		}

		return out;
	}

	/**
	 * The one loader: Odoo's taxes (stored by Django), journals and accounts (both stored when
	 * read), in that order, then the three stored lists. All three write rows, so this is for
	 * an owner of an active license only. Stops at the first refusal and says which step.
	 */
	async function loadLists( ctx ) {
		var loaders = [
			[ 'taxes', 'POST', 'fetch-and-store-odoo-taxes' ],
			[ 'journals', 'GET', 'odoo-journals' ],
			[ 'accounts', 'GET', 'odoo-accounts' ],
		];

		for ( var i = 0; i < loaders.length; i++ ) {
			var reply = await core.api( loaders[ i ][ 1 ], core.dashboardPath( ctx, loaders[ i ][ 2 ] ) );

			if ( ! reply.ok ) {
				return { ok: false, step: loaders[ i ][ 0 ], reply: reply };
			}
		}

		return readStored( ctx );
	}

	function option( value, label ) {
		return { value: value, label: label };
	}

	/** Picker options: a placeholder, the saved choice (by its stored name) when the list lacks it, then the list. */
	function withSaved( placeholder, listed, savedId, savedName, fallback ) {
		var options = [ option( '', placeholder ) ];
		var saved = Number.isInteger( savedId ) && savedId > 0 ? savedId : 0;

		if ( saved > 0 && ! listed.some( function ( o ) { return o.value === String( saved ); } ) ) {
			options.push( option( String( saved ), typeof savedName === 'string' && savedName !== '' ? savedName : t( fallback ).replace( '%s', String( saved ) ) ) );
		}

		return options.concat( listed );
	}

	/** Every sale tax is offered (not only the 0% ones), so a wrong choice is visible. */
	function taxOptions( items, values ) {
		var listed = ( Array.isArray( items ) ? items : [] ).filter( function ( i ) {
			return i && typeof i.type_tax_use === 'string' && i.type_tax_use.trim().toLowerCase() === 'sale';
		} ).map( function ( i ) {
			return option( String( i.id ), i.name );
		} );
		var v = values || {};

		return withSaved( listed.length > 0 ? t( 'selectTax' ) : t( 'loadFirst' ), listed, v.sale_tax_id, v.sale_tax_name, 'taxFallback' );
	}

	function journalOptions( items, savedId, savedName ) {
		var listed = ( Array.isArray( items ) ? items : [] ).map( function ( i ) {
			return option( String( i.journal_id ), i.name );
		} );

		return withSaved( listed.length > 0 ? t( 'selectJournal' ) : t( 'loadFirst' ), listed, savedId, savedName, 'journalFallback' );
	}

	/** An accountant finds an account by its code, so the code leads the label. */
	function accountOptions( items, savedId ) {
		var listed = ( Array.isArray( items ) ? items : [] ).map( function ( i ) {
			return option( String( i.account_id ), typeof i.code === 'string' && i.code !== '' ? i.code + ' ' + i.name : i.name );
		} );

		return withSaved( listed.length > 0 ? t( 'selectAccount' ) : t( 'loadFirst' ), listed, savedId, '', 'accountFallback' );
	}

	function find( items, key, value ) {
		var id = Number( value );

		return ( Array.isArray( items ) ? items : [] ).filter( function ( i ) {
			return i && i[ key ] === id;
		} )[ 0 ];
	}

	/** Chooses a tax by id ('' clears); only a sale tax of the list can be chosen. */
	function chooseTax( store, value, items ) {
		var found = value === '' ? undefined : find( ( Array.isArray( items ) ? items : [] ).filter( function ( i ) {
			return i && typeof i.type_tax_use === 'string' && i.type_tax_use.trim().toLowerCase() === 'sale';
		} ), 'id', value );

		if ( found ) {
			store.patch( { sale_tax_id: found.id, sale_tax_name: found.name } );
		} else if ( value !== '' && store.get().sale_tax_id === Number( value ) ) {
			return;
		} else {
			store.patch( { sale_tax_id: null, sale_tax_name: '' } );
		}
	}

	/** Chooses the gift card journal ('redeem') or the release journal ('expiry'). */
	function chooseJournal( store, which, value, items ) {
		var names = JOURNALS[ which ];
		var found = value === '' ? undefined : find( items, 'journal_id', value );
		var patch = {};

		if ( ! names ) {
			return;
		}

		if ( found ) {
			patch[ names[ 0 ] ] = found.journal_id;
			patch[ names[ 1 ] ] = found.name;
		} else if ( value !== '' && store.get()[ names[ 0 ] ] === Number( value ) ) {
			return;
		} else {
			patch[ names[ 0 ] ] = null;
			patch[ names[ 1 ] ] = '';
		}

		store.patch( patch );
	}

	/** Chooses an account by id ('' clears). Django holds the account's id only. */
	function chooseAccount( store, field, value ) {
		var patch = {};

		if ( ACCOUNT_FIELDS.indexOf( field ) === -1 ) {
			return;
		}

		patch[ field ] = value === '' ? null : Number( value );
		store.patch( patch );
	}

	function init( root ) {
		var ctx = { licenseId: Number( root.getAttribute( 'data-license' ) ) || 0, dashboardId: Number( root.getAttribute( 'data-dashboard' ) ) || 0 };
		var canWrite = root.getAttribute( 'data-can-write' ) === '1';
		var canRead = root.getAttribute( 'data-can-read' ) === '1';
		var doc = G.document;
		var form = root.querySelector( 'form' );
		var store = createStore();
		var lists = { taxes: [], journals: [], accounts: [] };
		var saveBtn = form.querySelector( '[type="submit"]' );
		var loadBtn = form.querySelector( '[data-action="load-lists"]' );
		var statusEl = form.querySelector( '.odoowoo-form-status' );
		var built = {};

		var CHECKS = [ 'enabled', 'enable_expiry_sweep' ];
		var TEXTS = [ 'sale_line_meta_key', 'sale_product_codes', 'redeem_order_meta_key', 'card_api_path', 'paid_flag_field', 'expiry_api_path', 'expiry_status_values', 'expiry_min_balance' ];

		function fill( select, options, wanted ) {
			var key = JSON.stringify( options );

			if ( built[ select.name ] !== key ) {
				built[ select.name ] = key;
				select.textContent = '';
				options.forEach( function ( o ) {
					var opt = doc.createElement( 'option' );

					opt.value = o.value;
					opt.textContent = o.label;
					select.appendChild( opt );
				} );
			}

			select.value = wanted;
		}

		function id( v ) {
			return Number.isInteger( v ) && v > 0 ? String( v ) : '';
		}

		function render() {
			var s = store.get();
			var el = form.elements;

			CHECKS.forEach( function ( name ) {
				el[ name ].checked = s[ name ] === true;
				el[ name ].disabled = ! canWrite;
			} );
			TEXTS.forEach( function ( name ) {
				if ( doc.activeElement !== el[ name ] ) {
					el[ name ].value = s[ name ] === null || s[ name ] === undefined ? '' : String( s[ name ] );
				}

				el[ name ].disabled = ! canWrite;
			} );
			fill( el.sale_tax_id, taxOptions( lists.taxes, s ), id( s.sale_tax_id ) );
			fill( el.redeem_journal_id, journalOptions( lists.journals, s.redeem_journal_id, s.redeem_journal_name ), id( s.redeem_journal_id ) );
			fill( el.expiry_journal_id, journalOptions( lists.journals, s.expiry_journal_id, s.expiry_journal_name ), id( s.expiry_journal_id ) );
			ACCOUNT_FIELDS.forEach( function ( name ) {
				fill( el[ name ], accountOptions( lists.accounts, s[ name ] ), id( s[ name ] ) );
			} );
			[ 'sale_tax_id', 'redeem_journal_id', 'expiry_journal_id' ].concat( ACCOUNT_FIELDS ).forEach( function ( name ) {
				el[ name ].disabled = ! canWrite;
			} );
			saveBtn.disabled = ! canWrite || ! store.complete();
		}

		CHECKS.forEach( function ( name ) {
			form.elements[ name ].addEventListener( 'change', function () {
				var patch = {};

				patch[ name ] = form.elements[ name ].checked;
				store.patch( patch );
			} );
		} );
		TEXTS.forEach( function ( name ) {
			form.elements[ name ].addEventListener( 'input', function () {
				var patch = {};

				patch[ name ] = form.elements[ name ].value;
				store.patch( patch );
			} );
		} );
		form.elements.sale_tax_id.addEventListener( 'change', function () {
			chooseTax( store, form.elements.sale_tax_id.value, lists.taxes );
		} );
		form.elements.redeem_journal_id.addEventListener( 'change', function () {
			chooseJournal( store, 'redeem', form.elements.redeem_journal_id.value, lists.journals );
		} );
		form.elements.expiry_journal_id.addEventListener( 'change', function () {
			chooseJournal( store, 'expiry', form.elements.expiry_journal_id.value, lists.journals );
		} );
		ACCOUNT_FIELDS.forEach( function ( name ) {
			form.elements[ name ].addEventListener( 'change', function () {
				chooseAccount( store, name, form.elements[ name ].value );
			} );
		} );
		store.subscribe( render );

		loadBtn.addEventListener( 'click', function () {
			var release = core.busy( loadBtn, t( 'loadingLists' ) );

			core.status( statusEl, 'saving', t( 'loadingLists' ) );
			loadLists( ctx ).then( function ( out ) {
				release();
				loadBtn.disabled = ! canWrite || ! canRead;

				if ( out.ok ) {
					lists = { taxes: out.taxes, journals: out.journals, accounts: out.accounts };
					core.status( statusEl, 'saved', t( 'listsLoaded' ) );
					render();
				} else {
					core.status( statusEl, 'error', core.errorText( out.reply ) );
				}
			} );
		} );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			core.clearFieldErrors( form );
			core.submit( saveBtn, statusEl, function () {
				return saveSettings( ctx, store );
			} ).then( function ( reply ) {
				if ( reply.ok !== true ) {
					core.showFieldError( form, reply );
				}

				render();
			} );
		} );

		render();

		if ( ctx.dashboardId <= 0 || ! canRead ) {
			return;
		}

		core.whenVisible( root, function () {
			ensureLoaded( ctx, store ).then( function ( reply ) {
				if ( ! reply.ok ) {
					core.status( statusEl, 'error', core.errorText( reply ) );
				}

				render();
			} );
			readStored( ctx ).then( function ( out ) {
				if ( out.ok ) {
					lists = { taxes: out.taxes, journals: out.journals, accounts: out.accounts };
					render();
				}
			} );
		} );
	}

	var api_ = { SEND_FIELDS: SEND_FIELDS, NOT_SENT: NOT_SENT, createStore: createStore, ensureLoaded: ensureLoaded, saveSettings: saveSettings, readStored: readStored, loadLists: loadLists, taxOptions: taxOptions, journalOptions: journalOptions, accountOptions: accountOptions, chooseTax: chooseTax, chooseJournal: chooseJournal, chooseAccount: chooseAccount };

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api_;
	}

	if ( typeof window !== 'undefined' ) {
		window.odoowoo = Object.assign( window.odoowoo || {}, { giftCards: api_ } );

		var begin = function () {
			Array.prototype.forEach.call( window.document.querySelectorAll( '[data-odoowoo-card="gift-cards"]' ), init );
		};

		if ( window.document.readyState === 'loading' ) {
			window.document.addEventListener( 'DOMContentLoaded', begin );
		} else {
			begin();
		}
	}
}() );
