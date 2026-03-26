<?php
/**
 * DC Service Worker Prefetcher — Main Plugin File
 *
 * @wordpress-plugin
 * Plugin Name: DC Service Worker Prefetcher
 * Plugin URI:  https://github.com/dc-plugins/dc-sw-prefetch
 * Description: Partytown service worker with viewport/pagination prefetching for WooCommerce. Offloads third-party scripts via Partytown and pre-fetches visible products & next pages.
 * Version:     1.3.0
 * Author:      lennilg
 * Author URI:  https://github.com/lennilg
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dc-sw-prefetch
 * Domain Path: /languages
 * Requires at least: 6.8
 * Requires PHP: 8.0
 * Tested up to: 6.9
 *
 * @package DC_Service_Worker_Prefetcher
 */

if ( ! defined( 'ABSPATH' ) ) {
	die(); }

// ============================================================
// BOT DETECTION
// Wrapped in function_exists so the child theme's copy yields
// to this one when both are active during the migration period.
// ============================================================

if ( ! function_exists( 'dc_swp_is_bot_request' ) ) :
	/**
	 * Detect if the current request is from a bot/crawler.
	 * Bots bypass age verification, cookie modals, and the service worker
	 * so they don't waste crawl budget and get clean, fast HTML.
	 */
	function dc_swp_is_bot_request() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return false;
		}

		$ua = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) );

		// Common bots, crawlers and speed-test tools.
		$bot_patterns = array(
			'googlebot',
			'bingbot',
			'slurp',
			'duckduckbot',
			'baiduspider',
			'yandexbot',
			'sogou',
			'exabot',
			'facebot',
			'facebookexternalhit',
			'ia_archiver',
			'semrushbot',
			'ahrefsbot',
			'ahrefssiteaudit',
			'mj12bot',
			'dotbot',
			'rogerbot',
			'seokicks',
			'seznambot',
			'blexbot',
			'yeti',
			'naverbot',
			'daumoa',
			'applebot',
			'twitterbot',
			'linkedinbot',
			'pinterestbot',
			'whatsapp',
			'telegrambot',
			'discordbot',
			'slackbot',
			'embedly',
			'quora link preview',
			'outbrain',
			'screaming frog',
			'seobilitybot',
			'gptbot',
			'chatgpt',
			'claudebot',
			'anthropic',
			'meta-externalagent',
			// Speed / audit tools.
			'pagespeed',
			'gtmetrix',
			'pingdom',
			'webpagetest',
			'lighthouse',
			'chrome-lighthouse',
			'calibre',
			'dareboost',
			// Generic.
			'spider',
			'crawler',
			'scraper',
			'bot/',
			'/bot',
		);

		foreach ( $bot_patterns as $pattern ) {
			if ( str_contains( $ua, $pattern ) ) {
				return true;
			}
		}

		// Headless Chrome / Puppeteer / Playwright without a real UA.
		if ( str_contains( $ua, 'headlesschrome' ) || str_contains( $ua, 'phantomjs' ) ) {
			return true;
		}

		return false;
	}
endif; // End dc_swp_is_bot_request check.


// ============================================================
// MARKETING CONSENT DETECTION
// Reads the first-party cookies set by the most common WordPress
// consent management plugins. Returns true once the visitor has
// granted marketing / analytics consent, so we can safely load
// third-party scripts via Partytown.
//
// Covers:
// Complianz            — cmplz_marketing = "allow"
// CookieYes            — cookieyes-consent contains "marketing:yes"
// Borlabs Cookie       — borlabs-cookie JSON .consents.marketing = true
// Cookie Notice (GDPR) — cookie_notice_accepted = "true"
// WebToffee GDPR       — cookie_cat_marketing = "accept"
// Cookiebot (Cybot)    — CookieConsent contains "marketing:true"
// Cookie Information   — CookieInformationConsent JSON consents_approved[] contains "cookie_cat_marketing"
// Moove GDPR           — moove_gdpr_popup JSON .thirdparty = 1
// ============================================================

/**
 * Return true if the current visitor has granted marketing consent
 * according to any of the common CMP cookie conventions.
 */
function dc_swp_has_marketing_consent() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	// Complianz.
	if ( isset( $_COOKIE['cmplz_marketing'] ) && 'allow' === $_COOKIE['cmplz_marketing'] ) {
		return true;
	}

	// CookieYes — cookie value looks like "consent:yes,marketing:yes,analytics:yes,...".
	if ( isset( $_COOKIE['cookieyes-consent'] ) ) {
		$cy = sanitize_text_field( wp_unslash( $_COOKIE['cookieyes-consent'] ) );
		if ( str_contains( $cy, 'marketing:yes' ) ) {
			return true;
		}
	}

	// Borlabs Cookie — JSON-encoded object; .consents.marketing === true.
	if ( isset( $_COOKIE['borlabs-cookie'] ) ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded for boolean key check only; not used in HTML output.
		$raw = json_decode( wp_unslash( $_COOKIE['borlabs-cookie'] ), true );
		if ( ! empty( $raw['consents']['marketing'] ) ) {
			return true;
		}
	}

	// Cookie Notice & Compliance for GDPR — single all-or-nothing cookie.
	if ( isset( $_COOKIE['cookie_notice_accepted'] ) && 'true' === $_COOKIE['cookie_notice_accepted'] ) {
		return true;
	}

	// WebToffee GDPR Cookie Consent.
	if ( isset( $_COOKIE['cookie_cat_marketing'] ) && 'accept' === $_COOKIE['cookie_cat_marketing'] ) {
		return true;
	}

	// Cookiebot (Cybot) — URL-encoded value contains "marketing:true".
	if ( isset( $_COOKIE['CookieConsent'] ) ) {
		$cc = sanitize_text_field( wp_unslash( $_COOKIE['CookieConsent'] ) );
		if ( str_contains( $cc, 'marketing:true' ) ) {
			return true;
		}
	}

	// Cookie Information (popular in Scandinavia) — JSON; consents_approved[] contains "cookie_cat_marketing".
	if ( isset( $_COOKIE['CookieInformationConsent'] ) ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded for array key check only; not used in HTML output.
		$ci = json_decode( wp_unslash( $_COOKIE['CookieInformationConsent'] ), true );
		if ( ! empty( $ci['consents_approved'] ) && in_array( 'cookie_cat_marketing', $ci['consents_approved'], true ) ) {
			return true;
		}
	}

	// Moove GDPR Cookie Compliance — JSON; .thirdparty === 1.
	if ( isset( $_COOKIE['moove_gdpr_popup'] ) ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded for integer key check only; not used in HTML output.
		$mg = json_decode( wp_unslash( $_COOKIE['moove_gdpr_popup'] ), true );
		if ( isset( $mg['thirdparty'] ) && 1 === (int) $mg['thirdparty'] ) {
			return true;
		}
	}

	return false;
}


// ============================================================
// ADMIN INTERFACE
// ============================================================

require_once plugin_dir_path( __FILE__ ) . 'admin.php';


// ============================================================
// FOOTER CREDIT
// Injects a tiny inline script via wp_footer that uses a DOM
// TreeWalker to find the first © text node inside <footer>
// and wraps it with a <a> link. Works universally across all
// themes without any PHP output-buffer or regex fragility.
// ============================================================

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
if ( get_option( 'dampcig_pwa_footer_credit', 'no' ) === 'yes' && ! function_exists( 'dc_footer_credit_owner' ) ) {
	/**
	 * Sentinel: marks this plugin as the active footer-credit owner.
	 * Other DC plugins check function_exists( 'dc_footer_credit_owner' ) and skip
	 * their own registration when this is already defined.
	 */
	function dc_footer_credit_owner(): void {} // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

	add_action( 'wp_footer', 'dc_swp_footer_credit_js', PHP_INT_MAX );
}

