/*
 * OdooWoo portal: card "Dashboard settings" (the Gift cards tab). Plain script. In the browser it
 * starts on every [data-odoowoo-card="dashboard-settings"]; under Node (tests) it sets
 * module.exports. The tick is display only: Django's show_gift_cards decides whether the tab shows.
 */
( function () {
	'use strict';

	var G = typeof window !== 'undefined' ? window : globalThis;
	var core = typeof module !== 'undefined' && module.exports ? require( './core.js' ) : G.odoowoo;

	function path( ctx ) {
		return 'licenses/' + encodeURIComponent( String( ctx.licenseId ) ) + '/dashboards/' + encodeURIComponent( String( ctx.dashboardId ) );
	}

	/** The PATCH that sets the flag and nothing else. A refusal's message is shown as it is, without the field name. */
	async function saveGiftTab( ctx, checked ) {
		var reply = await core.api( 'PATCH', path( ctx ), { show_gift_cards: checked === true } );

		return reply.ok ? reply : Object.assign( {}, reply, { field: undefined } );
	}

	function init( root ) {
		var ctx = { licenseId: Number( root.getAttribute( 'data-license' ) ) || 0, dashboardId: Number( root.getAttribute( 'data-dashboard' ) ) || 0 };
		var canWrite = root.getAttribute( 'data-can-write' ) === '1';
		var form = root.querySelector( 'form' );
		var gift = form.elements.show_gift_cards;
		var saveBtn = form.querySelector( '[type="submit"]' );
		var statusEl = form.querySelector( '.odoowoo-form-status' );
		var saved = false;

		function apply( d ) {
			saved = !! d && d.show_gift_cards === true;
			gift.checked = saved;

			// Show or hide the Gift cards tab now, without a reload (the header card owns the tab).
			if ( G.odoowoo && typeof G.odoowoo.applyGiftTab === 'function' ) {
				G.odoowoo.applyGiftTab( G.document, saved );
			}
		}

		gift.disabled = ! canWrite;
		saveBtn.disabled = ! canWrite;

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			core.submit( saveBtn, statusEl, function () {
				return saveGiftTab( ctx, gift.checked );
			} ).then( function ( reply ) {
				if ( reply.ok && reply.data ) {
					apply( reply.data );
				} else if ( ! reply.ok ) {
					// Not saved: the tick goes back to what is saved.
					gift.checked = saved;
				}
			} );
		} );

		if ( ctx.dashboardId <= 0 ) {
			return;
		}

		core.whenVisible( root, function () {
			core.api( 'GET', path( ctx ) ).then( function ( reply ) {
				if ( reply.ok ) {
					apply( reply.data );
				} else {
					core.status( statusEl, 'error', core.errorText( reply ) );
				}
			} );
		} );
	}

	var api_ = { saveGiftTab: saveGiftTab };

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api_;
	}

	if ( typeof window !== 'undefined' ) {
		window.odoowoo = Object.assign( window.odoowoo || {}, { dashboardSettings: api_ } );

		var start = function () {
			Array.prototype.forEach.call( window.document.querySelectorAll( '[data-odoowoo-card="dashboard-settings"]' ), init );
		};

		if ( window.document.readyState === 'loading' ) {
			window.document.addEventListener( 'DOMContentLoaded', start );
		} else {
			start();
		}
	}
}() );
