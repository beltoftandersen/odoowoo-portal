/*
 * OdooWoo portal: card "Sync logs" (the order syncs of the dashboard, newest first). Plain
 * script. In the browser it starts on every [data-odoowoo-card="sync-logs"]; under Node (tests)
 * it sets module.exports. Log text is written with textContent only, inside <details>; an
 * entry that looks like an engine crash is replaced by the fixed per-item message.
 */
( function () {
	'use strict';

	var G = typeof window !== 'undefined' ? window : globalThis;
	var core = typeof module !== 'undefined' && module.exports ? require( './core.js' ) : G.odoowoo;

	var t = core.translator( {
		loading: 'Loading logs…',
		empty: 'No sync logs yet.',
		refreshed: 'Refreshed',
		wooRef: 'WooCommerce %s',
		odooRef: 'Odoo %s',
		odooNone: 'Odoo N/A',
		logLabel: 'Log',
		itemFailed: 'This item was not synced. See the sync log for details.',
		unknownStatus: 'Unknown',
	} );

	function str( v ) {
		return typeof v === 'string' ? v : ( typeof v === 'number' ? String( v ) : '' );
	}

	/**
	 * The text of a log entry that may be shown: the server's own text, except an entry that
	 * carries a Python traceback or an XML-RPC fault, which is replaced by the fixed message.
	 */
	function safeLog( text ) {
		var s = str( text );

		if ( s.indexOf( 'Traceback (most recent call last)' ) !== -1 || s.indexOf( 'Fault ' ) !== -1 ) {
			return t( 'itemFailed' );
		}

		return s;
	}

	/** A time for the row; the text as given when it is not a date. */
	function formatTime( iso ) {
		var d = new Date( iso );

		if ( str( iso ) === '' || isNaN( d.getTime() ) ) {
			return str( iso );
		}

		try {
			return d.toLocaleString( undefined, { dateStyle: 'medium', timeStyle: 'short' } );
		} catch ( e ) {
			return str( iso );
		}
	}

	/** What one row shows. The badge is always the server's status_text, and words when it has none. */
	function rowView( row ) {
		var r = row && typeof row === 'object' ? row : {};
		var odoo = str( r.odoo_order_id );
		var log = safeLog( r.log );

		return {
			woo: str( r.woocommerce_order_id ),
			odoo: odoo !== '' ? t( 'odooRef' ).replace( '%s', odoo ) : t( 'odooNone' ),
			badge: str( r.status_text ) !== '' ? str( r.status_text ) : t( 'unknownStatus' ),
			raw: str( r.status ),
			at: str( r.synced_at ),
			time: formatTime( r.synced_at ),
			log: log,
			hasLog: log !== '',
		};
	}

	/** Fills a <ul> with the rows. DOM glue: doc is the document, list the element. */
	function render( doc, list, items ) {
		list.textContent = '';

		( Array.isArray( items ) ? items : [] ).forEach( function ( item ) {
			var v = rowView( item );
			var li = doc.createElement( 'li' );
			var head = doc.createElement( 'div' );
			var woo = doc.createElement( 'span' );
			var odoo = doc.createElement( 'span' );
			var badge = doc.createElement( 'span' );
			var time = doc.createElement( 'time' );

			li.className = 'odoowoo-log-row';
			head.className = 'odoowoo-log-row__head';
			woo.className = 'odoowoo-log-ref';
			woo.textContent = t( 'wooRef' ).replace( '%s', v.woo );
			odoo.className = 'odoowoo-log-ref';
			odoo.textContent = v.odoo;
			badge.className = 'odoowoo-log-badge';
			badge.textContent = v.badge;

			if ( v.raw !== '' ) {
				badge.setAttribute( 'title', v.raw );
			}

			if ( v.at !== '' ) {
				time.setAttribute( 'datetime', v.at );
			}

			time.textContent = v.time;
			head.appendChild( woo );
			head.appendChild( odoo );
			head.appendChild( badge );
			head.appendChild( time );
			li.appendChild( head );

			if ( v.hasLog ) {
				var details = doc.createElement( 'details' );
				var summary = doc.createElement( 'summary' );
				var pre = doc.createElement( 'pre' );

				summary.textContent = t( 'logLabel' );
				pre.className = 'odoowoo-log-text';
				pre.textContent = v.log;
				details.appendChild( summary );
				details.appendChild( pre );
				li.appendChild( details );
			}

			list.appendChild( li );
		} );
	}

	/** Reads the list: { ok: true, items } in Django's order, or { ok: false, reply }. */
	async function load( ctx ) {
		var reply = await core.api( 'GET', core.dashboardPath( ctx, 'order-syncs' ) );

		return reply.ok ? { ok: true, items: core.listOf( reply.data ) } : { ok: false, reply: reply };
	}

	function init( root ) {
		var ctx = { licenseId: Number( root.getAttribute( 'data-license' ) ) || 0, dashboardId: Number( root.getAttribute( 'data-dashboard' ) ) || 0 };
		var canRead = root.getAttribute( 'data-can-read' ) === '1';
		var button = root.querySelector( '[data-action="refresh"]' );
		var statusEl = root.querySelector( '.odoowoo-log-status' );
		var list = root.querySelector( '.odoowoo-log-list' );
		var seq = 0;

		async function refresh( announce ) {
			var mine = ++seq;
			var release = core.busy( button, t( 'loading' ) );

			core.status( statusEl, 'saving', t( 'loading' ) );

			var out = await load( ctx );

			release();

			if ( mine !== seq || root.isConnected === false ) {
				return;
			}

			if ( ! out.ok ) {
				core.status( statusEl, 'error', core.errorText( out.reply ) );

				return;
			}

			render( G.document, list, out.items );
			var message = out.items.length === 0 ? t( 'empty' ) : ( announce ? t( 'refreshed' ) : '' );

			core.status( statusEl, announce && out.items.length > 0 ? 'saved' : 'info', message );
		}

		button.addEventListener( 'click', function () {
			refresh( true );
		} );

		if ( ctx.dashboardId <= 0 || ! canRead ) {
			return;
		}

		core.whenVisible( root, function () {
			refresh( false );
		} );
	}

	var api_ = { safeLog: safeLog, rowView: rowView, render: render, load: load };

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api_;
	}

	if ( typeof window !== 'undefined' ) {
		window.odoowoo = Object.assign( window.odoowoo || {}, { syncLogs: api_ } );

		var begin = function () {
			Array.prototype.forEach.call( window.document.querySelectorAll( '[data-odoowoo-card="sync-logs"]' ), init );
		};

		if ( window.document.readyState === 'loading' ) {
			window.document.addEventListener( 'DOMContentLoaded', begin );
		} else {
			begin();
		}
	}
}() );
