import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const core = require( '../../assets/js/core.js' );
const wooConn = require( '../../assets/js/card-woo-connection.js' );
const wooSet = require( '../../assets/js/card-woo-settings.js' );
const odooConn = require( '../../assets/js/card-odoo-connection.js' );

import { fixture, fixtureRes } from './fixtures.mjs';

const REST = 'https://x.test/wp-json/odoowoo-portal/v1/';
const data = ( name ) => fixture( name ).body.data;

// Every reply below is a file produced by the real routes (tests/BrowserFixturesTest.php,
// connection_fixtures(), each Django body cited there from sync_app/views.py).
function boot() {
	globalThis.odoowooPortal = { rest: REST, nonce: 'n1', nonceUrl: 'https://x.test/n', i18n: {} };
}

function fakeFetch( names ) {
	const calls = [];
	let i = 0;
	globalThis.fetch = ( url, opts ) => {
		calls.push( { url, opts, body: opts.body === undefined ? undefined : JSON.parse( opts.body ) } );
		return Promise.resolve( fixtureRes( names[ Math.min( i++, names.length - 1 ) ] ) );
	};
	return calls;
}

const ctx = { licenseId: 4, dashboardId: 7 };
const P = 'licenses/4/dashboards/7/';

test( 'test_odoowoo_mistake_prefixes_in_use_marked', () => {
	const options = wooSet.prefixOptions( [ 'AB', 'ZZ' ], 'WS' );

	assert.equal( options[ 0 ].value, '' );
	assert.equal( options[ 0 ].label, 'Select an order prefix' );
	assert.equal( options.length, 677, '676 prefixes AA to ZZ plus the placeholder' );
	assert.equal( options[ 1 ].value, 'AA' );
	assert.equal( options[ 676 ].value, 'ZZ' );

	const ab = options.find( ( o ) => o.value === 'AB' );
	assert.equal( ab.disabled, true );
	assert.equal( ab.label, 'AB (already used by another of your dashboards)' );
	assert.ok( ! ab.label.includes( 'Other' ), 'No dashboard name.' );
	assert.equal( options.find( ( o ) => o.value === 'ZZ' ).disabled, true );

	const ws = options.find( ( o ) => o.value === 'WS' );
	assert.equal( ws.disabled, false );
	assert.equal( ws.label, 'WS' );
	assert.equal( options.filter( ( o ) => o.disabled ).length, 2 );

	// Django's own list includes this dashboard's prefix: it stays selectable and unmarked.
	const own = wooSet.prefixOptions( [ 'WS', 'AB' ], 'WS' ).find( ( o ) => o.value === 'WS' );
	assert.equal( own.disabled, false );
	assert.equal( own.label, 'WS' );

	// A saved prefix outside AA to ZZ (Django allows 1 character) is kept as an option.
	const odd = wooSet.prefixOptions( [], 'A' );
	assert.equal( odd.length, 678 );
	assert.equal( odd.filter( ( o ) => o.value === 'A' ).length, 1 );
	assert.equal( wooSet.prefixOptions( [], '' ).length, 677 );

	// The real reply feeds it.
	const saved = data( 'woo_keys_saved' );
	assert.deepEqual( saved.used_prefixes, [ 'AB' ] );
	assert.equal( wooSet.prefixOptions( saved.used_prefixes, saved.prefix ).filter( ( o ) => o.disabled ).length, 1 );
	// Garbage in the list never throws.
	assert.equal( wooSet.prefixOptions( undefined, undefined ).length, 677 );
	assert.equal( wooSet.prefixOptions( [ 1, null, 'ab' ], '' ).find( ( o ) => o.value === 'AB' ).disabled, true );
} );

test( 'test_odoowoo_mistake_callback_key_box_renders_when_key_exists', () => {
	const set = odooConn.callbackBox( data( 'odoo_settings_saved' ).has_middleware_api_key );
	assert.equal( set.text, 'Callback key is set' );
	assert.equal( set.buttonLabel, 'Set up Odoo callbacks' );
	assert.equal( set.isSet, true );

	const none = odooConn.callbackBox( data( 'odoo_settings_new' ).has_middleware_api_key );
	assert.equal( none.text, 'No callback key yet' );
	assert.equal( none.buttonLabel, 'Set up Odoo callbacks' );
	assert.equal( none.isSet, false );

	for ( const v of [ undefined, null, 'true', 1 ] ) {
		assert.equal( odooConn.callbackBox( v ).text, 'No callback key yet', String( v ) );
	}
	assert.equal( Object.prototype.hasOwnProperty.call( odooConn.callbackBox( true ), 'key' ), false, 'The key is never shown.' );
} );

