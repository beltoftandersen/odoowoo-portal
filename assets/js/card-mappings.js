/*
 * OdooWoo portal: the six mapping cards (payment, tax, price, stock, order route, order
 * pricelist). Plain script. Each card prints its config in data-config; mapping-editor.js does
 * the work. The script is enqueued once per card on the page, so every editor is started once.
 */
( function () {
	'use strict';

	if ( typeof window === 'undefined' ) {
		return;
	}

	var CARDS = [ 'payment-mappings', 'tax-mappings', 'price-mappings', 'stock-mappings', 'order-route-mappings', 'order-pricelist-mappings' ];

	function start() {
		CARDS.forEach( function ( card ) {
			Array.prototype.forEach.call( window.document.querySelectorAll( '[data-odoowoo-card="' + card + '"]' ), function ( root ) {
				var holder = root.querySelector( '.odoowoo-mapping' );

				if ( ! holder || holder.getAttribute( 'data-started' ) === '1' ) {
					return;
				}

				var config;

				try {
					config = JSON.parse( holder.getAttribute( 'data-config' ) || '' );
				} catch ( e ) {
					return;
				}

				holder.setAttribute( 'data-started', '1' );
				window.odoowoo.mappingEditor( holder, config );
			} );
		} );
	}

	if ( window.document.readyState === 'loading' ) {
		window.document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
