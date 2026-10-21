import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const editor = require( '../../assets/js/mapping-editor.js' );

import { fixture, fixtureRes } from './fixtures.mjs';

// Every config is what the card prints (tests/CardMappingsTest.php); every reply is a file produced
// by the real routes (tests/BrowserFixturesTest.php, mapping_fixtures(), each Django body cited there).
const REST = 'https://x.test/wp-json/odoowoo-portal/v1/';
const ctx = { licenseId: 4, dashboardId: 7 };
const P = 'licenses/4/dashboards/7/';
const config = ( card ) => fixture( 'mapping_config_' + card.replace( /-/g, '_' ) ).body;
const items = ( name ) => fixture( name ).body.data.items;

function boot() {
	globalThis.odoowooPortal = { rest: REST, nonce: 'n1', nonceUrl: 'https://x.test/n', i18n: {} };
}

function fakeFetch( names ) {
	const calls = [];
	let i = 0;
	globalThis.fetch = ( url, opts ) => {
		calls.push( { url, method: opts.method, body: opts.body === undefined ? undefined : JSON.parse( opts.body ) } );
		return Promise.resolve( fixtureRes( names[ Math.min( i++, names.length - 1 ) ] ) );
	};
	return calls;
}

function modelOf( card, listFixture ) {
	const m = editor.createModel( config( card ) );
	if ( listFixture ) {
		m.loadRows( items( listFixture ) );
	}
	return m;
}

const reply = ( name ) => {
	const { status, body } = fixture( name );
	return body.ok === true ? { ok: true, data: body.data, status } : { ok: false, code: body.code, message: body.message, field: body.field, status };
};

test( 'test_rows_add_remove_and_duplicate_check', () => {
	const m = modelOf( 'payment-mappings' );

	m.loadRows( [] );
	assert.deepEqual( m.rows(), [] );

	m.addRow();
	m.addRow();
	m.addRow();
	assert.equal( m.rows().length, 3 );
	m.removeRow( 1 );
	assert.equal( m.rows().length, 2 );
	m.removeRow( 99 );
	assert.equal( m.rows().length, 2, 'An unknown row is ignored.' );

	// Same Woo method twice (Django: "WooCommerce payment method 'x' is duplicated.").
	m.feedSaved( { route: 'saved-woocommerce-payment-methods', idKey: 'method_id', nameKey: 'title', into: 'woocommerce-payment-methods' }, items( 'saved_woo_payment_methods' ) );
	m.feedSaved( { route: 'saved-odoo-journals', idKey: 'journal_id', nameKey: 'name', into: 'odoo-journals' }, items( 'saved_odoo_journals' ) );
	m.setValue( 0, 'woocommerce_payment_method', 'bacs' );
	m.setValue( 0, 'odoo_journal_id', '8' );
	m.setValue( 1, 'woocommerce_payment_method', 'bacs' );
	m.setValue( 1, 'odoo_journal_id', '8' );

	const errors = m.validate();
	assert.deepEqual( errors.map( ( e ) => [ e.row, e.key, e.message ] ), [ [ 1, 'woocommerce_payment_method', 'Row 2 repeats row 1.' ] ] );

	m.setValue( 1, 'woocommerce_payment_method', '' );
	assert.equal( m.validate()[ 0 ].message, 'Row 2: choose a payment method.', 'Blank is not a duplicate of anything.' );

	// Pair keys (route rules): only the same key AND value repeats.
	const r = modelOf( 'order-route-mappings' );
	r.feedSaved( { route: 'odoo-routes', idKey: 'id', nameKey: 'name' }, items( 'odoo_routes' ) );
	[ [ 'source', 'pos' ], [ 'source', 'web' ], [ ' source ', 'pos ' ] ].forEach( ( [ k, v ], i ) => {
		r.addRow();
		r.setValue( i, 'meta_key', k );
		r.setValue( i, 'meta_value', v );
		r.setValue( i, 'odoo_route_id', '2' );
	} );
	assert.deepEqual( r.validate().map( ( e ) => [ e.row, e.key, e.message ] ), [ [ 2, 'meta_value', 'Row 3 repeats row 1.' ] ], 'Text is compared as Django does: stripped.' );

	// Price and stock: the pair is (target field, meta key) and a meta key only counts for a meta target.
	const p = modelOf( 'price-mappings' );
	p.addRow();
	p.addRow();
	assert.equal( p.validate().length, 1, 'Two default rows both write regular_price.' );
	assert.equal( p.validate()[ 0 ].message, 'Row 2 repeats row 1.' );
	p.setValue( 1, 'target_field', 'sale_price' );
	assert.deepEqual( p.validate(), [] );
	p.setValue( 0, 'target_field', 'meta' );
	p.setValue( 1, 'target_field', 'meta' );
	p.setValue( 0, 'target_meta_key', 'a' );
	p.setValue( 1, 'target_meta_key', 'b' );
	assert.deepEqual( p.validate(), [] );
	p.setValue( 1, 'target_meta_key', 'a' );
	assert.equal( p.validate()[ 0 ].key, 'target_meta_key', 'Marked on the key that differs.' );

	const s = modelOf( 'stock-mappings' );
	s.feedSaved( { route: 'odoo-stock-locations', idKey: 'id', nameKey: 'name' }, items( 'odoo_stock_locations' ) );
	s.addRow();
	s.addRow();
	s.setValue( 0, 'odoo_location_id', '12' );
	s.setValue( 1, 'odoo_location_id', '15' );
	assert.equal( s.validate()[ 0 ].message, 'Row 2 repeats row 1.', 'Stock quantity written twice.' );
} );

