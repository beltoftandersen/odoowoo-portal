import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const core = createRequire( import.meta.url )( '../../assets/js/core.js' );

const REST = 'https://x.test/wp-json/odoowoo-portal/v1/';
const NONCE_URL = 'https://x.test/wp-admin/admin-ajax.php?action=rest-nonce';
const GENERIC = 'Something went wrong on our side. Please try again in a moment.';
const SESSION = 'Please log in again. What you typed is still here.';

function boot( extra = {} ) {
	globalThis.odoowooPortal = { rest: REST, nonce: 'n1', nonceUrl: NONCE_URL, i18n: { generic: GENERIC, session: SESSION }, ...extra };
}

/** A response the way fetch gives it: ok, status, headers.get, text(). */
function res( status, body, headers = {} ) {
	const h = Object.fromEntries( Object.entries( headers ).map( ( [ k, v ] ) => [ k.toLowerCase(), v ] ) );
	return {
		ok: status >= 200 && status < 300,
		status,
		headers: { get: ( k ) => h[ String( k ).toLowerCase() ] ?? null },
		text: () => Promise.resolve( typeof body === 'string' ? body : JSON.stringify( body ) ),
	};
}

/** Installs a fetch that answers in order and records every call. */
function fakeFetch( answers ) {
	const calls = [];
	let i = 0;
	globalThis.fetch = ( url, opts ) => {
		calls.push( { url, opts } );
		const a = answers[ Math.min( i++, answers.length - 1 ) ];
		if ( a instanceof Error ) {
			return Promise.reject( a );
		}
		return Promise.resolve( a );
	};
	return calls;
}

function el() {
	return {
		textContent: '',
		disabled: false,
		attrs: {},
		states: [],
		texts: [],
		setAttribute( k, v ) {
			this.attrs[ k ] = String( v );
			if ( k === 'data-state' ) {
				this.states.push( v );
			}
		},
		getAttribute( k ) {
			return k in this.attrs ? this.attrs[ k ] : null;
		},
		removeAttribute( k ) {
			delete this.attrs[ k ];
		},
	};
}

const wpRefusal = ( status, code ) => res( status, { code, message: 'wp text', data: { status } } );

test( 'test_odoowoo_rest_auth_failure_is_not_saved', async () => {
	boot();
	const calls = fakeFetch( [ wpRefusal( 403, 'rest_cookie_invalid_nonce' ), res( 200, 'n2n2n2n2' ), wpRefusal( 403, 'rest_cookie_invalid_nonce' ) ] );
	const button = el();
	button.textContent = 'Save';
	const statusEl = el();
	const input = { value: 'typed value' };

	const reply = await core.submit( button, statusEl, () => core.api( 'POST', 'licenses/1/dashboards/2/woo-keys', { prefix: input.value } ) );

	assert.equal( reply.ok, false );
	assert.equal( reply.code, 'session' );
	assert.equal( reply.message, SESSION );
	assert.ok( ! statusEl.states.includes( 'saved' ), 'Never "saved".' );
	assert.equal( statusEl.attrs[ 'data-state' ], 'error' );
	assert.equal( statusEl.textContent, SESSION );
	assert.equal( input.value, 'typed value', 'What was typed is untouched.' );
	assert.equal( button.disabled, false, 'Button re-enabled.' );
	assert.equal( button.textContent, 'Save', 'Button text restored.' );
	assert.equal( calls.length, 3, 'The request, one nonce fetch, one retry, no more.' );
} );

