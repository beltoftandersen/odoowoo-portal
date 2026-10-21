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
	};

	function t( key ) {
		var i18n = ( G.odoowooPortal && G.odoowooPortal.i18n ) || {};

		return typeof i18n[ key ] === 'string' && i18n[ key ] !== '' ? i18n[ key ] : FALLBACK[ key ];
	}

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

		function show( d ) {
			var v = headerView( d );

			nameEl.textContent = v.name;
			stateEl.textContent = v.stateText;
			renameBtn.hidden = ! canWrite;
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
		}

		core.whenVisible( root, load );
	}

	var api_ = { renameBody: renameBody, headerView: headerView, headerFailure: headerFailure, hideDashboardTabs: hideDashboardTabs, rename: rename };

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
