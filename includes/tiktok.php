<?php
/**
 * DC Script Worker Proxy -- TikTok Events API (server-side)
 *
 * Server-side TikTok Events API event sending. Sends WooCommerce ecommerce
 * events from the server to TikTok's Business API, independent of browser
 * consent and ad-blockers. Supports hashed PII for improved attribution,
 * client-side deduplication via dcSwpTtEventIds, and ttclid forwarding from
 * the UTM attribution cookie.
 *
 * @package DC_Service_Worker_Proxy
 * @since   2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

// ============================================================
// TIKTOK -- CONFIGURATION
// ============================================================

/**
 * Return all TikTok config options, memoised for the request lifetime.
 *
 * @since 2.6.0
 * @return array{pixel_id: string, access_token: string, test_event_code: string, events: array<string, bool>, exclude_logged: string, send_pii: string}
 */
function dc_swp_tt_get_config(): array {
	static $cfg = null;
	if ( null !== $cfg ) {
		return $cfg;
	}
	$events_raw = json_decode( get_option( 'dc_swp_tt_events', '{}' ), true );

	$cfg = array(
		'pixel_id'        => get_option( 'dc_swp_tt_pixel_id', '' ),
		'access_token'    => get_option( 'dc_swp_tt_access_token', '' ),
		'test_event_code' => get_option( 'dc_swp_tt_test_event_code', '' ),
		'events'          => is_array( $events_raw ) ? $events_raw : array(),
		'exclude_logged'  => get_option( 'dc_swp_tt_exclude_logged_in', 'yes' ),
		'send_pii'        => get_option( 'dc_swp_tt_send_pii', 'no' ),
	);
	return $cfg;
}

/**
 * Check whether the TikTok Events API is fully configured and active.
 *
 * @since 2.6.0
 * @return bool
 */
function dc_swp_tt_is_active(): bool {
	$cfg = dc_swp_tt_get_config();
	return ! empty( $cfg['pixel_id'] ) && ! empty( $cfg['access_token'] );
}

/**
 * Check whether a specific TikTok event is enabled in settings.
 *
 * @since 2.6.0
 * @param string $event_name TikTok standard event name (e.g. 'Purchase').
 * @return bool
 */
function dc_swp_tt_is_event_enabled( string $event_name ): bool {
	if ( ! dc_swp_tt_is_active() ) {
		return false;
	}
	$cfg = dc_swp_tt_get_config();
	return ! empty( $cfg['events'][ $event_name ] );
}

// ============================================================
// TIKTOK -- HELPERS
// ============================================================

/**
 * SHA-256 hash a PII value using TikTok's normalisation rules.
 *
 * Rules: lowercase + trim, then SHA-256.
 * Returns empty string on empty input.
 *
 * @since 2.6.0
 * @param string $value Raw PII value.
 * @return string 64-char hex digest, or '' on empty input.
 */
function dc_swp_tt_hash( string $value ): string {
	$normalised = strtolower( trim( $value ) );
	if ( '' === $normalised ) {
		return '';
	}
	return hash( 'sha256', $normalised );
}

/**
 * Return the current page URL for the page.url field.
 *
 * @since 2.6.0
 * @return string Absolute URL of the current page.
 */
function dc_swp_tt_current_url(): string {
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized through esc_url_raw( home_url() ).
	return esc_url_raw( home_url( $request_uri ) );
}

/**
 * Build the user object for a TikTok Events API event.
 *
 * Connection-level fields (IP, user agent, ttclid) are always included.
 * Hashed PII fields (email, phone_number, external_id) are only included
 * when dc_swp_tt_send_pii = 'yes'.
 *
 * @since 2.6.0
 * @param \WC_Order|null $order WooCommerce order for purchase events, null for session events.
 * @return array<string, string> TikTok user object.
 */