test( 'test_expired_nonce_is_refreshed_once_and_the_request_retried', async () => {
	boot();
	const calls = fakeFetch( [ wpRefusal( 403, 'rest_cookie_invalid_nonce' ), res( 200, 'fresh123' ), res( 200, { items: [] } ) ] );

	const r = await core.api( 'POST', 'licenses/1/dashboards', { name: 'x' } );

	assert.equal( r.ok, true );
	assert.deepEqual( r.data, { items: [] } );
	assert.equal( calls.length, 3 );
	assert.equal( calls[ 0 ].url, REST + 'licenses/1/dashboards' );
	assert.equal( calls[ 0 ].opts.headers[ 'X-WP-Nonce' ], 'n1' );
	assert.equal( calls[ 1 ].url, NONCE_URL );
	assert.equal( calls[ 1 ].opts.credentials, 'same-origin' );
	assert.equal( calls[ 2 ].url, REST + 'licenses/1/dashboards' );
	assert.equal( calls[ 2 ].opts.headers[ 'X-WP-Nonce' ], 'fresh123' );
	assert.equal( calls[ 2 ].opts.body, JSON.stringify( { name: 'x' } ) );
	assert.equal( globalThis.odoowooPortal.nonce, 'fresh123' );

	// The portal's own login refusal is a session error too.
	boot();
	const c2 = fakeFetch( [ wpRefusal( 401, 'odoowoo_portal_login' ), res( 200, 'again1' ), res( 200, { items: [] } ) ] );
	assert.equal( ( await core.api( 'GET', 'licenses' ) ).ok, true );
	assert.equal( c2.length, 3 );

	// A refused nonce refresh (logged out: admin-ajax answers 0) gives up without a retry.
	boot();
	const c3 = fakeFetch( [ wpRefusal( 403, 'rest_cookie_invalid_nonce' ), res( 400, '0' ) ] );
	const r3 = await core.api( 'GET', 'licenses' );
	assert.equal( r3.code, 'session' );
	assert.equal( c3.length, 2 );
	assert.equal( globalThis.odoowooPortal.nonce, 'n1', 'A garbage answer never replaces the nonce.' );
} );

test( 'test_api_updates_the_nonce_from_the_response_header', async () => {
	boot();
	fakeFetch( [ res( 200, { items: [] }, { 'X-WP-Nonce': 'rotated9' } ) ] );
	await core.api( 'GET', 'licenses' );
	assert.equal( globalThis.odoowooPortal.nonce, 'rotated9' );
	const calls = fakeFetch( [ res( 200, { items: [] } ) ] );
	await core.api( 'GET', 'licenses' );
	assert.equal( calls[ 0 ].opts.headers[ 'X-WP-Nonce' ], 'rotated9' );
	assert.equal( calls[ 0 ].opts.headers[ 'Content-Type' ], undefined, 'No body, no content type.' );
	assert.equal( calls[ 0 ].opts.body, undefined );
} );

test( 'test_django_403_arriving_as_409_is_not_a_session_error', async () => {
	boot();
	for ( const [ status, code ] of [ [ 409, 'forbidden' ], [ 409, 'unauthorized' ], [ 403, 'odoowoo_portal_forbidden' ], [ 403, 'odoowoo_portal_read_only' ] ] ) {
		const calls = fakeFetch( [ res( status, { code, message: 'Django says no' } ) ] );
		const r = await core.api( 'POST', 'licenses/1/dashboards', { name: 'x' } );
		assert.equal( r.ok, false, code );
		assert.equal( r.code, code );
		assert.equal( r.message, 'Django says no' );
		assert.equal( r.status, status );
		assert.equal( calls.length, 1, code + ': no nonce fetch, no retry.' );
	}
} );

test( 'test_api_never_rejects', async () => {
	boot();
	const bad = [
		new Error( 'network down' ),
		res( 200, '' ),
		res( 200, 'not json {' ),
		res( 502, '<html><body>Bad gateway SECRET-RAW</body></html>' ),
		res( 500, { error: 'raw django text' } ),
		res( 400, { detail: 'raw detail' } ),
		res( 400, { code: 'x' } ),
		res( 400, { message: 'only message' } ),
		res( 400, { code: 5, message: 'numeric code' } ),
		res( 200, 'null' ),
		res( 200, '"string"' ),
	];
	for ( const b of bad ) {
		fakeFetch( [ b ] );
		const r = await core.api( 'GET', 'licenses/1' );
		assert.equal( r.ok, false );
		assert.equal( r.code, 'server_error' );
		assert.equal( r.message, GENERIC );
		assert.ok( ! JSON.stringify( r ).includes( 'SECRET-RAW' ) );
		assert.ok( ! JSON.stringify( r ).includes( 'raw ' ) );
	}
	// A shaped error passes, with its field.
	fakeFetch( [ res( 409, { code: 'invalid_request', message: 'Bad prefix.', field: 'prefix' } ) ] );
	const r = await core.api( 'POST', 'x', {} );
	assert.deepEqual( { ok: r.ok, code: r.code, message: r.message, field: r.field, status: r.status }, { ok: false, code: 'invalid_request', message: 'Bad prefix.', field: 'prefix', status: 409 } );
	// An empty object is a valid success, and so is an empty list.
	fakeFetch( [ res( 200, '{}' ) ] );
	assert.deepEqual( ( await core.api( 'GET', 'x' ) ).data, {} );
	fakeFetch( [ res( 200, '[]' ) ] );
	assert.deepEqual( ( await core.api( 'GET', 'x' ) ).data, [] );
} );