test( 'test_odoowoo_mistake_required_mapping_fields_refused', async () => {
	boot();
	const calls = fakeFetch( [ 'payment_save_success' ] );
	const m = modelOf( 'payment-mappings', 'payment_mappings_list' );

	m.applyLoader( 0, reply( 'woo_payment_methods' ) );
	m.addRow();
	m.setValue( 1, 'woocommerce_payment_method', 'cod' );
	// Journal 0 is what an empty picker sends; Django refuses it ("Choose an Odoo journal for every payment method.").
	const errors = m.validate();

	assert.deepEqual( errors.map( ( e ) => [ e.row, e.key, e.message ] ), [ [ 1, 'odoo_journal_id', 'Row 2: choose a journal.' ] ] );

	const out = await editor.saveList( ctx, m, () => assert.fail( 'No event for a save that did not happen.' ) );

	assert.equal( out.ok, false );
	assert.equal( out.errors.length, 1 );
	assert.equal( calls.length, 0, 'Nothing is sent.' );

	// A journal of 0, an empty one, a negative one and NaN are all "not chosen".
	for ( const bad of [ 0, '', null, -3, NaN ] ) {
		const x = modelOf( 'payment-mappings', 'payment_mappings_list' );
		x.setRaw( 0, 'odoo_journal_id', bad );
		assert.equal( x.validate().length, 1, String( bad ) );
	}

	// Tax: both ids are needed, and the class (Django would skip a blank class silently).
	const t = modelOf( 'tax-mappings', 'tax_mappings_list' );
	assert.deepEqual( t.validate().map( ( e ) => [ e.row, e.key, e.message ] ), [ [ 0, 'odoo_supplier_tax_id', 'Row 1: choose a supplier tax.' ] ], 'The saved row has no supplier tax.' );
	t.setRaw( 0, 'odoo_customer_tax_id', 0 );
	assert.equal( t.validate()[ 0 ].message, 'Row 1: choose a customer tax.' );

	// Required text: the order rules.
	const r = modelOf( 'order-route-mappings' );
	r.addRow();
	assert.deepEqual( r.validate().map( ( e ) => e.message ), [ 'Row 1: enter the meta key.', 'Row 1: enter the meta value.', 'Row 1: choose a route.' ] );
	r.setValue( 0, 'meta_key', '   ' );
	assert.equal( r.validate()[ 0 ].message, 'Row 1: enter the meta key.', 'Spaces are blank.' );
} );

test( 'test_odoowoo_mistake_price_rows_keep_saved_pricelist', () => {
	const m = modelOf( 'price-mappings', 'price_mappings_two_rows' );

	// No pricelist has been loaded: the row still shows its stored pricelist name as the choice.
	assert.deepEqual( m.options( 1, 'odoo_pricelist_id' ), [ { value: '6', label: 'Retail DKK' } ] );
	assert.equal( m.rows()[ 1 ].odoo_pricelist_name, 'Retail DKK' );
	assert.equal( m.shown( 1, 'odoo_pricelist_id' ), true );
	assert.equal( m.shown( 0, 'odoo_pricelist_id' ), false, 'A sales price row has no pricelist.' );

	// A load that does not list this pricelist does not take it away from the row.
	m.applyLoader( 0, reply( 'odoo_pricelists' ) );
	assert.deepEqual( m.options( 1, 'odoo_pricelist_id' ).map( ( o ) => o.value ), [ '5', '6' ], 'Retail DKK (6) is in the load: it is listed once, in the load\'s order.' );

	const body = m.body();

	assert.equal( body.items[ 1 ].odoo_pricelist_id, 6, 'Save sends the saved id unchanged.' );
	assert.equal( body.items[ 1 ].odoo_pricelist_name, 'Retail DKK' );

	// A pricelist that is gone from Odoo (not in any list) stays.
	const gone = editor.createModel( config( 'price-mappings' ) );
	gone.loadRows( [ { id: 3, source: 'pricelist', odoo_pricelist_id: 99, odoo_pricelist_name: 'Old wholesale', target_field: 'regular_price', target_meta_key: '', tax_treatment: 'as_is', require_explicit_rule: false } ] );
	gone.applyLoader( 0, reply( 'odoo_pricelists' ) );
	assert.deepEqual( gone.options( 0, 'odoo_pricelist_id' ).map( ( o ) => [ o.value, o.label ] ), [ [ '99', 'Old wholesale' ], [ '5', 'Public Pricelist (EUR)' ], [ '6', 'Retail DKK (DKK)' ] ] );
	assert.deepEqual( gone.body().items[ 0 ], { source: 'pricelist', odoo_pricelist_id: 99, odoo_pricelist_name: 'Old wholesale', target_field: 'regular_price', target_meta_key: '', tax_treatment: 'as_is', require_explicit_rule: false } );
} );

