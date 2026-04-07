/**
 * GCM v2 consent update listener.
 *
 * Translates CMP consent events into gtag('consent','update',...) calls.
 * Works with any CMP that implements the WP Consent API, and has
 * dedicated support for Complianz.
 *
 * The inline stub in <head> always sets everything to 'denied' as the
 * safe default. This script is responsible for upgrading those defaults
 * once the visitor's actual consent is known.
 *
 * Events handled:
 *   cmplz_fire_categories      — Complianz: fires on every page load with
 *                                the full array of consented categories, and
 *                                again on every consent change.
 *   wp_listen_for_consent_change — WP Consent API standard event; fired by
 *                                any compliant CMP (Complianz, CookieYes,
 *                                Borlabs, etc.) when a category changes.
 *                                detail: { [category]: 'allow'|'deny' }
 *   cmplz_revoke               — Complianz: visitor withdrew all consent.
 *
 * @package DC_Service_Worker_Prefetcher
 */

/* global gtag */

( function () {
	'use strict';

	/**
	 * Maps WP Consent API / Complianz category names to GCM v2 signal names.
	 * functional and security_storage are always 'granted' (set in the stub).
	 */
	var categoryMap = {
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
		var signals = categoryMap[ category ];
		if ( ! signals ) {
			return;
		}
		var gcmValue = ( value === 'allow' ) ? 'granted' : 'denied';
		var update   = {};
		signals.forEach( function ( signal ) {
			update[ signal ] = gcmValue;
		} );
		gtag( 'consent', 'update', update );
	}

	// ── Complianz: cmplz_fire_categories ─────────────────────────────────────
	// Fires automatically on page load (from conditionally_show_banner) with all
	// currently consented categories, and again whenever consent changes.
	// detail.categories = string[] of consented category names.
	document.addEventListener( 'cmplz_fire_categories', function ( e ) {
		var grantedCategories = ( e.detail && Array.isArray( e.detail.categories ) )
			? e.detail.categories
			: [];

		Object.keys( categoryMap ).forEach( function ( category ) {
			var value = ( grantedCategories.indexOf( category ) !== -1 ) ? 'allow' : 'deny';
			updateGcm( category, value );
		} );
	} );

	// ── WP Consent API ────────────────────────────────────────────────────────
	// Standard cross-CMP event. Complianz dispatches this via wp_set_consent()
	// on every category change. detail: { [category]: 'allow'|'deny' }
	document.addEventListener( 'wp_listen_for_consent_change', function ( e ) {
		var changed = ( e.detail && typeof e.detail === 'object' ) ? e.detail : {};
		Object.keys( changed ).forEach( function ( category ) {
			updateGcm( category, changed[ category ] );
		} );
	} );

	// ── Complianz: full revoke ────────────────────────────────────────────────
	document.addEventListener( 'cmplz_revoke', function () {
		if ( typeof window.gtag !== 'function' ) {
			return;
		}
		gtag( 'consent', 'update', {
			personalization_storage: 'denied',
			analytics_storage:       'denied',
			ad_storage:              'denied',
			ad_user_data:            'denied',
			ad_personalization:      'denied',
		} );
	} );
}() );