test( 'test_secret_inputs_are_never_prefilled_and_a_missing_flag_is_false', () => {
	const saved = core.secretState( data( 'woo_keys_saved' ).has_consumer_secret );
	assert.equal( saved.value, '' );
	assert.equal( saved.saved, true );
	assert.equal( saved.placeholder, 'Saved. Leave blank to keep it.' );

	// A new dashboard's reply has no has_consumer_secret and no has_odoo_password at all.
	assert.ok( ! ( 'has_consumer_secret' in data( 'woo_keys_new' ) ) );
	assert.ok( ! ( 'has_odoo_password' in data( 'odoo_settings_new' ) ) );
	for ( const flag of [ data( 'woo_keys_new' ).has_consumer_secret, data( 'odoo_settings_new' ).has_odoo_password, undefined, null, false, 'true', 1 ] ) {
		const s = core.secretState( flag );
		assert.equal( s.saved, false );
		assert.equal( s.value, '' );
		assert.equal( s.placeholder, '' );
	}
	// The replies never carry the secret itself.
	assert.ok( ! ( 'consumer_secret' in data( 'woo_keys_saved' ) ) );
	assert.ok( ! ( 'odoo_password' in data( 'odoo_settings_saved' ) ) );
} );

test( 'test_odoo_verify_shows_helper_module_status', () => {
	boot();
	const yes = core.verifyView( { ok: true, data: data( 'odoo_verify_helper' ), status: 200 } );
	assert.equal( yes.verified, true );
	assert.equal( yes.helper, 'installed' );
	assert.equal( yes.message, 'Odoo connection verified. The OdooWoo Helper module is installed.' );

	const no = core.verifyView( { ok: true, data: data( 'odoo_verify_no_helper' ), status: 200 } );
	assert.equal( no.verified, true );
	assert.equal( no.helper, 'missing' );
	assert.equal( no.message, 'Odoo connection verified, but the OdooWoo Helper module is not installed.' );

	// A reply without the flag is not "installed".
	assert.equal( core.verifyView( { ok: true, data: { success: true }, status: 200 } ).helper, 'unknown' );
	assert.equal( core.verifyView( { ok: true, data: { success: true, helper_installed: 'yes' }, status: 200 } ).helper, 'unknown' );

	const fail = core.verifyView( { ok: false, code: 'invalid_request', message: fixture( 'odoo_verify_failed' ).body.message, status: 400 } );
	assert.equal( fail.verified, false );
	assert.equal( fail.helper, 'unknown' );
	assert.equal( fail.message, 'Authentication with Odoo failed. Check your credentials.' );
} );

test( 'test_verify_woo_refreshes_the_saved_flag', async () => {
	boot();
	const store = core.createStore( { fields: [ 'consumer_key', 'woocommerce_url', 'consumer_secret', 'prefix' ], secrets: [ 'consumer_secret' ] } );
	store.commit( data( 'woo_keys_new' ) ); // Clean start: the stores are shared singletons.
	store.patch( { consumer_key: 'ck_1', woocommerce_url: 'https://shop.example/', consumer_secret: ' cs_dummy ' } );
	assert.equal( core.secretState( store.get().has_consumer_secret ).saved, false );

	const calls = fakeFetch( [ 'woo_verify_success', 'woo_keys_saved' ] );
	const out = await wooConn.verifyWoo( ctx, store );

	assert.equal( out.view.verified, true );
	assert.equal( out.view.refresh, true );
	assert.equal( calls.length, 2, 'The verify, then one re-read.' );
	assert.equal( calls[ 0 ].opts.method, 'POST' );
	assert.equal( calls[ 0 ].url, REST + P + 'verify-woocommerce' );
	assert.deepEqual( calls[ 0 ].body, { consumer_key: 'ck_1', woocommerce_url: 'https://shop.example/', consumer_secret: ' cs_dummy ' }, 'Byte-exact, and only the connection fields.' );
	assert.equal( calls[ 1 ].opts.method, 'GET' );
	assert.equal( calls[ 1 ].url, REST + P + 'woo-keys' );
	assert.equal( store.get().has_consumer_secret, true, 'Verify saved the keys in Django; the flag flipped.' );
	assert.equal( store.get().prefix, 'WS' );

	// A failed verify re-reads nothing and shows Django's refusal.
	const c2 = fakeFetch( [ 'woo_verify_failed' ] );
	const bad = await wooConn.verifyWoo( ctx, store );
	assert.equal( c2.length, 1 );
	assert.equal( bad.view.verified, false );
	assert.equal( bad.view.refresh, false );
	assert.equal( bad.view.message, 'Failed to fetch products. Check your credentials.' );

	for ( const name of [ 'woo_verify_unavailable', 'django_garbage', 'tenant_paused' ] ) {
		fakeFetch( [ name ] );
		const r = await wooConn.verifyWoo( ctx, store );
		assert.equal( r.view.verified, false, name );
	}
} );

