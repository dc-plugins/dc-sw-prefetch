<?php
/**
 * DC Script Worker Proxy -- Meta Conversions API (CAPI)
 *
 * Server-side Meta CAPI event sending, parallel in architecture to SSGA4.
 * Sends WooCommerce ecommerce events to Meta's Graph API via the Conversions
 * API, independent of browser consent and ad-blockers. Supports hashed PII
 * for improved attribution, client-side deduplication via dcSwpCapiEventIds,
 * and _fbp / _fbc cookie forwarding.
 *
 * @package DC_Service_Worker_Proxy
 * @since   2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

// ============================================================
// CAPI -- CONFIGURATION
// ============================================================

/**
 * Return all CAPI config options, memoised for the request lifetime.
 *
 * Avoids repeated get_option() calls when multiple CAPI events fire in a
 * single request (e.g. begin_checkout + add_payment_info).
 *
 * @since 2.4.0
 * @return array{mode: string, enabled: string, pixel_id: string, access_token: string, test_event_code: string, events: array<string, bool>, exclude_logged: string, send_pii: string}
 */
function dc_swp_capi_get_config(): array {
	static $cfg = null;
	if ( null !== $cfg ) {
		return $cfg;
	}
	$events_raw = json_decode( get_option( 'dc_swp_capi_events', '{}' ), true );
	$mode       = get_option( 'dc_swp_capi_mode', 'off' );

	$cfg = array(
		'mode'            => $mode,
		'enabled'         => ( 'off' !== $mode ) ? 'yes' : 'no',
		'pixel_id'        => get_option( 'dc_swp_capi_pixel_id', '' ),
		'access_token'    => get_option( 'dc_swp_capi_access_token', '' ),
		'test_event_code' => get_option( 'dc_swp_capi_test_event_code', '' ),
		'events'          => is_array( $events_raw ) ? $events_raw : array(),
		'exclude_logged'  => get_option( 'dc_swp_capi_exclude_logged_in', 'yes' ),
		'send_pii'        => get_option( 'dc_swp_capi_send_pii', 'no' ),
	);
	return $cfg;
}

/**
 * Check whether CAPI is fully configured and active.
 *
 * @since 2.4.0
 * @return bool
 */
function dc_swp_capi_is_active(): bool {
	$cfg = dc_swp_capi_get_config();
	return 'yes' === $cfg['enabled']
		&& ! empty( $cfg['pixel_id'] )
		&& ! empty( $cfg['access_token'] );
}

/**
 * Check whether a specific CAPI event is enabled in settings.
 *
 * @since 2.4.0
 * @param string $event_name Meta standard event name (e.g. 'Purchase').
 * @return bool
 */
function dc_swp_capi_is_event_enabled( string $event_name ): bool {
	if ( ! dc_swp_capi_is_active() ) {
		return false;
	}
	$cfg = dc_swp_capi_get_config();
	return ! empty( $cfg['events'][ $event_name ] );
}

// ============================================================
// CAPI -- IDENTITY / COOKIE HELPERS
// ============================================================

/**
 * SHA-256 hash a PII value using Meta's normalisation rules.
 *
 * Rules: lowercase + trim, then SHA-256.
 * Returns empty string on empty input so callers can guard with empty().
 *
 * @since 2.4.0
 * @param string $value Raw PII value (email, phone, name, etc.).
 * @return string 64-char hex digest, or '' on empty input.
 */
function dc_swp_capi_hash( string $value ): string {
	$normalised = strtolower( trim( $value ) );
	if ( '' === $normalised ) {
		return '';
	}
	return hash( 'sha256', $normalised );
}

/**
 * Read the _fbp cookie (Meta browser ID).
 *
 * The _fbp cookie is set by the Meta Pixel on the client. Format:
 * fb.1.<creation_time>.<random>. Meta accepts the raw value unhashed.
 *
 * @since 2.4.0
 * @return string Raw _fbp value or empty string.
 */
