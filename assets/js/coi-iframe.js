/**
 * COI iframe guard -- stamps `credentialless` on cross-origin iframes before
 * their src navigation begins, required when COEP headers are active.
 *
 * Only emitted when Cross-Origin Isolation is enabled in the plugin settings.
 * The internal `if (!window.crossOriginIsolated) return;` check is a belt-and-
 * suspenders guard for edge cases where the page may be loaded without COEP.
 *
 * @package DC_Service_Worker_Prefetcher
 */

( function () {
	if ( ! window.crossOriginIsolated ) return;

	// Save the original setAttribute before we override it so ensureCredentialless
	// can call it without recursing through our own override.
	const _sa = HTMLIFrameElement.prototype.setAttribute;

	function ensureCredentialless( el, src ) {
		if ( ! src || el.hasAttribute( 'credentialless' ) ) return;
		try {
			const u = new URL( src, location.href );
			// Skip about:, javascript:, data: -- only http(s) cross-origin iframes need this.
			if ( u.protocol === 'about:' || u.protocol === 'javascript:' || u.protocol === 'data:' ) return;
			if ( u.origin !== location.origin ) _sa.call( el, 'credentialless', '' );
		} catch { /* malformed URL -- ignore */ }
	}

	// Intercept iframe.src = '...' assignment -- fires before the value is applied.
	const d = Object.getOwnPropertyDescriptor( HTMLIFrameElement.prototype, 'src' );
	if ( d && d.set ) {
		Object.defineProperty( HTMLIFrameElement.prototype, 'src', {
			set: function ( v ) { ensureCredentialless( this, v ); d.set.call( this, v ); },
			get: d.get,
			configurable: true,
		} );
	}

	// Intercept iframe.setAttribute('src', '...') -- same guarantee.
	HTMLIFrameElement.prototype.setAttribute = function ( n, v ) {
		if ( n.toLowerCase() === 'src' ) ensureCredentialless( this, v );
		_sa.call( this, n, v );
	};

	// MutationObserver fallback for iframes inserted via innerHTML / DOMParser,
	// where the src navigation is deferred to a separate task.
	function markIfNeeded( el ) {
		if ( ! el || el.tagName !== 'IFRAME' || el.hasAttribute( 'credentialless' ) ) return;
		ensureCredentialless( el, el.getAttribute( 'src' ) );
	}

	const obs = new MutationObserver( function ( muts ) {
		muts.forEach( function ( m ) {
			if ( m.type === 'childList' ) {
				m.addedNodes.forEach( function ( n ) {
					if ( n.nodeType !== 1 ) return;
					markIfNeeded( n );
					if ( n.querySelectorAll ) n.querySelectorAll( 'iframe[src]' ).forEach( markIfNeeded );
				} );
			} else if ( m.type === 'attributes' && m.target && m.target.tagName === 'IFRAME' ) {
				markIfNeeded( m.target );
			}
		} );
	} );

	obs.observe( document.documentElement, {
		childList: true,
		subtree: true,
		attributes: true,
		attributeFilter: [ 'src' ],
	} );
} )();