/**
 * Output the footer credit inline script via wp_footer.
 *
 * @since 1.0.0
 * @return void
 */
function dc_swp_footer_credit_js() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	if ( is_admin() ) {
		return;
	}

	$url   = 'https://www.dampcig.dk';
	$title = esc_js( 'Powered by Dampcig.dk' );
	?>
<script>(function(){
var f=document.querySelector('footer');
if(!f)return;
var w=document.createTreeWalker(f,NodeFilter.SHOW_TEXT,null,false);
var node;
while((node=w.nextNode())){
	if(node.nodeValue.indexOf('\u00A9')===-1)continue;
	var idx=node.nodeValue.indexOf('\u00A9');
	var frag=document.createDocumentFragment();
	if(idx>0)frag.appendChild(document.createTextNode(node.nodeValue.slice(0,idx)));
	var a=document.createElement('a');
	a.href=<?php echo wp_json_encode( $url ); ?>;
	a.title=<?php echo wp_json_encode( $title ); ?>;
	a.target='_blank';
	a.rel='noopener noreferrer';
	a.textContent='\u00A9';
	frag.appendChild(a);
	var rest=node.nodeValue.slice(idx+1);
	if(rest)frag.appendChild(document.createTextNode(rest));
	node.parentNode.replaceChild(frag,node);
	break;
}
})();</script>
	<?php
}


// ============================================================
// FALLBACK CACHE HEADERS (when W3 Total Cache is not active)
// ============================================================

add_action( 'send_headers', 'dc_swp_fallback_cache_headers' );
add_action( 'send_headers', 'dc_swp_cross_origin_isolation_headers' );

/**
 * Emit Cross-Origin-Opener-Policy and Cross-Origin-Embedder-Policy headers
 * on pages where Partytown is active, when the admin has opted in.
 *
 * These headers unlock SharedArrayBuffer in the browser, which allows
 * Partytown to use the Atomics-based synchronisation bridge (partytown-atomics.js)
 * instead of the slower synchronous-XHR bridge (partytown-sw.js). The result
 * is reduced bridge round-trip latency for every worker ↔ main-thread call.
 *
 * COEP: credentialless is chosen over require-corp because it allows
 * cross-origin subresources (CDN images, fonts) to load without needing an
 * explicit Cross-Origin-Resource-Policy header on the remote server.
 *
 * Skipped for bots, logged-in users, and transactional pages (cart / checkout /
 * account). Skipped unless the dc_swp_coi_headers option is enabled.
 */
function dc_swp_cross_origin_isolation_headers() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	if ( get_option( 'dc_swp_coi_headers', 'no' ) !== 'yes' ) {
		return;
	}
	if ( is_admin() || dc_swp_is_bot_request() ) {
		return;
	}
	// Skip for logged-in users and transactional pages.
	if ( is_user_logged_in()
		|| ( function_exists( 'is_cart' ) && is_cart() )
		|| ( function_exists( 'is_checkout' ) && is_checkout() )
		|| ( function_exists( 'is_account_page' ) && is_account_page() ) ) {
		return;
	}
	header( 'Cross-Origin-Opener-Policy: same-origin' );
	header( 'Cross-Origin-Embedder-Policy: credentialless' );
}

/**
 * When W3TC is absent, emit sensible Cache-Control / Expires / Vary
 * headers directly from PHP so the browser and any CDN can still cache responses.
 *
 * Skipped entirely if W3TC is loaded (W3TC owns its own header logic).
 */
function dc_swp_fallback_cache_headers() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	// W3TC is present — let it handle headers.
	if ( defined( 'W3TC_DIR' ) || function_exists( 'w3tc_pgcache_flush' ) ) {
		return;
	}
	if ( is_admin() ) {
		return;
	}

	// Never cache personalised or transactional pages.
	if ( is_user_logged_in()
		|| ( function_exists( 'is_cart' ) && is_cart() )
		|| ( function_exists( 'is_checkout' ) && is_checkout() )
		|| ( function_exists( 'is_account_page' ) && is_account_page() ) ) {
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		return;
	}

	// Cache public pages: 1 hour browser, stale-while-revalidate 60 s.
	$max_age      = 3600;
	$is_cacheable = is_front_page() || is_home()
		|| ( function_exists( 'is_shop' ) && is_shop() )
		|| ( function_exists( 'is_product_category' ) && is_product_category() )
		|| ( function_exists( 'is_product' ) && is_product() )
		|| is_page()
		|| is_singular()
		|| is_archive();

	if ( $is_cacheable ) {
		header( 'Cache-Control: public, max-age=' . $max_age . ', stale-while-revalidate=60, stale-if-error=86400' );
		header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + $max_age ) . ' GMT' );
		header( 'Vary: Accept-Encoding, Accept' );
		header( 'X-Cache-Fallback: dc-sw-prefetch' ); // Debugging marker.
	}
}


// ============================================================
// PARTYTOWN — serve ~partytown/ lib files from the plugin
// ============================================================

/**
 * Partytown lib directory relative to the WordPress root.
 * Partytown itself is registered at this virtual path.
 */
define( 'DC_SWP_PARTYTOWN_LIB', '/wp-content/plugins/dc-sw-prefetch/assets/partytown/' );

add_action( 'init', 'dc_swp_serve_partytown_files', 1 );

/**
 * Stream any requested file from our vendored assets/partytown/ directory
 * when the request path starts with /~partytown/.
 *
 * Partytown resolves its own workers/sandboxes relative to the `lib` config
 * option, which we point to /~partytown/ in the inline snip below.
 */
