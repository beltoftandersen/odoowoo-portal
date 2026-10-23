/*
 * OdooWoo portal: ?tab= for Bricks' Tabs element. Pure helpers plus thin DOM glue. Bricks opens a
 * tab by a click on its title and then fires bricks/tabs/changed after its own replaceState, so
 * the address is updated in that listener, not in a click handler of ours.
 */
( function () {
	'use strict';

	function slugify( value ) {
		return String( value === undefined || value === null ? '' : value )
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '-' )
			.replace( /^-+|-+$/g, '' );
	}

	/** The slug asked for in the query string when it is one of the tabs, otherwise the first. */
	function pickTab( slugs, search ) {
		if ( ! slugs || slugs.length === 0 ) {
			return '';
		}

		var wanted = new URLSearchParams( typeof search === 'string' ? search : '' ).get( 'tab' );

		return wanted !== null && slugs.indexOf( wanted ) !== -1 ? wanted : slugs[ 0 ];
	}

	/** The address with ?tab= set, everything else (other params, the hash) kept. */
	function withTab( href, slug ) {
		var url = new URL( href );

		url.searchParams.set( 'tab', slug );

		return url.toString();
	}

	function titleSlug( title ) {
		return title.getAttribute( 'data-odoowoo-tab' ) || slugify( title.textContent );
	}

	function init() {
		var roots = document.querySelectorAll( '.brxe-tabs-nested' );

		Array.prototype.forEach.call( roots, function ( root ) {
			var titles = Array.prototype.slice.call( root.querySelectorAll( ':scope > .tab-menu > .tab-title' ) );

			// Only a Tabs element that holds one of our cards is ours to drive.
			if ( titles.length === 0 || ! root.querySelector( '.odoowoo-card' ) ) {
				return;
			}

			var slugs = titles.map( titleSlug );
			var fromHash = window.location.hash !== '' && titles.some( function ( t ) { return '#' + t.id === window.location.hash; } );
			var picked = titles[ slugs.indexOf( pickTab( slugs, window.location.search ) ) ];

			if ( ! fromHash && picked && ! picked.classList.contains( 'brx-open' ) ) {
				picked.click();
			}
		} );

		document.addEventListener( 'bricks/tabs/changed', function ( e ) {
			var d = e.detail || {};
			var title = d.activeTitle;

			if ( ! title || typeof title.getAttribute !== 'function' || ! title.closest( '.brxe-tabs-nested' ) || ! title.closest( '.brxe-tabs-nested' ).querySelector( '.odoowoo-card' ) ) {
				return;
			}

			var slug = titleSlug( title );

			// On a phone the tab row scrolls sideways: keep the open tab in view.
			if ( typeof title.scrollIntoView === 'function' ) {
				title.scrollIntoView( { block: 'nearest', inline: 'nearest' } );
			}

			if ( slug !== '' ) {
				window.history.replaceState( window.history.state, '', withTab( window.location.href, slug ) );
			}
		} );
	}

	var out = { slugify: slugify, pickTab: pickTab, withTab: withTab, init: init };

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = out;
	}

	if ( typeof window !== 'undefined' ) {
		window.odoowoo = window.odoowoo || {};
		window.odoowoo.tabs = out;

		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', init );
		} else {
			init();
		}
	}
}() );
