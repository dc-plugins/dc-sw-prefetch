/**
 * GCM v2 consent update — WP Consent API integration.
 *
 * Strategy:
 * 1. On DOMContentLoaded, poll for wp_has_consent() availability (WP Consent API)
 * 2. Once available, read current consent state and call gtag('consent','update')
 * 3. Listen for wp_listen_for_consent_change events for live banner changes
 *
 * The default consent stub (all-denied) is injected in <head>. This script
 * updates the consent state once actual consent is known from the CMP.
 *
 * @package DC_Service_Worker_Prefetcher
 */

/* global gtag, wp_has_consent, wp_consent_type */

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

	/** Track whether we've successfully applied consent at least once. */
	let consentApplied = false;

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
	 * @return {boolean} True if consent was successfully read and applied.
	 */
	function applyCurrentConsent() {
		if ( typeof wp_has_consent !== 'function' ) {
			return false;
		}

		Object.keys( categoryMap ).forEach( function ( category ) {
			const hasConsent = wp_has_consent( category );
			const value = hasConsent ? 'allow' : 'deny';
			updateGcm( category, value );
		} );

		consentApplied = true;
		return true;
	}

	/**
	 * Poll for WP Consent API availability with exponential backoff.
	 * Tries immediately, then at 100ms, 200ms, 400ms, 800ms intervals.
	 *
	 * @param {number} attempt Current attempt number (0-based).
	 */
	function pollForConsentApi( attempt ) {
		const maxAttempts = 5;
		const baseDelay   = 100;

		// Try to apply consent.
		if ( applyCurrentConsent() ) {
			return; // Success.
		}

		// If we've exceeded max attempts, stop polling.
		if ( attempt >= maxAttempts ) {
			// WP Consent API is not available — consent stays at default (denied).
			// This is expected if WP Consent API plugin is not installed.
			return;
		}

		// Schedule next attempt with exponential backoff.
		const delay = baseDelay * Math.pow( 2, attempt );
		setTimeout( function () {
			pollForConsentApi( attempt + 1 );
		}, delay );
	}

	// ── Initial read on DOMContentLoaded ─────────────────────────────────────
	// This script lives in the footer, so its DOMContentLoaded handler is
	// registered after all <head> CMP scripts.
	function onReady() {
		// Start polling for WP Consent API.
		pollForConsentApi( 0 );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', onReady );
	} else {
		// DOMContentLoaded already fired (pre-rendered / bfcache restore).
		onReady();
	}

	// ── WP Consent API: live consent changes ─────────────────────────────────
	// Fired by any WP-Consent-API-compliant CMP when the visitor updates
	// their preferences via the banner. detail: { [category]: 'allow'|'deny' }
	document.addEventListener( 'wp_listen_for_consent_change', function ( e ) {
		const changed = ( e.detail && typeof e.detail === 'object' ) ? e.detail : {};
		Object.keys( changed ).forEach( function ( category ) {
			updateGcm( category, changed[ category ] );
		} );
		consentApplied = true;
	} );

	// ── Fallback: watch for consent cookie changes ───────────────────────────
	// Some CMPs might not fire wp_listen_for_consent_change reliably.
	// Re-apply consent whenever the WP Consent API's consent_type changes.
	let lastConsentType = null;
	function checkConsentTypeChange() {
		if ( typeof wp_consent_type === 'undefined' ) {
			return;
		}
		if ( wp_consent_type !== lastConsentType ) {
			lastConsentType = wp_consent_type;
			applyCurrentConsent();
		}
	}

	// Check periodically for consent type changes (every 500ms for 10s after load).
	let checkCount = 0;
	const maxChecks = 20;
	const checkInterval = setInterval( function () {
		checkConsentTypeChange();
		checkCount++;
		if ( checkCount >= maxChecks || consentApplied ) {
			clearInterval( checkInterval );
		}
	}, 500 );
}() );
