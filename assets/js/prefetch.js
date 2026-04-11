/**
 * Viewport/pagination prefetcher for WooCommerce product pages.
 * Data injected by PHP via wp_localize_script as dcSwpPrefetchData.
 *
 * @package DC_Service_Worker_Prefetcher
 */

/* global dcSwpPrefetchData */

( function () {
	'use strict';

	const productBase    = dcSwpPrefetchData.productBase;
	const prefetchedUrls = new Set();
	const visibleItems   = new Set(); // track currently-intersecting elements

	function prefetch( url ) {
		if ( ! url || prefetchedUrls.has( url ) ) return;
		prefetchedUrls.add( url );
		const link = document.createElement( 'link' );
		link.rel   = 'prefetch';
		link.href  = url;
		link.as    = 'document';
		document.head.appendChild( link );
		console.log( '[DC SW Prefetch] Prefetching:', url );
	}

	function resolveProductLink( el ) {
		const bad = ( href ) => ! href
			|| href.includes( 'add-to-cart' )
			|| href.includes( '?remove_item' )
			|| href.includes( 'remove_item' )
			|| href.includes( '?added-to-cart' )
			|| href.includes( '#' );

		// Element itself may be the anchor (e.g. upsell-item <a> wrappers).
		if ( el.tagName === 'A' && el.href && ! bad( el.href ) ) return el.href;

		const anchors = Array.from( el.querySelectorAll( 'a[href]' ) );
		// Prefer a link matching the (auto-detected or overridden) product slug.
		let a = anchors.find( a => a.href.includes( productBase ) && ! bad( a.href ) );
		// Fallback: first non-utility anchor inside the item.
		if ( ! a ) a = anchors.find( a => ! bad( a.href ) );
		if ( ! a ) {
			console.debug( '[DC SW Prefetch] No link in item:', el, '| anchors found:', anchors.length, '| productBase:', productBase );
		}
		return a ? a.href : null;
	}

	function prefetchNextPage() {
		const next = document.querySelector(
			'.woocommerce-pagination a.next, .next.page-numbers, a.next-page'
		);
		if ( next && next.href ) setTimeout( () => prefetch( next.href ), 2000 );
	}

	// Wide selector -- catches product grid items and upsell anchor-wrappers.
	const items = document.querySelectorAll(
		'.products .product, ul.products li.product, .product-item, li.product, a.upsell-item[href]'
	);
	if ( ! items.length ) {
		console.warn( '[DC SW Prefetch] No product items found in DOM' );
		return;
	}

	console.log( '[DC SW Prefetch] Monitoring', items.length, 'products | productBase:', productBase );

	if ( 'IntersectionObserver' in window ) {
		const observer = new IntersectionObserver( ( entries ) => {
			entries.forEach( entry => {
				if ( entry.isIntersecting ) {
					visibleItems.add( entry.target );
					const url = resolveProductLink( entry.target );
					if ( url ) {
						prefetch( url );
					}
				} else {
					visibleItems.delete( entry.target );
				}
			} );
		}, { rootMargin: '50px', threshold: 0.1 } );

		items.forEach( item => observer.observe( item ) );
		prefetchNextPage();
	} else {
		// Fallback for browsers without IntersectionObserver.
		const vh = window.innerHeight;
		items.forEach( item => {
			const url  = resolveProductLink( item );
			const rect = item.getBoundingClientRect();
			if ( url && rect.top >= 0 && rect.top <= vh ) prefetch( url );
		} );
		prefetchNextPage();
	}
} )();