test( 'test_odoowoo_mistake_tax_refresh_empty_reply', () => {
	const m = modelOf( 'tax-mappings', 'tax_mappings_list' );

	m.feedSaved( { route: 'stored-odoo-taxes', idKey: 'id', nameKey: 'name', into: 'stored-odoo-taxes' }, items( 'stored_odoo_taxes' ) );

	const before = JSON.stringify( [ m.rows(), m.options( 0, 'odoo_customer_tax_id' ), m.options( 0, 'odoo_supplier_tax_id' ) ] );
	// Django answers 204 when Odoo has no taxes; the portal turns that into an empty list.
	const out = m.applyLoader( 1, reply( 'odoo_taxes_fetched_empty' ) );

	assert.equal( out.kind, 'empty' );
	assert.equal( out.message, 'Odoo returned no taxes.' );
	assert.equal( JSON.stringify( [ m.rows(), m.options( 0, 'odoo_customer_tax_id' ), m.options( 0, 'odoo_supplier_tax_id' ) ] ), before, 'Rows and choices are kept.' );

	// The same for a loader that is not the tax one.
	const j = modelOf( 'payment-mappings' );
	assert.equal( j.applyLoader( 1, reply( 'odoo_journals_empty' ) ).message, 'Odoo returned no journals.' );
} );

test( 'test_save_sends_whole_list_and_empty_list_allowed', async () => {
	boot();
	let calls = fakeFetch( [ 'payment_save_success' ] );
	const m = modelOf( 'payment-mappings', 'payment_mappings_list' );
	const events = [];

	m.setValue( 0, 'payment_ref_meta_keys', '  _txn_id , ref  ' );
	m.feedSaved( { route: 'saved-woocommerce-payment-methods', idKey: 'method_id', nameKey: 'title', into: 'woocommerce-payment-methods' }, items( 'saved_woo_payment_methods' ) );
	m.applyLoader( 0, reply( 'woo_payment_methods' ) );
	m.applyLoader( 1, reply( 'odoo_journals' ) );
	m.addRow();
	m.setValue( 1, 'woocommerce_payment_method', 'cod' );
	m.setValue( 1, 'odoo_journal_id', '9' );

	let out = await editor.saveList( ctx, m, ( route ) => events.push( route ) );

	assert.equal( out.ok, true );
	assert.equal( calls.length, 1 );
	assert.equal( calls[ 0 ].method, 'PUT' );
	assert.equal( calls[ 0 ].url, REST + P + 'payment-method-mappings' );
	assert.deepEqual( calls[ 0 ].body, { items: [
		{ woocommerce_payment_method: 'bacs', woocommerce_payment_method_name: 'Bank transfer', odoo_journal_id: 8, odoo_journal_name: 'Bank', payment_ref_meta_keys: '_txn_id , ref' },
		{ woocommerce_payment_method: 'cod', woocommerce_payment_method_name: 'Cash on delivery', odoo_journal_id: 9, odoo_journal_name: 'Cash', payment_ref_meta_keys: '' },
	] }, 'The whole list, names from the chosen options, no row ids.' );
	assert.deepEqual( events, [ 'payment-method-mappings' ], 'The saved event fires once, after the save.' );

	// Everything removed is a valid list: it replaces the stored one with nothing.
	calls = fakeFetch( [ 'payment_save_success' ] );
	m.removeRow( 1 );
	m.removeRow( 0 );
	out = await editor.saveList( ctx, m, ( route ) => events.push( route ) );
	assert.equal( out.ok, true );
	assert.deepEqual( calls[ 0 ].body, { items: [] } );
	assert.equal( events.length, 2 );

	// A refusal of Django is shown as it is and fires no event.
	calls = fakeFetch( [ 'order_route_save_duplicate' ] );
	const r = modelOf( 'order-route-mappings' );
	r.loadRows( [] );
	r.feedSaved( { route: 'odoo-routes', idKey: 'id', nameKey: 'name' }, items( 'odoo_routes' ) );
	r.addRow();
	r.setValue( 0, 'meta_key', 'source' );
	r.setValue( 0, 'meta_value', 'pos' );
	r.setValue( 0, 'odoo_route_id', '2' );
	out = await editor.saveList( ctx, r, () => assert.fail( 'No event after a refusal.' ) );
	assert.equal( out.ok, false );
	assert.equal( out.reply.message, 'Two rules both match source=pos. Each pair takes one route.' );
	assert.deepEqual( editor.refusalTarget( config( 'order-route-mappings' ), out.reply ), { key: 'meta_value', message: 'Two rules both match source=pos. Each pair takes one route.' } );
	assert.equal( editor.refusalTarget( config( 'order-route-mappings' ), { message: 'x', field: 'mappings' } ).key, '', 'A field that is no column is a general message.' );
} );