test( 'test_verify_woo_refuses_missing_fields_before_any_request', async () => {
	boot();
	const store = core.createStore( { fields: [ 'consumer_key', 'woocommerce_url', 'consumer_secret' ], secrets: [ 'consumer_secret' ] } );
	store.commit( data( 'woo_keys_new' ) ); // Clean start: the stores are shared singletons.
	const calls = fakeFetch( [ 'woo_verify_success' ] );

	const out = await wooConn.verifyWoo( ctx, store );
	assert.equal( calls.length, 0 );
	assert.equal( out.view.verified, false );
	assert.equal( out.reply.field, 'consumer_key' );
	assert.equal( out.view.message, 'consumer_key: Enter the consumer key.' );

	// A saved secret is enough; a blank secret field is left out of the request.
	store.commit( data( 'woo_keys_saved' ) ); // Clean start: the stores are shared singletons.
	fakeFetch( [ 'woo_verify_success', 'woo_keys_saved' ] );
	const calls2 = fakeFetch( [ 'woo_verify_success', 'woo_keys_saved' ] );
	await wooConn.verifyWoo( ctx, store );
	assert.equal( 'consumer_secret' in calls2[ 0 ].body, false );
} );

test( 'test_webhook_badge_is_session_only', async () => {
	boot();
	// Django reports no "transferred" flag: the saved reply has has_webhook_secret but that is not it.
	const saved = data( 'woo_keys_saved' );
	assert.equal( saved.has_webhook_secret, true );
	assert.equal( wooConn.webhookBadge( false ), false );
	assert.equal( wooConn.webhookBadge( undefined ), false );

	assert.equal( wooConn.transferState( saved ).enabled, true );
	assert.equal( wooConn.transferState( data( 'woo_keys_new' ) ).enabled, false, 'Disabled until the keys are saved.' );
	assert.equal( wooConn.transferState( { consumer_key: 'ck', woocommerce_url: 'https://s' } ).enabled, false, 'No saved secret.' );

	const store = core.createStore( { fields: [], secrets: [] } );
	store.load( saved );
	const calls = fakeFetch( [ 'woo_webhooks_success' ] );
	const ok = await wooConn.transferWebhooks( ctx, store );
	assert.equal( calls[ 0 ].opts.method, 'POST' );
	assert.equal( calls[ 0 ].url, REST + P + 'inject-woo-webhooks' );
	assert.equal( ok.transferred, true );
	assert.equal( wooConn.webhookBadge( ok.transferred ), true );

	for ( const [ name, text ] of [ [ 'woo_webhooks_incomplete', 'Some webhooks could not be configured.' ], [ 'woo_webhooks_not_configured', 'Save your WooCommerce connection settings first.' ] ] ) {
		fakeFetch( [ name ] );
		const r = await wooConn.transferWebhooks( ctx, store );
		assert.equal( r.transferred, false, name );
		assert.equal( r.reply.message, text );
		assert.equal( wooConn.webhookBadge( r.transferred ), false );
	}
	assert.equal( core.notConfigured( { ok: false, code: 'woocommerce_not_configured' } ), true );

	// Not enabled: no request at all.
	const empty = core.createStore( { fields: [], secrets: [] } );
	empty.load( data( 'woo_keys_new' ) );
	const c3 = fakeFetch( [ 'woo_webhooks_success' ] );
	const none = await wooConn.transferWebhooks( ctx, empty );
	assert.equal( c3.length, 0 );
	assert.equal( none.transferred, false );
} );