function dc_swp_tt_get_user_data( ?\WC_Order $order = null ): array {
	$cfg      = dc_swp_tt_get_config();
	$send_pii = 'yes' === $cfg['send_pii'];
	$user     = array();

	// -- Connection-level fields ------------------------------------------
	$client_ip = '';
	if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		$forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
		$client_ip = trim( explode( ',', $forwarded )[0] );
	} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
		$client_ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	}
	if ( ! empty( $client_ip ) ) {
		$user['ip'] = $client_ip;
	}

	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	if ( ! empty( $ua ) ) {
		$user['user_agent'] = $ua;
	}

	// ttclid from UTM attribution cookie (captured on landing page).
	if ( function_exists( 'dc_swp_get_attribution' ) ) {
		$attr   = dc_swp_get_attribution();
		$ttclid = $attr['ttclid'] ?? '';
		if ( '' !== $ttclid ) {
			$user['ttclid'] = $ttclid;
		}
	}

	if ( ! $send_pii ) {
		return $user;
	}

	// -- Hashed PII fields ------------------------------------------------
	if ( null !== $order ) {
		$email = $order->get_billing_email();
		if ( ! empty( $email ) && is_email( $email ) ) {
			$user['email'] = dc_swp_tt_hash( $email );
		}

		$phone = $order->get_billing_phone();
		if ( ! empty( $phone ) ) {
			// Strip all non-digit chars except a leading + (E.164 normalisation).
			$phone_clean = preg_replace( '/(?!^\+)[^\d]/', '', $phone );
			if ( '' !== $phone_clean ) {
				$user['phone_number'] = hash( 'sha256', $phone_clean );
			}
		}

		// external_id: WC customer ID (hashed), with email hash as fallback.
		$cid = (int) $order->get_customer_id();
		if ( $cid > 0 ) {
			$user['external_id'] = hash( 'sha256', (string) $cid );
		} elseif ( ! empty( $email ) ) {
			$user['external_id'] = dc_swp_tt_hash( $email );
		}
	} elseif ( function_exists( 'WC' ) && WC()->customer ) {
			$wc_user_id = (int) WC()->customer->get_id();
		if ( $wc_user_id > 0 ) {
			$user['external_id'] = hash( 'sha256', (string) $wc_user_id );
		}
	}

	return $user;
}

/**
 * Build a TikTok contents array from WooCommerce order items.
 *
 * @since 2.6.0
 * @param \WC_Order $order WooCommerce order object.
 * @return array<int, array<string, mixed>> TikTok contents array.
 */
function dc_swp_tt_build_contents_from_order( \WC_Order $order ): array {
	$ga4_items = dc_swp_ssga4_build_items( $order );
	$contents  = array();
	foreach ( $ga4_items as $item ) {
		$contents[] = array(
			'content_id'   => $item['item_id'],
			'content_type' => 'product',
			'content_name' => $item['item_name'],
			'price'        => $item['price'],
			'quantity'     => $item['quantity'],
		);
	}
	return $contents;
}

/**
 * Build a TikTok contents array from WooCommerce cart.
 *
 * @since 2.6.0
 * @return array<int, array<string, mixed>> TikTok contents array.
 */
function dc_swp_tt_build_contents_from_cart(): array {
	$ga4_items = dc_swp_ssga4_build_cart_items();
	$contents  = array();
	foreach ( $ga4_items as $item ) {
		$contents[] = array(
			'content_id'   => $item['item_id'],
			'content_type' => 'product',
			'content_name' => $item['item_name'],
			'price'        => $item['price'],
			'quantity'     => $item['quantity'],
		);
	}
	return $contents;
}

// ============================================================
// TIKTOK -- DEDUPLICATION
// ============================================================

/**
 * Generate a stable event ID for TikTok / client-side pixel deduplication.
 *
 * For purchase events: order ID is the seed so the ID is stable across retries.
 * For session-scoped events: WC session fingerprint + 5-second time bucket.
 *
 * @since 2.6.0
 * @param string $event      TikTok event name (e.g. 'Purchase').
 * @param int    $context_id Order ID for purchase events, 0 for session events.
 * @return string Event ID string.
 */