function dc_swp_serve_partytown_files() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	$request_uri = isset( $_SERVER['REQUEST_URI'] )
		? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		: '';

	if ( strncmp( $request_uri, '/~partytown/', 12 ) !== 0 ) {
		return;
	}

	// Resolve the physical file — prevent directory traversal.
	$relative = ltrim( substr( $request_uri, strlen( '/~partytown/' ) ), '/' );
	// Strip query string just in case.
	$relative  = strtok( $relative, '?' );
	$real_base = realpath( plugin_dir_path( __FILE__ ) . 'assets/partytown' );
	$file      = realpath( $real_base . '/' . $relative );

	// Security: must resolve inside the partytown assets directory.
	// Append directory separator to prevent matching sibling dirs like assets/partytown_other/.
	if ( false === $file || strncmp( $file, $real_base . DIRECTORY_SEPARATOR, strlen( $real_base ) + 1 ) !== 0 ) {
		status_header( 404 );
		exit();
	}

	if ( ! is_file( $file ) ) {
		status_header( 404 );
		exit();
	}

	$ext_map = array(
		'js'   => 'application/javascript; charset=utf-8',
		'html' => 'text/html; charset=utf-8',
		'mjs'  => 'application/javascript; charset=utf-8',
	);
	$ext     = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
	$mime    = $ext_map[ $ext ] ?? 'application/octet-stream';

	status_header( 200 );
	header( 'Content-Type: ' . $mime );
	header( 'Service-Worker-Allowed: /' );
	header( 'X-Robots-Tag: none' );
	// Partytown files are versioned by the plugin; cache for 1 hour, revalidate.
	header( 'Cache-Control: public, max-age=3600, stale-while-revalidate=60' );

	// Use WP_Filesystem to read and serve the file instead of direct readfile().
	global $wp_filesystem;
	if ( empty( $wp_filesystem ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
	}
	if ( empty( $wp_filesystem ) ) {
		status_header( 500 );
		exit();
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- serving a binary-safe static file
	echo $wp_filesystem->get_contents( $file );
	exit();
}


// ============================================================
// PARTYTOWN CORS PROXY
// Partytown's sandbox iframe fetches external scripts via fetch()
// from the site's own origin. CDNs like connect.facebook.net do
// not send Access-Control-Allow-Origin headers, so the browser
// blocks those fetches. This proxy endpoint fetches the script
// server-side (where CORS doesn't apply) and re-serves it with
// the necessary CORS header.
//
// The allowlist is built dynamically from the admin-configured
// Partytown Script List and Script Blocks — only hostnames the
// site owner has explicitly opted into are accepted. This prevents
// SSRF and ensures the proxy never touches third-party scripts
// that are not part of the active Partytown configuration.
// ============================================================

add_action( 'init', 'dc_swp_serve_partytown_proxy', 1 );

/**
 * Proxy an external CDN script through WordPress for CORS-free delivery
 * to Partytown's sandbox iframe.
 *
 * Route: GET /~partytown-proxy?url=<encoded-https-url>
 *
 * Security measures:
 *  - Only HTTPS scheme accepted.
 *  - Allowlist-only: only hostnames derived from the admin-configured
 *    Partytown Script List and Script Blocks are accepted.
 *  - URL is reconstructed from parsed components (no raw passthrough).
 *  - No redirect following (redirection=0) to prevent SSRF via redirect.
 *  - SSL verification enabled.
 */
function dc_swp_serve_partytown_proxy() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	$request_uri = isset( $_SERVER['REQUEST_URI'] )
		? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		: '';

	if ( '/~partytown-proxy' !== $request_uri ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only proxy, no state change
	$raw_url = isset( $_GET['url'] ) ? esc_url_raw( wp_unslash( $_GET['url'] ) ) : '';
	if ( '' === $raw_url ) {
		status_header( 400 );
		exit();
	}

	// Must be HTTPS.
	$parsed = wp_parse_url( $raw_url );
	if ( empty( $parsed['scheme'] ) || 'https' !== $parsed['scheme'] ) {
		status_header( 403 );
		exit();
	}

	$host = strtolower( $parsed['host'] ?? '' );

	// Allowlist: derived dynamically from the admin-configured Partytown Script
	// List and Script Blocks — only hostnames the site owner has opted into.
	$allowed_hosts = dc_swp_get_proxy_allowed_hosts();

	if ( empty( $allowed_hosts ) || ! in_array( $host, $allowed_hosts, true ) ) {
		status_header( 403 );
		exit();
	}

	// Reconstruct a clean URL from parsed components to prevent header injection.
	$clean_url = 'https://' . $host . ( $parsed['path'] ?? '/' );
	if ( ! empty( $parsed['query'] ) ) {
		$clean_url .= '?' . $parsed['query'];
	}

	$response = wp_remote_get(
		$clean_url,
		array(
			'timeout'     => 10,
			'redirection' => 0, // No redirects — prevents SSRF via open redirect.
			'sslverify'   => true,
			'user-agent'  => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
		)
	);

	if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
		status_header( 502 );
		exit();
	}

	$body = wp_remote_retrieve_body( $response );

	status_header( 200 );
	header( 'Content-Type: application/javascript; charset=utf-8' );
	header( 'Access-Control-Allow-Origin: *' );
	header( 'X-Robots-Tag: none' );
	// Cache for 1 hour — CDN script content rarely changes.
	header( 'Cache-Control: public, max-age=3600, stale-while-revalidate=300' );

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- proxied JS body from allowlisted CDN
	echo $body;
	exit();
}


// ============================================================
// PRODUCT BASE HELPER
// Auto-detects WooCommerce product permalink slug and allows
// manual override via the admin setting.
// ============================================================

/**
 * Return the URL path segment used to identify product permalinks.
 * e.g. "/product/" or "/produkt/" for localised installations.
 *
 * Priority:
 *  1. Admin override (dampcig_pwa_product_base)
 *  2. WooCommerce permalink setting (woocommerce_permalinks.product_base)
 *  3. Hard fallback: /product/
 */
function dc_swp_get_product_base() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	$override = trim( get_option( 'dampcig_pwa_product_base', '' ) );
	if ( '' !== $override ) {
		// Normalise: ensure leading and trailing slash.
		return '/' . trim( $override, '/' ) . '/';
	}

	// Auto-detect from WooCommerce.
	$wc = get_option( 'woocommerce_permalinks', array() );
	if ( ! empty( $wc['product_base'] ) ) {
		$base  = trim( $wc['product_base'], '/' );
		$parts = explode( '/', $base );
		// Use only the first path segment (ignore %product_cat% suffixes).
		return '/' . $parts[0] . '/';
	}

	return '/product/';
}


// ============================================================
// PARTYTOWN SNIPPET + VIEWPORT/PAGINATION PREFETCHER IN FOOTER
// ============================================================

add_action( 'wp_head', 'dc_swp_partytown_config', 2 );

/**
 * Return a stable per-request CSP nonce for Partytown inline scripts.
 *
 * The same nonce is reused for both the config <script> and the snippet
 * <script>, and is passed to window.partytown.nonce so Partytown stamps it
 * on every worker script it creates. CSP-hardened sites can read this value
 * via the 'dc_swp_csp_nonce' filter and include it in their
 * Content-Security-Policy: script-src 'nonce-…' header.
 *
 * @return string Base64-safe nonce, or empty string on failure.
 */
function dc_swp_get_csp_nonce() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	static $nonce = null;
	if ( null !== $nonce ) {
		return $nonce;
	}
	try {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$nonce = rtrim( base64_encode( random_bytes( 16 ) ), '=' );
	} catch ( \Exception $e ) {
		$nonce = '';
	}
	/**
	 * Filter the per-request CSP nonce used by Partytown inline scripts.
	 *
	 * Allows themes or plugins that manage their own CSP headers to read or
	 * override the nonce so it matches their own header value.
	 *
	 * @param string $nonce The generated nonce (base64, 16 random bytes).
	 */
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	$nonce = (string) apply_filters( 'dc_swp_csp_nonce', $nonce );
	return $nonce;
}

/**
 * Emit the Partytown config object and the inline snippet in <head>.
 * Must run before any type="text/partytown" scripts.
 */