test( 'test_save_is_refused_until_the_list_has_loaded', async () => {
	boot();
	const calls = fakeFetch( [ 'payment_save_success' ] );
	const m = modelOf( 'payment-mappings' ); // Never loaded: its stored rows are unknown.

	assert.equal( m.isLoaded(), false );
	assert.equal( editor.canSave( m, { canWrite: true } ), false );

	const out = await editor.saveList( ctx, m, () => assert.fail( 'No event.' ) );

	assert.equal( out.ok, false );
	assert.equal( out.reply.code, 'settings_not_loaded', 'Saving nothing over the stored rows is the worse mistake.' );
	assert.equal( calls.length, 0 );

	m.loadRows( [] );
	assert.equal( editor.canSave( m, { canWrite: true } ), true );
	assert.equal( editor.canSave( m, { canWrite: false } ), false, 'Read-only viewers cannot save.' );
} );

test( 'test_loader_reads_items_for_every_route', () => {
	// Fixture of each loader route's real reply, shaped to {items} by the portal (also the three Odoo pickers).
	const FIXTURE = {
		'woocommerce-payment-methods': 'woo_payment_methods',
		'odoo-journals': 'odoo_journals',
		'woo-tax-classes': 'woo_tax_classes',
		'fetch-and-store-odoo-taxes': 'odoo_taxes_fetched',
		'odoo-pricelists': 'odoo_pricelists',
		'odoo-stock-locations': 'odoo_stock_locations',
		'odoo-routes': 'odoo_routes',
	};
	const seen = new Set();

	[ 'payment-mappings', 'tax-mappings', 'price-mappings', 'stock-mappings', 'order-route-mappings', 'order-pricelist-mappings' ].forEach( ( card ) => {
		const cfg = config( card );

		cfg.loaders.forEach( ( loader, index ) => {
			assert.ok( FIXTURE[ loader.route ], card + ': a fixture for ' + loader.route );
			seen.add( loader.route );

			const m = editor.createModel( cfg );
			const raw = reply( FIXTURE[ loader.route ] );
			const out = m.applyLoader( index, raw );
			const pool = loader.into || loader.route;
			const col = cfg.columns.find( ( c ) => c.source && c.source.route === pool );

			assert.equal( out.kind, 'loaded', card + ' ' + loader.route );
			m.loadRows( [] );
			m.addRow();

			const options = m.options( 0, col.key );
			const expected = raw.data.items.filter( ( i ) => ! col.source.filter || Object.entries( col.source.filter ).every( ( [ k, v ] ) => i[ k ] === v ) );

			assert.deepEqual( options.map( ( o ) => o.value ), expected.map( ( i ) => String( i[ col.source.idKey ] ) ), card + ' ' + loader.route + ' options (ids)' );
			const label = ( i ) => i[ col.source.nameKey ] + ( col.source.suffixKey && ! i[ col.source.nameKey ].includes( '(' + i[ col.source.suffixKey ] + ')' ) ? ' (' + i[ col.source.suffixKey ] + ')' : '' );

			assert.deepEqual( options.map( ( o ) => o.label ), expected.map( label ), card + ' ' + loader.route + ' options (names)' );
			assert.ok( options.length > 0, card + ' ' + loader.route );
		} );
	} );

	assert.deepEqual( [ ...seen ].sort(), Object.keys( FIXTURE ).sort(), 'Every loader route is covered.' );

	// A body that is not {items: [...]}, or items that are not rows, give nothing.
	const m = modelOf( 'order-route-mappings' );
	[ { ok: true, data: {} }, { ok: true, data: { items: 'x' } }, { ok: true, data: { items: [ null, 3, { id: 0, name: 'Zero' }, { id: 2 }, { id: 'x', name: 'S' }, { id: 2.5, name: 'F' } ] } } ].forEach( ( bad ) => {
		assert.equal( m.applyLoader( 0, bad ).kind, 'empty', JSON.stringify( bad ) );
		assert.deepEqual( m.options( 0, 'odoo_route_id' ) || [], [] );
	} );
} );

