/**
 * Partytown configuration -- sets window.partytown before Partytown initialises.
 * Data injected by PHP via wp_localize_script as dcSwpPartytownData.
 *
 * Includes the SharedArrayBuffer probe on every page load. The probe is harmless
 * when COI headers are not active (window.crossOriginIsolated will be false and
 * the block never executes).
 *
 * @package DC_Service_Worker_Prefetcher
 */

/* global dcSwpPartytownData */

// SharedArrayBuffer probe: if the 256 MB allocation fails the browser's isolated
// context is unusable. Override crossOriginIsolated = false so Partytown falls
// back to the service-worker bridge instead of the broken atomics bridge.
if ( window.crossOriginIsolated ) {
	try {
		new SharedArrayBuffer( 268435456 );
	} catch {
		try {
			Object.defineProperty( window, 'crossOriginIsolated', { value: false, configurable: false } );
		} catch { /* browser may disallow redefining the property */ }
	}
}

// Safe-page guard: on WooCommerce cart, checkout, and account pages, force-disable
// the Atomics bridge by removing SharedArrayBuffer. COI headers are already suppressed
// server-side, but this JS override is a belt-and-suspenders guarantee — Partytown
// falls back to the Service Worker bridge, which has zero impact on payment iframes.
if ( dcSwpPartytownData.isSafePage ) {
	try {
		Object.defineProperty( window, 'SharedArrayBuffer', { value: undefined, configurable: false } );
		Object.defineProperty( window, 'crossOriginIsolated', { value: false, configurable: false } );
	} catch { /* property may be non-configurable in some engines */ }
}

// Set Partytown config from PHP-injected data (lib, debug, forward, nonce, etc.).
window.partytown = dcSwpPartytownData.config;

// Store path-rewrite data as plain properties on the partytown config object so
// they are serialised to JSON alongside the function source and available via
// `this` inside resolveUrl when Partytown reconstructs it with new Function()
// in the web worker (closures are lost during that serialisation round-trip).
window.partytown.pathRewrites      = dcSwpPartytownData.pathRewrites;
window.partytown.proxyAllowedHosts = dcSwpPartytownData.proxyAllowedHosts;
window.partytown.proxyUrl          = dcSwpPartytownData.proxyUrl;

// resolveUrl -- same-origin path rewrites + CORS proxy for Partytown workers.
// NOTE: this function is serialised to a string by Partytown and eval'd inside
// the web worker via new Function(), so it MUST be self-contained. Use `this`
// (the config object) instead of any outer-scope variable references.
window.partytown.resolveUrl = function ( url, location, type ) {
	// Reroute known same-origin analytics paths to their real external endpoints.
	// Only applies to non-script requests (fetch/XHR/sendBeacon) so that script
	// loads at a same-origin path are never rerouted to an external endpoint.
	const pr = this.pathRewrites;
	if ( type !== 'script' && url && url.hostname === location.hostname && pr[ url.pathname ] ) {
		return new URL( pr[ url.pathname ] );
	}
	// Forward external scripts through the server-side CORS proxy, but only for
	// hostnames the admin has explicitly allowed in the Partytown Script List.
	const ph = this.proxyAllowedHosts;
	if ( type === 'script' && url.hostname !== location.hostname && ph.indexOf( url.hostname ) !== -1 ) {
		const p = new URL( this.proxyUrl );
		p.searchParams.append( 'url', url.href );
		return p;
	}
	return url;
};

/**
 * Notify Partytown that new type="text/partytown" scripts have been added to the DOM.
 *
 * Call window.dcSwpPartytownUpdate() after programmatically inserting a
 * type="text/partytown" script element into the document, so Partytown
 * picks it up and executes it in the worker.
 *
 * @see https://partytown.qwik.dev/partytown-scripts/#dynamically-appending-scripts
 *
 * @example
 * const script = document.createElement( 'script' );
 * script.type = 'text/partytown';
 * script.textContent = 'console.log("worker script")';
 * document.head.appendChild( script );
 * window.dcSwpPartytownUpdate();
 */
window.dcSwpPartytownUpdate = function () {
	window.dispatchEvent( new CustomEvent( 'ptupdate' ) );
};
