import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

import { fixture, fixtureRes } from './fixtures.mjs';

const require = createRequire( import.meta.url );
const manual = require( '../../assets/js/card-manual-sync.js' );
const logs = require( '../../assets/js/card-sync-logs.js' );
const tasks = require( '../../assets/js/tasks.js' );
const core = require( '../../assets/js/core.js' );

// Every reply is a file produced by the real routes (tests/BrowserFixturesTest.php, sync_fixtures(),
// each Django body cited there).
const REST = 'https://x.test/wp-json/odoowoo-portal/v1/';
const ctx = { licenseId: 4, dashboardId: 7 };
const P = 'licenses/4/dashboards/7/';

function boot() {
	globalThis.odoowooPortal = { rest: REST, nonce: 'n1', nonceUrl: 'https://x.test/n', i18n: {} };
}

function fakeFetch( names ) {
	const calls = [];
	let i = 0;
	globalThis.fetch = ( url, opts ) => {
		calls.push( { url: String( url ).replace( REST, '' ), method: opts.method, body: opts.body === undefined ? undefined : JSON.parse( opts.body ) } );
		return Promise.resolve( fixtureRes( names[ Math.min( i++, names.length - 1 ) ] ) );
	};
	return calls;
}

const reply = ( name ) => {
	const { status, body } = fixture( name );
	return body.ok === true ? { ok: true, data: body.data, status } : { ok: false, code: body.code, message: body.message, field: body.field, status };
};

test( 'test_sync_body_per_kind', () => {
	const input = { productIds: '1, 2,,x 3', orderIds: '', from: '', to: '' };

	for ( const kind of [ 'products', 'stock', 'prices', 'barcodes' ] ) {
		assert.deepEqual( manual.syncBody( kind, input ), { ok: true, body: { product_ids: [ 1, 2, 3 ] } }, kind );
		// An empty field is "all" (the server leaves the list out; prices and barcodes treat it as everything).
		assert.deepEqual( manual.syncBody( kind, { productIds: '  ' } ), { ok: true, body: { product_ids: [] } }, kind );
	}

	assert.deepEqual( manual.syncBody( 'categories', input ), { ok: true, body: undefined }, 'No body.' );
	assert.deepEqual( manual.syncBody( 'brands', input ), { ok: true, body: undefined }, 'No body.' );

	// Orders: ids, or both dates; ids win (the server does the same).
	assert.deepEqual( manual.syncBody( 'orders', { orderIds: '10 11;11', from: '2026-01-01', to: '2026-01-02' } ), { ok: true, body: { order_ids: [ 10, 11 ] } } );
	assert.deepEqual( manual.syncBody( 'orders', { orderIds: '', from: '2026-01-01', to: '2026-01-31' } ), { ok: true, body: { from_date: '2026-01-01', to_date: '2026-01-31' } } );
	assert.equal( 'timezone' in manual.syncBody( 'orders', { orderIds: '', from: '2026-01-01', to: '2026-01-31' } ).body, false, 'No timezone is sent (pending decision).' );

	assert.deepEqual( manual.cleanIds( '1, 2,,x 3 3 -4 0 1e3 99999999999999999' ), { ids: [ 1, 2, 3 ], tooMany: false } );
	assert.equal( manual.cleanIds( Array.from( { length: 501 }, ( _, i ) => i + 1 ).join( ',' ) ).tooMany, true );
	assert.equal( manual.cleanIds( Array.from( { length: 500 }, ( _, i ) => i + 1 ).join( ',' ) ).tooMany, false );
} );

test( 'test_sync_refusals_say_what_the_server_says', () => {
	const said = ( name ) => fixture( name ).body.message;
	const refused = ( kind, input ) => manual.syncBody( kind, input );

	assert.deepEqual( refused( 'products', { productIds: 'x, -1' } ), { ok: false, field: 'product_ids', message: said( 'sync_products_no_valid_id' ) } );
	assert.deepEqual( refused( 'stock', { productIds: Array.from( { length: 501 }, ( _, i ) => i + 1 ).join( ' ' ) } ), { ok: false, field: 'product_ids', message: said( 'sync_products_too_many' ) } );
	assert.deepEqual( refused( 'orders', { orderIds: '', from: '', to: '' } ), { ok: false, field: 'order_ids', message: said( 'sync_orders_nothing_chosen' ) } );
	assert.deepEqual( refused( 'orders', { orderIds: 'abc', from: '', to: '' } ), { ok: false, field: 'order_ids', message: said( 'sync_products_no_valid_id' ) } );
	assert.deepEqual( refused( 'orders', { orderIds: '', from: '2026-02-01', to: '2026-01-01' } ), { ok: false, field: 'from_date', message: said( 'sync_orders_reversed' ) } );
	assert.deepEqual( refused( 'orders', { orderIds: '', from: '2026-02-01', to: '' } ), { ok: false, field: 'from_date', message: said( 'sync_orders_one_date' ) } );
	assert.equal( fixture( 'sync_orders_one_date' ).body.field, 'from_date' );
	assert.equal( fixture( 'sync_orders_nothing_chosen' ).body.field, 'order_ids' );
} );

