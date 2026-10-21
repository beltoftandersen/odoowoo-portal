import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const core = require( '../../assets/js/core.js' );
const deps = require( '../../assets/js/deps.js' );
const card = require( '../../assets/js/card-odoo-settings.js' );

import { fixture, fixtureRes } from './fixtures.mjs';

const REST = 'https://x.test/wp-json/odoowoo-portal/v1/';
const P = 'licenses/4/dashboards/7/';
const ctx = { licenseId: 4, dashboardId: 7 };
const data = ( name ) => fixture( name ).body.data;
const all = { hasPayment: true, hasTax: true, hasPrice: true, brandSync: true };
const store = core.odooSettingsStore;

function boot() {
	globalThis.odoowooPortal = { rest: REST, nonce: 'n1', nonceUrl: 'https://x.test/n', i18n: {} };
}

/** Answers by URL suffix: { 'payment-method-mappings': 'payment_mappings_list', ... }. */
function fakeFetch( routes ) {
	const calls = [];
	globalThis.fetch = ( url, opts ) => {
		calls.push( { url, method: opts.method, body: opts.body === undefined ? undefined : JSON.parse( opts.body ) } );
		const key = Object.keys( routes ).find( ( k ) => url.includes( P + k ) );
		const name = key === undefined ? undefined : routes[ key ];
		return Promise.resolve( fixtureRes( Array.isArray( name ) ? name.shift() : name ) );
	};
	return calls;
}

const BOOLEANS = [ 'enable_auto_payment', 'enable_auto_payment_cn', 'enable_set_invoice_status', 'enable_reset_all_deliveries', 'enable_set_deliveries_to_waiting', 'enable_auto_complete_deliveries', 'confirm_pending_orders', 'price_includes_tax', 'order_incl_tax', 'enable_brand_sync', 'enable_category_sync', 'enable_product_name_sync', 'enable_tag_sync', 'enable_image_sync', 'enable_price_sync', 'enable_barcode_sync' ];

test( 'deps: payment mappings rule, both states, both fields', () => {
	const off = deps.deps( {}, { ...all, hasPayment: false } );
	assert.deepEqual( Object.keys( off.disabled ).sort(), [ 'enable_auto_payment', 'enable_auto_payment_cn' ] );
	assert.equal( off.disabled.enable_auto_payment, 'Needs payment method mappings.' );
	assert.equal( off.disabled.enable_auto_payment_cn, 'Needs payment method mappings.' );
	assert.deepEqual( deps.deps( {}, all ).disabled, {} );
} );

test( 'deps: tax rule', () => {
	assert.deepEqual( deps.deps( {}, { ...all, hasTax: false } ).disabled, { price_includes_tax: 'Needs tax mappings.' } );
	assert.deepEqual( deps.deps( {}, all ).disabled, {} );
} );

test( 'deps: price rule', () => {
	assert.deepEqual( deps.deps( {}, { ...all, hasPrice: false } ).disabled, { enable_price_sync: 'Needs a price mapping.' } );
	assert.deepEqual( deps.deps( {}, all ).disabled, {} );
} );

test( 'deps: brand rule', () => {
	assert.deepEqual( deps.deps( {}, { ...all, brandSync: false } ).disabled, { enable_brand_sync: 'Brand sync is not part of your plan.' } );
	assert.deepEqual( deps.deps( {}, all ).disabled, {} );
} );

test( 'deps: an unknown fact (a failed read) disables nothing', () => {
	assert.deepEqual( deps.deps( {}, {} ).disabled, {} );
	assert.deepEqual( deps.deps( {}, { hasPayment: undefined, hasTax: undefined, hasPrice: undefined, brandSync: undefined } ).disabled, {} );
} );

test( 'deps: confirm_pending_orders is shown only for pending', () => {
	assert.deepEqual( deps.deps( { capture_stage: 'pending' }, all ).hidden, [] );
	assert.deepEqual( deps.deps( { capture_stage: 'processing' }, all ).hidden, [ 'confirm_pending_orders' ] );
	assert.deepEqual( deps.deps( { capture_stage: 'on_hold' }, all ).hidden, [ 'confirm_pending_orders' ] );
} );