const ODOO_FIELDS = [ 'odoo_url', 'odoo_db', 'odoo_user', 'odoo_password', 'enable_auto_payment', 'enable_auto_payment_cn', 'enable_set_invoice_status', 'enable_reset_all_deliveries', 'enable_set_deliveries_to_waiting', 'enable_auto_complete_deliveries', 'odoo_order_pricelist_id', 'odoo_order_pricelist_name', 'capture_stage', 'confirm_pending_orders', 'price_includes_tax', 'order_incl_tax', 'quantity_settings', 'enable_brand_sync', 'enable_category_sync', 'enable_product_name_sync', 'enable_tag_sync', 'enable_image_sync', 'enable_price_sync', 'enable_barcode_sync', 'stock_sku_meta_keys', 'stock_units_meta_keys' ];

test( 'test_odoo_cards_share_one_full_settings_object', async () => {
	boot();
	const store = core.odooSettingsStore;
	assert.equal( odooConn.store, store, 'The card uses the shared store.' );
	store.commit( data( 'odoo_settings_saved' ) ); // Clean start: the stores are shared singletons.
	assert.equal( store.dirty(), false );

	// An edit in the connection card ...
	store.patch( { odoo_url: 'https://other.example', odoo_password: ' pw ' } );
	assert.equal( store.dirty(), true );
	// ... and one in the settings card ...
	store.patch( { capture_stage: 'completed', enable_auto_payment: false } );

	// ... are in the next save body, which has every field and keeps the untouched values.
	const calls = fakeFetch( [ 'odoo_save_success', 'odoo_settings_saved' ] );
	const out = await odooConn.saveOdoo( ctx, store );

	assert.equal( out.reply.ok, true );
	assert.equal( calls[ 0 ].opts.method, 'PUT' );
	assert.equal( calls[ 0 ].url, REST + P + 'odoo-settings' );
	const body = calls[ 0 ].body;
	assert.deepEqual( Object.keys( body ).sort(), ODOO_FIELDS.slice().sort(), 'Every field, nothing else (no has_*, no brand_sync_allowed, no message).' );
	assert.equal( body.odoo_url, 'https://other.example' );
	assert.equal( body.odoo_password, ' pw ', 'Byte-exact.' );
	assert.equal( body.capture_stage, 'completed' );
	assert.equal( body.enable_auto_payment, false );
	assert.equal( body.enable_auto_complete_deliveries, true, 'Untouched settings are sent back as read.' );
	assert.equal( body.odoo_order_pricelist_id, 3 );
	assert.equal( body.order_incl_tax, true );

	// After a save: re-read, edits gone, the typed password is not kept in memory.
	assert.equal( store.dirty(), false );
	assert.equal( 'odoo_password' in store.get(), false );
	assert.equal( calls.length, 2 );

	// A blank password is left out of the body; a new dashboard's null pricelist stays null.
	store.commit( data( 'odoo_settings_new' ) ); // Clean start: the stores are shared singletons.
	store.patch( { odoo_url: 'https://o.example', odoo_db: 'd', odoo_user: 'u', odoo_password: 'p' } );
	const c2 = fakeFetch( [ 'odoo_save_success', 'odoo_settings_saved' ] );
	await odooConn.saveOdoo( ctx, store );
	assert.equal( c2[ 0 ].body.odoo_order_pricelist_id, null );
	assert.equal( c2[ 0 ].body.odoo_password, 'p' );

	store.commit( data( 'odoo_settings_saved' ) ); // Clean start: the stores are shared singletons.
	const c3 = fakeFetch( [ 'odoo_save_success', 'odoo_settings_saved' ] );
	await odooConn.saveOdoo( ctx, store );
	assert.equal( 'odoo_password' in c3[ 0 ].body, false, 'Blank means keep the stored one.' );
	assert.equal( Object.keys( c3[ 0 ].body ).length, ODOO_FIELDS.length - 1 );
} );