function dc_swp_capi_get_fbp(): string {
	if ( ! empty( $_COOKIE['_fbp'] ) ) {
		return sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) );
	}
	return '';
}

/**
 * Read the _fbc click ID cookie or synthesise it from ?fbclid= query param.
 *
 * The _fbc cookie format is: fb.1.<creation_time>.<fbclid>.
 * If the cookie is absent but ?fbclid= is present in the URL, a value is
 * synthesised so the click attribution is not lost. Meta accepts it raw.
 *
 * @since 2.4.0
 * @return string Raw _fbc value, synthesised fb.1.ts.fbclid, or empty string.
 */
function dc_swp_capi_get_fbc(): string {
	if ( ! empty( $_COOKIE['_fbc'] ) ) {
		return sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) );
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only URL param for attribution.
	$fbclid = sanitize_text_field( wp_unslash( $_GET['fbclid'] ?? '' ) );
	if ( ! empty( $fbclid ) ) {
		return 'fb.1.' . time() . '.' . $fbclid;
	}
	// Fallback: synthesise fbc from the stored attribution cookie (captured on
	// the landing page, so fbclid is available even on the thank-you page).
	if ( function_exists( 'dc_swp_attr_get_fbc' ) ) {
		$attr_fbc = dc_swp_attr_get_fbc();
		if ( '' !== $attr_fbc ) {
			return $attr_fbc;
		}
	}
	return '';
}

/**
 * Return the current page URL for the event_source_url field.
 *
 * @since 2.4.0
 * @return string Absolute URL of the current page.
 */
function dc_swp_capi_current_url(): string {
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized through esc_url_raw( home_url() ) on the next line; sanitize_text_field would corrupt encoded query strings.
	return esc_url_raw( home_url( $request_uri ) );
}

/**
 * Build the user_data object for a CAPI event.
 *
 * Connection-level fields (IP address, user agent, fbp, fbc) are always
 * included. Hashed PII fields (em, ph, fn, ln, ct, st, zp, country,
 * external_id) are only included when dc_swp_capi_send_pii = 'yes'.
 *
 * @since 2.4.0
 * @param \WC_Order|null $order WooCommerce order for purchase events, null for cart-based events.
 * @return array<string, mixed> Meta user_data object.
 */
