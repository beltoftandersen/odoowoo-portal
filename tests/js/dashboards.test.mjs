import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const list = require( '../../assets/js/card-dashboard-list.js' );
const header = require( '../../assets/js/card-dashboard-header.js' );

import { fixture, fixtureRes } from './fixtures.mjs';

const REST = 'https://x.test/wp-json/odoowoo-portal/v1/';

// Entries are the real GET /licenses summaries (tests/js/fixtures/licenses_*.json, made by
// tests/BrowserFixturesTest.php from Routes::license_summary); tests vary numbers on a copy.
// Dashboard objects: Django DashboardSerializer (sync_app/serializers.py:9-35).
const dash = ( id, name, enabled = true ) => ( { id, name, enabled, created_at: '2026-10-21T12:00:00Z' } );
const entry = ( o = {} ) => ( {
	...fixture( 'licenses_ready' ).body.data[ 0 ], plan: 'Starter', limit: 3, used: 2,
	dashboards: [ dash( 11, 'Shop' ), dash( 12, 'Outlet', false ) ], ...o,
} );

function boot() {
	globalThis.odoowooPortal = { rest: REST, nonce: 'n1', nonceUrl: 'https://x.test/n', i18n: {} };
}

/** A success the way the routes answer it: {ok:true, data}. For values no fixture covers (a Django list of N). */
function okRes( data ) {
	return { ok: true, status: 200, headers: { get: () => null }, text: () => Promise.resolve( JSON.stringify( { ok: true, data } ) ) };
}

function fakeFetch( answers ) {
	const calls = [];
	let i = 0;
	globalThis.fetch = ( url, opts ) => {
		calls.push( { url, opts } );
		return Promise.resolve( answers[ Math.min( i++, answers.length - 1 ) ] );
	};
	return calls;
}

test( 'test_view_model_groups_by_license', () => {
	const vm = list.viewModel( [ entry(), entry( { id: 2, plan: 'Pro', limit: 10, used: 1, dashboards: [ dash( 21, 'Other' ) ] } ) ] );

	assert.equal( vm.groups.length, 2 );
	assert.equal( vm.groups[ 0 ].plan, 'Starter' );
	assert.equal( vm.groups[ 0 ].countText, '2 of 3 dashboards' );
	assert.equal( vm.groups[ 1 ].plan, 'Pro' );
	assert.equal( vm.groups[ 1 ].countText, '1 of 10 dashboards' );
	assert.equal( vm.showNew, true );
	assert.equal( vm.needSelect, true, 'Two licenses with room: the dialog asks which.' );
	assert.deepEqual( vm.newOptions.map( ( o ) => o.id ), [ 1, 2 ] );

	// Only one license with room: no select, and the full one has no New.
	const one = list.viewModel( [ entry( { used: 3 } ), entry( { id: 2, plan: 'Pro', limit: 10, used: 1 } ) ] );
	assert.equal( one.needSelect, false );
	assert.deepEqual( one.newOptions.map( ( o ) => o.id ), [ 2 ] );
	assert.equal( one.groups[ 0 ].newState, 'hidden' );
	assert.equal( one.groups[ 1 ].newState, 'enabled' );
} );

test( 'test_limit_zero_means_unlimited', () => {
	// D views.py:2618 `if limit and tenant.used() >= limit`: 0 is unlimited.
	const vm = list.viewModel( [ entry( { limit: 0, used: 4, dashboards: [ dash( 1, 'A' ), dash( 2, 'B' ), dash( 3, 'C' ), dash( 4, 'D' ) ] } ) ] );

	assert.equal( vm.groups[ 0 ].countText, '4 dashboards' );
	assert.equal( vm.groups[ 0 ].limitNote, '' );
	assert.equal( vm.showNew, true );
	assert.ok( ! /of 0/.test( vm.groups[ 0 ].countText ) );

	assert.equal( list.viewModel( [ entry( { limit: 0, used: 1, dashboards: [ dash( 1, 'A' ) ] } ) ] ).groups[ 0 ].countText, '1 dashboard' );
	assert.equal( list.viewModel( [ entry( { limit: 1, used: 0, dashboards: [] } ) ] ).groups[ 0 ].countText, '0 of 1 dashboard' );
} );

