import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

import { fixtureRes } from './fixtures.mjs';

const require = createRequire( import.meta.url );
const tasks = require( '../../assets/js/tasks.js' );
const core = require( '../../assets/js/core.js' );

// Replies are what core.api() makes of the real routes' answers (fixtures), not hand-written objects.
globalThis.odoowooPortal = { rest: 'https://x.test/v1/', nonce: 'n1', nonceUrl: 'https://x.test/n', i18n: {} };

async function apiReply( name ) {
	globalThis.fetch = () => Promise.resolve( fixtureRes( name ) );

	return core.api( 'GET', 'licenses/1/dashboards/7/task-status/tk-1' );
}

const R = {};

for ( const name of [ 'task_running_start', 'task_running_1_of_10', 'task_running_2_of_10', 'task_running_30_of_120', 'task_done', 'task_skipped', 'task_failed', 'task_django_not_found', 'task_unavailable', 'task_unknown', 'session_login' ] ) {
	R[ name ] = await apiReply( name );
}

const MIN = 60 * 1000;

/** A fake clock whose sleep advances time and records every wait. */
function clock() {
	const c = { t: 1000, sleeps: [] };
	c.now = () => c.t;
	c.sleep = ( ms ) => {
		c.sleeps.push( ms );
		c.t += ms;
		return Promise.resolve();
	};
	return c;
}

/** A fetch that answers from a list; the last answer repeats. */
function answers( list ) {
	let i = 0;
	const f = () => Promise.resolve( list[ Math.min( i++, list.length - 1 ) ] );
	f.calls = () => i;
	return f;
}

const running = ( current, total ) => R[ current === 0 ? 'task_running_start' : `task_running_${ current }_of_${ total }` ];
const finished = ( state ) => R[ 'task_' + state ];
const failure = ( status, code ) => ( {
	task_not_found: R.task_django_not_found,
	task_status_unavailable: R.task_unavailable,
	// The portal's code on a status Django would never send: the code decides, not the status.
	odoowoo_portal_task_unknown: { ...R.task_unknown, status: 409 },
	session: R.session_login,
}[ code ] );

async function run( list, extra = {} ) {
	const c = clock();
	const f = answers( list );
	const updates = [];
	const done = [];
	const result = await tasks.poll( {
		fetch: f,
		onUpdate: ( v ) => updates.push( v ),
		onDone: ( reason, v ) => done.push( [ reason, v ] ),
		sleep: c.sleep,
		now: c.now,
		...extra,
	} );
	return { c, f, updates, done, result };
}

test( 'test_odoowoo_mistake_polling_stops_on_unknown_and_finished', async () => {
	for ( const state of [ 'done', 'skipped', 'failed' ] ) {
		const r = await run( [ finished( state ) ] );
		assert.equal( r.f.calls(), 1, state );
		assert.deepEqual( r.c.sleeps, [], state );
		assert.equal( r.result.reason, state );
		assert.equal( r.done.length, 1 );
		assert.equal( r.done[ 0 ][ 0 ], state );
	}

	// A state nobody knows is a failure, and it stops.
	const odd = await run( [ { ...R.task_done, data: { ...R.task_done.data, state: 'weird' } } ] );
	assert.equal( odd.f.calls(), 1 );
	assert.equal( odd.result.reason, 'failed' );

	// Django's own 404 and the portal's unknown-task code stop at once.
	const nf = await run( [ failure( 404, 'task_not_found' ) ] );
	assert.equal( nf.f.calls(), 1 );
	assert.equal( nf.result.reason, 'unknown' );
	const un = await run( [ failure( 409, 'odoowoo_portal_task_unknown' ) ] );
	assert.equal( un.f.calls(), 1 );
	assert.equal( un.result.reason, 'unknown' );

	// A lapsed login stops too: core already retried once.
	const se = await run( [ failure( 403, 'session' ) ] );
	assert.equal( se.f.calls(), 1 );
	assert.equal( se.result.reason, 'session' );

	// Five failed polls in a row stop (a 500 task_status_unavailable counts as one).
	const five = await run( [ failure( 500, 'task_status_unavailable' ) ] );
	assert.equal( five.f.calls(), 5 );
	assert.equal( five.result.reason, 'errors' );

	// A success in between resets the count.
	const bad = failure( 500, 'task_status_unavailable' );
	const mixed = await run( [ bad, bad, bad, bad, running( 1, 10 ), bad, bad, bad, bad, finished( 'done' ) ] );
	assert.equal( mixed.f.calls(), 10 );
	assert.equal( mixed.result.reason, 'done' );

	// A task that never ends stops at 30 minutes: 2, 3, 4.5, 6.75 s, then 10 s steps.
	const forever = await run( [ running( 1, 10 ) ] );
	assert.equal( forever.result.reason, 'timeout' );
	assert.equal( forever.f.calls(), 183, 'No request is made once the 30 minutes are up.' );
	assert.ok( forever.c.t - 1000 >= 30 * MIN );
	assert.ok( forever.c.t - 1000 - 10000 < 30 * MIN, 'It did not run on past the limit.' );
	assert.equal( forever.done[ 0 ][ 0 ], 'timeout' );
} );

