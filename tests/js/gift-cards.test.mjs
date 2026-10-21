import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

import { fixture, fixtureRes } from './fixtures.mjs';

const require = createRequire( import.meta.url );
const gift = require( '../../assets/js/card-gift-cards.js' );

// Every reply is a file produced by the real routes (tests/BrowserFixturesTest.php, gift_card_fixtures()).
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

const settingsOf = ( name ) => fixture( name ).body.data.settings;

async function loaded( name ) {
	boot();

	const store = gift.createStore();

	fakeFetch( [ name ] );
	await gift.ensureLoaded( ctx, store );

	return store;
}

test( 'test_gift_card_save_never_sends_expiry_tax_id', async () => {
	const store = await loaded( 'gift_card_settings_saved' );

	assert.equal( 'expiry_tax_id' in settingsOf( 'gift_card_settings_saved' ), true, 'Django does send it.' );

	// Even a stored value is not sent back (Django refuses any non-null one).
	const withTax = gift.createStore();

	withTax.load( Object.assign( {}, settingsOf( 'gift_card_settings_saved' ), { expiry_tax_id: 5 } ) );

	for ( const s of [ store, withTax ] ) {
		const calls = fakeFetch( [ 'gift_card_save_success', 'gift_card_settings_saved' ] );

		await gift.saveSettings( ctx, s );
		assert.equal( calls[ 0 ].method, 'PUT' );
		assert.equal( 'expiry_tax_id' in calls[ 0 ].body.settings, false );
		assert.equal( JSON.stringify( calls[ 0 ].body ).includes( 'expiry_tax_id' ), false );
	}
} );

test( 'test_gift_card_amount_is_a_string_in_the_body', async () => {
	const store = await loaded( 'gift_card_settings_saved' );

	let calls = fakeFetch( [ 'gift_card_save_success', 'gift_card_settings_saved' ] );

	await gift.saveSettings( ctx, store );
	assert.equal( calls[ 0 ].body.settings.expiry_min_balance, '2.50' );

	store.patch( { expiry_min_balance: 3 } );
	calls = fakeFetch( [ 'gift_card_save_success', 'gift_card_settings_saved' ] );
	await gift.saveSettings( ctx, store );
	assert.strictEqual( calls[ 0 ].body.settings.expiry_min_balance, '3', 'A number is made a string.' );

	store.patch( { expiry_min_balance: '' } );
	calls = fakeFetch( [ 'gift_card_save_success', 'gift_card_settings_saved' ] );
	await gift.saveSettings( ctx, store );
	assert.strictEqual( calls[ 0 ].body.settings.expiry_min_balance, '', 'Empty stays empty (Django reads it as 0.00).' );
} );

test( 'test_gift_card_save_sends_the_whole_object_and_rereads', async () => {
	const store = await loaded( 'gift_card_settings_saved' );
	const calls = fakeFetch( [ 'gift_card_save_success', 'gift_card_settings_saved' ] );
	const reply = await gift.saveSettings( ctx, store );

	assert.equal( reply.ok, true );
	assert.deepEqual( calls.map( ( c ) => [ c.method, c.url ] ), [ [ 'PUT', P + 'gift-card-settings' ], [ 'GET', P + 'gift-card-settings' ] ] );

	const sent = calls[ 0 ].body.settings;
	const expected = Object.assign( {}, settingsOf( 'gift_card_settings_saved' ) );

	delete expected.expiry_tax_id;
	assert.deepEqual( sent, expected, 'Every other setting goes back as read (legacy cutoff included).' );
	assert.equal( Object.keys( sent ).length, 21 );
} );

test( 'test_gift_card_save_is_refused_until_loaded', async () => {
	boot();

	const calls = fakeFetch( [ 'gift_card_save_success' ] );
	const reply = await gift.saveSettings( ctx, gift.createStore() );

	assert.equal( reply.ok, false );
	assert.equal( reply.code, 'settings_not_loaded' );
	assert.deepEqual( calls, [], 'Nothing is sent.' );

	// A failed read leaves the store unloaded, so a later save is still refused.
	const store = gift.createStore();

	fakeFetch( [ 'django_garbage' ] );
	assert.equal( ( await gift.ensureLoaded( ctx, store ) ).ok, false );
	assert.equal( ( await gift.saveSettings( ctx, store ) ).code, 'settings_not_loaded' );
} );

test( 'test_gift_card_django_refusal_is_shown_next_to_its_field', async () => {
	const store = await loaded( 'gift_card_settings_new' );

	store.patch( { enabled: true } );
	fakeFetch( [ 'gift_card_incomplete' ] );

	const reply = await gift.saveSettings( ctx, store );

	assert.equal( reply.ok, false );
	assert.equal( reply.message, 'Choose the exempt tax for gift card sales before turning this on.' );
	assert.equal( reply.field, 'sale_tax_id' );
	assert.equal( store.dirty(), true, 'A refused save keeps what was typed.' );
	assert.equal( store.get().enabled, true );

	for ( const name of [ 'gift_card_unknown_id', 'gift_card_sweep_needs_booking', 'gift_card_amount_invalid' ] ) {
		fakeFetch( [ name ] );

		const r = await gift.saveSettings( ctx, store );

		assert.equal( r.message, fixture( name ).body.message, name );
		assert.equal( r.field, fixture( name ).body.field, name );
	}
} );