test( 'test_empty_state_limit_note_and_upsell', () => {
	const empty = list.viewModel( [ entry( { used: 0, dashboards: [] } ) ] );
	assert.equal( empty.groups[ 0 ].emptyText, 'You have no dashboards yet. Create one to connect a store.' );
	assert.equal( empty.groups[ 0 ].dashboards.length, 0 );

	const full = list.viewModel( [ entry( { limit: 2, used: 2 } ) ] );
	assert.equal( full.groups[ 0 ].limitNote, 'You have reached your plan limit of 2 dashboards.' );
	assert.equal( full.groups[ 0 ].newState, 'hidden' );
	assert.equal( full.showNew, false );
	assert.equal( list.viewModel( [ entry( { limit: 1, used: 1 } ) ] ).groups[ 0 ].limitNote, 'You have reached your plan limit of 1 dashboard.' );
	assert.equal( list.viewModel( [ entry( { limit: 5, used: 2 } ) ] ).groups[ 0 ].limitNote, '' );

	const none = list.viewModel( [] );
	assert.equal( none.upsell, true );
	assert.equal( none.upsellText, 'You do not have an OdooWoo plan yet.' );
	assert.equal( none.groups.length, 0 );
	assert.equal( none.showNew, false );
	assert.equal( list.viewModel( [ entry() ] ).upsell, false );
} );

test( 'test_paused_license_keeps_delete_only', () => {
	// The real paused summary (Context::state()/notice(), Context.php:221-232).
	const paused = fixture( 'licenses_paused' ).body.data[ 0 ];
	const notice = 'Your OdooWoo plan is paused. Renew in My Account → Subscriptions';
	assert.equal( paused.notice, notice );
	assert.equal( paused.can_write, false );
	const owner = list.viewModel( [ paused ] ).groups[ 0 ];

	assert.equal( owner.notice, notice );
	assert.equal( owner.newState, 'disabled' );
	for ( const d of owner.dashboards ) {
		assert.equal( d.canDelete, true );
		assert.equal( d.canPause, false );
		assert.equal( d.canOpen, true );
	}
	assert.equal( list.viewModel( [ paused ] ).showNew, false, 'A disabled New is not one the dialog can use.' );

	// Support: nothing that changes anything is enabled.
	const support = list.viewModel( [ entry( { mode: 'support', can_write: false, notice: 'Viewing as support (read-only)' } ) ] ).groups[ 0 ];
	assert.equal( support.newState, 'hidden' );
	for ( const d of support.dashboards ) {
		assert.equal( d.canDelete, false );
		assert.equal( d.canPause, false );
		assert.equal( d.canOpen, true );
	}

	// A writer can do it all.
	const rw = list.viewModel( [ entry() ] ).groups[ 0 ];
	assert.equal( rw.dashboards[ 0 ].canPause, true );
	assert.equal( rw.dashboards[ 0 ].canDelete, true );
	assert.equal( rw.dashboards[ 0 ].stateText, 'Active' );
	assert.equal( rw.dashboards[ 1 ].stateText, 'Paused' );
	assert.equal( rw.dashboards[ 1 ].pauseLabel, 'Enable' );
	assert.equal( rw.dashboards[ 0 ].pauseLabel, 'Pause' );
} );

test( 'test_list_error_and_unready_license_show_no_actions', () => {
	// Real states are ready, paused, getting-ready and unavailable (Context::state()).
	const ready = fixture( 'licenses_getting_ready' ).body.data[ 0 ];
	assert.equal( ready.state, 'getting-ready' );
	const g = list.viewModel( [ ready ] ).groups[ 0 ];
	assert.equal( g.notice, 'Getting ready…' );
	assert.equal( g.newState, 'disabled' );
	assert.equal( g.countText, '' );
	assert.equal( g.emptyText, '', 'No "no dashboards yet" while the license is getting ready.' );

	const bad = list.viewModel( [ entry( { limit: null, used: null, dashboards: [], error: 'Something went wrong' } ) ] ).groups[ 0 ];
	assert.equal( bad.error, 'Something went wrong' );
	assert.equal( bad.newState, 'hidden' );
} );

