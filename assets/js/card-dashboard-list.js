/*
 * OdooWoo portal: card "dashboard list". Plain script. In the browser it adds its functions to
 * window.odoowoo and starts on every [data-odoowoo-card="dashboard-list"]; under Node (tests) it
 * sets module.exports. Names from Django are written with textContent only.
 */
( function () {
	'use strict';

	var G = typeof window !== 'undefined' ? window : globalThis;
	var core = typeof module !== 'undefined' && module.exports ? require( './core.js' ) : G.odoowoo;

	var FALLBACK = {
		loading: 'Loading your dashboards…',
		newDashboard: 'New dashboard',
		newTitle: 'New dashboard',
		nameLabel: 'Dashboard name',
		planLabel: 'Plan',
		create: 'Create',
		creating: 'Creating…',
		nameRequired: 'Give the dashboard a name.',
		open: 'Open',
		pause: 'Pause',
		enable: 'Enable',
		'delete': 'Delete',
		openNamed: 'Open %s',
		pauseNamed: 'Pause %s',
		enableNamed: 'Enable %s',
		deleteNamed: 'Delete %s',
		active: 'Active',
		paused: 'Paused',
		created: 'Created %s',
		deleteTitle: 'Delete dashboard',
		deleteBody: 'Delete %s? This removes its settings and logs. Stores already connected stop syncing.',
		emptyList: 'You have no dashboards yet. Create one to connect a store.',
		noPlan: 'You do not have an OdooWoo plan yet.',
		count: '%s dashboards',
		countOne: '1 dashboard',
		countOf: '%1$s of %2$s dashboards',
		countOfOne: '%s of 1 dashboard',
		limitReached: 'You have reached your plan limit of %s dashboards.',
		limitReachedOne: 'You have reached your plan limit of 1 dashboard.',
	};

	var t = core.translator( FALLBACK );

	function isObject( v ) {
		return v !== null && typeof v === 'object' && ! Array.isArray( v );
	}

	function whole( v ) {
		return typeof v === 'number' && isFinite( v ) ? v : null;
	}

	function limitReachedText( limit ) {
		return limit === 1 ? t( 'limitReachedOne' ) : core.fill( t( 'limitReached' ), limit );
	}

	/** "N of M dashboards", or "N dashboards" for an unlimited plan (limit 0). '' when unknown. */
	function countText( used, limit ) {
		if ( used === null ) {
			return '';
		}

		if ( limit === 0 || limit === null ) {
			return used === 1 ? t( 'countOne' ) : core.fill( t( 'count' ), used );
		}

		return limit === 1 ? core.fill( t( 'countOfOne' ), used ) : core.fill( t( 'countOf' ), used, limit );
	}

	/**
	 * What to draw for GET /licenses. A limit of 0 means unlimited (Django: `if limit and used >= limit`).
	 * newState per license: enabled (owner, can write, room), disabled (owner who cannot write: paused
	 * or not ready), hidden (no room, support, or a failed read).
	 */
	function viewModel( licenses ) {
		var list = Array.isArray( licenses ) ? licenses.filter( isObject ) : [];
		var groups = list.map( function ( l ) {
			var dashboards = Array.isArray( l.dashboards ) ? l.dashboards.filter( isObject ) : [];
			var limit = whole( l.limit );
			var used = whole( l.used );
			var owner = l.mode === 'owner';
			var writable = owner && l.can_write === true;
			var failed = typeof l.error === 'string' && l.error !== '';
			var known = limit !== null && used !== null;
			var full = known && limit > 0 && used >= limit;
			var ready = l.state === 'ready' || l.state === 'paused';
			var newState = 'hidden';

			if ( owner && ! failed && ! full ) {
				newState = writable && known ? 'enabled' : 'disabled';
			}

			return {
				id: Number( l.id ),
				plan: typeof l.plan === 'string' ? l.plan : '',
				countText: failed ? '' : countText( used, limit ),
				notice: typeof l.notice === 'string' ? l.notice : '',
				error: failed ? l.error : '',
				limitNote: full && ! failed ? limitReachedText( limit ) : '',
				emptyText: ready && ! failed && dashboards.length === 0 ? t( 'emptyList' ) : '',
				newState: newState,
				dashboards: dashboards.map( function ( d ) {
					var enabled = d.enabled !== false;

					return {
						id: Number( d.id ),
						name: typeof d.name === 'string' ? d.name : '',
						enabled: enabled,
						created_at: d.created_at,
						stateText: enabled ? t( 'active' ) : t( 'paused' ),
						pauseLabel: enabled ? t( 'pause' ) : t( 'enable' ),
						canOpen: true,
						canPause: writable,
						canDelete: owner,
					};
				} ),
			};
		} );
		var newOptions = groups.filter( function ( g ) { return g.newState === 'enabled'; } ).map( function ( g ) {
			return { id: g.id, plan: g.plan };
		} );

		return {
			upsell: groups.length === 0,
			upsellText: groups.length === 0 ? t( 'noPlan' ) : '',
			groups: groups,
			newOptions: newOptions,
			showNew: newOptions.length > 0,
			needSelect: newOptions.length > 1,
		};
	}

	function renameBody( name ) {
		var n = typeof name === 'string' ? name.trim() : '';

		return n === '' ? null : { name: n };
	}

	/** The PATCH body that pauses (false) or enables (true) and nothing else. */
	function pauseBody( enabled ) {
		return typeof enabled === 'boolean' ? { enabled: enabled } : null;
	}

	/** Sets license and dashboard on a copy of the template; keeps everything else (plain permalinks carry ?page_id=). */
	function dashboardUrl( template, licenseId, dashboardId ) {
		var absolute = /^[a-z][a-z0-9+.-]*:/i.test( String( template ) );
		var u = new URL( String( template ), 'http://odoowoo.invalid' );

		u.searchParams.set( 'license', String( licenseId ) );
		u.searchParams.set( 'dashboard', String( dashboardId ) );

		return absolute ? u.toString() : u.pathname + u.search + u.hash;
	}

	function formatCreated( iso, lang ) {
		var date = new Date( iso );

		if ( typeof iso !== 'string' || isNaN( date.getTime() ) ) {
			return '';
		}

		var format;

		try {
			format = new Intl.DateTimeFormat( lang || undefined, { dateStyle: 'medium' } );
		} catch ( e ) {
			format = new Intl.DateTimeFormat( undefined, { dateStyle: 'medium' } );
		}

		return format.format( date );
	}

	function deleteText( name ) {
		return core.fill( t( 'deleteBody' ), name );
	}

	function nameRefused() {
		return { ok: false, code: 'invalid_value', message: t( 'nameRequired' ), field: undefined, status: 0, data: undefined };
	}

	function base( licenseId ) {
		return 'licenses/' + encodeURIComponent( String( licenseId ) ) + '/dashboards';
	}

	/** POST a new dashboard. The name is checked here first; Django (and the plan limit) decide the rest. */
	async function create( licenseId, name ) {
		var body = renameBody( name );

		if ( body === null ) {
			return nameRefused();
		}

		var reply = await core.api( 'POST', base( licenseId ), body );

		if ( reply.ok && isObject( reply.data ) ) {
			reply.id = Number( reply.data.id );
		}

		return reply;
	}

	function setEnabled( licenseId, dashboardId, enabled ) {
		return core.api( 'PATCH', base( licenseId ) + '/' + encodeURIComponent( String( dashboardId ) ), pauseBody( enabled ) );
	}

	function remove( licenseId, dashboardId ) {
		return core.api( 'DELETE', base( licenseId ) + '/' + encodeURIComponent( String( dashboardId ) ) );
	}

	/** The licenses to show: the support summary the server embedded, otherwise GET /licenses. */
	async function loadLicenses( root ) {
		var raw = root.getAttribute( 'data-summary' );

		if ( typeof raw === 'string' && raw !== '' ) {
			try {
				var one = JSON.parse( raw );

				if ( isObject( one ) ) {
					return { ok: true, licenses: [ one ] };
				}
			} catch ( e ) {
				// Fall through to REST.
			}
		}

		var reply = await core.api( 'GET', 'licenses' );

		if ( ! reply.ok ) {
			return { ok: false, message: core.errorText( reply ), licenses: [] };
		}

		return { ok: true, licenses: Array.isArray( reply.data ) ? reply.data : [] };
	}

	/* ---- DOM (checked in the browser, Tasks 17 and 18) ---- */

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
		var statusEl = root.querySelector( '.odoowoo-list-status' );
		var bodyEl = root.querySelector( '.odoowoo-list-body' );
		var newBtn = root.querySelector( '.odoowoo-new' );
		var template = root.getAttribute( 'data-dashboard-url' ) || '';
		var preselect = Number( root.getAttribute( 'data-license' ) ) || 0;
		var lang = G.document.documentElement.getAttribute( 'lang' ) || undefined;
		var model = null;

		function say( kind, message ) {
			core.status( statusEl, kind, message );
		}

		function open( license, id ) {
			G.location.assign( dashboardUrl( template, license, id ) );
		}

		function row( group, d ) {
			var li = el( 'li', 'odoowoo-dashboard' );
			var actions = el( 'span', 'odoowoo-actions' );

			li.setAttribute( 'data-id', String( d.id ) );
			li.appendChild( el( 'strong', 'odoowoo-name', d.name ) );
			var stateEl = el( 'span', 'odoowoo-state', d.stateText );

			stateEl.setAttribute( 'data-on', d.enabled === false ? '0' : '1' );
			li.appendChild( stateEl );

			var when = formatCreated( d.created_at, lang );

			if ( when !== '' ) {
				li.appendChild( el( 'span', 'odoowoo-created', core.fill( t( 'created' ), when ) ) );
			}

			var link = el( 'a', 'odoowoo-button odoowoo-open', t( 'open' ) );
			link.setAttribute( 'href', dashboardUrl( template, group.id, d.id ) );
			link.setAttribute( 'aria-label', core.fill( t( 'openNamed' ), d.name ) );
			actions.appendChild( link );

			var pause = el( 'button', 'odoowoo-button odoowoo-button--secondary odoowoo-pause', d.pauseLabel );
			pause.type = 'button';
			pause.disabled = ! d.canPause;
			pause.setAttribute( 'aria-label', core.fill( t( d.enabled ? 'pauseNamed' : 'enableNamed' ), d.name ) );
			pause.addEventListener( 'click', function () {
				var release = core.busy( pause );

				say( 'saving', t( 'loading' ) );
				setEnabled( group.id, d.id, ! d.enabled ).then( function ( reply ) {
					release();

					if ( ! reply.ok ) {
						say( 'error', core.errorText( reply ) );
						return;
					}

					say( 'saved', '' );
					load();
				} );
			} );
			actions.appendChild( pause );

			var del = el( 'button', 'odoowoo-button odoowoo-button--danger odoowoo-delete', t( 'delete' ) );
			del.type = 'button';
			del.disabled = ! d.canDelete;
			del.setAttribute( 'aria-label', core.fill( t( 'deleteNamed' ), d.name ) );
			del.addEventListener( 'click', function () {
				core.dialog( { title: t( 'deleteTitle' ), body: deleteText( d.name ), confirm: t( 'delete' ) } ).then( function ( yes ) {
					if ( ! yes ) {
						return;
					}

					var release = core.busy( del );

					remove( group.id, d.id ).then( function ( reply ) {
						release();

						if ( ! reply.ok ) {
							say( 'error', core.errorText( reply ) );
							return;
						}

						say( 'saved', '' );
						load();
					} );
				} );
			} );
			actions.appendChild( del );
			li.appendChild( actions );

			return li;
		}

		function render() {
			bodyEl.textContent = '';
			say( 'saved', '' );
			statusEl.removeAttribute( 'data-state' );

			if ( model.upsell ) {
				bodyEl.appendChild( el( 'p', 'odoowoo-empty', model.upsellText ) );
			}

			model.groups.forEach( function ( g ) {
				var section = el( 'section', 'odoowoo-license-group' );
				var head = el( 'h3', 'odoowoo-license-plan', g.plan );

				section.setAttribute( 'data-license', String( g.id ) );

				if ( g.countText !== '' ) {
					head.appendChild( el( 'span', 'odoowoo-count', ' ' + g.countText ) );
				}

				section.appendChild( head );

				[ [ g.notice, 'odoowoo-notice' ], [ g.error, 'odoowoo-notice odoowoo-notice--error' ], [ g.limitNote, 'odoowoo-limit-note' ], [ g.emptyText, 'odoowoo-empty' ] ].forEach( function ( p ) {
					if ( p[ 0 ] !== '' ) {
						section.appendChild( el( 'p', p[ 1 ], p[ 0 ] ) );
					}
				} );

				if ( g.dashboards.length > 0 ) {
					var ul = el( 'ul', 'odoowoo-dashboards' );

					g.dashboards.forEach( function ( d ) {
						ul.appendChild( row( g, d ) );
					} );
					section.appendChild( ul );
				}

				bodyEl.appendChild( section );
			} );

			// A paused or not-ready owner still sees New, disabled, with the notice above it.
			var anyDisabled = model.groups.some( function ( g ) { return g.newState === 'disabled'; } );

			newBtn.hidden = ! ( model.showNew || anyDisabled );
			newBtn.disabled = ! model.showNew;
		}

		function newDialog() {
			var form = el( 'div', 'odoowoo-form' );
			var nameId = 'odoowoo-new-name-' + Math.random().toString( 36 ).slice( 2, 8 );
			var label = el( 'label', '', t( 'nameLabel' ) );
			var input = el( 'input' );
			var select = null;
			var problem = el( 'p', 'odoowoo-form-error' );

			label.setAttribute( 'for', nameId );
			input.type = 'text';
			input.id = nameId;
			input.maxLength = 255;
			input.setAttribute( 'autocomplete', 'off' );
			problem.setAttribute( 'role', 'alert' );

			if ( model.needSelect ) {
				var selectId = nameId + '-plan';
				var planLabel = el( 'label', '', t( 'planLabel' ) );

				planLabel.setAttribute( 'for', selectId );
				select = el( 'select' );
				select.id = selectId;
				model.newOptions.forEach( function ( o ) {
					var option = el( 'option', '', o.plan );

					option.value = String( o.id );
					option.selected = o.id === preselect;
					select.appendChild( option );
				} );
				form.appendChild( planLabel );
				form.appendChild( select );
			}

			form.appendChild( label );
			form.appendChild( input );
			form.appendChild( problem );

			return core.dialog( {
				title: t( 'newTitle' ),
				body: '',
				confirm: t( 'create' ),
				primary: true,
				content: form,
				focus: input,
				accept: async function () {
					var license = select ? Number( select.value ) : model.newOptions[ 0 ].id;
					var reply = await create( license, input.value );

					if ( ! reply.ok ) {
						problem.textContent = core.errorText( reply );
						return false;
					}

					open( license, reply.id );

					return true;
				},
			} );
		}

		async function load() {
			var result = await loadLicenses( root );

			if ( ! result.ok ) {
				say( 'error', result.message );
				return;
			}

			model = viewModel( result.licenses );
			render();
		}

		newBtn.addEventListener( 'click', function () {
			if ( model && model.showNew ) {
				newDialog();
			}
		} );

		core.whenVisible( root, load );
	}

	var api_ = {
		viewModel: viewModel,
		renameBody: renameBody,
		pauseBody: pauseBody,
		dashboardUrl: dashboardUrl,
		formatCreated: formatCreated,
		deleteText: deleteText,
		create: create,
		setEnabled: setEnabled,
		remove: remove,
		loadLicenses: loadLicenses,
	};

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api_;
	}

	if ( typeof window !== 'undefined' ) {
		window.odoowoo = Object.assign( window.odoowoo || {}, api_ );

		var start = function () {
			Array.prototype.forEach.call( window.document.querySelectorAll( '[data-odoowoo-card="dashboard-list"]' ), init );
		};

		if ( window.document.readyState === 'loading' ) {
			window.document.addEventListener( 'DOMContentLoaded', start );
		} else {
			start();
		}
	}
}() );
