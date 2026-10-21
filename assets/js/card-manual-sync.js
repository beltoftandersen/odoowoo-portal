/*
 * OdooWoo portal: card "Manual sync". Plain script. In the browser it starts on every
 * [data-odoowoo-card="manual-sync"]; under Node (tests) it sets module.exports. Every text from
 * Django is written with textContent. A sync that is running is found again after a reload (the
 * active read below), so the progress never starts over and never polls a task that is gone.
 */
( function () {
	'use strict';

	var G = typeof window !== 'undefined' ? window : globalThis;
	var core = typeof module !== 'undefined' && module.exports ? require( './core.js' ) : G.odoowoo;
	var tasks = typeof module !== 'undefined' && module.exports ? require( './tasks.js' ) : G.odoowoo.tasks;

	var t = core.translator( {
		syncing: 'Syncing…',
		brandsNotInPlan: 'Brand sync is not part of your plan.',
		backToList: 'Back to your dashboards',
		noValidId: 'Enter at least one valid id, or leave the field empty.',
		tooManyIds: 'At most 500 ids at a time.',
		nothingChosen: 'Enter order ids, or choose a start and end date.',
		badDates: 'Enter a valid start and end date.',
		reversedDates: 'The start date is after the end date.',
		running: '%s sync running…',
		kindBrands: 'Brands',
		kindCategories: 'Categories',
		kindProducts: 'Products',
		kindStock: 'Stock',
		kindPrices: 'Prices',
		kindBarcodes: 'Barcodes',
		kindOrders: 'Orders',
		itemsHeading: 'Items that were not synced',
		resultLost: 'We could not follow this sync any more. See the sync log for what happened.',
	} );

	var CAP = 500;

	/** kind => the portal route it posts to, and what its input is. */
	var KINDS = {
		brands: { route: 'trigger-brand-sync', input: 'none' },
		categories: { route: 'trigger-category-sync', input: 'none' },
		products: { route: 'trigger-sync', input: 'products' },
		stock: { route: 'sync-stock', input: 'products' },
		prices: { route: 'sync-prices', input: 'products' },
		barcodes: { route: 'sync-barcodes', input: 'products' },
		orders: { route: 'sync-orders', input: 'orders' },
	};

	/**
	 * Ids from typed text, as the server reads them (Api\Map::clean_ids): split on blanks, commas
	 * and semicolons; whole numbers above zero only; no duplicates. For the UX only.
	 */
	function cleanIds( text ) {
		var seen = {};
		var ids = [];

		String( text === undefined || text === null ? '' : text ).split( /[\s,;]+/ ).forEach( function ( word ) {
			if ( /^\d{1,15}$/.test( word ) && Number( word ) > 0 && ! seen[ Number( word ) ] ) {
				seen[ Number( word ) ] = true;
				ids.push( Number( word ) );
			}
		} );

		return { ids: ids, tooMany: ids.length > CAP };
	}

	function refuse( field, message ) {
		return { ok: false, field: field, message: message };
	}

	/** A real calendar day as YYYY-MM-DD. */
	function isDay( value ) {
		if ( typeof value !== 'string' || ! /^\d{4}-\d{2}-\d{2}$/.test( value ) ) {
			return false;
		}

		var d = new Date( value + 'T00:00:00Z' );

		return ! isNaN( d.getTime() ) && d.toISOString().slice( 0, 10 ) === value;
	}

	/**
	 * The body of a sync from what was typed: { ok: true, body } (body undefined when the route
	 * takes none) or { ok: false, field, message } for an input the server would refuse anyway.
	 * Text typed but without one valid id is refused here too: sending an empty list would sync
	 * everything. Order dates go as YYYY-MM-DD, with no timezone.
	 */
	function syncBody( kind, input ) {
		var def = KINDS[ kind ];
		var i = input && typeof input === 'object' ? input : {};
		var raw;
		var clean;

		if ( ! def || def.input === 'none' ) {
			return { ok: true, body: undefined };
		}

		if ( def.input === 'products' ) {
			raw = String( i.productIds === undefined || i.productIds === null ? '' : i.productIds ).trim();

			if ( raw === '' ) {
				return { ok: true, body: { product_ids: [] } };
			}

			clean = cleanIds( raw );

			if ( clean.tooMany ) {
				return refuse( 'product_ids', t( 'tooManyIds' ) );
			}

			return clean.ids.length === 0 ? refuse( 'product_ids', t( 'noValidId' ) ) : { ok: true, body: { product_ids: clean.ids } };
		}

		raw = String( i.orderIds === undefined || i.orderIds === null ? '' : i.orderIds ).trim();

		if ( raw !== '' ) {
			clean = cleanIds( raw );

			if ( clean.tooMany ) {
				return refuse( 'order_ids', t( 'tooManyIds' ) );
			}

			return clean.ids.length === 0 ? refuse( 'order_ids', t( 'noValidId' ) ) : { ok: true, body: { order_ids: clean.ids } };
		}

		var from = typeof i.from === 'string' ? i.from.trim() : '';
		var to = typeof i.to === 'string' ? i.to.trim() : '';

		if ( from === '' && to === '' ) {
			return refuse( 'order_ids', t( 'nothingChosen' ) );
		}

		if ( ! isDay( from ) || ! isDay( to ) ) {
			return refuse( 'from_date', t( 'badDates' ) );
		}

		if ( from > to ) {
			return refuse( 'from_date', t( 'reversedDates' ) );
		}

		return { ok: true, body: { from_date: from, to_date: to } };
	}

	/** The line shown for a task: its state in words, then Django's message once it has ended. */
	function resultLine( view ) {
		return tasks.line( view );
	}

	/** The items of a finished task that did not sync, as {label, status, text}. */
	function resultItems( view ) {
		var out = [];

		( view && Array.isArray( view.results ) ? view.results : [] ).forEach( function ( r ) {
			if ( ! r || typeof r !== 'object' ) {
				return;
			}

			var status = typeof r.status === 'string' ? r.status.toLowerCase() : '';
			var text = typeof r.message === 'string' && r.message !== '' ? r.message : ( typeof r.reason === 'string' ? r.reason : '' );

			if ( status === 'success' || ( status === '' && text === '' ) ) {
				return;
			}

			var name = r.product_name !== undefined ? String( r.product_name ) : ( r.order !== undefined ? String( r.order ) : '' );
			var sku = typeof r.sku === 'string' && r.sku !== '' ? r.sku : '';

			out.push( { label: sku !== '' && name !== '' ? name + ' (' + sku + ')' : ( name !== '' ? name : sku ), status: status, text: text } );
		} );

		return out;
	}

	/** What a refused start means: Django's message as given, the field it names, and whether to link back to the list. */
	function refusalView( reply ) {
		var r = reply && typeof reply === 'object' ? reply : {};

		return {
			message: typeof r.message === 'string' && r.message !== '' ? r.message : core.text( 'generic' ),
			field: core.fieldOf( r ),
			back: r.code === 'dashboard_paused',
		};
	}

	/** Only a brand_sync of false (the license summary) hides brand sync; unknown is left to Django's own refusal. */
	function brandsAvailable( licenses, licenseId ) {
		var found = ( Array.isArray( licenses ) ? licenses : [] ).filter( function ( l ) {
			return l && Number( l.id ) === Number( licenseId );
		} )[ 0 ];

		return ! ( found && found.brand_sync === false );
	}

	/** Starts a sync: { ok: true, taskId, kind } or { ok: false, reply } (a refused input makes no call). */
	async function start( ctx, kind, input ) {
		var def = KINDS[ kind ];
		var made = syncBody( kind, input );

		if ( ! def ) {
			return { ok: false, reply: { ok: false, code: 'invalid_value', message: core.text( 'generic' ), field: undefined, status: 0 } };
		}

		if ( ! made.ok ) {
			return { ok: false, reply: { ok: false, code: 'invalid_value', message: made.message, field: made.field, status: 0 } };
		}

		var reply = await core.api( 'POST', core.dashboardPath( ctx, def.route ), made.body );

		if ( ! reply.ok ) {
			return { ok: false, reply: reply };
		}

		var id = reply.data && typeof reply.data.task_id === 'string' ? reply.data.task_id : '';

		return id === '' ? { ok: false, reply: { ok: false, code: 'server_error', message: core.text( 'generic' ), field: undefined, status: reply.status } } : { ok: true, taskId: id, kind: kind };
	}

	/** The task this user started for the dashboard, from the portal's memory: {task_id, kind, started} or null. */
	async function activeTask( ctx ) {
		var reply = await core.api( 'GET', core.dashboardPath( ctx, 'sync/active' ) );
		var task = reply.ok && reply.data ? reply.data.task : null;

		return task && typeof task === 'object' && typeof task.task_id === 'string' && task.task_id !== '' ? task : null;
	}

	/** Follows a task to its end (tasks.poll: stops with the card, on a finished, lost or unknown task). */
	function follow( ctx, taskId, o ) {
		return tasks.poll( {
			el: o.el,
			sleep: o.sleep,
			cancelled: o.cancelled,
			fetch: function () {
				return core.api( 'GET', core.dashboardPath( ctx, 'task-status/' + encodeURIComponent( taskId ) ) );
			},
			onUpdate: o.onUpdate,
			onDone: o.onDone,
		} );
	}

	/**
	 * After a reload: asks what is running and, when there is a task, follows it. Resolves null
	 * when nothing runs, else the poll's {reason, view} plus the task's kind.
	 */
	async function resume( ctx, o ) {
		var task = await activeTask( ctx );

		if ( ! task ) {
			return null;
		}

		if ( o && typeof o.onTask === 'function' ) {
			o.onTask( task );
		}

		var out = await follow( ctx, task.task_id, o || {} );

		return { reason: out.reason, view: out.view, kind: task.kind };
	}

	/** "Products sync running…" for a kind; '' for an unknown kind. */
	function runningText( kind ) {
		var name = KINDS[ kind ] ? t( 'kind' + kind.charAt( 0 ).toUpperCase() + kind.slice( 1 ) ) : '';

		return name === '' ? '' : core.fill( t( 'running' ), name );
	}

	function init( root ) {
		var ctx = { licenseId: Number( root.getAttribute( 'data-license' ) ) || 0, dashboardId: Number( root.getAttribute( 'data-dashboard' ) ) || 0 };
		var canWrite = root.getAttribute( 'data-can-write' ) === '1';
		var canRead = root.getAttribute( 'data-can-read' ) === '1';
		var listUrl = root.getAttribute( 'data-list-url' ) || '';
		var doc = G.document;
		var buttons = Array.prototype.slice.call( root.querySelectorAll( '[data-kind]' ) );
		var resultEl = root.querySelector( '.odoowoo-sync-result' );
		var itemsEl = root.querySelector( '.odoowoo-sync-items' );
		var messageEl = root.querySelector( '.odoowoo-sync-message' );
		var brandNotice = root.querySelector( '[data-brand-notice]' );
		var brandButton = root.querySelector( '[data-kind="brands"]' );
		var running = false;
		var brands = true;

		function field( name ) {
			return root.querySelector( '[name="' + name + '"]' );
		}

		function input() {
			return { productIds: field( 'product_ids' ).value, orderIds: field( 'order_ids' ).value, from: field( 'from' ).value, to: field( 'to' ).value };
		}

		function enable() {
			buttons.forEach( function ( b ) {
				b.disabled = ! canWrite || running || ( b === brandButton && ! brands );
			} );

			if ( brandButton ) {
				brandButton.hidden = ! brands;
			}

			if ( brandNotice ) {
				brandNotice.hidden = brands;
			}
		}

		function clearMessages() {
			core.clearFieldErrors( root );
			messageEl.textContent = '';
			messageEl.hidden = true;
		}

		function showRefusal( reply ) {
			var v = refusalView( reply );

			clearMessages();

			if ( core.showFieldError( root, reply ) ) {
				return;
			}

			core.status( messageEl, 'error', v.message + ' ' );
			messageEl.hidden = false;

			if ( v.back && core.safeUrl( listUrl ) ) {
				var a = doc.createElement( 'a' );

				a.setAttribute( 'href', listUrl );
				a.textContent = t( 'backToList' );
				messageEl.appendChild( a );
			}
		}

		function showItems( view ) {
			itemsEl.textContent = '';

			var items = resultItems( view );

			if ( items.length === 0 ) {
				return;
			}

			var h = doc.createElement( 'h4' );
			var ul = doc.createElement( 'ul' );

			h.textContent = t( 'itemsHeading' );
			itemsEl.appendChild( h );
			items.slice( 0, 100 ).forEach( function ( i ) {
				var li = doc.createElement( 'li' );

				li.textContent = i.label + ( i.text !== '' ? ': ' + i.text : '' );
				ul.appendChild( li );
			} );
			itemsEl.appendChild( ul );
		}

		function onUpdate( v ) {
			tasks.render( resultEl, v );

			if ( v.state !== 'running' ) {
				showItems( v );
			}
		}

		function finished( out, resumed ) {
			running = false;
			enable();

			if ( messageEl.getAttribute( 'data-state' ) === 'info' ) {
				messageEl.textContent = '';
				messageEl.hidden = true;
			}

			if ( ! out || out.reason === 'cancelled' || out.reason === 'done' || out.reason === 'skipped' || out.reason === 'failed' ) {
				return;
			}

			if ( out.reason === 'unknown' && resumed ) {
				resultEl.textContent = '';

				return;
			}

			resultEl.textContent = '';
			core.status( messageEl, 'error', out.reason === 'session' ? core.text( 'session' ) : t( 'resultLost' ) );
			messageEl.hidden = false;
		}

		async function run( kind, button ) {
			var release = core.busy( button, t( 'syncing' ) );

			clearMessages();
			itemsEl.textContent = '';
			resultEl.textContent = '';
			running = true;

			var out = await start( ctx, kind, input() );

			release();

			if ( ! out.ok ) {
				running = false;
				enable();
				showRefusal( out.reply );

				return;
			}

			enable();
			onUpdate( tasks.view( { state: 'running', current: 0, total: 0 } ) );
			finished( await follow( ctx, out.taskId, { el: root, onUpdate: onUpdate } ), false );
		}

		buttons.forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				if ( ! running && canWrite ) {
					run( b.getAttribute( 'data-kind' ), b );
				}
			} );
		} );

		enable();

		if ( ctx.dashboardId <= 0 || ! canRead ) {
			return;
		}

		core.whenVisible( root, function () {
			// First thing: is a sync running? (a reload must not lose it)
			running = true;
			enable();
			resume( ctx, {
				el: root,
				onUpdate: onUpdate,
				onTask: function ( task ) {
					var text = runningText( task.kind );

					if ( text !== '' ) {
						core.status( messageEl, 'info', text );
						messageEl.hidden = false;
					}
				},
			} ).then( function ( out ) {
				finished( out, true );
			}, function () {
				running = false;
				enable();
			} );

			core.api( 'GET', 'licenses' ).then( function ( reply ) {
				brands = brandsAvailable( reply.ok && Array.isArray( reply.data ) ? reply.data : [], ctx.licenseId );
				enable();
			} );
		} );
	}

	var api_ = { runningText: runningText, syncBody: syncBody, cleanIds: cleanIds, resultLine: resultLine, resultItems: resultItems, refusalView: refusalView, brandsAvailable: brandsAvailable, start: start, activeTask: activeTask, resume: resume, follow: follow };

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api_;
	}

	if ( typeof window !== 'undefined' ) {
		window.odoowoo = Object.assign( window.odoowoo || {}, { manualSync: api_ } );

		var begin = function () {
			Array.prototype.forEach.call( window.document.querySelectorAll( '[data-odoowoo-card="manual-sync"]' ), init );
		};

		if ( window.document.readyState === 'loading' ) {
			window.document.addEventListener( 'DOMContentLoaded', begin );
		} else {
			begin();
		}
	}
}() );