function dc_swp_capi_get_user_data( ?\WC_Order $order = null ): array {
	$cfg      = dc_swp_capi_get_config();
	$send_pii = 'yes' === $cfg['send_pii'];

	$user_data = array();

	// -- Connection-level fields (never hashed per Meta specification) -----
	$client_ip = '';
	if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		// Take the leftmost (original client) IP from a forwarded-for header.
		$forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
		$client_ip = trim( explode( ',', $forwarded )[0] );
	} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
		$client_ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	}
	if ( ! empty( $client_ip ) ) {
		$user_data['client_ip_address'] = $client_ip;
	}

	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	if ( ! empty( $ua ) ) {
		$user_data['client_user_agent'] = $ua;
	}

	$fbp = dc_swp_capi_get_fbp();
	if ( ! empty( $fbp ) ) {
		$user_data['fbp'] = $fbp;
	}

	$fbc = dc_swp_capi_get_fbc();
	if ( ! empty( $fbc ) ) {
		$user_data['fbc'] = $fbc;
	}

	if ( ! $send_pii ) {
		return $user_data;
	}

	// -- Hashed PII fields (only when send_pii is enabled) -----------------
	if ( null !== $order ) {
		$email = $order->get_billing_email();
		if ( ! empty( $email ) ) {
			$user_data['em'] = array( dc_swp_capi_hash( $email ) );
		}

		$phone = $order->get_billing_phone();
		if ( ! empty( $phone ) ) {
			// Strip all non-digit chars except a leading + (E.164 normalisation).
			$phone_clean = preg_replace( '/(?!^\+)[^\d]/', '', $phone );
			$hashed      = dc_swp_capi_hash( $phone_clean );
			if ( '' !== $hashed ) {
				$user_data['ph'] = array( $hashed );
			}
		}

		$first = $order->get_billing_first_name();
		if ( ! empty( $first ) ) {
			$user_data['fn'] = array( dc_swp_capi_hash( $first ) );
		}

		$last = $order->get_billing_last_name();
		if ( ! empty( $last ) ) {
			$user_data['ln'] = array( dc_swp_capi_hash( $last ) );
		}

		$city = $order->get_billing_city();
		if ( ! empty( $city ) ) {
			// Meta rule: city -- lowercase, strip spaces and hyphens, then hash.
			$city_clean      = strtolower( preg_replace( '/[\s\-]/', '', $city ) );
			$user_data['ct'] = array( hash( 'sha256', $city_clean ) );
		}

		$state = $order->get_billing_state();
		if ( ! empty( $state ) ) {
			$user_data['st'] = array( dc_swp_capi_hash( $state ) );
		}

		$postcode = $order->get_billing_postcode();
		if ( ! empty( $postcode ) ) {
			$user_data['zp'] = array( dc_swp_capi_hash( $postcode ) );
		}

		$country = $order->get_billing_country();
		if ( ! empty( $country ) ) {
			$user_data['country'] = array( dc_swp_capi_hash( $country ) );
		}

		// external_id: WC customer ID (hashed), with email hash fallback.
		$cid = (int) $order->get_customer_id();
		if ( $cid > 0 ) {
			$user_data['external_id'] = array( dc_swp_capi_hash( (string) $cid ) );
		} elseif ( ! empty( $email ) ) {
			$user_data['external_id'] = array( dc_swp_capi_hash( $email ) );
		}
	} else {
		// No order -- pull from logged-in user or WC session.
		$wc_user_id = function_exists( 'WC' ) && WC()->customer ? (int) WC()->customer->get_id() : 0;
		if ( $wc_user_id > 0 ) {
			$user_data['external_id'] = array( dc_swp_capi_hash( (string) $wc_user_id ) );
		}
		// Billing email from WC session if available (entered on checkout).
		if ( function_exists( 'WC' ) && ! is_null( WC()->session ) ) {
			$session_email = WC()->session->get( 'billing_email', '' );
			if ( ! empty( $session_email ) && is_email( $session_email ) ) {
				$user_data['em'] = array( dc_swp_capi_hash( $session_email ) );
			}
		}
	}

	return $user_data;
}

// ============================================================
// CAPI -- CONTENT BUILDERS
// Thin adapters over the existing SSGA4 builders to avoid
// re-querying the DB for the same order / cart items.
// ============================================================

/**
 * Build a Meta CAPI contents array from WooCommerce order items.
 *
 * Delegates to dc_swp_ssga4_build_items() for the underlying DB query
 * and re-maps field names to the Meta contents schema.
 *
 * @since 2.4.0
 * @param \WC_Order $order WooCommerce order object.
 * @return array<int, array<string, mixed>> Meta contents array.
 */
function dc_swp_capi_build_contents_from_order( \WC_Order $order ): array {
	$ga4_items = dc_swp_ssga4_build_items( $order );
	$contents  = array();
	foreach ( $ga4_items as $item ) {
		$contents[] = array(
			'id'         => $item['item_id'],
			'quantity'   => $item['quantity'],
			'item_price' => $item['price'],
			'title'      => $item['item_name'],
		);
	}
	return $contents;
}

/**
 * Build a Meta CAPI contents array from WooCommerce cart.
 *
 * @since 2.4.0
 * @return array<int, array<string, mixed>> Meta contents array.
 */
