/**
 * GCM v2 consent update — WP Consent API direct read.
 *
 * Strategy: rather than listening for CMP-specific events (which have
 * unreliable timing), we call wp_has_consent() directly on
 * DOMContentLoaded. Because this script is loaded in the footer, its
 * DOMContentLoaded handler is registered *after* all <head> CMP scripts
 * (Complianz, etc.), so it fires last and our gtag('consent','update')
 * call is the definitive, authoritative signal for GCM v2.
 *
 * A secondary wp_listen_for_consent_change listener handles live consent
 * changes when the visitor interacts with the banner after page load.
 *
 * @package DC_Service_Worker_Prefetcher
 */

/* global gtag, wp_has_consent */

( function () {
	'use strict';

	/**
	 * Maps WP Consent API category names to GCM v2 signal names.
	 * functional and security_storage are always 'granted' (set in the stub).
	 */
	const categoryMap = {
		marketing:   [ 'ad_storage', 'ad_user_data', 'ad_personalization' ],
		statistics:  [ 'analytics_storage' ],
		preferences: [ 'personalization_storage' ],
	};

	/**
	 * Call gtag('consent','update') for one consent category.
	 *
	 * @param {string} category  Consent category name (marketing|statistics|preferences).
	 * @param {string} value     'allow' or 'deny'.
	 */
	function updateGcm( category, value ) {
		if ( typeof window.gtag !== 'function' ) {
			return;
		}
		const signals = categoryMap[ category ];
		if ( ! signals ) {
			return;
		}
		const gcmValue = ( value === 'allow' ) ? 'granted' : 'denied';
		const update   = {};
		signals.forEach( function ( signal ) {
			update[ signal ] = gcmValue;
		} );
		gtag( 'consent', 'update', update );
	}

	/**
	 * Read current consent state via wp_has_consent() and push a single
	 * GCM v2 update covering all mapped categories.
	 *
	 * wp_has_consent() is provided by the WP Consent API (used by
	 * Complianz, CookieYes, Borlabs, etc.) and reads the consent cookies
	 * that the CMP has already written, so no event timing issues arise.
	 */
	function applyCurrentConsent() {
		if ( typeof wp_has_consent !== 'function' ) {
			return;
		}
		Object.keys( categoryMap ).forEach( function ( category ) {
			const value = wp_has_consent( category ) ? 'allow' : 'deny';
			updateGcm( category, value );
		} );
	}

	// ── Initial read on DOMContentLoaded ─────────────────────────────────────
	// This script lives in the footer, so its DOMContentLoaded handler is
	// registered after all <head> CMP scripts. That means it fires last,
	// ensuring our update overwrites any earlier GCM consent calls.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', applyCurrentConsent );
	} else {
		// DOMContentLoaded already fired (pre-rendered / bfcache restore).
		applyCurrentConsent();
	}

	// ── WP Consent API: live consent changes ─────────────────────────────────
	// Fired by any WP-Consent-API-compliant CMP when the visitor updates
	// their preferences via the banner. detail: { [category]: 'allow'|'deny' }
	document.addEventListener( 'wp_listen_for_consent_change', function ( e ) {
		const changed = ( e.detail && typeof e.detail === 'object' ) ? e.detail : {};
		Object.keys( changed ).forEach( function ( category ) {
			updateGcm( category, changed[ category ] );
		} );
	} );
}() );
