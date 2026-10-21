/*
 * OdooWoo portal: which Odoo settings depend on something else (spec 5.6). A pure function, no
 * DOM: window.odoowoo.deps( values, ctx ) => { disabled: { field: reason }, hidden: [ field ] }.
 * ctx = { hasPayment, hasTax, hasPrice, brandSync }. A fact that is exactly false disables its
 * field; an unknown fact (undefined, for instance a list that could not be read) disables
 * nothing, because Django is the boundary and a wrong guess here would silently turn a saved
 * setting off.
 */
( function () {
	'use strict';

	var G = typeof window !== 'undefined' ? window : globalThis;
	var core = typeof module !== 'undefined' && module.exports ? require( './core.js' ) : G.odoowoo;

	var t = core.translator( {
		needsPayment: 'Needs payment method mappings.',
		needsTax: 'Needs tax mappings.',
		needsPrice: 'Needs a price mapping.',
		needsPlan: 'Brand sync is not part of your plan.',
	} );

	/** The 16 settings Django reads as booleans. */
	var BOOLEANS = [ 'enable_auto_payment', 'enable_auto_payment_cn', 'enable_set_invoice_status', 'enable_reset_all_deliveries', 'enable_set_deliveries_to_waiting', 'enable_auto_complete_deliveries', 'confirm_pending_orders', 'price_includes_tax', 'order_incl_tax', 'enable_brand_sync', 'enable_category_sync', 'enable_product_name_sync', 'enable_tag_sync', 'enable_image_sync', 'enable_price_sync', 'enable_barcode_sync' ];

	function deps( values, ctx ) {
		var v = values && typeof values === 'object' ? values : {};
		var c = ctx && typeof ctx === 'object' ? ctx : {};
		var disabled = {};
		var hidden = [];

		if ( c.hasPayment === false ) {
			disabled.enable_auto_payment = t( 'needsPayment' );
			disabled.enable_auto_payment_cn = t( 'needsPayment' );
		}

		if ( c.hasTax === false ) {
			disabled.price_includes_tax = t( 'needsTax' );
		}

		if ( c.hasPrice === false ) {
			disabled.enable_price_sync = t( 'needsPrice' );
		}

		if ( c.brandSync === false ) {
			disabled.enable_brand_sync = t( 'needsPlan' );
		}

		if ( v.capture_stage !== 'pending' ) {
			hidden.push( 'confirm_pending_orders' );
		}

		return { disabled: disabled, hidden: hidden };
	}

	/**
	 * The changes that make a save body complete and honest: every boolean a real boolean (a
	 * string "false" would be true in Django), a disabled one false (Django would refuse it), the
	 * pricelist id a positive integer or null with its name. Other fields are left as they are.
	 */
	function prepare( values, ctx ) {
		var v = values && typeof values === 'object' ? values : {};
		var d = deps( v, ctx );
		var out = {};

		BOOLEANS.forEach( function ( f ) {
			out[ f ] = d.disabled[ f ] !== undefined ? false : v[ f ] === true;
		} );

		var id = v.odoo_order_pricelist_id;
		var valid = typeof id === 'number' && Number.isInteger( id ) && id > 0;

		out.odoo_order_pricelist_id = valid ? id : null;
		out.odoo_order_pricelist_name = valid && typeof v.odoo_order_pricelist_name === 'string' ? v.odoo_order_pricelist_name : '';

		return out;
	}

	var api_ = { deps: deps, prepare: prepare, BOOLEANS: BOOLEANS };

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api_;
	}

	if ( typeof window !== 'undefined' ) {
		window.odoowoo = Object.assign( window.odoowoo || {}, { deps: deps, depsApi: api_ } );
	}
}() );