function dc_swp_partytown_config() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	if ( dc_swp_is_bot_request() ) {
		return;
	}
	if ( is_admin() ) {
		return;
	}

	$pt_enabled = get_option( 'dampcig_pwa_sw_enabled', 'yes' ) === 'yes';
	if ( ! $pt_enabled ) {
		return;
	}

	// Inline Partytown snippet — serves workers from /~partytown/.
	$snippet_file = plugin_dir_path( __FILE__ ) . 'assets/partytown/partytown.js';
	if ( ! file_exists( $snippet_file ) ) {
		return;
	}

	$snippet = file_get_contents( $snippet_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	// Build the Partytown config as a PHP structure so it is always valid JSON.
	// forward list — only officially tested services from https://partytown.qwik.dev/common-services/
	// Note: 'gtag' is intentionally excluded — it is defined as an inline wrapper that calls
	// dataLayer.push(), which is already forwarded. Forwarding 'gtag' separately is redundant.
	// 'lintrk' (LinkedIn) and 'twq' (Twitter/X) are excluded — not on the officially tested list.
	$config = array(
		'lib'     => '/~partytown/',
		'debug'   => false,
		// preserveBehavior:true on dataLayer.push ensures GTM and consent stacks
		// also fire the original (main-thread) implementation, keeping tag-manager
		// event flow intact.
		'forward' => array(
			// Array-of-arrays tuple format: ['forwardProp', {options}].
			array( 'dataLayer.push', array( 'preserveBehavior' => true ) ), // Google Tag Manager.
			'fbq',            // Meta / Facebook Pixel.
			'_hsq.push',      // HubSpot Tracking.
			'Intercom',       // Intercom.
			'_learnq.push',   // Klaviyo.
			'ttq.track',      // TikTok Pixel.
			'ttq.page',
			'ttq.load',
			'mixpanel.track', // Mixpanel.
		),
	);

	// Feature 4: loadScriptsOnMainThread — scripts dynamically injected inside the
	// worker that match a pattern are loaded on the main thread instead.
	//
	// NOTE: connect.facebook.net is intentionally NOT hardcoded here.
	// Putting it in loadScriptsOnMainThread causes fbevents.js to run BOTH inside
	// the Partytown sandbox (via the CORS proxy) AND on the main thread, which
	// produces "Multiple pixels with conflicting versions" and a Symbol.iterator
	// TypeError inside partytown-sandbox-sw.html. Meta Pixel fbevents.js v2.0 is
	// handled exclusively via the CORS proxy (/~partytown-proxy) within the worker.
	// The forward: ['fbq'] entry ensures all fbq() calls are proxied correctly.
	//
	// loadScriptsOnMainThread is used only for user-configured exclusions — scripts
	// that are genuinely incompatible with the Partytown worker environment.
	// NOTE: Partytown expects a plain string[], NOT tuples.
	$exclude = dc_swp_get_partytown_exclude_patterns();
	if ( ! empty( $exclude ) ) {
		$config['loadScriptsOnMainThread'] = array_values( $exclude );
	}

	// Feature 2: Pass a per-request CSP nonce into the Partytown config.
	// Partytown will stamp this nonce on every <script> element it creates,
	// allowing sites with strict CSP to whitelist exactly these scripts.
	$nonce = dc_swp_get_csp_nonce();
	if ( '' !== $nonce ) {
		$config['nonce'] = $nonce;
	}

	$nonce_attr  = '' !== $nonce ? ' nonce="' . esc_attr( $nonce ) . '"' : '';
	$config_json = wp_json_encode( $config, JSON_UNESCAPED_SLASHES );

	// resolveUrl routes cross-origin script fetches through our CORS proxy so
	// Partytown's sandbox iframe can load external scripts (e.g. fbevents.js)
	// without being blocked by CORS. The server-side proxy only accepts an
	// explicit allowlist of CDN hostnames, preventing SSRF.
	//
	// Path-rewrite map: analytics scripts often issue fetch/beacon calls using a
	// root-relative path (e.g. "/api/event") rather than a full URL. Inside the
	// Partytown sandbox that relative path resolves against the site origin,
	// resulting in a same-origin 404. We maintain a filterable map of known
	// same-origin paths → correct external endpoints so any analytics tool can
	// be handled without touching this file.
	//
	// To add a new entry from a theme/plugin, use add_filter on 'dc_swp_partytown_path_rewrites'.
	// phpcs:disable Squiz.Commenting.InlineComment.InvalidEndChar
	// add_filter( 'dc_swp_partytown_path_rewrites', function( $map ) {
	// $map['/collect'] = 'https://analytics.example.com/collect';
	// return $map;
	// } );
	// phpcs:enable Squiz.Commenting.InlineComment.InvalidEndChar
	$path_rewrites = apply_filters(
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		'dc_swp_partytown_path_rewrites',
		array(
			'/api/event' => 'https://analytics.ahrefs.com/api/event', // Ahrefs Analytics.
		)
	);
	$path_rewrites_json = wp_json_encode( $path_rewrites, JSON_UNESCAPED_SLASHES );

	$proxy_url_json           = wp_json_encode( home_url( '/~partytown-proxy' ), JSON_UNESCAPED_SLASHES );
	$proxy_allowed_hosts_json = wp_json_encode( dc_swp_get_proxy_allowed_hosts(), JSON_UNESCAPED_SLASHES );

	$resolve_url_fn = 'window.partytown.resolveUrl=function(url,location,type){'
		// Same-origin path rewrite: catches analytics fetch/sendBeacon/XHR calls
		// that use a root-relative URL and would otherwise 404 on the WordPress
		// site. Explicitly excludes type==="script" — script loads at a same-origin
		// path should never be rerouted to an external analytics endpoint.
		// (resolveUrl is called for every request type: "script", "fetch", "xhr",
		// "sendBeacon" — the type param lets us be precise about what we intercept.
		. 'var pr=' . $path_rewrites_json . ';'
		. 'if(type!=="script"&&url&&url.hostname===location.hostname&&pr[url.pathname]){'
		. 'return new URL(pr[url.pathname]);'
		. '}'
		// Cross-origin script proxy: routes external script loads through the
		// server-side CORS proxy, but only for hostnames the admin has configured
		// in the Partytown Script List or Script Blocks.  Scripts from any other
		// origin are returned as-is and Partytown fetches them directly.
		. 'var ph=' . $proxy_allowed_hosts_json . ';'
		. 'if(type==="script"&&url.hostname!==location.hostname&&ph.indexOf(url.hostname)!==-1){'
		. 'var p=new URL(' . $proxy_url_json . ');'
		. 'p.searchParams.append("url",url.href);'
		. 'return p;'
		. '}return url;};';

	// SharedArrayBuffer probe: Partytown reads window.crossOriginIsolated and loads
	// partytown-atomics.js if true. On some page loads (W3TC cache, certain browser
	// versions) the response headers are in place but the browser process has not
	// fully entered the isolated context, causing new SharedArrayBuffer() to throw
	// RangeError: Array buffer allocation failed — an unhandled promise rejection
	// that breaks the entire atomics bridge init.
	//
	// Probe whether a 256 MB SharedArrayBuffer can be allocated (the size our
	// patched partytown-atomics.js bundle uses). If it throws, shadow
	// crossOriginIsolated = false so Partytown falls back to the SW bridge.
	// Note: partytown-atomics.js has been patched to use 268435456 (256 MB)
	// instead of 1073741824 (1 GB) to stay well within typical heap budgets.
	$coi_active = get_option( 'dc_swp_coi_headers', 'no' ) === 'yes';
	$coi_probe  = $coi_active
		? 'if(window.crossOriginIsolated){try{new SharedArrayBuffer(268435456);}catch(e){'
			. 'try{Object.defineProperty(window,"crossOriginIsolated",{value:false,configurable:false});}catch(e2){}}}'
		: '';

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<script' . $nonce_attr . '>' . $coi_probe . 'window.partytown=' . $config_json . ';' . $resolve_url_fn . "</script>\n";
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<script' . $nonce_attr . '>' . $snippet . "</script>\n";

	// When Cross-Origin isolation (COEP) is active, every cross-origin iframe
	// needs the `credentialless` attribute before its src navigation begins —
	// otherwise the browser enforces CORP and blocks it (e.g. Trustpilot).
	//
	// A MutationObserver fires too late: the browser starts the iframe navigation
	// synchronously when the element is appended with a src, before any microtask
	// can run. We therefore intercept HTMLIFrameElement.prototype src setter and
	// setAttribute so `credentialless` is stamped BEFORE the src is applied.
	// The MutationObserver is kept as a fallback for iframes inserted via innerHTML
	// or DOMParser, where navigation is deferred to a separate task.
	if ( $coi_active ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<script' . $nonce_attr . '>'
			. '(function(){'
			. 'if(!window.crossOriginIsolated)return;'
			// Save original setAttribute before we override it, so ensureCredentialless
			// can call it directly without going through our override.
			. 'var _sa=HTMLIFrameElement.prototype.setAttribute;'
			. 'function ensureCredentialless(el,src){'
			. 'if(!src||el.hasAttribute("credentialless"))return;'
			. 'try{'
			. 'var u=new URL(src,location.href);'
			// Skip about:, javascript:, data: — only http(s) cross-origin iframes need this.
			. 'if(u.protocol==="about:"||u.protocol==="javascript:"||u.protocol==="data:")return;'
			. 'if(u.origin!==location.origin)_sa.call(el,"credentialless","");'
			. '}catch(e){}}'
			// Intercept iframe.src = '...' — fires before the value is applied.
			. 'var d=Object.getOwnPropertyDescriptor(HTMLIFrameElement.prototype,"src");'
			. 'if(d&&d.set){'
			. 'Object.defineProperty(HTMLIFrameElement.prototype,"src",{'
			. 'set:function(v){ensureCredentialless(this,v);d.set.call(this,v);},'
			. 'get:d.get,configurable:true});}'
			// Intercept iframe.setAttribute("src", '...') — same guarantee.
			. 'HTMLIFrameElement.prototype.setAttribute=function(n,v){'
			. 'if(n.toLowerCase()==="src")ensureCredentialless(this,v);'
			. '_sa.call(this,n,v);};'
			// MutationObserver fallback for innerHTML / DOMParser inserted iframes.
			. 'function markIfNeeded(el){'
			. 'if(!el||el.tagName!=="IFRAME"||el.hasAttribute("credentialless"))return;'
			. 'ensureCredentialless(el,el.getAttribute("src"));}'
			. 'var obs=new MutationObserver(function(muts){'
			. 'muts.forEach(function(m){'
			. 'if(m.type==="childList"){'
			. 'm.addedNodes.forEach(function(n){'
			. 'if(n.nodeType!==1)return;'
			. 'markIfNeeded(n);'
			. 'if(n.querySelectorAll)n.querySelectorAll("iframe[src]").forEach(markIfNeeded);'
			. '});}'
			. 'else if(m.type==="attributes"&&m.target&&m.target.tagName==="IFRAME"){'
			. 'markIfNeeded(m.target);}'
			. '});});'
			. 'obs.observe(document.documentElement,'
			. '{childList:true,subtree:true,attributes:true,attributeFilter:["src"]});'
			. '})();'
			. "</script>\n";
	}
}