test( 'test_dependencies_checked_on_every_change_and_on_load', async () => {
	boot();
	fakeFetch( { 'payment-method-mappings': 'payment_mappings_empty', 'tax-mappings': 'tax_mappings_list', 'price-mappings': 'price_mappings_empty' } );
	const seen = [];
	const checker = card.createChecker( ctx, ( facts ) => seen.push( { ...facts } ) );

	// On load: the three lists are read once and the card is told.
	await checker.refresh();
	assert.deepEqual( seen.at( -1 ), { hasPayment: false, hasTax: true, hasPrice: false } );

	// On every change the card computes deps from the values it has right now.
	store.commit( data( 'odoo_settings_brand_allowed' ) );
	const first = card.computeDeps( store.get(), checker.facts() );
	assert.equal( first.disabled.enable_auto_payment, 'Needs payment method mappings.' );
	assert.equal( first.disabled.enable_price_sync, 'Needs a price mapping.' );
	assert.equal( first.disabled.price_includes_tax, undefined );
	assert.deepEqual( first.hidden, [] );

	store.patch( { capture_stage: 'processing' } );
	assert.deepEqual( card.computeDeps( store.get(), checker.facts() ).hidden, [ 'confirm_pending_orders' ] );

	// brandSync comes from the settings' own brand_sync_allowed.
	assert.equal( card.computeDeps( store.get(), checker.facts() ).disabled.enable_brand_sync, undefined );
	store.commit( data( 'odoo_settings_saved' ) );
	assert.equal( data( 'odoo_settings_saved' ).brand_sync_allowed, false );
	assert.equal( card.computeDeps( store.get(), checker.facts() ).disabled.enable_brand_sync, 'Brand sync is not part of your plan.' );
} );

test( 'a failed list read keeps the earlier fact and reports unknown for a first failure', async () => {
	boot();
	fakeFetch( { 'payment-method-mappings': 'django_garbage', 'tax-mappings': 'tax_mappings_list', 'price-mappings': 'price_mappings_list' } );
	const checker = card.createChecker( ctx, () => {} );
	await checker.refresh();
	assert.deepEqual( checker.facts(), { hasPayment: undefined, hasTax: true, hasPrice: true } );

	fakeFetch( { 'payment-method-mappings': 'payment_mappings_list', 'tax-mappings': 'tax_mappings_list', 'price-mappings': 'price_mappings_list' } );
	await checker.refresh();
	assert.equal( checker.facts().hasPayment, true );

	fakeFetch( { 'payment-method-mappings': 'django_garbage', 'tax-mappings': 'tax_mappings_list', 'price-mappings': 'price_mappings_list' } );
	await checker.refresh();
	assert.equal( checker.facts().hasPayment, true, 'A failed read does not flip a known fact.' );
} );

test( 'test_mappings_saved_event_rechecks', async () => {
	boot();
	const calls = fakeFetch( { 'payment-method-mappings': [ 'payment_mappings_empty', 'payment_mappings_list' ], 'tax-mappings': 'tax_mappings_empty', 'price-mappings': 'price_mappings_empty' } );
	const target = new EventTarget();
	let changes = 0;
	const checker = card.createChecker( ctx, () => { changes++; } );
	const stop = card.onMappingsSaved( target, () => checker.refresh() );

	await checker.refresh();
	assert.equal( checker.facts().hasPayment, false );
	assert.equal( calls.length, 3 );

	target.dispatchEvent( new Event( 'odoowoo:mappings-saved' ) );
	await new Promise( ( r ) => setTimeout( r, 20 ) );
	assert.equal( calls.length, 6, 'The three lists are read again.' );
	assert.deepEqual( calls.map( ( c ) => c.method ), Array( 6 ).fill( 'GET' ) );
	assert.equal( checker.facts().hasPayment, true );
	assert.ok( changes >= 2 );

	stop();
	target.dispatchEvent( new Event( 'odoowoo:mappings-saved' ) );
	await new Promise( ( r ) => setTimeout( r, 20 ) );
	assert.equal( calls.length, 6, 'Removed listeners do nothing.' );
} );