function dc_swp_capi_build_contents_from_cart(): array {
	$ga4_items = dc_swp_ssga4_build_cart_items();
	$contents  = array();
	foreach ( $ga4_items as $item ) {
		$contents[] = array(
			'id'         => $item['item_id'],
			'quantity'   => $item['quantity'],
			'item_price' => $item['price'],
			'title'      => $item['item_name'],
		);
	}
	return $contents;
}

// ============================================================
// CAPI -- DEDUPLICATION
// ============================================================

/**
 * Generate a stable event ID for CAPI / client-side pixel deduplication.
 *
 * The server-side event_id must match the eventID passed in:
 *   fbq('track', EventName, data, { eventID: window.dcSwpCapiEventIds[EventName] })
 *
 * For purchase / refund: the order ID is the seed so the ID is stable across
 * retries (e.g. user refreshes the thank-you page).
 *
 * For session-scoped events: a WC session fingerprint + 5-second time bucket
 * is used so server and client IDs generated in the same page load match,
 * but a fresh ID is generated on each new visit.
 *
 * @since 2.4.0
 * @param string $event      Meta standard event name (e.g. 'Purchase').
 * @param int    $context_id Order ID for purchase/refund, 0 for session events.
 * @return string Event ID string.
 */
function dc_swp_capi_get_event_id( string $event, int $context_id = 0 ): string {
	$slug = strtolower( preg_replace( '/([a-z])([A-Z])/', '$1_$2', $event ) );
	if ( $context_id > 0 ) {
		return 'capi_' . $slug . '_' . $context_id;
	}
	// Session fingerprint (WC customer/session ID or IP+UA hash as fallback).
	$session_key = '';
	if ( function_exists( 'WC' ) && ! is_null( WC()->session ) ) {
		$session_key = (string) WC()->session->get_customer_id();
	}
	if ( '' === $session_key ) {
		$remote      = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ua          = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$session_key = wp_hash( $remote . $ua );
	}
	// 5-second bucket keeps server ID in sync with client ID emitted in the same page load.
	$bucket = (int) floor( microtime( true ) / 5 );
	return 'capi_' . $slug . '_' . substr( md5( $session_key ), 0, 8 ) . '_' . $bucket;
}

/**
 * Guard against duplicate page-render events within the same WC session.
 *
 * Mirrors dc_swp_ssga4_should_fire_once(). Repeated hooks (begin_checkout,
 * view_cart) fire on every request to the page; this ensures only the first
 * fires per session.
 *
 * @since 2.4.0
 * @param string $event Meta event name used as the session key.
 * @return bool True if the event should fire, false if already fired.
 */
function dc_swp_capi_should_fire_once( string $event ): bool {
	if ( ! function_exists( 'WC' ) || is_null( WC()->session ) ) {
		return true;
	}
	$key = 'dc_swp_capi_fired_' . $event;
	if ( WC()->session->get( $key ) ) {
		return false;
	}
	WC()->session->set( $key, true );
	return true;
}

// ============================================================
// CAPI -- SEND
// ============================================================

/**
 * Send one or more events to Meta Conversions API via the Graph API.
 *
 * Endpoint: https://graph.facebook.com/v20.0/{PIXEL_ID}/events
 * The test_event_code option is appended to the payload when set so events
 * appear in the Meta Events Manager real-time test view.
 *
 * @since 2.4.0
 * @param array<int, array<string, mixed>> $server_events Assembled server event objects.
 * @param bool                             $blocking      True = wait for response (purchase/refund).
 * @return bool True on success or non-blocking dispatch, false on config error.
 */
/**
 * Build the data_processing_options fields to merge into a CAPI payload.
 *
 * When LDU is enabled, Meta requires the server-side payload to declare the
 * same restriction level as the client-side Pixel so that Events Manager
 * deduplication parity checks pass. When the visitor has consented (WP
 * Consent API gate is active), we clear the LDU restriction instead.
 *
 * @return array Empty array (omit field) or associative array with DPO keys.
 */
