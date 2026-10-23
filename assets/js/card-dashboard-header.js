/*
 * OdooWoo portal: card "dashboard header" (name, state, rename). Plain script. In the browser it
 * starts on every [data-odoowoo-card="dashboard-header"]; under Node (tests) it sets module.exports.
 * The name from Django is written with textContent only.
 */
( function () {
	'use strict';

	var G = typeof window !== 'undefined' ? window : globalThis;
	var core = typeof module !== 'undefined' && module.exports ? require( './core.js' ) : G.odoowoo;

	var FALLBACK = {
		rename: 'Rename',
		save: 'Save',
		nameLabel: 'Dashboard name',
		nameRequired: 'Give the dashboard a name.',
		active: 'Active',
		paused: 'Paused',
		notFound: 'This dashboard was not found.',
		headerError: 'Dashboard',
		retry: 'Try again',
		healthWoo: 'WooCommerce',
		healthOdoo: 'Odoo',
		healthOrders: 'Orders',
		connected: 'Connected',
		notConnected: 'Not connected',
		unchecked: 'Could not be checked',
		connectWoo: 'Connect your WooCommerce store',
		connectOdoo: 'Connect Odoo',
		waiting: 'Waiting for both connections',
		noOrders: 'No orders synced yet',
		runSync: 'Run a first sync',
		lastOrder: 'Last order synced %s',
		needAttention: '%1$s of the last %2$s orders need attention',
		needAttentionOne: '1 of the last %s orders needs attention',
		seeLogs: 'See the logs',
		headHealthy: 'Your sync is healthy',
		headAttention: 'Something needs your attention',
		headSetup: 'Finish setting up to start syncing',
		headReady: 'Connected and ready for the first sync',
		headPaused: 'This dashboard is paused, so nothing syncs',
	};

	var t = core.translator( FALLBACK );

	function renameBody( name ) {
		var n = typeof name === 'string' ? name.trim() : '';

		return n === '' ? null : { name: n };
	}

	/** {name, stateText} of a dashboard object ({id, name, enabled, created_at}). */
	function headerView( d ) {
		if ( d === null || typeof d !== 'object' || typeof d.name !== 'string' ) {
			return { name: '', stateText: '' };
		}

		return { name: d.name, stateText: d.enabled === false ? t( 'paused' ) : t( 'active' ) };
	}

	function filled( v ) {
		return typeof v === 'string' && v.trim() !== '';
	}

	/** Order sync statuses that need the customer's eyes (OrderSync.status values of the engine). */
	function needsAttention( row ) {
		var raw = row && typeof row.status === 'string' ? row.status.toLowerCase() : '';

		return [ 'error', 'failed', 'warning' ].indexOf( raw ) !== -1;
	}

	function when( iso ) {
		var d = new Date( iso );

		if ( typeof iso !== 'string' || iso === '' || isNaN( d.getTime() ) ) {
			return '';
		}

		try {
			return d.toLocaleString( undefined, { dateStyle: 'medium', timeStyle: 'short' } );
		} catch ( e ) {
			return iso;
		}
	}

	/**
	 * The status line of a dashboard: a headline and three rows (WooCommerce, Odoo, Orders), each
	 * {key, label, text, tone, action}. tone is ok, todo, attention, idle or unknown; action is
	 * {text, tab} or null. woo, odoo and syncs are the replies of the three reads, or null when a
	 * read failed. enabled is the dashboard's own flag.
	 */
	function healthView( woo, odoo, syncs, enabled ) {
		var wooOk = woo && woo.ok === true && woo.data && typeof woo.data === 'object';
		var odooOk = odoo && odoo.ok === true && odoo.data && typeof odoo.data === 'object';
		var syncOk = syncs && syncs.ok === true;
		var wooOn = !! wooOk && filled( woo.data.woocommerce_url ) && filled( woo.data.consumer_key );
		var odooOn = !! odooOk && filled( odoo.data.odoo_url ) && filled( odoo.data.odoo_db ) && filled( odoo.data.odoo_user );
		var items = syncOk ? core.listOf( syncs.data ) : [];
		var bad = items.filter( needsAttention ).length;
		var rows = [];

		rows.push( ! wooOk
			? { key: 'woo', label: t( 'healthWoo' ), text: t( 'unchecked' ), tone: 'unknown', action: null }
			: ( wooOn
				? { key: 'woo', label: t( 'healthWoo' ), text: t( 'connected' ), tone: 'ok', action: null }
				: { key: 'woo', label: t( 'healthWoo' ), text: t( 'notConnected' ), tone: 'todo', action: { text: t( 'connectWoo' ), tab: 'connections' } } ) );
		rows.push( ! odooOk
			? { key: 'odoo', label: t( 'healthOdoo' ), text: t( 'unchecked' ), tone: 'unknown', action: null }
			: ( odooOn
				? { key: 'odoo', label: t( 'healthOdoo' ), text: t( 'connected' ), tone: 'ok', action: null }
				: { key: 'odoo', label: t( 'healthOdoo' ), text: t( 'notConnected' ), tone: 'todo', action: { text: t( 'connectOdoo' ), tab: 'connections' } } ) );

		var order;

		if ( ! syncOk ) {
			order = { key: 'orders', label: t( 'healthOrders' ), text: t( 'unchecked' ), tone: 'unknown', action: null };
		} else if ( ! wooOn || ! odooOn ) {
			order = { key: 'orders', label: t( 'healthOrders' ), text: t( 'waiting' ), tone: 'idle', action: null };
		} else if ( items.length === 0 ) {
			order = { key: 'orders', label: t( 'healthOrders' ), text: t( 'noOrders' ), tone: 'idle', action: { text: t( 'runSync' ), tab: 'sync' } };
		} else if ( bad > 0 ) {
			order = {
				key: 'orders',
				label: t( 'healthOrders' ),
				text: bad === 1 ? core.fill( t( 'needAttentionOne' ), items.length ) : core.fill( t( 'needAttention' ), bad, items.length ),
				tone: 'attention',
				action: { text: t( 'seeLogs' ), tab: 'logs' },
			};
		} else {
			var at = when( items[ 0 ] && items[ 0 ].synced_at );

			order = { key: 'orders', label: t( 'healthOrders' ), text: at !== '' ? core.fill( t( 'lastOrder' ), at ) : t( 'connected' ), tone: 'ok', action: null };
		}

		rows.push( order );

		var headline;

		if ( enabled === false ) {
			headline = { text: t( 'headPaused' ), tone: 'idle' };
		} else if ( bad > 0 ) {
			headline = { text: t( 'headAttention' ), tone: 'attention' };
		} else if ( wooOk && odooOk && ( ! wooOn || ! odooOn ) ) {
			headline = { text: t( 'headSetup' ), tone: 'todo' };
		} else if ( wooOn && odooOn && syncOk && items.length === 0 ) {
			headline = { text: t( 'headReady' ), tone: 'idle' };
		} else if ( wooOn && odooOn && syncOk ) {
			headline = { text: t( 'headHealthy' ), tone: 'ok' };
		} else {
			headline = { text: '', tone: 'unknown' };
		}

		return { headline: headline, rows: rows };
	}

	/** Opens a tab of the Tabs element that holds our cards; false when there is none to open. */
	function openTab( doc, slug ) {
		var title = doc.querySelector( '.brxe-tabs-nested [data-odoowoo-tab="' + slug + '"]' );

		if ( ! title ) {
			return false;
		}

		title.click();

		if ( typeof title.scrollIntoView === 'function' ) {
			title.scrollIntoView( { block: 'nearest', inline: 'nearest' } );
		}

		return true;
	}

	/** Fills the status line. DOM glue. */
	function renderHealth( doc, box, view ) {
		var headline = box.querySelector( '.odoowoo-health__headline' );
		var list = box.querySelector( '.odoowoo-health__list' );

		headline.textContent = view.headline.text;
		headline.setAttribute( 'data-tone', view.headline.tone );
		headline.hidden = view.headline.text === '';
		list.textContent = '';

		view.rows.forEach( function ( row ) {
			var li = doc.createElement( 'li' );
			var label = doc.createElement( 'span' );
			var text = doc.createElement( 'span' );

			li.className = 'odoowoo-health__row';
			li.setAttribute( 'data-tone', row.tone );
			label.className = 'odoowoo-health__label';
			label.textContent = row.label;
			text.className = 'odoowoo-health__text';
			text.textContent = row.text;
			li.appendChild( label );
			li.appendChild( text );

			if ( row.action ) {
				var a = doc.createElement( 'a' );

				a.className = 'odoowoo-health__action';
				a.href = '?tab=' + encodeURIComponent( row.action.tab );
				a.textContent = row.action.text;
				a.addEventListener( 'click', function ( e ) {
					if ( openTab( doc, row.action.tab ) ) {
						e.preventDefault();
					}
				} );
				li.appendChild( a );
			}

			list.appendChild( li );
		} );

		box.hidden = false;
	}

	/**
	 * What to show when the dashboard cannot be read. Only dashboard_not_found (or a 404) is "not
	 * found"; anything else (Django down, a paused-plan refusal...) keeps a neutral heading, its own
	 * message and a retry.
	 */
	function headerFailure( reply ) {
		var r = reply || {};

		if ( r.code === 'dashboard_not_found' || r.status === 404 ) {
			return { notFound: true, heading: t( 'notFound' ), message: '', retry: false };
		}

		return { notFound: false, heading: t( 'headerError' ), message: core.errorText( r ), retry: true };
	}

	/** A dashboard that is not there has no forms: hide the Tabs element that holds our cards. */
	function hideDashboardTabs( doc ) {
		Array.prototype.forEach.call( doc.querySelectorAll( '.brxe-tabs-nested' ), function ( tabs ) {
			if ( tabs.querySelector( '.odoowoo-card' ) ) {
				tabs.hidden = true;
				tabs.style.display = 'none';
			}
		} );
	}

	function path( licenseId, dashboardId ) {
		return 'licenses/' + encodeURIComponent( String( licenseId ) ) + '/dashboards/' + encodeURIComponent( String( dashboardId ) );
	}

	/**
	 * Renames: an empty name is refused before any request; the heading is set from the reply
	 * (what Django kept), only on success.
	 */
	async function rename( licenseId, dashboardId, raw, nameEl ) {
		var body = renameBody( raw );

		if ( body === null ) {
			return { ok: false, code: 'invalid_value', message: t( 'nameRequired' ), field: undefined, status: 0, data: undefined };
		}

		var reply = await core.api( 'PATCH', path( licenseId, dashboardId ), body );

		if ( ! reply.ok ) {
			return reply;
		}

		if ( ! reply.data || typeof reply.data.name !== 'string' || reply.data.name === '' ) {
			// Django said yes but not what it kept: never "Saved" next to a stale heading.
			return { ok: false, code: 'server_error', message: core.text( 'generic' ), field: undefined, status: reply.status, data: undefined };
		}

		nameEl.textContent = reply.data.name;

		return reply;
	}

	function el( tag, className, text ) {
		var node = G.document.createElement( tag );

		if ( className ) {
			node.className = className;
		}

		if ( typeof text === 'string' ) {
			node.textContent = text;
		}

		return node;
	}

	function init( root ) {
		var licenseId = Number( root.getAttribute( 'data-license' ) ) || 0;
		var dashboardId = Number( root.getAttribute( 'data-dashboard' ) ) || 0;
		var nameEl = root.querySelector( '.odoowoo-dashboard-name' );
		var stateEl = root.querySelector( '.odoowoo-state' );
		var renameBtn = root.querySelector( '.odoowoo-rename' );
		var formBox = root.querySelector( '.odoowoo-header__form' );
		var statusEl = root.querySelector( '.odoowoo-header__status' );
		var canWrite = root.getAttribute( 'data-can-write' ) === '1';
		var canRead = root.getAttribute( 'data-can-read' ) === '1';
		var healthBox = root.querySelector( '.odoowoo-health' );
		var ctx = { licenseId: licenseId, dashboardId: dashboardId };

		function show( d ) {
			var v = headerView( d );

			nameEl.textContent = v.name;
			stateEl.textContent = v.stateText;
			stateEl.setAttribute( 'data-on', d && d.enabled === false ? '0' : '1' );
			renameBtn.hidden = ! canWrite;
		}

		async function loadHealth( enabled ) {
			if ( ! healthBox || ! canRead ) {
				return;
			}

			var replies = await Promise.all( [ 'woo-keys', 'odoo-settings', 'order-syncs' ].map( function ( route ) {
				return core.api( 'GET', core.dashboardPath( ctx, route ) );
			} ) );

			if ( root.isConnected === false ) {
				return;
			}

			renderHealth( G.document, healthBox, healthView( replies[ 0 ], replies[ 1 ], replies[ 2 ], enabled ) );
		}

		function openForm() {
			var id = 'odoowoo-rename-' + Math.random().toString( 36 ).slice( 2, 8 );
			var form = el( 'form', 'odoowoo-rename-form' );
			var label = el( 'label', '', t( 'nameLabel' ) );
			var input = el( 'input' );
			var save = el( 'button', 'odoowoo-button', t( 'save' ) );
			var cancel = el( 'button', 'odoowoo-button odoowoo-button--secondary', core.text( 'cancel' ) );
			var line = el( 'span', 'odoowoo-form-status' );

			label.setAttribute( 'for', id );
			input.type = 'text';
			input.id = id;
			input.maxLength = 255;
			input.value = nameEl.textContent;
			save.type = 'submit';
			cancel.type = 'button';
			[ label, input, save, cancel, line ].forEach( function ( n ) { form.appendChild( n ); } );

			function close() {
				formBox.textContent = '';
				renameBtn.focus();
			}

			cancel.addEventListener( 'click', close );
			form.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Escape' ) {
					close();
				}
			} );
			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				core.submit( save, line, function () {
					return rename( licenseId, dashboardId, input.value, nameEl );
				} ).then( function ( reply ) {
					if ( reply.ok ) {
						close();
						core.status( statusEl, 'saved', core.text( 'saved' ) );
					}
				} );
			} );

			formBox.textContent = '';
			formBox.appendChild( form );
			input.focus();
			input.select();
		}

		renameBtn.addEventListener( 'click', openForm );

		if ( dashboardId <= 0 ) {
			nameEl.textContent = t( 'notFound' );
			hideDashboardTabs( window.document );
			return;
		}

		var retryBtn = null;

		async function load() {
			var reply = await core.api( 'GET', path( licenseId, dashboardId ) );

			if ( retryBtn ) {
				retryBtn.remove();
				retryBtn = null;
			}

			if ( ! reply.ok ) {
				var f = headerFailure( reply );

				nameEl.textContent = f.heading;
				stateEl.textContent = '';
				renameBtn.hidden = true;
				core.status( statusEl, 'error', f.message );

				if ( f.notFound ) {
					hideDashboardTabs( window.document );
				}

				if ( f.retry ) {
					retryBtn = el( 'button', 'odoowoo-button odoowoo-button--secondary odoowoo-retry', t( 'retry' ) );
					retryBtn.type = 'button';
					retryBtn.addEventListener( 'click', load );
					statusEl.parentNode.insertBefore( retryBtn, statusEl.nextSibling );
				}

				return;
			}

			core.status( statusEl, 'saved', '' );
			statusEl.removeAttribute( 'data-state' );
			show( reply.data );
			loadHealth( reply.data && reply.data.enabled );
		}

		core.whenVisible( root, load );
	}

	var api_ = { renameBody: renameBody, headerView: headerView, headerFailure: headerFailure, healthView: healthView, hideDashboardTabs: hideDashboardTabs, rename: rename };

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api_;
	}

	if ( typeof window !== 'undefined' ) {
		window.odoowoo = Object.assign( window.odoowoo || {}, api_ );

		var start = function () {
			Array.prototype.forEach.call( window.document.querySelectorAll( '[data-odoowoo-card="dashboard-header"]' ), init );
		};

		if ( window.document.readyState === 'loading' ) {
			window.document.addEventListener( 'DOMContentLoaded', start );
		} else {
			start();
		}
	}
}() );