test( 'test_loader_failures_keep_what_is_there', () => {
	const m = modelOf( 'payment-mappings', 'payment_mappings_list' );
	const before = JSON.stringify( [ m.rows(), m.options( 0, 'woocommerce_payment_method' ) ] );

	const nc = m.applyLoader( 0, reply( 'woo_not_configured' ) );
	assert.equal( nc.kind, 'not-configured', 'The Connections link is shown for this.' );
	assert.equal( nc.message, 'Save your WooCommerce connection settings first.' );

	const odoo = m.applyLoader( 1, reply( 'not_configured' ) );
	assert.equal( odoo.kind, 'not-configured' );
	assert.equal( odoo.message, 'Save your Odoo connection settings first.' );

	const failed = m.applyLoader( 1, { ok: false, code: 'odoo_unavailable', message: 'Could not fetch the data from Odoo. Check your Odoo connection and try again.', status: 500 } );
	assert.equal( failed.kind, 'failed' );
	assert.equal( failed.message, 'Could not fetch the data from Odoo. Check your Odoo connection and try again.' );
	assert.equal( JSON.stringify( [ m.rows(), m.options( 0, 'woocommerce_payment_method' ) ] ), before );
} );

test( 'test_conditional_columns', () => {
	const m = modelOf( 'price-mappings' );

	m.loadRows( [] );
	m.addRow();
	assert.equal( m.rows()[ 0 ].source, 'list_price', 'A new row starts as the sales price.' );
	assert.equal( m.rows()[ 0 ].target_field, 'regular_price' );
	assert.equal( m.rows()[ 0 ].tax_treatment, 'as_is' );
	assert.equal( m.shown( 0, 'odoo_pricelist_id' ), false );
	assert.equal( m.shown( 0, 'target_meta_key' ), false );
	assert.equal( m.shown( 0, 'source' ), true );
	assert.deepEqual( m.validate(), [], 'A hidden column is not required.' );

	m.setValue( 0, 'source', 'pricelist' );
	assert.equal( m.shown( 0, 'odoo_pricelist_id' ), true );
	assert.deepEqual( m.validate().map( ( e ) => e.message ), [ 'Row 1: choose a pricelist.' ] );

	m.setValue( 0, 'target_field', 'meta' );
	assert.deepEqual( m.validate().map( ( e ) => e.message ), [ 'Row 1: choose a pricelist.', 'Row 1: enter the meta key to write.' ] );

	// What is hidden is not sent, whatever was typed before.
	m.applyLoader( 0, reply( 'odoo_pricelists' ) );
	m.setValue( 0, 'odoo_pricelist_id', '5' );
	m.setValue( 0, 'target_meta_key', 'wholesale' );
	m.setValue( 0, 'source', 'list_price' );
	m.setValue( 0, 'target_field', 'sale_price' );
	assert.deepEqual( m.validate(), [] );
	assert.deepEqual( m.body().items[ 0 ], { source: 'list_price', odoo_pricelist_id: null, odoo_pricelist_name: '', target_field: 'sale_price', target_meta_key: '', tax_treatment: 'as_is', require_explicit_rule: false } );

	// The checkbox is a boolean.
	m.setValue( 0, 'require_explicit_rule', true );
	assert.equal( m.body().items[ 0 ].require_explicit_rule, true );

	// Stock: a new row is "available" into the stock quantity.
	const s = modelOf( 'stock-mappings' );
	s.loadRows( [] );
	s.addRow();
	assert.equal( s.rows()[ 0 ].quantity_setting, 'available' );
	assert.equal( s.rows()[ 0 ].target_field, 'stock_quantity' );
} );