test( 'test_save_body_has_every_field_with_real_booleans', async () => {
	boot();
	store.commit( data( 'odoo_settings_brand_allowed' ) );
	const calls = fakeFetch( { 'odoo-settings': [ 'odoo_save_success', 'odoo_settings_brand_allowed' ] } );

	const out = await card.saveSettings( ctx, store, all );
	assert.equal( out.reply.ok, true );

	const put = calls[ 0 ];
	assert.equal( put.method, 'PUT' );
	assert.ok( put.url.endsWith( P + 'odoo-settings' ) );
	BOOLEANS.forEach( ( f ) => assert.equal( typeof put.body[ f ], 'boolean', f ) );
	assert.equal( BOOLEANS.length, 16 );
	assert.equal( put.body.capture_stage, 'pending' );
	assert.equal( put.body.quantity_settings, 'on_hand' );
	assert.equal( put.body.odoo_order_pricelist_id, 3 );
	assert.equal( put.body.odoo_order_pricelist_name, 'Public' );
	assert.equal( put.body.stock_sku_meta_keys, '_bss_stock_sku' );
	assert.equal( put.body.stock_units_meta_keys, '' );
	assert.equal( put.body.odoo_url, 'https://odoo.example' );
	assert.equal( put.body.odoo_db, 'prod' );
	assert.equal( put.body.odoo_user, 'api@example.com' );
	assert.equal( 'odoo_password' in put.body, false, 'A blank password is left out.' );
	assert.equal( 'brand_sync_allowed' in put.body, false );
	assert.equal( 'has_odoo_password' in put.body, false );
	assert.equal( Object.keys( put.body ).length, 25 );
} );

test( 'save: disabled dependencies and brand sync without the plan go out as false, and the pricelist as null', async () => {
	boot();
	store.commit( { ...data( 'odoo_settings_brand_allowed' ), enable_price_sync: true, price_includes_tax: true, enable_auto_payment_cn: true, odoo_order_pricelist_id: 3, brand_sync_allowed: false } );
	const calls = fakeFetch( { 'odoo-settings': [ 'odoo_save_success', 'odoo_settings_brand_allowed' ] } );

	card.choosePricelist( store, '', [] );
	await card.saveSettings( ctx, store, { hasPayment: false, hasTax: false, hasPrice: false } );

	const b = calls[ 0 ].body;
	[ 'enable_auto_payment', 'enable_auto_payment_cn', 'price_includes_tax', 'enable_price_sync', 'enable_brand_sync' ].forEach( ( f ) => assert.strictEqual( b[ f ], false, f ) );
	assert.strictEqual( b.enable_category_sync, true );
	assert.strictEqual( b.odoo_order_pricelist_id, null );
	assert.strictEqual( b.odoo_order_pricelist_name, '' );
} );

test( 'save: non-boolean values (strings) never reach Django', async () => {
	boot();
	store.commit( { ...data( 'odoo_settings_brand_allowed' ), enable_tag_sync: 'false', enable_image_sync: 1 } );
	const calls = fakeFetch( { 'odoo-settings': [ 'odoo_save_success', 'odoo_settings_brand_allowed' ] } );
	await card.saveSettings( ctx, store, all );
	assert.strictEqual( calls[ 0 ].body.enable_tag_sync, false );
	assert.strictEqual( calls[ 0 ].body.enable_image_sync, false );
} );

test( 'a save before the shared store has loaded sends no request', async () => {
	boot();
	const fresh = core.createStore( { fields: [ 'odoo_url' ] } );
	const calls = fakeFetch( { 'odoo-settings': 'odoo_save_success' } );
	const out = await card.saveSettings( ctx, fresh, all );

	assert.equal( calls.length, 0 );
	assert.equal( out.reply.ok, false );
	assert.equal( out.reply.message, 'The settings are still loading. Try again in a moment.' );
	assert.equal( card.canSave( fresh, true ), false );
	assert.equal( card.canSave( store, true ), true );
	assert.equal( card.canSave( store, false ), false );
} );

test( 'a save without the Odoo connection sends no request', async () => {
	boot();
	store.commit( { ...data( 'odoo_settings_brand_allowed' ), odoo_url: '' } );
	const calls = fakeFetch( { 'odoo-settings': 'odoo_save_success' } );
	const out = await card.saveSettings( ctx, store, all );

	assert.equal( calls.length, 0 );
	assert.equal( out.reply.message, 'Save your Odoo connection first.' );
	assert.equal( out.reply.field, 'odoo_url' );
} );

test( 'Django refusals keep their field and message; a failed save changes nothing', async () => {
	boot();
	for ( const [ name, field ] of [ [ 'odoo_settings_auto_payment_refused', 'enable_auto_payment' ], [ 'odoo_settings_price_tax_refused', 'price_includes_tax' ], [ 'odoo_settings_price_sync_refused', 'enable_price_sync' ], [ 'odoo_settings_brand_refused', 'enable_brand_sync' ] ] ) {
		store.commit( data( 'odoo_settings_brand_allowed' ) );
		store.patch( { stock_sku_meta_keys: '_typed' } );
		fakeFetch( { 'odoo-settings': name } );
		const out = await card.saveSettings( ctx, store, all );

		assert.equal( out.reply.ok, false, name );
		assert.equal( card.fieldOf( out.reply ), field, name );
		assert.equal( store.get().stock_sku_meta_keys, '_typed', 'what was typed stays' );
		assert.equal( store.dirty(), true );
	}
} );

