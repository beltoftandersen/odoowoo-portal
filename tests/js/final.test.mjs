import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readdirSync, readFileSync } from 'node:fs';

import { fixtureRes } from './fixtures.mjs';

const require = createRequire( import.meta.url );
const core = require( '../../assets/js/core.js' );
const logs = require( '../../assets/js/card-sync-logs.js' );
const odooCard = require( '../../assets/js/card-odoo-settings.js' );

const REST = 'https://x.test/wp-json/odoowoo-portal/v1/';
const JS_DIR = new URL( '../../assets/js/', import.meta.url );
const sources = readdirSync( JS_DIR ).filter( ( f ) => f.endsWith( '.js' ) ).map( ( f ) => [ f, readFileSync( new URL( f, JS_DIR ), 'utf8' ) ] );

function boot( i18n = {} ) {
	globalThis.odoowooPortal = { rest: REST, nonce: 'n1', nonceUrl: 'https://x.test/n', i18n };
}

test( 'test_fill_uses_a_function_replacer_so_dollar_sequences_stay_text', () => {
	assert.equal( core.fill( 'Odoo %s', 'S$&1' ), 'Odoo S$&1' );
	assert.equal( core.fill( 'Odoo %s', "S$'1" ), "Odoo S$'1" );
	assert.equal( core.fill( 'Odoo %s', 'S$`1' ), 'Odoo S$`1' );
	assert.equal( core.fill( '%1$s of %2$s', 'a$&', 'b$1' ), 'a$& of b$1' );
	assert.equal( core.fill( '%s and %s', 1, 2 ), '1 and 1', 'A plain %s is the first value.' );
	assert.equal( core.fill( 'none', 'x' ), 'none' );
} );

test( 'test_sync_log_references_keep_dollar_sequences', () => {
	boot();
	const v = logs.rowView( { woocommerce_order_id: 'W$&1', odoo_order_id: "S$'2", status: 'success', status_text: 'Synced', log: '' } );
	assert.equal( v.odoo, "Odoo S$'2" );
	assert.equal( v.woo, 'W$&1' );
} );

test( 'test_no_script_replaces_percent_s_with_a_string_replacer', () => {
	for ( const [ file, code ] of sources ) {
		assert.ok( ! /\.replace\(\s*'%(?:s|\d\$s)'/.test( code ), file + ' uses a string replacer for a placeholder.' );
	}
} );

test( 'test_one_translator_and_one_url_check_in_core', () => {
	const lookups = sources.filter( ( [ , code ] ) => code.includes( 'odoowooPortal.i18n' ) ).map( ( [ file ] ) => file );
	assert.deepEqual( lookups, [ 'core.js' ], 'Only core reads the boot strings.' );

	const urlChecks = sources.map( ( [ file, code ] ) => [ file, ( code.match( /https\?:\\\/\\\//g ) || [] ).length ] ).filter( ( [ , n ] ) => n > 0 );
	assert.deepEqual( urlChecks, [ [ 'core.js', 1 ] ], 'One regex for "a link the app may follow".' );
} );

test( 'test_safe_url_accepts_http_and_site_paths_only', () => {
	for ( const ok of [ 'https://x.test/a', 'http://x.test', '/a/b?c=1', '/' ] ) {
		assert.equal( core.safeUrl( ok ), true, ok );
	}
	for ( const bad of [ '//evil.test', 'javascript:alert(1)', 'data:text/html,x', '', 'ftp://x', undefined, null, 5 ] ) {
		assert.equal( core.safeUrl( bad ), false, String( bad ) );
	}
} );

test( 'test_translator_falls_back_and_prefers_boot_strings', () => {
	boot( { hello: 'Hej' } );
	const t = core.translator( { hello: 'Hello', bye: 'Bye' } );
	assert.equal( t( 'hello' ), 'Hej' );
	assert.equal( t( 'bye' ), 'Bye' );
	assert.equal( t( 'nope' ), '' );
} );

test( 'test_dead_secret_helpers_are_gone', () => {
	assert.equal( core.secretField, undefined );
	assert.equal( core.secretValue, undefined );
} );

test( 'test_optional_fields_are_sent_when_present_but_not_needed_for_complete', () => {
	const store = core.createStore( { fields: [ 'a', 'pw', 'opt' ], secrets: [ 'pw' ], optional: [ 'opt' ] } );
	store.load( { a: 1 } );
	assert.equal( store.complete(), true, 'Django treats the optional field as unchanged when absent.' );
	assert.deepEqual( store.body(), { a: 1 } );

	store.load( { a: 1, opt: 'x' } );
	assert.deepEqual( store.body(), { a: 1, opt: 'x' } );

	const strict = core.createStore( { fields: [ 'a', 'b' ], secrets: [] } );
	strict.load( { a: 1 } );
	assert.equal( strict.complete(), false );

	assert.ok( core.odooSettingsStore, 'the shared store exists' );
	core.odooSettingsStore.load( {} );
	assert.equal( core.odooSettingsStore.complete(), false );
	const full = {};
	[ 'odoo_url', 'odoo_db', 'odoo_user', 'enable_auto_payment', 'enable_auto_payment_cn', 'enable_set_invoice_status', 'enable_reset_all_deliveries', 'enable_set_deliveries_to_waiting', 'enable_auto_complete_deliveries', 'odoo_order_pricelist_id', 'odoo_order_pricelist_name', 'capture_stage', 'confirm_pending_orders', 'price_includes_tax', 'order_incl_tax', 'quantity_settings', 'enable_brand_sync', 'enable_category_sync', 'enable_product_name_sync', 'enable_tag_sync', 'enable_image_sync', 'enable_price_sync', 'enable_barcode_sync' ].forEach( ( k ) => { full[ k ] = null; } );
	core.odooSettingsStore.load( full );
	assert.equal( core.odooSettingsStore.complete(), true, 'stock_*_meta_keys are optional.' );
} );

test( 'test_overlapping_refreshes_apply_only_the_newest_answer', async () => {
	boot();
	const P = 'licenses/4/dashboards/7/';
	let call = 0;
	// The first refresh's payment list is slow and says "has mappings"; the second is fast and says "none".
	globalThis.fetch = ( url ) => {
		const n = url.includes( P + 'payment-method-mappings' ) ? ++call : 0;
		const name = n === 1 ? 'payment_mappings_list' : ( n === 2 ? 'payment_mappings_empty' : ( url.includes( 'tax-mappings' ) ? 'tax_mappings_empty' : 'price_mappings_empty' ) );

		return new Promise( ( resolve ) => setTimeout( () => resolve( fixtureRes( name ) ), n === 1 ? 40 : 0 ) );
	};
	const seen = [];
	const checker = odooCard.createChecker( { licenseId: 4, dashboardId: 7 }, ( facts ) => seen.push( facts.hasPayment ) );

	const slow = checker.refresh();
	const fast = checker.refresh();
	await Promise.all( [ slow, fast ] );

	assert.equal( checker.facts().hasPayment, false, 'The older, slower answer must not overwrite the newer one.' );
	assert.deepEqual( seen, [ false ], 'And it does not report.' );
} );