add_action( 'wp_footer', 'dc_swp_prefetch_footer', 9999 );

/**
 * Viewport/pagination prefetcher — runs in wp_footer.
 * Unchanged from original; does NOT depend on a service worker.
 */
function dc_swp_prefetch_footer() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	if ( dc_swp_is_bot_request() ) {
		return;
	}

	if (
		( function_exists( 'is_cart' ) && is_cart() ) ||
		( function_exists( 'is_checkout' ) && is_checkout() ) ||
		( function_exists( 'is_account_page' ) && is_account_page() )
	) {
		return;
	}

	$preload_enabled = get_option( 'dampcig_pwa_preload_products', 'yes' ) === 'yes';
	if ( ! $preload_enabled ) {
		return;
	}

	$product_base = dc_swp_get_product_base();
	?>
	<script>
	(function () {
		'use strict';

		const productBase    = <?php echo wp_json_encode( $product_base ); ?>;
		const prefetchedUrls = new Set();
		const visibleItems   = new Set(); // track currently-intersecting elements

		function prefetch(url) {
			if (!url || prefetchedUrls.has(url)) return;
			prefetchedUrls.add(url);
			const link    = document.createElement('link');
			link.rel      = 'prefetch';
			link.href     = url;
			link.as       = 'document';
			document.head.appendChild(link);
			console.log('[DC SW Prefetch] Prefetching:', url);
		}

		function resolveProductLink(el) {
			const bad = (href) => !href
				|| href.includes('add-to-cart')
				|| href.includes('?remove_item')
				|| href.includes('remove_item')
				|| href.includes('?added-to-cart')
				|| href.includes('#');

			// Element itself may be the anchor (e.g. upsell-item <a> wrappers)
			if (el.tagName === 'A' && el.href && !bad(el.href)) return el.href;

			const anchors = Array.from( el.querySelectorAll('a[href]') );
			// Prefer a link matching the (auto-detected or overridden) product slug
			let a = anchors.find(a => a.href.includes(productBase) && !bad(a.href));
			// Fallback: first non-utility anchor inside the item
			if (!a) a = anchors.find(a => !bad(a.href));
			if (!a) {
				console.debug('[DC SW Prefetch] No link in item:', el, '| anchors found:', anchors.length, '| productBase:', productBase);
			}
			return a ? a.href : null;
		}

		function prefetchNextPage() {
			const next = document.querySelector(
				'.woocommerce-pagination a.next, .next.page-numbers, a.next-page'
			);
			if (next && next.href) setTimeout(() => prefetch(next.href), 2000);
		}

		// Wide selector — catches product grid items and upsell anchor-wrappers
		const items = document.querySelectorAll(
			'.products .product, ul.products li.product, .product-item, li.product, a.upsell-item[href]'
		);
		if (!items.length) {
			console.warn('[DC SW Prefetch] No product items found in DOM');
			return;
		}

		console.log('[DC SW Prefetch] Monitoring', items.length, 'products | productBase:', productBase);

		if ('IntersectionObserver' in window) {
			const observer = new IntersectionObserver((entries) => {
				entries.forEach(entry => {
					if (entry.isIntersecting) {
						visibleItems.add(entry.target);
						const url = resolveProductLink(entry.target);
						if (url) {
							prefetch(url);
						}
					} else {
						visibleItems.delete(entry.target);
					}
				});
			}, { rootMargin: '50px', threshold: 0.1 });

			items.forEach(item => observer.observe(item));
			prefetchNextPage();
		} else {
			// Fallback for browsers without IntersectionObserver
			const vh = window.innerHeight;
			items.forEach(item => {
				const url  = resolveProductLink(item);
				const rect = item.getBoundingClientRect();
				if (url && rect.top >= 0 && rect.top <= vh) prefetch(url);
			});
			prefetchNextPage();
		}
	})();
	</script>
	<?php
}


// ============================================================
// WP EMOJI REMOVAL
// WordPress loads SVG emoji detection JS (fetches from s.w.org)
// on every page — an unnecessary round-trip with no benefit on
// modern browsers. Removing it saves ~76 KB and one DNS lookup.
// A tiny inline style ensures any emoji <img> that slips through
// is still sized correctly.
// ============================================================

add_action( 'init', 'dc_swp_maybe_remove_emoji', 1 );

/**
 * Remove WordPress emoji detection scripts and styles when the option is enabled.
 *
 * @since 1.0.0
 * @return void
 */
function dc_swp_maybe_remove_emoji() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	if ( is_admin() ) {
		return;
	}
	if ( get_option( 'dc_swp_disable_emoji', 'yes' ) !== 'yes' ) {
		return;
	}

	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
	remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
	remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
	add_filter( 'emoji_svg_url', '__return_false' );

	// Size any emoji <img> that still renders (e.g. from cached markup).
	add_action(
		'wp_head',
		function () {
			echo '<style>img.emoji{width:1em;height:1em;vertical-align:-0.1em}</style>' . "\n";
		},
		1
	);
}