test( 'test_load_lists_runs_three_loaders_then_rereads_the_stored_lists', async () => {
	boot();

	const calls = fakeFetch( [ 'odoo_taxes_fetched', 'odoo_journals', 'odoo_accounts', 'stored_odoo_taxes', 'saved_odoo_journals', 'saved_odoo_accounts' ] );
	const out = await gift.loadLists( ctx );

	assert.deepEqual( calls.map( ( c ) => c.method + ' ' + c.url ), [
		'POST ' + P + 'fetch-and-store-odoo-taxes',
		'GET ' + P + 'odoo-journals',
		'GET ' + P + 'odoo-accounts',
		'GET ' + P + 'stored-odoo-taxes',
		'GET ' + P + 'saved-odoo-journals',
		'GET ' + P + 'saved-odoo-accounts',
	] );
	assert.equal( out.ok, true );
	assert.deepEqual( out.taxes.map( ( t ) => t.id ), [ 3, 4, 5, 6 ], 'The picker reads the stored list, not the live one.' );
	assert.deepEqual( out.journals, [ { journal_id: 8, name: 'Bank' } ] );
	assert.deepEqual( out.accounts.map( ( a ) => a.account_id ), [ 41, 96 ] );
	assert.deepEqual( calls[ 0 ].body, undefined );
} );

test( 'test_load_lists_stops_at_the_first_refusal_and_says_which', async () => {
	boot();

	const calls = fakeFetch( [ 'odoo_taxes_fetched', 'odoo_not_configured_fixture' ].map( ( n ) => ( n === 'odoo_not_configured_fixture' ? 'not_configured' : n ) ) );
	const out = await gift.loadLists( ctx );

	assert.equal( out.ok, false );
	assert.equal( out.step, 'journals' );
	assert.equal( out.reply.code, 'odoo_not_configured' );
	assert.equal( out.reply.message, 'Save your Odoo connection settings first.' );
	assert.equal( calls.length, 2, 'Nothing after the refusal.' );

	const paused = fakeFetch( [ 'refusal_read_only' ] );
	const first = await gift.loadLists( ctx );

	assert.equal( first.ok, false );
	assert.equal( first.step, 'taxes' );
	assert.equal( paused.length, 1 );
} );

test( 'test_pickers_show_stored_sale_taxes_journals_and_accounts_code_first', () => {
	boot();

	const taxes = fixture( 'stored_odoo_taxes' ).body.data.items;
	const opts = gift.taxOptions( taxes, { sale_tax_id: null, sale_tax_name: '' } );

	assert.deepEqual( opts.map( ( o ) => o.value ), [ '', '3', '5' ], 'Only sale taxes, after the placeholder.' );
	assert.deepEqual( opts.map( ( o ) => o.label ), [ 'Select a sale tax', '21% VAT sales', '0% VAT sales' ] );

	// The saved tax stays selectable by its stored name when the list does not hold it.
	assert.deepEqual( gift.taxOptions( [], { sale_tax_id: 9, sale_tax_name: 'Old tax' } ).map( ( o ) => [ o.value, o.label ] ), [ [ '', 'Load taxes, journals and accounts first' ], [ '9', 'Old tax' ] ] );
	assert.equal( gift.taxOptions( [], { sale_tax_id: 9, sale_tax_name: '' } )[ 1 ].label, 'Tax 9' );

	const journals = fixture( 'saved_odoo_journals' ).body.data.items;

	assert.deepEqual( gift.journalOptions( journals, 8, '' ).map( ( o ) => [ o.value, o.label ] ), [ [ '', 'Select a journal' ], [ '8', 'Bank' ] ] );

	const accounts = fixture( 'saved_odoo_accounts' ).body.data.items;

	assert.deepEqual( gift.accountOptions( accounts, null ).map( ( o ) => [ o.value, o.label ] ), [ [ '', 'Select an account' ], [ '41', '2191 Gift card liability' ], [ '96', '7881 Breakage income' ] ] );
	assert.equal( gift.accountOptions( [], 12 )[ 1 ].label, 'Account 12' );

	// No VAT picker: the expiry tax is fixed.
	assert.deepEqual( gift.NOT_SENT, [ 'expiry_tax_id' ] );
} );

test( 'test_choosing_a_tax_or_journal_stores_its_name_too', async () => {
	const store = await loaded( 'gift_card_settings_new' );
	const taxes = fixture( 'stored_odoo_taxes' ).body.data.items;
	const journals = fixture( 'saved_odoo_journals' ).body.data.items;

	gift.chooseTax( store, '5', taxes );
	assert.equal( store.get().sale_tax_id, 5 );
	assert.equal( store.get().sale_tax_name, '0% VAT sales' );

	gift.chooseTax( store, '4', taxes );
	assert.equal( store.get().sale_tax_id, null, 'A purchase tax is not chosen.' );
	assert.equal( store.get().sale_tax_name, '' );

	gift.chooseJournal( store, 'redeem', '8', journals );
	assert.equal( store.get().redeem_journal_id, 8 );
	assert.equal( store.get().redeem_journal_name, 'Bank' );

	gift.chooseJournal( store, 'expiry', '8', journals );
	assert.equal( store.get().expiry_journal_id, 8 );
	assert.equal( store.get().expiry_journal_name, 'Bank' );

	gift.chooseJournal( store, 'expiry', '', journals );
	assert.equal( store.get().expiry_journal_id, null );
	assert.equal( store.get().expiry_journal_name, '' );

	gift.chooseAccount( store, 'expiry_debit_account_id', '41' );
	assert.strictEqual( store.get().expiry_debit_account_id, 41 );
	gift.chooseAccount( store, 'expiry_debit_account_id', '' );
	assert.strictEqual( store.get().expiry_debit_account_id, null );
} );