test( 'test_odoowoo_mistake_dashboard_name_shown_and_rename', async () => {
	assert.deepEqual( header.renameBody( ' Shop ' ), { name: 'Shop' } );
	assert.equal( header.renameBody( '   ' ), null );
	assert.equal( header.renameBody( '' ), null );

	boot();
	const nameEl = { textContent: 'Old' };

	// D serializers.py:5 NAME_REQUIRED, refused before any request.
	let calls = fakeFetch( [ fixtureRes( 'dashboard_rename_success' ) ] );
	const refused = await header.rename( 1, 9, '  ', nameEl );
	assert.equal( refused.ok, false );
	assert.equal( refused.code, 'invalid_value' );
	assert.equal( refused.message, 'Give the dashboard a name.' );
	assert.equal( calls.length, 0 );
	assert.equal( nameEl.textContent, 'Old' );

	// The heading comes from the PATCH reply (D views.py DashboardDetailView.patch).
	calls = fakeFetch( [ fixtureRes( 'dashboard_rename_success' ) ] );
	const ok = await header.rename( 1, 9, ' Shop ', nameEl );
	assert.equal( ok.ok, true );
	assert.equal( nameEl.textContent, 'Outlet', 'What Django kept, from the reply.' );
	assert.equal( calls[ 0 ].opts.method, 'PATCH' );
	assert.equal( calls[ 0 ].url, REST + 'licenses/1/dashboards/9' );
	assert.deepEqual( JSON.parse( calls[ 0 ].opts.body ), { name: 'Shop' } );

	// A refusal leaves the heading alone (D views.py:151-157 invalid_value with field name).
	fakeFetch( [ fixtureRes( 'dashboard_name_invalid' ) ] );
	const bad = await header.rename( 1, 9, 'x'.repeat( 300 ), nameEl );
	assert.equal( bad.ok, false );
	assert.equal( bad.field, 'name' );
	assert.equal( nameEl.textContent, 'Outlet' );

	// HTML in a name is text, never markup (a made-up name in an otherwise real reply).
	fakeFetch( [ okRes( dash( 9, '<img src=x onerror=alert(1)>' ) ) ] );
	await header.rename( 1, 9, 'whatever', nameEl );
	assert.equal( nameEl.textContent, '<img src=x onerror=alert(1)>' );

	// A success reply without a name is not "Saved" with a stale heading: it is the generic failure.
	fakeFetch( [ fixtureRes( 'dashboard_rename_no_name' ) ] );
	const stale = await header.rename( 1, 9, 'Shop', nameEl );
	assert.equal( stale.ok, false );
	assert.equal( stale.message, 'Something went wrong on our side. Please try again in a moment.' );
	assert.equal( nameEl.textContent, '<img src=x onerror=alert(1)>', 'The heading is not blanked.' );
} );

test( 'test_header_view_from_dashboard', () => {
	assert.deepEqual( header.headerView( dash( 9, 'Shop' ) ), { name: 'Shop', stateText: 'Active' } );
	assert.deepEqual( header.headerView( dash( 9, 'Shop', false ) ), { name: 'Shop', stateText: 'Paused' } );
	assert.deepEqual( header.headerView( {} ), { name: '', stateText: '' } );
} );

test( 'test_pause_enable_patches_enabled_only', async () => {
	assert.deepEqual( list.pauseBody( false ), { enabled: false } );
	assert.deepEqual( list.pauseBody( true ), { enabled: true } );
	assert.deepEqual( Object.keys( list.pauseBody( false ) ), [ 'enabled' ] );
	assert.equal( list.pauseBody( 'no' ), null, 'Only a real boolean.' );
	assert.equal( list.pauseBody( undefined ), null );

	boot();
	const calls = fakeFetch( [ okRes( dash( 11, 'Shop', false ) ) ] );
	const r = await list.setEnabled( 1, 11, false );
	assert.equal( r.ok, true );
	assert.equal( calls[ 0 ].opts.method, 'PATCH' );
	assert.equal( calls[ 0 ].url, REST + 'licenses/1/dashboards/11' );
	assert.deepEqual( JSON.parse( calls[ 0 ].opts.body ), { enabled: false } );
} );