// ============================================================
// PARTYTOWN SCRIPT OFFLOADING
// Reads the admin-configured list of URL patterns and marks
// matching <script src="..."> tags as type="text/partytown" at
// runtime via the wp_script_attributes filter — no manual code
// edits needed for each third-party tool.
//
// Patterns (one per line, e.g. "analytics.ahrefs.com" or a full
// URL) are cached in the WP object cache so there is at most one
// DB read per cache-miss (zero reads on persistent-cache installs).
// ============================================================

/**
 * Return admin-configured Partytown patterns, object-cache memoised.
 *
 * @return string[]
 */
function dc_swp_get_partytown_patterns() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	static $patterns = null;
	if ( null !== $patterns ) {
		return $patterns;
	}
	$cached = wp_cache_get( 'patterns', 'dc_swp' );
	if ( false !== $cached ) {
		$patterns = $cached;
		return $patterns;
	}
	$raw      = (string) get_option( 'dc_swp_partytown_scripts', '' );
	$patterns = array_values(
		array_filter(
			array_map( 'trim', explode( "\n", $raw ) ),
			static fn( $line ) => '' !== $line
		)
	);
	wp_cache_set( 'patterns', $patterns, 'dc_swp', HOUR_IN_SECONDS );
	return $patterns;
}

/**
 * Build the proxy allowlist dynamically from what the admin has actually configured:
 *
 *  1. Hostnames extracted from the Partytown Script List patterns
 *     (e.g. "www.googletagmanager.com/gtag/js" → "www.googletagmanager.com").
 *  2. Hostnames found inside Partytown Script Blocks (inline script snippets often
 *     reference their own CDN URL, e.g. the Meta Pixel snippet contains
 *     "https://connect.facebook.net/en_US/fbevents.js").
 *
 * Only domains the site owner has explicitly opted into are ever accepted by the
 * proxy — no hard-coded vendor list.
 *
 * @return string[] Lowercase, unique hostnames eligible for proxy.
 */
function dc_swp_get_proxy_allowed_hosts() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	// Static memoisation: consistent with dc_swp_get_partytown_patterns().
	// Mid-request option updates clear the object cache on the next request via
	// dc_swp_bust_page_cache(); the static variable intentionally holds for the
	// duration of the current request.
	static $hosts = null;
	if ( null !== $hosts ) {
		return $hosts;
	}

	$hosts = array();

	// ── 1. Script List patterns ──────────────────────────────────────────────
	foreach ( dc_swp_get_partytown_patterns() as $pattern ) {
		if ( '' === $pattern ) {
			continue;
		}
		// Prepend a scheme so wp_parse_url() can reliably extract the host even
		// when the pattern is a bare hostname ("analytics.ahrefs.com") or a
		// hostname+path ("www.googletagmanager.com/gtag/js").
		$to_parse = str_contains( $pattern, '://' ) ? $pattern : 'https://' . ltrim( $pattern, '/' );
		$parsed   = wp_parse_url( $to_parse );
		$host     = strtolower( trim( $parsed['host'] ?? '' ) );
		if ( '' !== $host ) {
			$hosts[] = $host;
		}
	}

	// ── 2. Script Blocks (inline scripts) ───────────────────────────────────
	// Inline snippets (e.g. Meta Pixel, TikTok Pixel) usually embed the CDN
	// URL directly — extract every https:// hostname that appears in the code.
	$raw_stored = (string) get_option( 'dc_swp_inline_scripts', '' );
	if ( '' !== $raw_stored ) {
		$decoded = json_decode( $raw_stored, true );
		if ( is_array( $decoded ) ) {
			$code_parts = array();
			foreach ( $decoded as $blk ) {
				if ( ! empty( $blk['enabled'] ) && '' !== trim( (string) ( $blk['code'] ?? '' ) ) ) {
					$code_parts[] = $blk['code'];
				}
			}
			$raw_code = implode( "\n", $code_parts );
		} else {
			$raw_code = $raw_stored; // Legacy plain-text format.
		}

		if ( preg_match_all( '#https://[^\s"\'<>\\\\)]+#', $raw_code, $url_matches ) ) {
			foreach ( $url_matches[0] as $url ) {
				$parsed = wp_parse_url( $url );
				$h      = strtolower( trim( $parsed['host'] ?? '' ) );
				// Require at least one dot and only valid hostname characters.
				if ( '' !== $h && str_contains( $h, '.' ) && preg_match( '/^[a-z0-9]([a-z0-9.\-]*[a-z0-9])?$/', $h ) ) {
					$hosts[] = $h;
				}
			}
		}
	}

	$hosts = array_values( array_unique( array_filter( $hosts ) ) );
	return $hosts;
}

add_filter( 'wp_script_attributes', 'dc_swp_partytown_script_attrs', 5 );

/**
 * Mark any registered <script src> whose URL matches a configured
 * pattern as type="text/partytown", moving it off the main thread.
 * Priority 5 fires before per-script filters (default priority 10).
 *
 * @since 1.0.0
 * @param array $attributes The script element attributes array.
 * @return array Modified attributes.
 */
function dc_swp_partytown_script_attrs( $attributes ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	if ( dc_swp_is_bot_request() ) {
		return $attributes;
	}
	$src = $attributes['src'] ?? '';
	if ( ! $src ) {
		return $attributes;
	}

	if ( get_option( 'dampcig_pwa_sw_enabled', 'yes' ) !== 'yes' ) {
		// Partytown disabled → render matched scripts on the main thread with defer.
		foreach ( dc_swp_get_partytown_patterns() as $pattern ) {
			if ( '' !== $pattern && str_contains( $src, $pattern ) ) {
				$attributes['defer'] = true;
				unset( $attributes['async'] );
				break;
			}
		}
		return $attributes;
	}

	// Never mark excluded scripts as text/partytown.
	foreach ( dc_swp_get_partytown_exclude_patterns() as $excl ) {
		if ( '' !== $excl && str_contains( $src, $excl ) ) {
			return $attributes;
		}
	}
	// GDPR guard: if an upstream filter (rare at priority 5 but possible) has already
	// set a non-standard type, leave it alone so the CMP's blocking is not disturbed.
	$current_type = strtolower( $attributes['type'] ?? '' );
	if ( '' !== $current_type && 'text/javascript' !== $current_type ) {
		return $attributes;
	}
	foreach ( dc_swp_get_partytown_patterns() as $pattern ) {
		if ( '' !== $pattern && str_contains( $src, $pattern ) ) {
			// Consent granted → off-load via Partytown; no consent → block silently.
			$attributes['type'] = dc_swp_has_marketing_consent() ? 'text/partytown' : 'text/plain';
			unset( $attributes['async'] );
			break;
		}
	}
	return $attributes;
}

// Bust the in-request static cache, object cache, and W3TC page cache when settings change.
add_action( 'update_option_dc_swp_partytown_scripts', 'dc_swp_bust_page_cache' );
add_action( 'update_option_dc_swp_partytown_exclude', 'dc_swp_bust_page_cache' );
add_action( 'update_option_dc_swp_inline_scripts', 'dc_swp_bust_page_cache' );

/**
 * Delete all object-cache pattern keys and flush W3TC page cache (if active),
 * so stale cached HTML with old type attributes is never served.
 */