test( 'test_odoowoo_mistake_progress_reads_top_level_current_total', async () => {
	assert.equal( R.task_running_30_of_120.data.current, 30, 'The route puts current/total at the top level.' );
	const r = await run( [ running( 30, 120 ), finished( 'done' ) ] );
	assert.equal( r.updates[ 0 ].current, 30 );
	assert.equal( r.updates[ 0 ].total, 120 );
	assert.equal( r.updates[ 0 ].state, 'running' );
	assert.equal( tasks.percent( 30, 120 ), 25 );
	assert.equal( tasks.percent( 0, 0 ), 0 );
	assert.equal( tasks.percent( 5, 0 ), 0 );
	assert.equal( tasks.percent( 150, 100 ), 100 );
	assert.equal( tasks.percent( -3, 100 ), 0 );
	assert.equal( tasks.percent( undefined, undefined ), 0 );
	assert.equal( tasks.percent( 'x', 'y' ), 0 );
	assert.equal( tasks.percent( NaN, 10 ), 0 );
	assert.ok( ! Number.isNaN( tasks.percent( 1, NaN ) ) );
} );

test( 'test_odoowoo_mistake_skipped_and_failed_not_completed', () => {
	assert.equal( tasks.label( 'done' ), 'Completed' );
	assert.equal( tasks.label( 'skipped' ), 'Skipped' );
	assert.equal( tasks.label( 'failed' ), 'Failed' );
	assert.equal( tasks.label( 'running' ), 'Running' );
	assert.equal( tasks.label( 'whatever' ), 'Failed' );
	assert.equal( tasks.label( undefined ), 'Failed' );
	assert.notEqual( tasks.label( 'skipped' ), tasks.label( 'done' ) );
	assert.notEqual( tasks.label( 'failed' ), tasks.label( 'done' ) );
} );

test( 'test_running_with_no_total_is_indeterminate_and_says_starting', () => {
	const v = tasks.view( R.task_running_start.data );
	assert.equal( v.indeterminate, true );
	assert.equal( v.text, 'Starting…' );
	const w = tasks.view( { state: 'running', current: 3, total: 12 } );
	assert.equal( w.indeterminate, false );
	assert.equal( w.percent, 25 );
	assert.equal( w.text, 'Running: 3 of 12' );
	// The portal's own running text: Django's message is not used while running.
	assert.ok( ! tasks.view( { state: 'running', current: 1, total: 2, message: 'Django says hi' } ).text.includes( 'Django' ) );
} );

test( 'test_backoff_grows_and_resets_on_progress', async () => {
	const r = await run( [ running( 1, 10 ), running( 1, 10 ), running( 1, 10 ), running( 1, 10 ), running( 1, 10 ), running( 1, 10 ), running( 2, 10 ), running( 2, 10 ), finished( 'done' ) ] );
	assert.deepEqual( r.c.sleeps, [ 2000, 3000, 4500, 6750, 10000, 10000, 2000, 3000 ] );
	assert.equal( r.result.reason, 'done' );
} );

test( 'test_hidden_tab_slows_to_30_seconds', async () => {
	const states = [ false, true, true, false, false ];
	let n = 0;
	const r = await run(
		[ running( 1, 10 ), running( 1, 10 ), running( 1, 10 ), running( 1, 10 ), finished( 'done' ) ],
		{ hidden: () => states[ n++ ] || false }
	);
	assert.deepEqual( r.c.sleeps, [ 2000, 30000, 30000, 3000 ] );
} );

test( 'test_cancelled_poll_stops_without_calling_done_twice', async () => {
	let stop = false;
	const r = await run( [ running( 1, 10 ) ], { cancelled: () => stop, onUpdate: () => { stop = true; } } );
	assert.equal( r.f.calls(), 1 );
	assert.equal( r.result.reason, 'cancelled' );
} );

test( 'test_poll_stops_by_itself_when_its_element_is_removed', async () => {
	const el = { isConnected: true };
	const r = await run( [ running( 1, 10 ) ], { el, onUpdate: () => { el.isConnected = false; } } );
	assert.equal( r.f.calls(), 1 );
	assert.equal( r.result.reason, 'cancelled' );
	assert.deepEqual( r.c.sleeps, [] );

	// Removed while it sleeps: no further request.
	const el2 = { isConnected: true };
	const c = clock();
	const f = answers( [ running( 1, 10 ) ] );
	const result = await tasks.poll( { fetch: f, el: el2, now: c.now, sleep: ( ms ) => { el2.isConnected = false; return c.sleep( ms ); } } );
	assert.equal( f.calls(), 1 );
	assert.equal( result.reason, 'cancelled' );

	// Still on the page: it carries on to the end.
	const el3 = { isConnected: true };
	const done = await run( [ running( 1, 10 ), finished( 'done' ) ], { el: el3 } );
	assert.equal( done.result.reason, 'done' );

	// render() leaves a removed element alone.
	assert.equal( tasks.render( { isConnected: false }, tasks.view( R.task_done.data ) ), false );
} );
