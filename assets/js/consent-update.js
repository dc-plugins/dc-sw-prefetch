/**
 * GCM v2 consent update -- WP Consent API integration.
 *
 * Strategy:
 * 1. On DOMContentLoaded, read initial consent state via wp_has_consent()
 * 2. Poll for consent STATE changes (not just API availability)
 * 3. Listen for wp_listen_for_consent_change events for live banner changes
 * 4. Fallback: watch for consent cookie changes
 *
 * The default consent stub (all-denied) is injected in <head>. This script
 * updates the consent state once actual consent is known from the CMP.
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
		marketing:              [ 'ad_storage', 'ad_user_data', 'ad_personalization' ],
		statistics:             [ 'analytics_storage' ],
		'statistics-anonymous': [ 'analytics_storage' ], // anonymised stats → same GA4 signal.
		preferences:            [ 'personalization_storage' ],
	};

	/** Track last known consent state per category to detect changes. */
	const lastConsentState = {
		marketing:              null,
		statistics:             null,
		'statistics-anonymous': null,
		preferences:            null,
	};

	/** Track last known Meta Consent Mode state ('grant'|'revoke'|null). */
	let lastMetaConsent = null;

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
	 * Apply Meta Pixel Consent Mode and LDU state when marketing consent changes.
	 *
	 * Only active when the WP Consent API gate is enabled (dcSwpMeta.consentGate).
	 * Mirrors the server-side logic in dc_swp_inject_meta_ldu_default() so that
	 * consent changes after page load are reflected in Meta Pixel immediately.
	 *
	 * @param {boolean} hasConsent Whether marketing consent was granted.
	 */
	function updateMetaConsent( hasConsent ) {
		if ( typeof window.fbq !== 'function' ) {
			return;
		}
		const meta        = window.dcSwpMeta || {};
		const consentGate = meta.consentGate === '1';
		const metaLdu     = meta.ldu === '1';

		// Only drive Meta signals when the consent gate is on — when it's off,
		// the server-side stub already handles the static state at page load.
		if ( ! consentGate ) {
			return;
		}

		const state = hasConsent ? 'grant' : 'revoke';
		if ( lastMetaConsent === state ) {
			return; // No change — avoid redundant fbq calls.
		}
		lastMetaConsent = state;

		if ( hasConsent ) {
			window.fbq( 'consent', 'grant' );
			if ( metaLdu ) {
				// Consented visitor — clear any LDU restriction.
				window.fbq( 'dataProcessingOptions', [], 0, 0 );
			}
		} else {
			window.fbq( 'consent', 'revoke' );
			if ( metaLdu ) {
				window.fbq( 'dataProcessingOptions', [ 'LDU' ], 0, 0 );
			}
		}
	}

	/**
	 * Apply TikTok Pixel consent state when marketing consent changes.
	 *
	 * TikTok Pixel exposes holdConsent/revokeConsent/grantConsent on the ttq
	 * stub. When marketing consent is absent, revokeConsent() holds event firing.
	 * When consent is granted, grantConsent() releases held events.
	 *
	 * @param {boolean} hasConsent Whether marketing consent was granted.
	 */
	function updateTikTokConsent( hasConsent ) {
		if ( typeof window.ttq === 'undefined' ||
		     typeof window.ttq.grantConsent !== 'function' ) {
			return;
		}
		if ( hasConsent ) {
			window.ttq.grantConsent();
		} else {
			window.ttq.revokeConsent();
		}
	}

	/**
	 * Read current consent state via wp_has_consent() and push GCM v2 updates
	 * only for categories that have changed since last check.
	 *
	 * @return {boolean} True if WP Consent API is available.
	 */
	function applyCurrentConsent() {
		if ( typeof wp_has_consent !== 'function' ) {
			return false;
		}

		Object.keys( categoryMap ).forEach( function ( category ) {
			const hasConsent = wp_has_consent( category );
			const value = hasConsent ? 'allow' : 'deny';

			// Only update GCM if state has changed.
			if ( lastConsentState[ category ] !== value ) {
				lastConsentState[ category ] = value;
				updateGcm( category, value );
			}

			// Sync pixel consent signals whenever marketing state is evaluated.
			if ( category === 'marketing' ) {
				updateMetaConsent( hasConsent );
				updateTikTokConsent( hasConsent );
			}
		} );

		return true;
	}

	/**
	 * Poll for consent state changes. Unlike the previous implementation,
	 * this continues polling until the user actually grants consent,
	 * not just until the WP Consent API function is available.
	 *
	 * Uses exponential backoff up to 2 seconds, then continues at 2s intervals
	 * for up to 30 seconds total, or until consent is granted.
	 */
	function pollForConsentChanges() {
		const maxDuration = 30000; // 30 seconds max polling
		const maxInterval = 2000;  // Cap at 2 second intervals
		const baseDelay   = 100;
		const startTime   = Date.now();
		let attempt       = 0;

		function poll() {
			// Try to apply consent.
			const apiAvailable = applyCurrentConsent();

			// Stop conditions:
			// 1. We've been polling for 30 seconds (give up gracefully).
			// 2. WP Consent API not available after 5 attempts (not installed).
			// Subsequent consent changes are caught by the event listener + fallback interval.
			const elapsed = Date.now() - startTime;
			if ( elapsed >= maxDuration ) {
				return; // Timeout - stop polling.
			}

			if ( ! apiAvailable && attempt >= 5 ) {
				// WP Consent API not installed after 5 attempts.
				return;
			}

			// Schedule next poll with exponential backoff, capped at maxInterval.
			attempt++;
			const delay = Math.min( baseDelay * Math.pow( 2, attempt ), maxInterval );
			setTimeout( poll, delay );
		}

		// Start polling immediately.
		poll();
	}

	// -- Initial read on DOMContentLoaded -------------------------------------
	// This script lives in the footer, so its DOMContentLoaded handler is
	// registered after all <head> CMP scripts.
	function onReady() {
		// Start polling for consent state changes.
		pollForConsentChanges();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', onReady );
	} else {
		// DOMContentLoaded already fired (pre-rendered / bfcache restore).
		onReady();
	}

	// -- WP Consent API: live consent changes ---------------------------------
	// Fired by any WP-Consent-API-compliant CMP when the visitor updates
	// their preferences via the banner. detail: { [category]: 'allow'|'deny' }
	document.addEventListener( 'wp_listen_for_consent_change', function ( e ) {
		const changed = ( e.detail && typeof e.detail === 'object' ) ? e.detail : {};
		Object.keys( changed ).forEach( function ( category ) {
			const value = changed[ category ];
			if ( lastConsentState[ category ] !== value ) {
				lastConsentState[ category ] = value;
				updateGcm( category, value );
			}
		} );

		// Sync pixel consent signals if the marketing category changed.
		if ( 'marketing' in changed ) {
			updateMetaConsent( changed.marketing === 'allow' );
			updateTikTokConsent( changed.marketing === 'allow' );
		}
	} );

	// -- Fallback: watch for WP Consent API cookie changes --------------------
	// Some CMPs might not fire wp_listen_for_consent_change reliably.
	// Poll consent state periodically to catch cookie-based changes.
	// This runs independently of the initial polling for 60 seconds.
	let fallbackChecks = 0;
	const maxFallbackChecks = 120; // 60 seconds at 500ms intervals
	const fallbackInterval = setInterval( function () {
		applyCurrentConsent();
		fallbackChecks++;
		if ( fallbackChecks >= maxFallbackChecks ) {
			clearInterval( fallbackInterval );
		}
	}, 500 );

	// -- Storage event listener for cross-tab consent sync --------------------
	// If user accepts consent in another tab, pick it up here.
	window.addEventListener( 'storage', function () {
		applyCurrentConsent();
	} );
}() );