function dc_swp_bust_page_cache() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	wp_cache_delete( 'patterns', 'dc_swp' );
	wp_cache_delete( 'exclude_patterns', 'dc_swp' );
	// W3TC page cache flush.
	if ( function_exists( 'w3tc_pgcache_flush' ) ) {
		w3tc_pgcache_flush();
	}
}

/**
 * Default exclude list: scripts known to be incompatible with Partytown.
 * Pre-populated into the admin textarea on first use (when option is empty).
 * Users can edit the list freely — remove patterns they do not need.
 *
 * @since 1.0.0
 * @return string Newline-separated list of incompatible script patterns.
 */
function dc_swp_default_exclude_list() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	return implode(
		"\n",
		array(
			'widget.trustpilot.com',
			'invitejs.trustpilot.com',
			'tp.widget.bootstrap',
			'cdn.reamaze.com',
			'js.stripe.com',
			'js.braintreegateway.com',
			'checkout.paypal.com',
			'maps.googleapis.com',
			'connect.facebook.net/en_US/sdk',
		)
	);
}

/**
 * Return admin-configured Partytown EXCLUSION patterns, object-cache memoised.
 * Scripts whose src matches any of these are never rewritten to text/partytown.
 *
 * @since 1.0.0
 * @return string[]
 */
function dc_swp_get_partytown_exclude_patterns() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	static $exclude = null;
	if ( null !== $exclude ) {
		return $exclude;
	}
	$cached = wp_cache_get( 'exclude_patterns', 'dc_swp' );
	if ( false !== $cached ) {
		$exclude = $cached;
		return $exclude;
	}
	$raw     = (string) get_option( 'dc_swp_partytown_exclude', '' );
	$exclude = array_values(
		array_filter(
			array_map( 'trim', explode( "\n", $raw ) ),
			static fn( $line ) => '' !== $line
		)
	);
	wp_cache_set( 'exclude_patterns', $exclude, 'dc_swp', HOUR_IN_SECONDS );
	return $exclude;
}


// ============================================================
// OUTPUT BUFFER — PARTYTOWN SCRIPT REWRITER
// Rewrites <script src> tags in the final HTML: when the src
// matches a configured pattern, sets type to text/partytown
// (marketing consent cookie present) or text/plain (no consent).
// Catches scripts injected via direct echo that bypass
// wp_script_attributes — e.g. Ahrefs in functions.php.
// ============================================================

add_action( 'template_redirect', 'dc_swp_partytown_buffer_start', 2 );

/**
 * Start output buffering so dc_swp_partytown_buffer_rewrite()
 * can rewrite the full HTML response before it is sent.
 */
function dc_swp_partytown_buffer_start() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	if ( is_admin() ) {
		return;
	}
	if ( dc_swp_is_bot_request() ) {
		return;
	}
	if ( get_option( 'dampcig_pwa_sw_enabled', 'yes' ) !== 'yes' ) {
		return;
	}
	if ( empty( dc_swp_get_partytown_patterns() ) ) {
		return;
	}
	ob_start( 'dc_swp_partytown_buffer_rewrite' );
}

/**
 * Output-buffer callback: walk every <script> element and, when its src= URL
 * matches a configured pattern, swap the type attribute to text/partytown
 * (consent granted) or text/plain (no consent) and strip async.
 *
 * Inline companion scripts are also rewritten: when a src= script is
 * rewritten, the immediately following inline <script> (no src=) is given
 * the same type so both run in the same Partytown context. This fixes the
 * pattern emitted by GTM / Google Site Kit where the external loader is
 * followed by an inline gtag() initializer — without this, only the loader
 * runs in the worker while gtag() stays on the main thread, causing GTM to
 * receive no data.
 *
 * Only services that are documented to use the src= loader + inline
 * initializer pattern are eligible for companion rewriting. Each entry in
 * the companion map carries a content validator regex so that a random
 * inline script that happens to appear after a matched src= script is
 * never incorrectly moved into the worker.
 *
 * @param string $html Full page HTML.
 * @return string Modified HTML.
 */
function dc_swp_partytown_buffer_rewrite( $html ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	$patterns = dc_swp_get_partytown_patterns();
	if ( empty( $patterns ) ) {
		return $html;
	}

	/**
	 * Maps a src= URL substring to a regex that the following inline script's
	 * body must match before it is rewritten to type="text/partytown".
	 *
	 * Only services that genuinely emit a <script src=…> loader followed by
	 * an inline <script> initializer are listed here. Services that use a
	 * pure inline embed (Facebook Pixel, TikTok Pixel, GTM container snippet)
	 * or a standalone src= tag (Ahrefs, HubSpot) must NOT be in this map —
	 * the next inline script in the page could be completely unrelated and
	 * would break if pushed into the Partytown worker.
	 *
	 * Use the 'dc_swp_inline_companion_map' filter to add custom entries.
	 *
	 * @var array<string,string> $companion_map  key = URL substring, value = body regex
	 */
	$companion_map = (array) apply_filters(
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		'dc_swp_inline_companion_map',
		array(
			// Google gtag.js (Google Analytics 4 / Google Site Kit):
			// <script src="…/gtag/js?id=G-…"></script>
			// <script>window.dataLayer=…;function gtag(){…}</script>.
			'googletagmanager.com/gtag/js' => '/\bdataLayer\b|\bgtag\s*\(/i',
		)
	);

	// Pending companion state:
	// null  → previous matched script has no known inline companion.
	// array → [ 'type' => 'text/partytown'|'text/plain', 'validator' => '/regex/' ].
	$pending_companion = null;

	return preg_replace_callback(
		'/<script\b([^>]*)>(.*?)<\/script>/is',
		static function ( $matches ) use ( $patterns, $companion_map, &$pending_companion ) {
			$tag_inner = $matches[1];
			$body      = $matches[2];

			// ── Inline script (no src=) ──────────────────────────────────────
			if ( ! preg_match( '/\bsrc=(["\'])([^"\']+)\1/i', $tag_inner, $src_match ) ) {
				if ( null !== $pending_companion ) {
					$carry             = $pending_companion;
					$pending_companion = null; // Consume — one companion per src= script.

					// Content guard: inline body must match the service's expected
					// initializer pattern. If it doesn't, this is an unrelated inline
					// script that must NOT be moved into the Partytown worker.
					if ( ! preg_match( $carry['validator'], $body ) ) {
						return $matches[0];
					}

					// GDPR guard: CMP-blocked inline (non text/javascript type) stays untouched.
					if ( preg_match( '/\btype=(["\'])([^"\']+)\1/i', $tag_inner, $type_match ) ) {
						if ( strtolower( $type_match[2] ) !== 'text/javascript' ) {
							return $matches[0];
						}
					}

					$carry_type = $carry['type'];
					if ( preg_match( '/\btype=["\'][^"\']*["\']/i', $tag_inner ) ) {
						$tag_inner = preg_replace( '/\btype=["\'][^"\']*["\']/i', 'type="' . $carry_type . '"', $tag_inner );
					} else {
						$tag_inner = ' type="' . $carry_type . '"' . $tag_inner;
					}
					return '<script' . $tag_inner . '>' . $body . '</script>';
				}

				// Unrelated inline — leave untouched.
				return $matches[0];
			}

			// ── External (src=) script ───────────────────────────────────────
			$src = $src_match[2];

			// Exclusion list — always takes priority.
			foreach ( dc_swp_get_partytown_exclude_patterns() as $excl ) {
				if ( '' !== $excl && str_contains( $src, $excl ) ) {
					$pending_companion = null;
					return $matches[0];
				}
			}

			foreach ( $patterns as $pattern ) {
				if ( '' === $pattern || ! str_contains( $src, $pattern ) ) {
					continue;
				}

				$existing_type_val = '';
				if ( preg_match( '/\btype=(["\'])([^"\']+)\1/i', $tag_inner, $type_match ) ) {
					$existing_type_val = strtolower( $type_match[2] );
				}

				// wp_script_attributes already set text/partytown — honour it and
				// still arm the companion state in case this src has a known companion.
				if ( 'text/partytown' === $existing_type_val ) {
					$pending_companion = dc_swp_resolve_companion( $src, 'text/partytown', $companion_map );
					return $matches[0];
				}

				// GDPR guard: CMP has blocked this script — leave untouched entirely.
				if ( '' !== $existing_type_val && 'text/javascript' !== $existing_type_val ) {
					$pending_companion = null;
					return $matches[0];
				}

				$new_type = dc_swp_has_marketing_consent() ? 'text/partytown' : 'text/plain';

				if ( preg_match( '/\btype=["\'][^"\']*["\']/i', $tag_inner ) ) {
					$tag_inner = preg_replace( '/\btype=["\'][^"\']*["\']/i', 'type="' . $new_type . '"', $tag_inner );
				} else {
					$tag_inner = ' type="' . $new_type . '"' . $tag_inner;
				}
				$tag_inner = preg_replace( '/\s+async(?:=["\'][^"\']*["\'])?/i', '', $tag_inner );

				// Arm companion state only for services with a known inline companion.
				$pending_companion = dc_swp_resolve_companion( $src, $new_type, $companion_map );

				return '<script' . $tag_inner . '>' . $body . '</script>';
			}

			// No pattern matched — clear any pending companion state.
			$pending_companion = null;
			return $matches[0];
		},
		$html
	);
}