test( 'test_tax_names_come_from_stored_taxes_and_sale_purchase_split', () => {
	const m = modelOf( 'tax-mappings' );

	m.loadRows( [ { id: 1, woocommerce_tax_class: 'standard', odoo_customer_tax_id: 3, odoo_supplier_tax_id: 4 } ] );
	assert.deepEqual( m.rows()[ 0 ], { woocommerce_tax_class: 'standard', odoo_customer_tax_id: 3, odoo_supplier_tax_id: 4 }, 'A tax row holds ids, no names.' );
	assert.deepEqual( m.options( 0, 'odoo_customer_tax_id' ), [ { value: '3', label: '#3' } ], 'Before the stored taxes are read there is only the id.' );

	m.feedSaved( { route: 'stored-odoo-taxes', idKey: 'id', nameKey: 'name', into: 'stored-odoo-taxes' }, items( 'stored_odoo_taxes' ) );

	assert.deepEqual( m.options( 0, 'odoo_customer_tax_id' ), [ { value: '3', label: '21% VAT sales' }, { value: '5', label: '0% VAT sales' } ], 'Customer taxes are the sale ones.' );
	assert.deepEqual( m.options( 0, 'odoo_supplier_tax_id' ), [ { value: '4', label: '21% VAT purchases' }, { value: '6', label: '0% VAT purchases' } ], 'Supplier taxes are the purchase ones.' );

	// A saved choice whose tax is of the other kind (or gone) stays visible, named when known.
	m.setRaw( 0, 'odoo_customer_tax_id', 4 );
	assert.deepEqual( m.options( 0, 'odoo_customer_tax_id' ).map( ( o ) => [ o.value, o.label ] ), [ [ '4', '21% VAT purchases' ], [ '3', '21% VAT sales' ], [ '5', '0% VAT sales' ] ] );

	// Choosing sets the id only; the body has ids and the class.
	m.setValue( 0, 'odoo_customer_tax_id', '5' );
	assert.deepEqual( m.body(), { items: [ { woocommerce_tax_class: 'standard', odoo_customer_tax_id: 5, odoo_supplier_tax_id: 4 } ] } );

	// The refresh replaces the stored list with the loaded one.
	assert.equal( m.applyLoader( 1, reply( 'odoo_taxes_fetched' ) ).kind, 'loaded' );
	assert.deepEqual( m.options( 0, 'odoo_supplier_tax_id' ).map( ( o ) => o.value ), [ '4' ] );

	// Tax classes: the saved class stays visible without a load, its label is its value.
	assert.deepEqual( m.options( 0, 'woocommerce_tax_class' ), [ { value: 'standard', label: 'standard' } ] );
	m.applyLoader( 0, reply( 'woo_tax_classes' ) );
	assert.deepEqual( m.options( 0, 'woocommerce_tax_class' ).map( ( o ) => o.label ), [ 'Standard rate', 'Reduced rate', 'Zero rate' ] );
} );

test( 'test_blank_tax_class_row_is_refused_client_side', async () => {
	boot();
	const calls = fakeFetch( [ 'tax_save_success' ] );
	const m = modelOf( 'tax-mappings' );

	m.loadRows( [] );
	m.feedSaved( { route: 'stored-odoo-taxes', idKey: 'id', nameKey: 'name', into: 'stored-odoo-taxes' }, items( 'stored_odoo_taxes' ) );
	m.addRow();
	m.setValue( 0, 'odoo_customer_tax_id', '3' );
	m.setValue( 0, 'odoo_supplier_tax_id', '4' );
	// Django skips a row with a blank class without telling (views.py:2375-2376): the editor must not let it through.
	assert.deepEqual( m.validate().map( ( e ) => [ e.row, e.key, e.message ] ), [ [ 0, 'woocommerce_tax_class', 'Row 1: choose a tax class.' ] ] );

	const out = await editor.saveList( ctx, m, () => assert.fail( 'no event' ) );

	assert.equal( out.ok, false );
	assert.equal( calls.length, 0 );
} );

test( 'test_select_choice_sets_the_stored_name', () => {
	const m = modelOf( 'stock-mappings' );

	m.loadRows( [] );
	m.applyLoader( 0, reply( 'odoo_stock_locations' ) );
	m.addRow();
	m.setValue( 0, 'odoo_location_id', '15' );
	assert.equal( m.rows()[ 0 ].odoo_location_id, 15 );
	assert.equal( m.rows()[ 0 ].odoo_location_name, 'WH/Stock/Shelf 1' );

	m.setValue( 0, 'odoo_location_id', '999' );
	assert.equal( m.rows()[ 0 ].odoo_location_id, 15, 'A value that is not a choice is ignored.' );

	m.setValue( 0, 'odoo_location_id', '' );
	assert.equal( m.rows()[ 0 ].odoo_location_id, null );
	assert.equal( m.rows()[ 0 ].odoo_location_name, '' );

	// Fixed choices (quantity) are choices too, and nothing else.
	m.setValue( 0, 'quantity_setting', 'forecast' );
	assert.equal( m.rows()[ 0 ].quantity_setting, 'forecast' );
	m.setValue( 0, 'quantity_setting', 'bogus' );
	assert.equal( m.rows()[ 0 ].quantity_setting, 'forecast' );
} );