function dc_swp_capi_get_ldu_payload_fields(): array {
	if ( ! dc_swp_is_meta_ldu_enabled() ) {
		return array();
	}

	// If the consent gate is active and the visitor has marketing consent,
	// return an empty restriction array — consented visitors are unrestricted.
	if ( dc_swp_is_consent_gate_enabled() && function_exists( 'dc_swp_has_consent_for' ) ) {
		if ( dc_swp_has_consent_for( 'marketing' ) ) {
			return array( 'data_processing_options' => array() );
		}
	}

	return array(
		'data_processing_options'         => array( 'LDU' ),
		'data_processing_options_country' => 0,
		'data_processing_options_state'   => 0,
	);
}

/**
 * Send one or more server events to the Meta Conversions API.
 *
 * @since 2.4.0
 * @param array<int,array<string,mixed>> $server_events  Array of CAPI event objects.
 * @param bool                           $blocking        Whether to wait for the HTTP response.
 * @return bool True if the request was dispatched, false if pixel/token are missing.
 */
function dc_swp_capi_send( array $server_events, bool $blocking = false ): bool {
	$cfg          = dc_swp_capi_get_config();
	$pixel_id     = $cfg['pixel_id'];
	$access_token = $cfg['access_token'];

	if ( empty( $pixel_id ) || empty( $access_token ) ) {
		return false;
	}

	$url = 'https://graph.facebook.com/v20.0/' . rawurlencode( $pixel_id ) . '/events';

	$payload = array_merge( array( 'data' => $server_events ), dc_swp_capi_get_ldu_payload_fields() );

	$tec = $cfg['test_event_code'];
	if ( ! empty( $tec ) ) {
		$payload['test_event_code'] = $tec;
	}

	$response = wp_remote_post(
		$url,
		array(
			'timeout'  => $blocking ? 5 : 1,
			'blocking' => $blocking,
			'headers'  => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $access_token,
			),
			'body'     => wp_json_encode( $payload ),
		)
	);

	if ( $blocking && is_wp_error( $response ) ) {
		return false;
	}

	return true;
}

// ============================================================
// CAPI -- CLIENT-SIDE DEDUPLICATION INJECTION
// ============================================================

/**
 * Inject the CSP nonce onto the dc-swp-capi-ids inline script tag.
 *
 * Hooked onto wp_inline_script_attributes (WP 6.3+) so the tag participates
 * in any Content-Security-Policy: script-src 'nonce-...' header emitted by
 * dc_swp_get_csp_nonce().
 *
 * @since 2.5.1
 * @param array<string,string> $attributes Existing attributes for the tag.
 * @param string               $handle     Script handle being output.
 * @return array<string,string>
 */
function dc_swp_capi_ids_nonce( array $attributes, string $handle ): array {
	if ( 'dc-swp-capi-ids' === $handle ) {
		$nonce = dc_swp_get_csp_nonce();
		if ( '' !== $nonce ) {
			$attributes['nonce'] = $nonce;
		}
	}
	return $attributes;
}
add_filter( 'wp_inline_script_attributes', 'dc_swp_capi_ids_nonce', 10, 2 );

/**
 * Output the window.dcSwpCapiEventIds JSON blob in the page footer.
 *
 * Provides the same server-generated event IDs to the client-side Meta Pixel
 * so that fbq('track', Event, data, { eventID: dcSwpCapiEventIds[Event] })
 * correctly deduplicates against the CAPI server hit.
 *
 * @since 2.4.0
 * @return void
 */