/**
 * Return a pending-companion descriptor if $src matches a key in $companion_map,
 * or null if this src= script has no known inline companion.
 *
 * @param string               $src           The script src= URL.
 * @param string               $type          'text/partytown' or 'text/plain'.
 * @param array<string,string> $companion_map Map of URL substring → body validator regex.
 * @return array{type:string,validator:string}|null
 */
function dc_swp_resolve_companion( $src, $type, $companion_map ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	foreach ( $companion_map as $cdn_pattern => $validator_regex ) {
		if ( str_contains( $src, $cdn_pattern ) ) {
			return array(
				'type'      => $type,
				'validator' => $validator_regex,
			);
		}
	}
	return null;
}


// ============================================================
// INLINE SCRIPT BLOCKS — PARTYTOWN WEB WORKER
// Allows admins to paste complete third-party script blocks
// (e.g. Meta Pixel, TikTok Pixel) directly into the admin UI.
// Inline <script> blocks are output with type="text/partytown"
// so Partytown executes them in a Web Worker. The dynamically
// injected external script (e.g. fbevents.js) is automatically
// intercepted and loaded within the worker context by Partytown.
// <noscript> tracking pixels are only emitted when consent is
// present (GDPR). All output is consent-gated via
// dc_swp_has_marketing_consent().
// ============================================================

add_action( 'wp_head', 'dc_swp_output_inline_scripts', 3 );

/**
 * Parse the admin-stored raw script paste and output each inline
 * <script> block with type="text/partytown" (consent granted) or
 * type="text/plain" (no consent). External src= scripts inside
 * the paste are skipped — they are handled by the include list or
 * injected automatically from within the worker by the inline code.
 *
 * Runs at wp_head priority 3, after Partytown lib is loaded (priority 2).
 */
function dc_swp_output_inline_scripts() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	if ( dc_swp_is_bot_request() ) {
		return;
	}
	if ( is_admin() ) {
		return;
	}
	// Skip cart, checkout, and account pages.
	if (
		( function_exists( 'is_cart' ) && is_cart() ) ||
		( function_exists( 'is_checkout' ) && is_checkout() ) ||
		( function_exists( 'is_account_page' ) && is_account_page() )
	) {
		return;
	}

	$raw_stored = (string) get_option( 'dc_swp_inline_scripts', '' );
	if ( '' === $raw_stored ) {
		return;
	}

	// JSON array format (new): combine only enabled blocks.
	// Legacy plain-text format (old): use as-is for backward compatibility.
	$decoded_stored = json_decode( $raw_stored, true );
	if ( is_array( $decoded_stored ) ) {
		$enabled_parts = array();
		foreach ( $decoded_stored as $blk ) {
			if ( ! empty( $blk['enabled'] ) && '' !== trim( (string) ( $blk['code'] ?? '' ) ) ) {
				$enabled_parts[] = $blk['code'];
			}
		}
		$raw = implode( "\n", $enabled_parts );
	} else {
		$raw = $raw_stored; // Legacy plain-text format.
	}

	if ( '' === $raw ) {
		return;
	}

	// Extract inline <script> blocks — skip any that have a src= attribute
	// (those are external scripts handled by the include-list / output buffer).
	$js_blocks = array();
	if ( preg_match_all( '/<script\b([^>]*)>(.*?)<\/script>/is', $raw, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $m ) {
			if ( preg_match( '/\bsrc\s*=/i', $m[1] ) ) {
				continue; // External — handled elsewhere.
			}
			$content = trim( $m[2] );
			if ( '' !== $content ) {
				$js_blocks[] = $content;
			}
		}
	}

	if ( empty( $js_blocks ) ) {
		return;
	}

	$pt_enabled = get_option( 'dampcig_pwa_sw_enabled', 'yes' ) === 'yes';
	$consent    = dc_swp_has_marketing_consent();
	$nonce      = dc_swp_get_csp_nonce();
	$nonce_attr = '' !== $nonce ? ' nonce="' . esc_attr( $nonce ) . '"' : '';

	if ( $pt_enabled ) {
		// Partytown active — run in Web Worker, consent-gated.
		$type = $consent ? 'text/partytown' : 'text/plain';
		foreach ( $js_blocks as $js ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-controlled inline JS.
			echo '<script type="' . esc_attr( $type ) . '"' . $nonce_attr . ">\n" . $js . "\n</script>\n";
		}
		// <noscript> tracking pixels are passive image loads — only emit when consent is granted.
		if ( $consent ) {
			if ( preg_match_all( '/<noscript\b[^>]*>(.*?)<\/noscript>/is', $raw, $ns_matches ) ) {
				foreach ( $ns_matches[1] as $ns_content ) {
					$ns_content = trim( $ns_content );
					if ( '' !== $ns_content ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-controlled noscript fallback.
						echo '<noscript>' . $ns_content . "</noscript>\n";
					}
				}
			}
		}
	} else {
		// Partytown disabled — diagnostic mode: render scripts directly on the main
		// thread with defer so they do not block page rendering. No consent gate
		// is applied here; this mode is intended for local debugging only.
		foreach ( $js_blocks as $js ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-controlled inline JS.
			echo '<script defer' . $nonce_attr . ">\n" . $js . "\n</script>\n";
		}
		// Always emit <noscript> pixels in diagnostic mode when consent is granted.
		if ( $consent ) {
			if ( preg_match_all( '/<noscript\b[^>]*>(.*?)<\/noscript>/is', $raw, $ns_matches ) ) {
				foreach ( $ns_matches[1] as $ns_content ) {
					$ns_content = trim( $ns_content );
					if ( '' !== $ns_content ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-controlled noscript fallback.
						echo '<noscript>' . $ns_content . "</noscript>\n";
					}
				}
			}
		}
	}
}