test( 'test_reading_a_list_keeps_unknown_values_as_they_are', () => {
	const m = modelOf( 'stock-mappings', 'stock_mappings_list' );

	assert.equal( m.rows().length, 2 );
	assert.equal( m.rows()[ 1 ].target_meta_key, 'shelf_stock' );
	assert.equal( m.shown( 1, 'target_meta_key' ), true );
	assert.equal( m.shown( 0, 'target_meta_key' ), false );
	assert.deepEqual( m.options( 0, 'odoo_location_id' ), [ { value: '12', label: 'WH/Stock' } ], 'Saved names are shown before anything is loaded.' );
	assert.deepEqual( m.body().items[ 0 ], { odoo_location_id: 12, odoo_location_name: 'WH/Stock', quantity_setting: 'available', target_field: 'stock_quantity', target_meta_key: '' } );
	assert.deepEqual( m.validate(), [] );
} );

test( 'test_loader_buttons_follow_the_permissions', () => {
	const payment = config( 'payment-mappings' );
	const tax = config( 'tax-mappings' );
	const byRoute = ( cfg, route ) => cfg.loaders.find( ( l ) => l.route === route );

	const owner = { canWrite: true, canRead: true };
	const readOnly = { canWrite: false, canRead: true }; // A paused owner, or support.
	const notReady = { canWrite: false, canRead: false };

	[ 'woocommerce-payment-methods', 'odoo-journals' ].forEach( ( route ) => {
		assert.equal( editor.loaderEnabled( byRoute( payment, route ), owner ), true, route );
		assert.equal( editor.loaderEnabled( byRoute( payment, route ), readOnly ), false, route + ': writes Django rows' );
	} );
	assert.equal( editor.loaderEnabled( byRoute( tax, 'fetch-and-store-odoo-taxes' ), readOnly ), false );
	assert.equal( editor.loaderEnabled( byRoute( tax, 'fetch-and-store-odoo-taxes' ), owner ), true );
	assert.equal( editor.loaderEnabled( byRoute( tax, 'woo-tax-classes' ), readOnly ), true, 'A pure read stays on.' );
	assert.equal( editor.loaderEnabled( byRoute( tax, 'woo-tax-classes' ), notReady ), false, 'No Django call before the tenant is ready.' );

	[ 'price-mappings', 'stock-mappings', 'order-route-mappings', 'order-pricelist-mappings' ].forEach( ( card ) => {
		assert.equal( editor.loaderEnabled( config( card ).loaders[ 0 ], readOnly ), true, card );
	} );

	assert.equal( editor.canSave( modelOf( 'tax-mappings', 'tax_mappings_list' ), readOnly ), false );
} );

test( 'test_read_all_reads_the_list_and_the_saved_names', async () => {
	boot();
	const calls = fakeFetch( [ 'payment_mappings_list', 'saved_woo_payment_methods', 'saved_odoo_journals' ] );
	const m = editor.createModel( config( 'payment-mappings' ) );

	const out = await editor.readAll( ctx, m );

	assert.equal( out.ok, true );
	assert.deepEqual( calls.map( ( c ) => [ c.method, c.url ] ), [
		[ 'GET', REST + P + 'payment-method-mappings' ],
		[ 'GET', REST + P + 'saved-woocommerce-payment-methods' ],
		[ 'GET', REST + P + 'saved-odoo-journals' ],
	], 'Only pure reads; never a loader.' );
	assert.equal( m.isLoaded(), true );
	assert.equal( m.rows().length, 1 );
	assert.deepEqual( m.options( 0, 'odoo_journal_id' ), [ { value: '8', label: 'Bank' } ] );

	// The list failing leaves the editor unloaded (Save stays off) and says why.
	fakeFetch( [ 'dashboard_not_found' ] );
	const bad = editor.createModel( config( 'payment-mappings' ) );
	const failed = await editor.readAll( ctx, bad );

	assert.equal( failed.ok, false );
	assert.equal( failed.reply.code, 'dashboard_not_found' );
	assert.equal( bad.isLoaded(), false );

	// The saved names failing does not stop the list (the rows carry their names).
	fakeFetch( [ 'payment_mappings_list', 'django_garbage', 'django_garbage' ] );
	const partial = editor.createModel( config( 'payment-mappings' ) );
	assert.equal( ( await editor.readAll( ctx, partial ) ).ok, true );
	assert.equal( partial.isLoaded(), true );
	assert.deepEqual( partial.options( 0, 'odoo_journal_id' ), [ { value: '8', label: 'Bank' } ] );
} );