test( 'test_dashboard_url_fills_license_and_dashboard', () => {
	const tpl = 'https://beltoft.test/odoowoo/dashboard/?license=0&dashboard=0&tab=connections';
	const u = new URL( list.dashboardUrl( tpl, 7, 9 ) );

	assert.equal( u.searchParams.get( 'license' ), '7' );
	assert.equal( u.searchParams.get( 'dashboard' ), '9' );
	assert.equal( u.searchParams.get( 'tab' ), 'connections' );
	assert.equal( u.pathname, '/odoowoo/dashboard/' );

	// Plain permalinks already carry ?page_id=.
	const plain = new URL( list.dashboardUrl( 'https://beltoft.test/?page_id=12&license=0&dashboard=0&tab=connections', 7, 9 ) );
	assert.equal( plain.searchParams.get( 'page_id' ), '12' );
	assert.equal( plain.searchParams.get( 'license' ), '7' );
	assert.equal( plain.searchParams.get( 'dashboard' ), '9' );
	assert.equal( plain.searchParams.get( 'tab' ), 'connections' );
	assert.equal( ( list.dashboardUrl( tpl, 7, 9 ).match( /license=/g ) || [] ).length, 1 );

	// The template itself is left alone; a relative template stays relative.
	assert.equal( tpl.includes( 'license=0' ), true );
	assert.equal( list.dashboardUrl( '/app/?page_id=3&tab=connections', 1, 2 ), '/app/?page_id=3&tab=connections&license=1&dashboard=2' );
} );

test( 'test_format_created_uses_the_page_language', () => {
	assert.equal( list.formatCreated( '2026-10-21T12:00:00Z', 'en' ), 'Oct 21, 2026' );
	const da = list.formatCreated( '2026-10-21T12:00:00Z', 'da' );
	assert.ok( da.includes( '2026' ) && da.includes( '21' ), 'Any ICU: the date is there.' );
	assert.equal( list.formatCreated( 'garbage', 'en' ), '' );
	assert.equal( list.formatCreated( undefined, 'en' ), '' );
	assert.ok( list.formatCreated( '2026-10-21T12:00:00Z', 'not a language!!' ).includes( '2026' ), 'A bad language tag falls back to the default.' );
} );

test( 'test_create_posts_name_only_and_returns_the_new_id', async () => {
	boot();
	const calls = fakeFetch( [ okRes( dash( 31, 'New' ) ) ] );
	const r = await list.create( 1, '  New ' );

	assert.equal( r.ok, true );
	assert.equal( r.id, 31 );
	assert.equal( calls[ 0 ].opts.method, 'POST' );
	assert.equal( calls[ 0 ].url, REST + 'licenses/1/dashboards' );
	assert.deepEqual( JSON.parse( calls[ 0 ].opts.body ), { name: 'New' } );

	const none = fakeFetch( [ okRes( dash( 1, 'x' ) ) ] );
	const empty = await list.create( 1, '   ' );
	assert.equal( empty.ok, false );
	assert.equal( empty.message, 'Give the dashboard a name.' );
	assert.equal( none.length, 0 );

	// D views.py:2619 is 403 at Django; the browser gets 409 and the card keys on the code.
	fakeFetch( [ fixtureRes( 'dashboard_limit_reached' ) ] );
	const limit = await list.create( 1, 'Again' );
	assert.equal( limit.ok, false );
	assert.equal( limit.code, 'dashboard_limit_reached' );
	assert.equal( limit.message, 'You have reached your plan limit of 1 dashboard.' );
} );

test( 'test_delete_sends_delete_and_accepts_the_empty_reply', async () => {
	boot();
	const calls = fakeFetch( [ okRes( {} ) ] );
	const r = await list.remove( 1, 11 );

	assert.equal( r.ok, true );
	assert.equal( calls[ 0 ].opts.method, 'DELETE' );
	assert.equal( calls[ 0 ].url, REST + 'licenses/1/dashboards/11' );
	assert.equal( calls[ 0 ].opts.body, undefined );
	assert.equal( list.deleteText( 'Shop <b>' ), 'Delete Shop <b>? This removes its settings and logs. Stores already connected stop syncing.' );
	assert.equal( list.deleteText( '$& x' ), 'Delete $& x? This removes its settings and logs. Stores already connected stop syncing.' );
} );