function dc_swp_tt_get_event_id( string $event, int $context_id = 0 ): string {
	$slug = strtolower( preg_replace( '/([a-z])([A-Z])/', '$1_$2', $event ) );
	if ( $context_id > 0 ) {
		return 'tt_' . $slug . '_' . $context_id;
	}
	$session_key = '';
	if ( function_exists( 'WC' ) && ! is_null( WC()->session ) ) {
		$session_key = (string) WC()->session->get_customer_id();
	}
	if ( '' === $session_key ) {
		$remote      = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ua          = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$session_key = wp_hash( $remote . $ua );
	}
	$bucket = (int) floor( microtime( true ) / 5 );
	return 'tt_' . $slug . '_' . substr( md5( $session_key ), 0, 8 ) . '_' . $bucket;
}

/**
 * Guard against duplicate page-render events within the same WC session.
 *
 * @since 2.6.0
 * @param string $event TikTok event name used as the session key.
 * @return bool True if the event should fire, false if already fired.
 */
function dc_swp_tt_should_fire_once( string $event ): bool {
	if ( ! function_exists( 'WC' ) || is_null( WC()->session ) ) {
		return true;
	}
	$key = 'dc_swp_tt_fired_' . $event;
	if ( WC()->session->get( $key ) ) {
		return false;
	}
	WC()->session->set( $key, true );
	return true;
}

// ============================================================
// TIKTOK -- SEND
// ============================================================

/**
 * Send one or more events to the TikTok Events API.
 *
 * Endpoint: https://business-api.tiktok.com/open_api/v1.3/event/track/
 * Authentication uses the Access-Token header (not Authorization: Bearer).
 *
 * @since 2.6.0
 * @param array<int, array<string, mixed>> $events   Array of TikTok event objects.
 * @param bool                             $blocking Whether to wait for the HTTP response.
 * @return bool True if dispatched, false if pixel ID or access token is missing.
 */