test( 'test_brand_sync_toggle_is_not_the_security_boundary (browser side): a forced true is still sent as typed when the plan is unknown', async () => {
	boot();
	store.commit( { ...data( 'odoo_settings_brand_allowed' ), brand_sync_allowed: undefined } );
	const calls = fakeFetch( { 'odoo-settings': 'odoo_settings_brand_refused' } );
	const out = await card.saveSettings( ctx, store, { ...all, brandSync: undefined } );

	assert.strictEqual( calls[ 0 ].body.enable_brand_sync, true, 'Django decides when the plan is unknown.' );
	assert.equal( out.reply.code, 'plan_feature_unavailable' );
	assert.equal( out.reply.message, 'Brand sync is not included in your plan.' );
} );

test( 'pricelists: shaped reply is read on demand, the saved one is shown first', async () => {
	boot();
	store.commit( data( 'odoo_settings_brand_allowed' ) );

	const before = card.pricelistOptions( store.get(), [] );
	assert.deepEqual( before.map( ( o ) => [ o.value, o.label ] ), [ [ '', 'Let Odoo decide from the customer' ], [ '3', 'Public' ] ] );

	const calls = fakeFetch( { 'odoo-pricelists': 'odoo_pricelists' } );
	const out = await card.loadPricelists( ctx );
	assert.equal( out.ok, true );
	assert.equal( calls.length, 1 );
	assert.equal( calls[ 0 ].method, 'GET' );
	assert.deepEqual( out.items.map( ( i ) => i.id ), [ 5, 6 ] );

	const after = card.pricelistOptions( store.get(), out.items );
	assert.deepEqual( after.map( ( o ) => o.value ), [ '', '3', '5', '6' ] );
	assert.equal( after[ 2 ].label, 'Public Pricelist (EUR)' );

	card.choosePricelist( store, '6', out.items );
	assert.equal( store.get().odoo_order_pricelist_id, 6 );
	assert.equal( store.get().odoo_order_pricelist_name, 'Retail DKK' );
	card.choosePricelist( store, '', out.items );
	assert.strictEqual( store.get().odoo_order_pricelist_id, null );
	assert.strictEqual( store.get().odoo_order_pricelist_name, '' );
	card.choosePricelist( store, '999', out.items );
	assert.strictEqual( store.get().odoo_order_pricelist_id, null, 'An id that is not in the list is not chosen.' );

	fakeFetch( { 'odoo-pricelists': 'odoo_pricelists_not_configured' } );
	const bad = await card.loadPricelists( ctx );
	assert.equal( bad.ok, false );
	assert.equal( bad.reply.code, 'odoo_not_configured' );
} );

test( 'a Django refusal marks the named input and puts the message below it', () => {
	const attrs = {};
	const place = { textContent: '', hidden: true };
	const other = { textContent: 'old', hidden: false };
	const input = { setAttribute: ( k, v ) => { attrs[ k ] = v; }, removeAttribute: ( k ) => { delete attrs[ k ]; } };
	const form = {
		elements: { enable_price_sync: input },
		querySelector: ( sel ) => ( sel === '[data-error-for="enable_price_sync"]' ? place : null ),
		querySelectorAll: ( sel ) => ( sel === '[data-error-for]' ? [ place, other ] : sel === '[aria-invalid]' ? [ input ] : [] ),
	};

	card.showFieldError( form, { ok: false, code: 'invalid_value', message: 'Add at least one price mapping before enabling the price sync.', field: 'enable_price_sync' } );
	assert.equal( attrs[ 'aria-invalid' ], 'true' );
	assert.equal( place.textContent, 'Add at least one price mapping before enabling the price sync.' );
	assert.equal( place.hidden, false );
	assert.equal( other.textContent, '', 'Other messages are cleared.' );

	card.showFieldError( form, { ok: false, code: 'x', message: 'No field.' } );
	assert.equal( attrs[ 'aria-invalid' ], undefined );
	assert.equal( place.hidden, true );
} );