test( 'test_list_loader_uses_the_support_summary_and_rest_for_owners', async () => {
	boot();
	const calls = fakeFetch( [ okRes( [ entry() ] ) ] );

	const owner = await list.loadLicenses( { getAttribute: () => null } );
	assert.equal( owner.ok, true );
	assert.equal( owner.licenses.length, 1 );
	assert.equal( calls.length, 1 );
	assert.equal( calls[ 0 ].url, REST + 'licenses' );

	const summary = JSON.stringify( entry( { mode: 'support', can_write: false } ) );
	const c2 = fakeFetch( [ okRes( [] ) ] );
	const support = await list.loadLicenses( { getAttribute: ( k ) => ( k === 'data-summary' ? summary : null ) } );
	assert.equal( support.ok, true );
	assert.equal( support.licenses[ 0 ].mode, 'support' );
	assert.equal( c2.length, 0, 'No request: the server embedded it.' );

	// A bad summary falls back to REST rather than showing nothing.
	const c3 = fakeFetch( [ okRes( [ entry() ] ) ] );
	const bad = await list.loadLicenses( { getAttribute: ( k ) => ( k === 'data-summary' ? '{not json' : null ) } );
	assert.equal( bad.licenses.length, 1 );
	assert.equal( c3.length, 1 );

	// A failed read gives the message, no licenses.
	fakeFetch( [ fixtureRes( 'django_garbage' ) ] );
	const down = await list.loadLicenses( { getAttribute: () => null } );
	assert.equal( down.ok, false );
	assert.equal( down.message, 'Something went wrong on our side. Please try again in a moment.' );
} );

test( 'test_header_failure_only_404_is_not_found', () => {
	// Real replies: dashboard_not_found (D errors.py:46, middleware.py:78), Django down, a refusal.
	const missing = header.headerFailure( { ok: false, ...fixture( 'dashboard_not_found' ).body, status: 404 } );
	assert.equal( missing.notFound, true );
	assert.equal( missing.heading, 'This dashboard was not found.' );
	assert.equal( missing.retry, false );

	// A bare 404 (no code) is also not found; the code alone is enough too.
	assert.equal( header.headerFailure( { ok: false, code: 'x', status: 404 } ).notFound, true );
	assert.equal( header.headerFailure( { ok: false, code: 'dashboard_not_found', status: 200 } ).notFound, true );

	for ( const name of [ 'django_garbage', 'django_401', 'django_403', 'tenant_paused', 'task_unavailable', 'session_nonce' ] ) {
		const f = fixture( name );
		const reply = { ok: false, code: f.body.code, message: f.body.message, field: undefined, status: f.status };
		const r = header.headerFailure( reply );

		assert.equal( r.notFound, false, name );
		assert.equal( r.heading, 'Dashboard', name );
		assert.equal( r.message, f.body.message, name );
		assert.equal( r.retry, true, name );
	}
} );

test( 'test_tenant_paused_409_is_shown_for_pause_and_rename', async () => {
	// Django middleware.py:57-58, 81: a paused tenant refuses writes with 409 tenant_paused.
	const want = fixture( 'tenant_paused' );
	assert.equal( want.status, 409 );
	assert.equal( want.body.code, 'tenant_paused' );

	boot();
	fakeFetch( [ fixtureRes( 'tenant_paused' ) ] );
	const p = await list.setEnabled( 1, 11, false );
	assert.equal( p.ok, false );
	assert.equal( p.code, 'tenant_paused' );
	assert.equal( p.message, want.body.message );

	const nameEl = { textContent: 'Shop' };
	fakeFetch( [ fixtureRes( 'tenant_paused' ) ] );
	const r = await header.rename( 1, 9, 'Outlet', nameEl );
	assert.equal( r.ok, false );
	assert.equal( r.code, 'tenant_paused' );
	assert.equal( r.message, want.body.message );
	assert.equal( nameEl.textContent, 'Shop' );
} );

test( 'test_real_ready_summary_through_the_view_model', () => {
	const g = list.viewModel( fixture( 'licenses_ready' ).body.data ).groups[ 0 ];
	assert.equal( g.countText, '1 of 3 dashboards' );
	assert.equal( g.newState, 'enabled' );
	assert.equal( g.dashboards[ 0 ].name, 'Shop' );
	assert.equal( g.dashboards[ 0 ].canPause, true );
} );