test( 'test_odoo_save_checks_required_fields_and_shows_refusals', async () => {
	boot();
	const store = core.odooSettingsStore;
	store.commit( data( 'odoo_settings_new' ) ); // Clean start: the stores are shared singletons.
	const calls = fakeFetch( [ 'odoo_save_success' ] );

	let out = await odooConn.saveOdoo( ctx, store );
	assert.equal( calls.length, 0 );
	assert.equal( out.reply.field, 'odoo_url' );
	assert.equal( out.reply.ok, false );

	// URL, db and user typed, no password and none saved: refused before a request.
	store.patch( { odoo_url: 'https://o.example', odoo_db: 'd', odoo_user: 'u' } );
	out = await odooConn.saveOdoo( ctx, store );
	assert.equal( calls.length, 0 );
	assert.equal( out.reply.field, 'odoo_password' );

	// Django's own refusal reaches the card with its field, and nothing is marked saved.
	store.patch( { odoo_password: 'p' } );
	fakeFetch( [ 'odoo_password_required' ] );
	out = await odooConn.saveOdoo( ctx, store );
	assert.equal( out.reply.ok, false );
	assert.equal( out.reply.field, 'odoo_password' );
	assert.equal( store.dirty(), true, 'What was typed stays.' );

	// Session lapsed: the card says so and keeps the text.
	fakeFetch( [ 'session_nonce', 'session_nonce' ] );
	globalThis.fetch = ( () => { let n = 0; return () => Promise.resolve( n++ === 1 ? { ok: true, status: 200, headers: { get: () => null }, text: () => Promise.resolve( 'fresh123' ) } : fixtureRes( 'session_nonce' ) ); } )();
	out = await odooConn.saveOdoo( ctx, store );
	assert.equal( out.reply.ok, false );
	assert.equal( out.reply.code, 'session' );
	assert.equal( store.dirty(), true );
} );

test( 'test_woo_save_sends_the_whole_object_and_the_blank_secret_means_keep', async () => {
	boot();
	const store = core.wooKeysStore;
	assert.equal( wooConn.store, store );
	assert.equal( wooSet.store, store, 'Connection and settings cards share one object (Django resets omitted fields).' );

	store.commit( data( 'woo_keys_saved' ) ); // Clean start: the stores are shared singletons.
	store.patch( { enable_stock_update: false } ); // The settings card.
	store.patch( { consumer_key: 'ck_2' } ); // The connection card.
	const calls = fakeFetch( [ 'woo_save_created', 'woo_keys_saved' ] );
	const out = await wooConn.saveWoo( ctx, store );

	assert.equal( out.reply.ok, true );
	assert.equal( calls[ 0 ].opts.method, 'PUT' );
	assert.equal( calls[ 0 ].url, REST + P + 'woo-keys' );
	assert.deepEqual( calls[ 0 ].body, { consumer_key: 'ck_2', woocommerce_url: 'https://shop.example', prefix: 'WS', enable_stock_update: false, change_status_delivery: false } );
	assert.equal( store.dirty(), false );

	// A typed secret goes byte-exact and is dropped from memory afterwards.
	store.patch( { consumer_secret: ' cs ' } );
	const c2 = fakeFetch( [ 'woo_save_created', 'woo_keys_saved' ] );
	await wooConn.saveWoo( ctx, store );
	assert.equal( c2[ 0 ].body.consumer_secret, ' cs ' );
	assert.equal( 'consumer_secret' in store.get(), false );

	// First save without a secret: refused before a request. The prefix from Django's refusal is shown with its field.
	store.commit( data( 'woo_keys_new' ) ); // Clean start: the stores are shared singletons.
	store.patch( { consumer_key: 'ck', woocommerce_url: 'https://s.example' } );
	const c3 = fakeFetch( [ 'woo_secret_required' ] );
	const refused = await wooConn.saveWoo( ctx, store );
	assert.equal( c3.length, 0 );
	assert.equal( refused.reply.field, 'consumer_secret' );

	store.patch( { consumer_secret: 's', prefix: 'AB' } );
	fakeFetch( [ 'woo_prefix_taken' ] );
	const taken = await wooConn.saveWoo( ctx, store );
	assert.equal( taken.reply.ok, false );
	assert.equal( taken.reply.field, 'prefix' );
	assert.equal( core.errorText( taken.reply ), 'prefix: That order prefix is already used by another of your dashboards.' );
	assert.equal( store.dirty(), true );
} );

