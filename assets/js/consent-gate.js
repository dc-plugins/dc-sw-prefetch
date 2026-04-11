/**
 * DC Script Worker Proxy — Consent Gate (client-side unblocking)
 *
 * Listens for consent changes via the WP Consent API and dynamically
 * unblocks scripts that were rendered as type="text/plain" with a
 * data-wp-consent-category attribute.
 *
 * When consent is granted for a category, matching blocked scripts are
 * cloned as type="text/partytown" so Partytown picks them up and runs
 * them in the Web Worker. After all scripts are swapped,
 * window.dcSwpPartytownUpdate() is called to notify Partytown of the
 * new scripts.
 *
 * @package DC_Service_Worker_Proxy
 * @since   1.9.0
 */

/* global wp_has_consent, wp_listen_for_consent_change */

( function () {
	'use strict';

	/**
	 * Unblock all scripts whose data-wp-consent-category matches a
	 * category for which consent has been granted.
	 *
	 * @param {string} category WP Consent API category name.
	 */
	function unblockCategory( category ) {
		if ( typeof wp_has_consent !== 'function' || ! wp_has_consent( category ) ) {
			return;
		}

		const scripts = document.querySelectorAll(
			'script[type="text/plain"][data-wp-consent-category="' + category + '"]'
		);

		if ( ! scripts.length ) {
			return;
		}

		let changed = false;

		scripts.forEach( function ( oldScript ) {
			const newScript = document.createElement( 'script' );

			// Copy all attributes except type.
			Array.prototype.forEach.call( oldScript.attributes, function ( attr ) {
				if ( attr.name !== 'type' ) {
					newScript.setAttribute( attr.name, attr.value );
				}
			} );

			// Set the Partytown type.
			newScript.setAttribute( 'type', 'text/partytown' );

			// Copy inline content if present.
			if ( oldScript.textContent ) {
				newScript.textContent = oldScript.textContent;
			}

			oldScript.parentNode.replaceChild( newScript, oldScript );
			changed = true;
		} );

		// Notify Partytown to pick up the new scripts.
		if ( changed && typeof window.dcSwpPartytownUpdate === 'function' ) {
			window.dcSwpPartytownUpdate();
		}
	}

	/**
	 * Check all 5 WP Consent API categories and unblock any that
	 * are now granted.
	 */
	function checkAll() {
		const categories = [ 'marketing', 'statistics', 'statistics-anonymous', 'functional', 'preferences' ];
		categories.forEach( unblockCategory );
	}

	// Initial check on DOMContentLoaded — consent may already be set.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', checkAll );
	} else {
		checkAll();
	}

	// Live listener — fires when the visitor interacts with the CMP banner.
	if ( typeof wp_listen_for_consent_change === 'function' ) {
		wp_listen_for_consent_change( checkAll );
	} else {
		// WP Consent API not loaded yet — wait for it.
		document.addEventListener( 'wp_listen_for_consent_change', function () {
			if ( typeof wp_listen_for_consent_change === 'function' ) {
				wp_listen_for_consent_change( checkAll );
			}
		} );
	}
} )();