test( 'test_result_line_has_text_for_every_state', () => {
	boot();

	const line = ( name ) => manual.resultLine( tasks.view( fixture( name ).body.data ) );

	assert.equal( line( 'task_running_30_of_120' ), 'Running: 30 of 120', 'The portal\'s own text, never "Task is progress."' );
	assert.equal( line( 'task_running_start' ), 'Starting…' );
	assert.match( line( 'task_done' ), /^Completed/ );
	assert.match( line( 'task_skipped' ), /^Skipped/ );
	assert.match( line( 'task_failed' ), /^Failed/ );
	assert.doesNotMatch( line( 'task_skipped' ), /Completed/, 'Skipped is never "completed".' );
	assert.doesNotMatch( line( 'task_failed' ), /Completed/, 'Failed is never "completed".' );
	assert.doesNotMatch( line( 'task_running_start' ), /progress/i );
	assert.equal( manual.resultLine( tasks.view( { state: 'weird' } ) ).startsWith( 'Failed' ), true );
} );

test( 'test_result_items_list_the_items_that_did_not_sync', () => {
	boot();

	const v = tasks.view( fixture( 'task_done_with_results' ).body.data );
	const items = manual.resultItems( v );

	assert.deepEqual( items, [
		{ label: 'Red mug (MUG-2)', status: 'skipped', text: 'No SKU' },
		{ label: 'Green mug (MUG-3)', status: 'error', text: 'This item was not synced. See the sync log for details.' },
	] );
	assert.deepEqual( manual.resultItems( tasks.view( fixture( 'task_done' ).body.data ) ), [] );
	assert.deepEqual( manual.resultItems( { results: [ { order: '1051', status: 'error', message: 'x'.repeat( 5 ) } ] } ), [ { label: '1051', status: 'error', text: 'xxxxx' } ] );
} );

test( 'test_dashboard_paused_message_is_shown_with_back_link', () => {
	const paused = manual.refusalView( reply( 'sync_dashboard_paused' ) );

	assert.equal( paused.message, 'This dashboard is paused. Enable it to run a sync.' );
	assert.equal( paused.back, true );
	assert.equal( paused.field, '' );

	const tenant = manual.refusalView( reply( 'sync_tenant_paused' ) );

	assert.equal( tenant.message, 'Your OdooWoo plan is paused. Renew it to make changes or run syncs.' );
	assert.equal( tenant.back, false );

	const brands = manual.refusalView( reply( 'sync_brands_not_in_plan' ) );

	assert.equal( brands.message, 'Brand sync is not included in your plan.' );
	assert.equal( brands.field, 'enable_brand_sync' );
	assert.equal( brands.back, false );

	boot();
	assert.equal( manual.refusalView( { ok: false, code: 'server_error', message: 'Something went wrong on our side. Please try again in a moment.' } ).message, 'Something went wrong on our side. Please try again in a moment.' );
} );

test( 'test_brand_section_follows_the_plan', () => {
	const licenses = fixture( 'licenses_ready' ).body.data;

	assert.equal( manual.brandsAvailable( licenses, licenses[ 0 ].id ), false, 'brand_sync false hides the section.' );
	assert.equal( manual.brandsAvailable( [ Object.assign( {}, licenses[ 0 ], { brand_sync: true } ) ], licenses[ 0 ].id ), true );
	assert.equal( manual.brandsAvailable( licenses, 999 ), true, 'Unknown: Django decides (403 plan_feature_unavailable).' );
	assert.equal( manual.brandsAvailable( [], 1 ), true );
	assert.equal( manual.brandsAvailable( undefined, 1 ), true );
} );