test( 'test_woo_settings_need_saved_connection_first', async () => {
	boot();
	const store = core.wooKeysStore;
	store.commit( data( 'woo_keys_new' ) ); // Clean start: the stores are shared singletons.
	store.patch( { prefix: 'AB' } );
	const calls = fakeFetch( [ 'woo_save_created' ] );
	const out = await wooSet.saveSettings( ctx, store );

	assert.equal( calls.length, 0 );
	assert.equal( out.reply.ok, false );
	assert.equal( out.reply.code, 'woocommerce_not_configured' );
	assert.equal( core.notConfigured( out.reply ), true );
	assert.equal( out.reply.message, 'Save your WooCommerce connection first.' );

	store.commit( data( 'woo_keys_saved' ) ); // Clean start: the stores are shared singletons.
	store.patch( { change_status_delivery: true } );
	const c2 = fakeFetch( [ 'woo_save_created', 'woo_keys_saved' ] );
	const ok = await wooSet.saveSettings( ctx, store );
	assert.equal( ok.reply.ok, true );
	assert.equal( c2[ 0 ].body.change_status_delivery, true );
	assert.equal( c2[ 0 ].body.consumer_key, 'ck_1' );
} );

test( 'test_callback_setup_confirms_first_and_shows_the_reasons', async () => {
	boot();
	const store = core.odooSettingsStore;
	store.commit( data( 'odoo_settings_saved' ) ); // Clean start: the stores are shared singletons.
	const calls = fakeFetch( [ 'callback_success', 'odoo_settings_saved' ] );

	const no = await odooConn.setUpCallbacks( ctx, store, () => Promise.resolve( false ) );
	assert.equal( calls.length, 0, 'Cancel: nothing is sent, no key is minted.' );
	assert.equal( no.cancelled, true );

	let asked = 0;
	const yes = await odooConn.setUpCallbacks( ctx, store, () => { asked++; return Promise.resolve( true ); } );
	assert.equal( asked, 1 );
	assert.equal( calls[ 0 ].opts.method, 'POST' );
	assert.equal( calls[ 0 ].url, REST + P + 'generate-api-key' );
	assert.equal( calls[ 0 ].body, undefined );
	assert.equal( yes.reply.ok, true );
	assert.equal( calls[ 1 ].url, REST + P + 'odoo-settings', 'The flag is re-read; the key is never returned.' );
	assert.equal( JSON.stringify( yes ).includes( 'api_key' ), false );

	fakeFetch( [ 'callback_not_configured' ] );
	const nc = await odooConn.setUpCallbacks( ctx, store, () => Promise.resolve( true ) );
	assert.equal( core.notConfigured( nc.reply ), true );
	assert.equal( nc.reply.message, 'Save your Odoo connection settings first.' );

	fakeFetch( [ 'callback_unavailable' ] );
	const un = await odooConn.setUpCallbacks( ctx, store, () => Promise.resolve( true ) );
	assert.equal( un.reply.ok, false );
	assert.equal( un.reply.code, 'odoo_unavailable' );
} );

test( 'test_store_dirty_commit_and_subscribers', () => {
	const s = core.createStore( { fields: [ 'a', 'pw' ], secrets: [ 'pw' ] } );
	let seen = 0;
	s.subscribe( () => { seen++; } );
	s.load( { a: 1, has_pw: true } );
	assert.equal( s.dirty(), false );
	s.patch( { a: 1 } );
	assert.equal( s.dirty(), false, 'An edit equal to the saved value is no edit.' );
	s.patch( { a: 2, pw: '' } );
	assert.deepEqual( s.body(), { a: 2 }, 'Blank secret left out.' );
	s.patch( { pw: 'x' } );
	assert.deepEqual( s.body(), { a: 2, pw: 'x' } );
	s.commit( { a: 2, has_pw: true } );
	assert.equal( s.dirty(), false );
	assert.deepEqual( s.get(), { a: 2, has_pw: true } );
	assert.ok( seen >= 3 );
} );