function dc_swp_tt_send( array $events, bool $blocking = false ): bool {
	$cfg          = dc_swp_tt_get_config();
	$pixel_id     = $cfg['pixel_id'];
	$access_token = $cfg['access_token'];

	if ( empty( $pixel_id ) || empty( $access_token ) ) {
		return false;
	}

	$payload = array(
		'event_source'    => 'web',
		'event_source_id' => $pixel_id,
		'data'            => $events,
	);

	$tec = $cfg['test_event_code'];
	if ( ! empty( $tec ) ) {
		$payload['test_event_code'] = $tec;
	}

	$response = wp_remote_post(
		'https://business-api.tiktok.com/open_api/v1.3/event/track/',
		array(
			'timeout'  => $blocking ? 5 : 1,
			'blocking' => $blocking,
			'headers'  => array(
				'Content-Type' => 'application/json',
				'Access-Token' => $access_token,
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
// TIKTOK -- CLIENT-SIDE DEDUPLICATION INJECTION
// ============================================================

/**
 * Inject the CSP nonce onto the dc-swp-tt-ids inline script tag.
 *
 * @since 2.6.0
 * @param array<string,string> $attributes Existing tag attributes.
 * @param string               $handle     Script handle being output.
 * @return array<string,string>
 */
function dc_swp_tt_ids_nonce( array $attributes, string $handle ): array {
	if ( 'dc-swp-tt-ids' === $handle ) {
		$nonce = dc_swp_get_csp_nonce();
		if ( '' !== $nonce ) {
			$attributes['nonce'] = $nonce;
		}
	}
	return $attributes;
}
add_filter( 'wp_inline_script_attributes', 'dc_swp_tt_ids_nonce', 10, 2 );

/**
 * Output the window.dcSwpTtEventIds JSON blob in the page footer.
 *
 * Provides the same server-generated event IDs to the client-side TikTok Pixel
 * so ttq.trackWithDeduplication() can pass the matching event_id and suppress
 * the duplicate server hit.
 *
 * @since 2.6.0
 * @return void
 */
function dc_swp_tt_inject_event_ids(): void {
	if ( ! dc_swp_tt_is_active() ) {
		return;
	}

	$ids = array();
	foreach ( array( 'ViewContent', 'InitiateCheckout', 'AddToCart' ) as $event ) {
		$ids[ $event ] = dc_swp_tt_get_event_id( $event );
	}

	if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
		global $wp;
		$order_id = absint( $wp->query_vars['order-received'] ?? 0 );
		if ( $order_id > 0 ) {
			$ids['Purchase'] = dc_swp_tt_get_event_id( 'Purchase', $order_id );
		}
	}

	// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- inline-only handle, no file to version.
	wp_register_script( 'dc-swp-tt-ids', false, array(), null, array( 'in_footer' => true ) );
	wp_add_inline_script( 'dc-swp-tt-ids', 'window.dcSwpTtEventIds=' . wp_json_encode( $ids ) . ';' );
	wp_enqueue_script( 'dc-swp-tt-ids' );
}
add_action( 'wp_footer', 'dc_swp_tt_inject_event_ids', 1 );

// ============================================================
// TIKTOK -- WOOCOMMERCE EVENT HOOKS
// ============================================================

if ( class_exists( 'WooCommerce' ) ) {

	/**
	 * TikTok Events API: Purchase event -- fires on thank-you page.
	 *
	 * Uses _dc_swp_tt_purchase_tracked order meta to prevent double-firing.
	 *
	 * @since 2.6.0
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	function dc_swp_tt_purchase( int $order_id ): void {
		if ( ! dc_swp_tt_is_event_enabled( 'Purchase' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( $order->get_meta( '_dc_swp_tt_purchase_tracked' ) ) {
			return;
		}

		$cfg = dc_swp_tt_get_config();
		if ( 'yes' === $cfg['exclude_logged'] && is_user_logged_in() ) {
			return;
		}

		$event = array(
			'event'      => 'Purchase',
			'event_id'   => dc_swp_tt_get_event_id( 'Purchase', $order_id ),
			'event_time' => time(),
			'user'       => dc_swp_tt_get_user_data( $order ),
			'page'       => array( 'url' => dc_swp_tt_current_url() ),
			'properties' => array(
				'currency'     => $order->get_currency(),
				'value'        => (float) $order->get_total(),
				'contents'     => dc_swp_tt_build_contents_from_order( $order ),
				'content_type' => 'product',
			),
		);

		if ( dc_swp_tt_send( array( $event ), true ) ) {
			$order->update_meta_data( '_dc_swp_tt_purchase_tracked', '1' );
			$order->save();
		}
	}
	add_action( 'woocommerce_thankyou', 'dc_swp_tt_purchase', 20 );

	/**
	 * TikTok Events API: AddToCart event.
	 *
	 * @since 2.6.0
	 * @param string $cart_item_key Cart item key.
	 * @param string $product_id    Product ID.
	 * @param int    $quantity      Quantity added.
	 * @param int    $variation_id  Variation ID.
	 * @param array  $variation     Variation data.
	 * @param array  $cart_item_data Cart item data.
	 * @return void
	 */
	function dc_swp_tt_add_to_cart( string $cart_item_key, string $product_id, int $quantity, int $variation_id, array $variation, array $cart_item_data ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! dc_swp_tt_is_event_enabled( 'AddToCart' ) ) {
			return;
		}
		if ( ! dc_swp_tt_should_fire_once( 'AddToCart_' . $product_id ) ) {
			return;
		}

		$cfg = dc_swp_tt_get_config();
		if ( 'yes' === $cfg['exclude_logged'] && is_user_logged_in() ) {
			return;
		}

		$pid     = $variation_id > 0 ? $variation_id : (int) $product_id;
		$product = wc_get_product( $pid );
		if ( ! $product ) {
			return;
		}

		$event = array(
			'event'      => 'AddToCart',
			'event_id'   => dc_swp_tt_get_event_id( 'AddToCart' ),
			'event_time' => time(),
			'user'       => dc_swp_tt_get_user_data(),
			'page'       => array( 'url' => dc_swp_tt_current_url() ),
			'properties' => array(
				'currency'     => get_woocommerce_currency(),
				'value'        => (float) $product->get_price() * $quantity,
				'content_type' => 'product',
				'contents'     => array(
					array(
						'content_id'   => $product->get_sku() ? $product->get_sku() : (string) $pid,
						'content_type' => 'product',
						'content_name' => $product->get_name(),
						'price'        => (float) $product->get_price(),
						'quantity'     => $quantity,
					),
				),
			),
		);

		dc_swp_tt_send( array( $event ) );
	}
	add_action( 'woocommerce_add_to_cart', 'dc_swp_tt_add_to_cart', 20, 6 );

	/**
	 * TikTok Events API: InitiateCheckout event -- fires on the checkout page.
	 *
	 * @since 2.6.0
	 * @return void
	 */
	function dc_swp_tt_initiate_checkout(): void {
		if ( ! dc_swp_tt_is_event_enabled( 'InitiateCheckout' ) ) {
			return;
		}
		if ( ! is_checkout() || is_order_received_page() ) {
			return;
		}
		if ( ! dc_swp_tt_should_fire_once( 'InitiateCheckout' ) ) {
			return;
		}

		$cfg = dc_swp_tt_get_config();
		if ( 'yes' === $cfg['exclude_logged'] && is_user_logged_in() ) {
			return;
		}

		if ( ! function_exists( 'WC' ) || is_null( WC()->cart ) ) {
			return;
		}

		$event = array(
			'event'      => 'InitiateCheckout',
			'event_id'   => dc_swp_tt_get_event_id( 'InitiateCheckout' ),
			'event_time' => time(),
			'user'       => dc_swp_tt_get_user_data(),
			'page'       => array( 'url' => dc_swp_tt_current_url() ),
			'properties' => array(
				'currency'     => get_woocommerce_currency(),
				'value'        => (float) WC()->cart->get_total( 'edit' ),
				'content_type' => 'product',
				'contents'     => dc_swp_tt_build_contents_from_cart(),
			),
		);

		dc_swp_tt_send( array( $event ) );
	}
	add_action( 'wp_footer', 'dc_swp_tt_initiate_checkout', 5 );

	/**
	 * TikTok Events API: ViewContent event -- fires on single product pages.
	 *
	 * @since 2.6.0
	 * @return void
	 */
	function dc_swp_tt_view_content(): void {
		if ( ! dc_swp_tt_is_event_enabled( 'ViewContent' ) ) {
			return;
		}
		if ( ! is_product() ) {
			return;
		}
		if ( ! dc_swp_tt_should_fire_once( 'ViewContent' ) ) {
			return;
		}

		$cfg = dc_swp_tt_get_config();
		if ( 'yes' === $cfg['exclude_logged'] && is_user_logged_in() ) {
			return;
		}

		global $post;
		$product = wc_get_product( $post->ID ?? 0 );
		if ( ! $product ) {
			return;
		}

		$event = array(
			'event'      => 'ViewContent',
			'event_id'   => dc_swp_tt_get_event_id( 'ViewContent' ),
			'event_time' => time(),
			'user'       => dc_swp_tt_get_user_data(),
			'page'       => array( 'url' => dc_swp_tt_current_url() ),
			'properties' => array(
				'currency'     => get_woocommerce_currency(),
				'value'        => (float) $product->get_price(),
				'content_type' => 'product',
				'contents'     => array(
					array(
						'content_id'   => $product->get_sku() ? $product->get_sku() : (string) $product->get_id(),
						'content_type' => 'product',
						'content_name' => $product->get_name(),
						'price'        => (float) $product->get_price(),
						'quantity'     => 1,
					),
				),
			),
		);

		dc_swp_tt_send( array( $event ) );
	}
	add_action( 'wp_footer', 'dc_swp_tt_view_content', 5 );

}// class_exists WooCommerce

// ============================================================
// TIKTOK -- PROXY HOST REGISTRATION
// ============================================================

/**
 * Register the TikTok Pixel CDN host with dc_swp_get_proxy_allowed_hosts().
 *
 * The analytics.tiktok.com CDN hosts the pixel events.js that ttq.load() dynamically
 * injects -- Partytown must proxy requests to this host via its CORS proxy so
 * the web worker can fetch the script from within the worker context.
 *
 * @since 2.6.0
 * @param string[] $hosts Current list of allowed proxy hosts.
 * @return string[]
 */
function dc_swp_tt_extra_proxy_hosts( array $hosts ): array {
	if ( dc_swp_tt_is_active() ) {
		$hosts[] = 'analytics.tiktok.com';
	}
	return $hosts;
}
add_filter( 'dc_swp_extra_proxy_hosts', 'dc_swp_tt_extra_proxy_hosts' );