test( 'test_start_posts_the_cleaned_body_and_returns_the_task', async () => {
	boot();

	const calls = fakeFetch( [ 'sync_started' ] );
	const out = await manual.start( ctx, 'products', { productIds: '1, 2,,x 3' } );

	assert.equal( out.ok, true );
	assert.equal( out.taskId, 'tk-1' );
	assert.deepEqual( calls, [ { url: P + 'trigger-sync', method: 'POST', body: { product_ids: [ 1, 2, 3 ] } } ] );

	const nobody = fakeFetch( [ 'sync_started' ] );

	await manual.start( ctx, 'categories', {} );
	assert.deepEqual( nobody, [ { url: P + 'trigger-category-sync', method: 'POST', body: undefined } ] );
} );

test( 'test_start_refuses_a_bad_input_without_a_call', async () => {
	boot();

	const calls = fakeFetch( [ 'sync_started' ] );
	const out = await manual.start( ctx, 'orders', { orderIds: '', from: '2026-02-01', to: '2026-01-01' } );

	assert.equal( out.ok, false );
	assert.equal( out.reply.message, fixture( 'sync_orders_reversed' ).body.message );
	assert.equal( out.reply.field, 'from_date' );
	assert.deepEqual( calls, [] );
} );

test( 'test_start_shows_a_django_refusal_as_is', async () => {
	boot();
	fakeFetch( [ 'sync_dashboard_paused' ] );

	const out = await manual.start( ctx, 'categories', {} );

	assert.equal( out.ok, false );
	assert.equal( out.reply.code, 'dashboard_paused' );
	assert.equal( out.reply.message, 'This dashboard is paused. Enable it to run a sync.' );
} );

test( 'test_odoowoo_mistake_running_task_resumed_after_reload', async () => {
	boot();

	// A reload mid-run: the first call is sync/active, then the task is polled to its end.
	const calls = fakeFetch( [ 'sync_active_task', 'task_running_1_of_10', 'task_running_2_of_10', 'task_done' ] );
	const seen = [];
	const out = await manual.resume( ctx, {
		el: { isConnected: true },
		sleep: () => Promise.resolve(),
		onUpdate: ( v ) => seen.push( v.text ),
	} );

	assert.equal( calls[ 0 ].url, P + 'sync/active', 'The first call is the active read.' );
	assert.equal( calls[ 0 ].method, 'GET' );
	assert.deepEqual( calls.slice( 1 ).map( ( c ) => c.url ), [ P + 'task-status/tk-1', P + 'task-status/tk-1', P + 'task-status/tk-1' ] );
	assert.deepEqual( seen, [ 'Running: 1 of 10', 'Running: 2 of 10', 'Completed' ] );
	assert.equal( out.reason, 'done' );
	assert.equal( out.kind, 'products' );
} );

test( 'test_resume_with_nothing_running_makes_one_call', async () => {
	boot();

	const calls = fakeFetch( [ 'sync_active_none' ] );
	const out = await manual.resume( ctx, { el: { isConnected: true }, sleep: () => Promise.resolve() } );

	assert.equal( calls.length, 1 );
	assert.equal( out, null );
} );

test( 'test_resume_stops_when_the_card_is_gone', async () => {
	boot();

	const calls = fakeFetch( [ 'sync_active_task', 'task_running_1_of_10' ] );
	const el = { isConnected: true };
	const out = await manual.resume( ctx, {
		el,
		sleep: () => Promise.resolve(),
		onUpdate: () => {
			el.isConnected = false;
		},
	} );

	assert.equal( out.reason, 'cancelled' );
	assert.equal( calls.length, 2, 'No polling after the card left the page.' );
} );

test( 'test_resume_ends_on_an_unknown_task', async () => {
	boot();

	const calls = fakeFetch( [ 'sync_active_task', 'task_django_not_found' ] );
	const out = await manual.resume( ctx, { el: { isConnected: true }, sleep: () => Promise.resolve() } );

	assert.equal( out.reason, 'unknown' );
	assert.equal( calls.length, 2 );
} );

test( 'test_log_row_uses_status_text_and_unknown_gets_text', () => {
	boot();

	const rows = fixture( 'order_syncs_list' ).body.data.items;
	const views = rows.map( ( r ) => logs.rowView( r ) );

	assert.deepEqual( views.map( ( v ) => v.badge ), [ 'Synced', 'Failed', 'Warning', 'Failed', 'Unknown' ] );
	assert.deepEqual( views.map( ( v ) => v.raw ), [ 'success', 'error', 'warning', 'failed', 'pending' ], 'The raw status is kept for a title only.' );
	assert.equal( views[ 0 ].woo, 'WS_1051' );
	assert.equal( views[ 0 ].odoo, 'Odoo S00042' );
	assert.equal( views[ 1 ].odoo, 'Odoo N/A', 'No Odoo order.' );
	assert.equal( views[ 0 ].hasLog, false );
	assert.equal( views[ 1 ].hasLog, true );

	// Missing or odd status text is still text, never blank.
	assert.equal( logs.rowView( { woocommerce_order_id: '1', status: 'x' } ).badge, 'Unknown' );
	assert.equal( logs.rowView( { woocommerce_order_id: '1', status: 'success', status_text: '' } ).badge, 'Unknown' );
	assert.equal( logs.rowView( null ).badge, 'Unknown' );
} );