function dc_swp_capi_inject_event_ids(): void {
	if ( ! dc_swp_capi_is_active() ) {
		return;
	}

	$ids = array();

	// Pre-generate IDs for all supported session-scoped events.
	foreach ( array( 'ViewContent', 'InitiateCheckout', 'AddToCart', 'AddPaymentInfo' ) as $event ) {
		$ids[ $event ] = dc_swp_capi_get_event_id( $event );
	}

	// Purchase ID is order-based -- only available on the thank-you page.
	if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
		global $wp;
		$order_id = absint( $wp->query_vars['order-received'] ?? 0 );
		if ( $order_id > 0 ) {
			$ids['Purchase'] = dc_swp_capi_get_event_id( 'Purchase', $order_id );
		}
	}

	// Register a virtual (no-file) handle so the inline blob goes through the
	// WP enqueue API: participates in wp_inline_script_attributes (nonce),
	// can be deregistered by companion plugins, and avoids raw echo.
	// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- inline-only handle, no file to version.
	wp_register_script( 'dc-swp-capi-ids', false, array(), null, array( 'in_footer' => true ) );
	wp_add_inline_script( 'dc-swp-capi-ids', 'window.dcSwpCapiEventIds=' . wp_json_encode( $ids ) . ';' );
	wp_enqueue_script( 'dc-swp-capi-ids' );
}
add_action( 'wp_footer', 'dc_swp_capi_inject_event_ids', 1 );

// ============================================================
// CAPI -- WOOCOMMERCE EVENT HOOKS
// ============================================================