test( 'test_every_row_input_gets_a_label_and_the_texts_are_translatable', () => {
	boot();
	globalThis.odoowooPortal.i18n = { rowRepeats: 'Rij %1$s herhaalt rij %2$s.', rowError: 'Rij %1$s: %2$s', rowLabel: '%1$s, rij %2$s' };

	const m = modelOf( 'payment-mappings' );
	m.loadRows( [] );
	m.addRow();
	m.addRow();
	m.setValue( 0, 'woocommerce_payment_method', 'x' );
	m.setValue( 1, 'woocommerce_payment_method', 'x' );
	m.setValue( 0, 'odoo_journal_id', '' );

	assert.equal( m.validate().find( ( e ) => e.key === 'odoo_journal_id' && e.row === 0 ).message, 'Rij 1: choose a journal.', 'The row text is translated, the column text comes from the config.' );
	assert.equal( editor.rowLabel( 'Odoo journal', 1 ), 'Odoo journal, rij 2' );
	assert.equal( editor.rowLabel( 'Odoo journal', 0 ), 'Odoo journal, rij 1' );
	globalThis.odoowooPortal.i18n = {};
	assert.equal( editor.rowLabel( 'Odoo journal', 0 ), 'Odoo journal, row 1' );
} );

test( 'test_pricelist_options_show_the_currency_but_store_the_plain_name', () => {
	const m = modelOf( 'price-mappings' );

	m.loadRows( [] );
	m.applyLoader( 0, reply( 'odoo_pricelists' ) );
	m.addRow();
	m.setValue( 0, 'source', 'pricelist' );
	assert.deepEqual( m.options( 0, 'odoo_pricelist_id' ).map( ( o ) => o.label ), [ 'Public Pricelist (EUR)', 'Retail DKK (DKK)' ], 'A name that already says its currency is not repeated.' );

	m.setValue( 0, 'odoo_pricelist_id', '6' );
	assert.equal( m.rows()[ 0 ].odoo_pricelist_name, 'Retail DKK', 'The stored name has no currency suffix.' );
	assert.equal( m.options( 0, 'odoo_pricelist_id' ).find( ( o ) => o.value === '6' ).label, 'Retail DKK (DKK)' );
} );

test( 'test_tax_class_may_repeat', () => {
	const m = modelOf( 'tax-mappings' );

	m.loadRows( [] );
	m.feedSaved( { route: 'stored-odoo-taxes', idKey: 'id', nameKey: 'name', into: 'stored-odoo-taxes' }, items( 'stored_odoo_taxes' ) );
	m.applyLoader( 0, reply( 'woo_tax_classes' ) );
	[ 0, 1 ].forEach( ( i ) => {
		m.addRow();
		m.setValue( i, 'woocommerce_tax_class', 'standard' );
		m.setValue( i, 'odoo_customer_tax_id', i === 0 ? '3' : '5' );
		m.setValue( i, 'odoo_supplier_tax_id', '4' );
	} );
	assert.deepEqual( m.validate(), [], 'Django has no unique constraint on the class (models.py:247).' );
} );

test( 'test_rows_cannot_be_added_or_removed_before_the_list_has_loaded', () => {
	const m = modelOf( 'stock-mappings' );

	assert.equal( editor.canEdit( m, { canWrite: true } ), false, 'A row added now would vanish when the list arrives.' );
	m.loadRows( [] );
	assert.equal( editor.canEdit( m, { canWrite: true } ), true );
	assert.equal( editor.canEdit( m, { canWrite: false } ), false );
} );

test( 'test_a_200_that_is_not_the_list_leaves_the_editor_unloaded', async () => {
	boot();
	fakeFetch( [ 'stock_mappings_unreadable' ] ); // The portal's refusal of a 200 without {mappings: [...]}.
	const m = editor.createModel( config( 'stock-mappings' ) );
	const out = await editor.readAll( ctx, m );

	assert.equal( out.ok, false );
	assert.equal( out.reply.message, 'Something went wrong on our side. Please try again in a moment.' );
	assert.equal( m.isLoaded(), false );
	assert.equal( editor.canSave( m, { canWrite: true } ), false );
	assert.equal( editor.canEdit( m, { canWrite: true } ), false );
} );

test( 'test_error_ids_link_each_input_to_its_message', () => {
	const marks = [ { row: 1, key: 'odoo_journal_id', message: 'Row 2: choose a journal.' }, { row: -1, key: 'target_field', message: 'Two mappings both write x.' } ];
	const ids = editor.describedBy( marks, 'u', 1, 'odoo_journal_id' );

	assert.deepEqual( ids, [ 'u-err-0' ] );
	assert.deepEqual( editor.describedBy( marks, 'u', 0, 'odoo_journal_id' ), [] );
	assert.deepEqual( editor.describedBy( marks, 'u', 3, 'target_field' ), [ 'u-err-1' ], 'A refusal of a column is linked from every row of it.' );
	assert.deepEqual( editor.describedBy( marks, 'u', 0, 'source' ), [] );
} );