test( 'test_api_url_joins_a_plain_permalink_root', async () => {
	boot( { rest: 'https://x.test/index.php?rest_route=/odoowoo-portal/v1/' } );
	const calls = fakeFetch( [ res( 200, { items: [] } ) ] );
	await core.api( 'GET', 'licenses/4/dashboards' );
	assert.equal( calls[ 0 ].url, 'https://x.test/index.php?rest_route=/odoowoo-portal/v1/licenses/4/dashboards' );
	await core.api( 'GET', '/licenses/4' );
	assert.equal( calls[ 1 ].url, 'https://x.test/index.php?rest_route=/odoowoo-portal/v1/licenses/4' );
	assert.equal( calls[ 0 ].opts.credentials, 'same-origin' );
	assert.equal( calls[ 0 ].opts.method, 'GET' );
} );

test( 'test_list_of_reads_items_and_survives_garbage', () => {
	assert.deepEqual( core.listOf( { items: [ { id: 1 } ] } ), [ { id: 1 } ] );
	assert.deepEqual( core.listOf( { items: [] } ), [] );
	for ( const g of [ null, undefined, 5, 'x', {}, { items: 'x' }, { items: null }, { items: { a: 1 } } ] ) {
		assert.deepEqual( core.listOf( g ), [], String( g ) );
	}
	assert.deepEqual( core.listOf( [ 1, 2 ] ), [], 'A bare array is not accepted by default.' );
	assert.deepEqual( core.listOf( [ 1, 2 ], true ), [ 1, 2 ], 'Only GET /licenses passes true.' );
	assert.deepEqual( core.listOf( { x: 1 }, true ), [] );
} );

test( 'test_not_configured_codes_get_the_connections_link', () => {
	assert.equal( core.notConfigured( { ok: false, code: 'odoo_not_configured' } ), true );
	assert.equal( core.notConfigured( { ok: false, code: 'woocommerce_not_configured' } ), true );
	assert.equal( core.notConfigured( { ok: false, code: 'invalid_request' } ), false );
	assert.equal( core.notConfigured( { ok: true } ), false );
	assert.equal( core.notConfigured( null ), false );
	assert.equal( core.notConfigured( undefined ), false );
} );

test( 'test_submit_shows_saved_only_for_ok_and_always_reenables', async () => {
	boot();
	const button = el();
	button.textContent = 'Save';
	const statusEl = el();

	const ok = await core.submit( button, statusEl, () => Promise.resolve( { ok: true, data: {} } ) );
	assert.equal( ok.ok, true );
	assert.deepEqual( statusEl.states, [ 'saving', 'saved' ] );
	assert.equal( statusEl.textContent, 'Saved' );
	assert.equal( button.disabled, false );

	const s2 = el();
	await core.submit( button, s2, () => Promise.resolve( { ok: false, code: 'invalid_request', message: 'Bad prefix.', field: 'prefix' } ) );
	assert.deepEqual( s2.states, [ 'saving', 'error' ] );
	assert.equal( s2.textContent, 'prefix: Bad prefix.' );
	assert.equal( button.disabled, false );

	// A request function that throws still re-enables the button and never says saved.
	const s3 = el();
	const r3 = await core.submit( button, s3, () => { throw new Error( 'boom' ); } );
	assert.equal( r3.ok, false );
	assert.ok( ! s3.states.includes( 'saved' ) );
	assert.equal( s3.textContent, GENERIC );
	assert.equal( button.disabled, false );

	// While it runs the button is disabled and busy.
	const s4 = el();
	let during;
	await core.submit( button, s4, () => { during = [ button.disabled, button.attrs[ 'aria-busy' ], button.textContent ]; return Promise.resolve( { ok: true } ); } );
	assert.deepEqual( during, [ true, 'true', 'Saving…' ] );
	assert.equal( button.attrs[ 'aria-busy' ], undefined );
} );

test( 'test_secret_value_is_byte_exact_and_blank_keeps_the_saved_one', () => {
	const input = el();
	input.value = '';
	core.secretField( input, true );
	assert.equal( input.attrs.placeholder, 'Saved. Leave blank to keep it.' );
	assert.equal( input.attrs.autocomplete, 'new-password' );
	assert.equal( core.secretValue( input, true ), undefined, 'Blank and saved: leave it out of the body.' );
	input.value = '  sp ace  ';
	assert.equal( core.secretValue( input, true ), '  sp ace  ', 'No trimming.' );
	input.value = '';
	assert.equal( core.secretValue( input, false ), '' );
} );