if ( class_exists( 'WooCommerce' ) ) {

	/**
	 * CAPI: Purchase event -- fires on thank-you page.
	 *
	 * Uses _dc_swp_capi_purchase_tracked order meta to prevent double-firing
	 * if the thank-you page is refreshed. Sent with blocking = true so the
	 * meta flag is written before the response ends.
	 *
	 * @since 2.4.0
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	function dc_swp_capi_purchase( int $order_id ): void {
		if ( ! dc_swp_capi_is_event_enabled( 'Purchase' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( $order->get_meta( '_dc_swp_capi_purchase_tracked' ) ) {
			return;
		}

		$cfg = dc_swp_capi_get_config();
		if ( 'yes' === $cfg['exclude_logged'] && is_user_logged_in() ) {
			return;
		}

		$contents    = dc_swp_capi_build_contents_from_order( $order );
		$content_ids = array_column( $contents, 'id' );

		$custom_data = array(
			'value'        => (float) $order->get_total(),
			'currency'     => $order->get_currency(),
			'content_ids'  => $content_ids,
			'contents'     => $contents,
			'content_type' => 'product',
			'num_items'    => count( $contents ),
			'order_id'     => (string) $order->get_order_number(),
		);

		$server_event = array(
			'event_name'       => 'Purchase',
			'event_time'       => time(),
			'event_id'         => dc_swp_capi_get_event_id( 'Purchase', $order_id ),
			'event_source_url' => esc_url_raw( $order->get_checkout_order_received_url() ),
			'action_source'    => 'website',
			'user_data'        => dc_swp_capi_get_user_data( $order ),
			'custom_data'      => $custom_data,
		);

		if ( dc_swp_capi_send( array( $server_event ), true ) ) {
			$order->update_meta_data( '_dc_swp_capi_purchase_tracked', '1' );
			$order->save_meta_data();
		}
	}
	add_action( 'woocommerce_thankyou', 'dc_swp_capi_purchase', 21 );

	/**
	 * CAPI: InitiateCheckout event.
	 *
	 * Gated on marketing consent. de-duplicated per WC session so repeated
	 * renders of the checkout page (validation errors, etc.) only fire once.
	 *
	 * @since 2.4.0
	 * @return void
	 */
	function dc_swp_capi_initiate_checkout(): void {
		if ( ! dc_swp_capi_is_event_enabled( 'InitiateCheckout' ) ) {
			return;
		}
		if ( ! dc_swp_capi_should_fire_once( 'InitiateCheckout' ) ) {
			return;
		}
		if ( ! function_exists( 'WC' ) || is_null( WC()->cart ) ) {
			return;
		}
		if ( function_exists( 'dc_swp_has_consent_for' ) && ! dc_swp_has_consent_for( 'marketing' ) ) {
			return;
		}

		$cfg = dc_swp_capi_get_config();
		if ( 'yes' === $cfg['exclude_logged'] && is_user_logged_in() ) {
			return;
		}

		$contents    = dc_swp_capi_build_contents_from_cart();
		$content_ids = array_column( $contents, 'id' );

		$custom_data = array(
			'value'        => (float) WC()->cart->get_total( 'edit' ),
			'currency'     => get_woocommerce_currency(),
			'content_ids'  => $content_ids,
			'contents'     => $contents,
			'content_type' => 'product',
			'num_items'    => count( $contents ),
		);

		$server_event = array(
			'event_name'       => 'InitiateCheckout',
			'event_time'       => time(),
			'event_id'         => dc_swp_capi_get_event_id( 'InitiateCheckout' ),
			'event_source_url' => dc_swp_capi_current_url(),
			'action_source'    => 'website',
			'user_data'        => dc_swp_capi_get_user_data(),
			'custom_data'      => $custom_data,
		);

		dc_swp_capi_send( array( $server_event ) );
	}
	add_action( 'woocommerce_before_checkout_form', 'dc_swp_capi_initiate_checkout', 21 );

	/**
	 * WooCommerce Blocks checkout compatibility: fire InitiateCheckout via
	 * template_redirect so it also triggers on block-based checkout pages,
	 * which do not emit woocommerce_before_checkout_form.
	 *
	 * The dc_swp_capi_should_fire_once() guard prevents double-firing on classic checkout
	 * where both hooks reach the function in the same session.
	 *
	 * @since 2.6.0
	 * @return void
	 */
	function dc_swp_capi_initiate_checkout_blocks(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return; // Thank-you page satisfies is_checkout() -- exclude it.
		}
		dc_swp_capi_initiate_checkout();
	}
	add_action( 'template_redirect', 'dc_swp_capi_initiate_checkout_blocks', 21 );

	/**
	 * CAPI: AddToCart event.
	 *
	 * @since 2.4.0
	 * @param string $cart_item_key Cart item key (unused, required by hook).
	 * @param int    $product_id    WooCommerce product ID.
	 * @param int    $quantity      Quantity added.
	 * @param int    $variation_id  Variation ID (0 for simple products).
	 * @return void
	 */
	function dc_swp_capi_add_to_cart( string $cart_item_key, int $product_id, int $quantity, int $variation_id ): void {
		if ( ! dc_swp_capi_is_event_enabled( 'AddToCart' ) ) {
			return;
		}
		if ( function_exists( 'dc_swp_has_consent_for' ) && ! dc_swp_has_consent_for( 'marketing' ) ) {
			return;
		}

		$cfg = dc_swp_capi_get_config();
		if ( 'yes' === $cfg['exclude_logged'] && is_user_logged_in() ) {
			return;
		}

		$pid     = $variation_id > 0 ? $variation_id : $product_id;
		$product = wc_get_product( $pid );
		if ( ! $product ) {
			return;
		}

		$sku   = $product->get_sku();
		$id    = $sku ? $sku : (string) $product->get_id();
		$price = (float) $product->get_price();

		$custom_data = array(
			'value'        => $price * $quantity,
			'currency'     => get_woocommerce_currency(),
			'content_ids'  => array( $id ),
			'contents'     => array(
				array(
					'id'         => $id,
					'quantity'   => $quantity,
					'item_price' => $price,
					'title'      => $product->get_name(),
				),
			),
			'content_type' => 'product',
		);

		$server_event = array(
			'event_name'       => 'AddToCart',
			'event_time'       => time(),
			'event_id'         => dc_swp_capi_get_event_id( 'AddToCart' ),
			'event_source_url' => esc_url_raw( get_permalink( $product->get_id() ) ),
			'action_source'    => 'website',
			'user_data'        => dc_swp_capi_get_user_data(),
			'custom_data'      => $custom_data,
		);

		dc_swp_capi_send( array( $server_event ) );
	}
	add_action( 'woocommerce_add_to_cart', 'dc_swp_capi_add_to_cart', 21, 4 );

	/**
	 * CAPI: ViewContent event -- single product page.
	 *
	 * @since 2.4.0
	 * @return void
	 */
	function dc_swp_capi_view_content(): void {
		if ( ! dc_swp_capi_is_event_enabled( 'ViewContent' ) ) {
			return;
		}
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		if ( ! dc_swp_capi_should_fire_once( 'ViewContent' ) ) {
			return;
		}
		if ( function_exists( 'dc_swp_has_consent_for' ) && ! dc_swp_has_consent_for( 'marketing' ) ) {
			return;
		}

		global $product;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$cfg = dc_swp_capi_get_config();
		if ( 'yes' === $cfg['exclude_logged'] && is_user_logged_in() ) {
			return;
		}

		$sku   = $product->get_sku();
		$id    = $sku ? $sku : (string) $product->get_id();
		$price = (float) $product->get_price();

		$custom_data = array(
			'value'        => $price,
			'currency'     => get_woocommerce_currency(),
			'content_ids'  => array( $id ),
			'contents'     => array(
				array(
					'id'         => $id,
					'quantity'   => 1,
					'item_price' => $price,
					'title'      => $product->get_name(),
				),
			),
			'content_name' => $product->get_name(),
			'content_type' => 'product',
		);

		$server_event = array(
			'event_name'       => 'ViewContent',
			'event_time'       => time(),
			'event_id'         => dc_swp_capi_get_event_id( 'ViewContent' ),
			'event_source_url' => esc_url_raw( get_permalink( $product->get_id() ) ),
			'action_source'    => 'website',
			'user_data'        => dc_swp_capi_get_user_data(),
			'custom_data'      => $custom_data,
		);

		dc_swp_capi_send( array( $server_event ) );
	}
	add_action( 'woocommerce_after_single_product', 'dc_swp_capi_view_content', 21 );

	/**
	 * CAPI: AddPaymentInfo event.
	 *
	 * @since 2.4.0
	 * @return void
	 */
	function dc_swp_capi_add_payment_info(): void {
		if ( ! dc_swp_capi_is_event_enabled( 'AddPaymentInfo' ) ) {
			return;
		}
		if ( ! function_exists( 'WC' ) || is_null( WC()->cart ) ) {
			return;
		}
		if ( ! dc_swp_capi_should_fire_once( 'AddPaymentInfo' ) ) {
			return;
		}
		if ( function_exists( 'dc_swp_has_consent_for' ) && ! dc_swp_has_consent_for( 'marketing' ) ) {
			return;
		}

		$cfg = dc_swp_capi_get_config();
		if ( 'yes' === $cfg['exclude_logged'] && is_user_logged_in() ) {
			return;
		}

		$contents    = dc_swp_capi_build_contents_from_cart();
		$content_ids = array_column( $contents, 'id' );

		$custom_data = array(
			'value'        => (float) WC()->cart->get_total( 'edit' ),
			'currency'     => get_woocommerce_currency(),
			'content_ids'  => $content_ids,
			'contents'     => $contents,
			'content_type' => 'product',
		);

		$server_event = array(
			'event_name'       => 'AddPaymentInfo',
			'event_time'       => time(),
			'event_id'         => dc_swp_capi_get_event_id( 'AddPaymentInfo' ),
			'event_source_url' => dc_swp_capi_current_url(),
			'action_source'    => 'website',
			'user_data'        => dc_swp_capi_get_user_data(),
			'custom_data'      => $custom_data,
		);

		dc_swp_capi_send( array( $server_event ) );
	}
	add_action( 'woocommerce_checkout_after_order_review', 'dc_swp_capi_add_payment_info', 21 );

} // end if class_exists( 'WooCommerce' )