test( 'test_log_with_a_traceback_or_a_fault_is_replaced_by_the_fixed_message', () => {
	boot();

	const fixed = 'This item was not synced. See the sync log for details.';
	const rows = fixture( 'order_syncs_list' ).body.data.items;

	assert.equal( logs.safeLog( rows[ 3 ].log ), fixed, 'A Fault with a traceback.' );
	assert.equal( logs.safeLog( 'Traceback (most recent call last):\n  File "x.py"' ), fixed );
	assert.equal( logs.safeLog( 'Odoo said: Fault 2: boom' ), fixed );
	assert.equal( logs.safeLog( rows[ 1 ].log ), rows[ 1 ].log, 'A plain refusal is shown as written.' );
	assert.equal( logs.safeLog( 'The default journal is missing. Faulty lines skipped.' ), 'The default journal is missing. Faulty lines skipped.' );
	assert.equal( logs.safeLog( '' ), '' );
	assert.equal( logs.safeLog( undefined ), '' );
} );

test( 'test_log_list_is_built_with_details_and_text_only', () => {
	boot();

	const made = [];
	const node = ( tag ) => {
		const n = { tag, children: [], attrs: {}, _text: '' };
		Object.defineProperty( n, 'innerHTML', { set() { throw new Error( 'innerHTML is never used' ); }, get() { return ''; } } );
		Object.defineProperty( n, 'textContent', { set( v ) { n._text = String( v ); n.children = []; }, get() { return n._text; } } );
		n.appendChild = ( c ) => { n.children.push( c ); return c; };
		n.setAttribute = ( k, v ) => { n.attrs[ k ] = String( v ); };
		made.push( n );
		return n;
	};
	const doc = { createElement: node };
	const list = node( 'ul' );

	logs.render( doc, list, fixture( 'order_syncs_list' ).body.data.items );

	assert.equal( list.children.length, 5 );

	const withLog = list.children[ 1 ];
	const details = withLog.children.find( ( c ) => c.tag === 'details' );

	assert.ok( details, 'A row with a log has <details>.' );
	assert.equal( details.children[ 0 ].tag, 'summary' );
	assert.ok( details.children.some( ( c ) => c.tag === 'pre' && c.textContent.startsWith( 'Order 1050 was not created' ) ) );
	assert.equal( list.children[ 0 ].children.some( ( c ) => c.tag === 'details' ), false, 'No log, nothing to expand.' );

	const fault = list.children[ 3 ].children.find( ( c ) => c.tag === 'details' );

	assert.ok( fault.children.some( ( c ) => c.tag === 'pre' && c.textContent === 'This item was not synced. See the sync log for details.' ) );

	const badge = ( row ) => JSON.stringify( row ).includes( '"Unknown"' );

	assert.equal( badge( list.children[ 4 ] ), true );
	assert.ok( JSON.stringify( list.children[ 1 ] ).includes( 'title' ), 'The raw status sits in a title.' );
} );

test( 'test_logs_load_reads_the_list_as_given', async () => {
	boot();

	const calls = fakeFetch( [ 'order_syncs_list' ] );
	const out = await logs.load( ctx );

	assert.equal( out.ok, true );
	assert.equal( out.items.length, 5 );
	assert.equal( out.items[ 0 ].woocommerce_order_id, 'WS_1051', 'Django\'s order is kept (newest first).' );
	assert.deepEqual( calls, [ { url: P + 'order-syncs', method: 'GET', body: undefined } ] );

	fakeFetch( [ 'order_syncs_empty' ] );
	assert.deepEqual( ( await logs.load( ctx ) ).items, [] );

	fakeFetch( [ 'django_garbage' ] );

	const failed = await logs.load( ctx );

	assert.equal( failed.ok, false );
	assert.equal( failed.reply.message, 'Something went wrong on our side. Please try again in a moment.' );
	assert.ok( core );
} );
