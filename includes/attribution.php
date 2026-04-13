<?php
/**
 * DC Script Worker Proxy -- UTM & Click ID Attribution
 *
 * Captures UTM parameters and click IDs (gclid, fbclid, ttclid, msclkid)
 * from the landing page URL and stores them in a 30-day first-party cookie.
 * Server-side event senders (CAPI, SSGA4 Enhanced Conversions) read this
 * cookie on later pages (e.g. the thank-you page) where the original click
 * parameters are no longer present in the URL.
 *
 * @package DC_Service_Worker_Proxy
 * @since   2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

// ============================================================
// ATTRIBUTION -- CONSTANTS
// ============================================================

/**
 * Click ID and UTM parameter keys captured by the attribution cookie.
 *
 * @since 2.6.0
 */
define(
	'DC_SWP_ATTR_PARAMS',
	array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'fbclid', 'ttclid', 'msclkid' )
);

// ============================================================
// ATTRIBUTION -- COOKIE CAPTURE
// ============================================================

/**
 * Capture UTM parameters and click IDs from the current request URL.
 *
 * Runs at `init` priority 1 so the cookie is set before WooCommerce session
 * or any other cookie logic. Respects the first-touch / last-touch model
 * from settings: first-touch will not overwrite an existing cookie.
 *
 * @since 2.6.0
 * @return void
 */
function dc_swp_attr_capture(): void {
	if ( 'yes' !== get_option( 'dc_swp_attr_enabled', 'no' ) ) {
		return;
	}

	// Bail if none of the tracked params are present in the current URL.
	$has_tracked = false;
	foreach ( DC_SWP_ATTR_PARAMS as $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only URL params for attribution, no state change.
		if ( ! empty( $_GET[ $key ] ) ) {
			$has_tracked = true;
			break;
		}
	}
	if ( ! $has_tracked ) {
		return;
	}

	// First-touch: do not overwrite if attribution data is already stored.
	$model = get_option( 'dc_swp_attr_model', 'first' );
	if ( 'first' === $model && ! empty( $_COOKIE['dc_swp_attr'] ) ) {
		return;
	}

	$data = array( 'ts' => time() );
	foreach ( DC_SWP_ATTR_PARAMS as $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only URL params for attribution, no state change.
		$val = sanitize_text_field( wp_unslash( $_GET[ $key ] ?? '' ) );
		if ( '' !== $val ) {
			$data[ $key ] = $val;
		}
	}

	$encoded = wp_json_encode( $data );
	$expires = time() + 30 * DAY_IN_SECONDS;

	// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie -- first-party attribution cookie; SameSite=Lax enforced.
	setcookie(
		'dc_swp_attr',
		$encoded,
		array(
			'expires'  => $expires,
			'path'     => '/',
			'domain'   => '',
			'secure'   => is_ssl(),
			'httponly' => false, // JS-readable so GTM/pixels can consume UTM data client-side if needed.
			'samesite' => 'Lax',
		)
	);

	// Make the data available to the rest of the current request without a round-trip.
	$_COOKIE['dc_swp_attr'] = $encoded;
}
add_action( 'init', 'dc_swp_attr_capture', 1 );

// ============================================================
// ATTRIBUTION -- RETRIEVAL HELPERS
// ============================================================

/**
 * Return the stored attribution data array.
 *
 * Decodes the dc_swp_attr cookie. Returns an empty array when attribution is
 * disabled, no cookie is stored, or the cookie cannot be decoded.
 *
 * @since 2.6.0
 * @return array<string, string|int> Associative array of captured attribution params plus 'ts'.
 */
function dc_swp_get_attribution(): array {
	if ( 'yes' !== get_option( 'dc_swp_attr_enabled', 'no' ) ) {
		return array();
	}
	if ( empty( $_COOKIE['dc_swp_attr'] ) ) {
		return array();
	}
	$raw  = sanitize_text_field( wp_unslash( $_COOKIE['dc_swp_attr'] ) );
	$data = json_decode( $raw, true );
	return is_array( $data ) ? $data : array();
}

/**
 * Return the gclid stored in the attribution cookie.
 *
 * @since 2.6.0
 * @return string Google Ads click ID or empty string.
 */
function dc_swp_attr_get_gclid(): string {
	$attr = dc_swp_get_attribution();
	return isset( $attr['gclid'] ) ? (string) $attr['gclid'] : '';
}

/**
 * Return the fbclid from the attribution cookie formatted as a Meta fbc value.
 *
 * Synthesises the fbc cookie format fb.1.<timestamp>.<fbclid> using the
 * timestamp recorded when the click ID was first captured. Meta accepts
 * synthesised fbc values for attribution matching.
 *
 * @since 2.6.0
 * @return string Synthesised fbc value or empty string.
 */
function dc_swp_attr_get_fbc(): string {
	$attr   = dc_swp_get_attribution();
	$fbclid = $attr['fbclid'] ?? '';
	if ( '' === $fbclid ) {
		return '';
	}
	$ts = isset( $attr['ts'] ) ? (int) $attr['ts'] : time();
	return 'fb.1.' . $ts . '.' . $fbclid;
}
