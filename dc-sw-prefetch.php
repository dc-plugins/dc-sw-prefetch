<?php
/**
 * DC Script Worker Proxy -- Main Plugin File
 *
 * @wordpress-plugin
 * Plugin Name: DC Script Worker Proxy
 * Plugin URI:  https://github.com/dc-plugins/dc-sw-prefetch
 * Description: Offloads third-party scripts (GTM, Pixel, Analytics...) to a Web Worker via Partytown with consent-aware loading. Fully vendored -- no build step required.
 * Version:     3.1.0
 * Author:      lennilg
 * Author URI:  https://github.com/lennilg
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dc-script-worker-prefetcher
 * Domain Path:       /languages
 * Requires at least: 6.8
 * Requires PHP:      8.0
 * Tested up to:      6.9
 * WC tested up to:   10.7.0
 *
 * @package DC_Service_Worker_Proxy
 */

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

// ============================================================
// ACTIVATION -- OPTION MIGRATION
// Migrates legacy dampcig_pwa_* option names to the canonical
// dc_swp_* prefix introduced in v1.6.0. Safe to run multiple
// times: only copies the value when the old key exists AND the
// new key has not already been set.
// ============================================================

/**
 * Migrate legacy option names to dc_swp_* on plugin activation.
 *
 * @since 1.6.0
 * @return void
 */
function dc_swp_migrate_options() {
	$migrations = array(
		'dampcig_pwa_sw_enabled'    => 'dc_swp_sw_enabled',
		'dampcig_pwa_footer_credit' => 'dc_swp_footer_credit',
	);
	foreach ( $migrations as $old => $new ) {
		if ( false !== get_option( $old ) && false === get_option( $new ) ) {
			update_option( $new, get_option( $old ) );
			delete_option( $old );
		}
	}
}
register_activation_hook( __FILE__, 'dc_swp_migrate_options' );

// ============================================================
// I18N
// ============================================================
add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'dc-script-worker-prefetcher', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

// ============================================================
// WOOCOMMERCE HPOS COMPATIBILITY DECLARATION
// Declares compatibility with High-Performance Order Storage
// (Custom Order Tables). This plugin does not query the orders
// table at all, so it is fully compatible with HPOS.
// ============================================================
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);

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
	function dc_swp_is_bot_request() {
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
// CONSENT GATE -- WP Consent API
//
// All consent decisions are delegated to the WP Consent API plugin.
// The admin "Consent Gate" toggle (dc_swp_consent_gate) controls
// whether scripts are gated at all:
// • OFF (default): scripts always load as text/partytown -- no
// consent check is performed. Suitable for sites without a
// CMP or where the CMP handles blocking externally.
// • ON: scripts are blocked (text/plain) until the WP Consent
// API reports consent for the required category.
//
// Each service is mapped to a WP Consent API category via
// dc_swp_get_service_category(). The Script List uses a global
// default category (dc_swp_script_list_category). Inline Script
// Blocks carry a per-block category field.
//
// Self-managing services bypass the gate entirely:
// • GCM v2-aware services (when GCM v2 is enabled)
// • Meta Pixel (when LDU is enabled)
// ============================================================

// Register dc-sw-prefetch as compliant with WP Consent API (shows in Site Health when the plugin is active).
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Consent API's own filter convention.
add_filter( 'wp_consent_api_registered_dc-sw-prefetch/dc-sw-prefetch.php', '__return_true' );

/**
 * Return the WP Consent API category for a given script hostname.
 *
 * Known services are mapped to their most appropriate consent category.
 * Unknown hosts fall back to the Script List default category setting.
 *
 * @param string $hostname Lowercase hostname (or substring) to look up.
 * @return string WP Consent API category: marketing|statistics|statistics-anonymous|functional|preferences.
 */
function dc_swp_get_service_category( $hostname ) {
	// Static map: hostname substring → WP Consent API category.
	$map = array(
		// Marketing.
		'js.hs-scripts.com'       => 'marketing',
		'js.hsforms.net'          => 'marketing',
		'js.hscollectedforms.net' => 'marketing',
		'js.hubspot.com'          => 'marketing',
		'static.klaviyo.com'      => 'marketing',
		'static.ads-twitter.com'  => 'marketing',
		'snap.licdn.com'          => 'marketing',
		// Statistics.
		'cdn.mxpnl.com'           => 'statistics',
		'cdn4.mxpnl.com'          => 'statistics',
		'cdn.segment.com'         => 'statistics',
		'static.hotjar.com'       => 'statistics',
		'script.hotjar.com'       => 'statistics',
		'clarity.ms'              => 'statistics',
		// Functional.
		'widget.intercom.io'      => 'functional',
		'js.intercomcdn.com'      => 'functional',
	);

	$hostname = strtolower( $hostname );
	foreach ( $map as $pattern => $category ) {
		if ( str_contains( $hostname, $pattern ) ) {
			return $category;
		}
	}

	// Fall back to the admin-configured Script List default category.
	return dc_swp_get_script_list_category();
}

/**
 * Return the default consent category for Script List entries.
 *
 * @return string WP Consent API category (default: 'marketing').
 */
function dc_swp_get_script_list_category() {
	$valid = array( 'marketing', 'statistics', 'statistics-anonymous', 'functional', 'preferences' );
	$cat   = get_option( 'dc_swp_script_list_category', 'marketing' );
	return in_array( $cat, $valid, true ) ? $cat : 'marketing';
}

/**
 * Return true if the Consent Gate is enabled in admin settings.
 *
 * When disabled (default), all scripts load unconditionally as
 * text/partytown -- no consent check is performed.
 *
 * @return bool
 */
function dc_swp_is_consent_gate_enabled() {
	return get_option( 'dc_swp_consent_gate', 'no' ) === 'yes';
}

/**
 * Determine whether a script should load based on the Consent Gate.
 *
 * @param string $category WP Consent API category for the script.
 * @return bool True = load (text/partytown), false = block (text/plain).
 */
function dc_swp_has_consent_for( $category ) {
	// Gate disabled → always allow.
	if ( ! dc_swp_is_consent_gate_enabled() ) {
		return true;
	}

	// WP Consent API active → delegate.
	if ( function_exists( 'wp_has_consent' ) ) {
		return wp_has_consent( $category );
	}

	// WP Consent API not installed but gate is enabled → block by default
	// (fail-closed: do not load scripts without a working consent mechanism).
	return false;
}

/**
 * Resolve the consent category for a script src URL and return whether
 * it should load. Handles GCM v2 and Meta LDU bypass automatically.
 *
 * @param string $src Script src URL.
 * @return array{ 0: bool, 1: string } [ should_load, category ].
 */
function dc_swp_resolve_script_consent( $src ) {
	// GCM v2-aware services bypass the consent gate when GCM v2 is enabled.
	if ( dc_swp_is_consent_mode_enabled() && dc_swp_script_uses_gcm_v2( $src ) ) {
		return array( true, 'marketing' );
	}

	// Meta Pixel bypasses when LDU is enabled.
	if ( dc_swp_is_meta_ldu_enabled() && dc_swp_is_meta_script( $src ) ) {
		return array( true, 'marketing' );
	}

	// Per-entry category from the Script List takes priority over hostname auto-detection.
	foreach ( dc_swp_get_script_list_entries() as $entry ) {
		if ( '' !== $entry['pattern'] && str_contains( $src, $entry['pattern'] ) ) {
			$cat = $entry['category'];
			return array( dc_swp_has_consent_for( $cat ), $cat );
		}
	}

	$host    = strtolower( (string) wp_parse_url( $src, PHP_URL_HOST ) );
	$cat     = dc_swp_get_service_category( $host );
	$consent = dc_swp_has_consent_for( $cat );
	return array( $consent, $cat );
}

/**
 * Resolve consent for an inline script body and return whether it should load.
 * Handles GCM v2 and Meta LDU bypass automatically.
 *
 * @param string $js      Inline JS content.
 * @param string $category Override category (from inline block settings). Empty = auto-detect.
 * @return array{ 0: bool, 1: string } [ should_load, category ].
 */
function dc_swp_resolve_inline_consent( $js, $category = '' ) {
	// GCM v2-aware inline scripts bypass when GCM v2 is enabled.
	if ( dc_swp_is_consent_mode_enabled() && dc_swp_inline_uses_gcm_v2( $js ) ) {
		return array( true, 'marketing' );
	}

	// Meta Pixel inline bypass when LDU is enabled.
	if ( dc_swp_is_meta_ldu_enabled() && dc_swp_inline_is_meta( $js ) ) {
		return array( true, 'marketing' );
	}

	if ( '' === $category ) {
		$category = dc_swp_get_script_list_category();
	}
	$consent = dc_swp_has_consent_for( $category );
	return array( $consent, $category );
}

/**
 * Return true if Google Consent Mode v2 is enabled in settings.
 *
 * When active, Partytown-managed scripts always use type="text/partytown"
 * and a gtag('consent','default',{...denied}) snippet is injected early in
 * <head> so Google's own Consent Mode API handles measurement signals.
 *
 * @return bool
 */
function dc_swp_is_consent_mode_enabled() {
	return get_option( 'dc_swp_consent_mode', 'no' ) === 'yes';
}

/**
 * Return true if Meta/Facebook Pixel Limited Data Use (LDU) mode is enabled.
 *
 * When active, Partytown-managed fbq scripts always use type="text/partytown"
 * and an fbq('dataProcessingOptions',['LDU'],0,0) snippet is injected early
 * in <head> so the Meta pixel fires in LDU mode on every page load without
 * requiring a marketing consent cookie to be present.
 *
 * @return bool
 */
function dc_swp_is_meta_ldu_enabled() {
	return get_option( 'dc_swp_meta_ldu', 'no' ) === 'yes';
}


// ============================================================
// VERSION
// Must be defined before require_once calls so that included
// files can safely reference DC_SWP_VERSION at module level.
// ============================================================

define( 'DC_SWP_VERSION', '3.1.0' );


// ============================================================
// I18N -- LOAD TEXT DOMAIN
// Loads .mo translation files from the /languages directory so
// that __() / _e() / esc_html__() calls with the 'dc-script-worker-prefetcher'
// text domain resolve to the admin user's locale.
// ============================================================
// ADMIN INTERFACE
// ============================================================

require_once plugin_dir_path( __FILE__ ) . 'admin.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/integrations.php';


// ============================================================
// FOOTER CREDIT
// Injects a tiny inline script via wp_footer that uses a DOM
// TreeWalker to find the first © text node inside <footer>
// and wraps it with a <a> link. Works universally across all
// themes without any PHP output-buffer or regex fragility.
// ============================================================

if ( get_option( 'dc_swp_footer_credit', 'no' ) === 'yes' && ! function_exists( 'dc_swp_footer_credit_owner' ) && ! function_exists( 'dc_footer_credit_owner' ) ) {
	/**
	 * Sentinel: marks this plugin as the active footer-credit owner.
	 * Other DC plugins check function_exists( 'dc_swp_footer_credit_owner' ) (or the
	 * legacy 'dc_footer_credit_owner' for older plugin versions) and skip their own
	 * registration when this is already defined.
	 */
	function dc_swp_footer_credit_owner(): void {}

	add_action( 'wp_enqueue_scripts', 'dc_swp_footer_credit_js', PHP_INT_MAX );
}

/**
 * Output the footer credit inline script via wp_footer.
 *
 * @since 1.0.0
 * @return void
 */
function dc_swp_footer_credit_js() {
	if ( is_admin() ) {
		return;
	}

	$url   = 'https://www.dampcig.dk';
	$title = esc_html__( 'Powered by Dampcig.dk', 'dc-script-worker-prefetcher' );
	wp_register_script( 'dc-swp-footer-credit', plugins_url( 'assets/js/footer-credit.js', __FILE__ ), array(), DC_SWP_VERSION, array( 'in_footer' => true ) );
	wp_localize_script(
		'dc-swp-footer-credit',
		'dcSwpFooterCreditData',
		array(
			'url'   => esc_url( $url ),
			'title' => $title,
		)
	);
	wp_enqueue_script( 'dc-swp-footer-credit' );
}


// ============================================================
// CROSS-ORIGIN ISOLATION HEADERS (for Atomics bridge)
// ============================================================

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
function dc_swp_cross_origin_isolation_headers() {
	if ( get_option( 'dc_swp_coi_headers', 'no' ) !== 'yes' ) {
		return;
	}
	if ( is_admin() || dc_swp_is_bot_request() ) {
		return;
	}
	// Skip for logged-in users and transactional pages.
	if ( is_user_logged_in() || dc_swp_is_safe_page() ) {
		return;
	}
	header( 'Cross-Origin-Opener-Policy: same-origin' );
	header( 'Cross-Origin-Embedder-Policy: credentialless' );
}


// ============================================================
// PARTYTOWN -- serve ~partytown/ lib files from the plugin
// ============================================================

add_action( 'init', 'dc_swp_serve_partytown_files', 1 );

/**
 * Stream any requested file from our vendored assets/partytown/ directory
 * when the request path starts with /~partytown/.
 *
 * Partytown resolves its own workers/sandboxes relative to the `lib` config
 * option, which we point to /~partytown/ in the inline snip below.
 */
function dc_swp_serve_partytown_files() {
	$request_uri = isset( $_SERVER['REQUEST_URI'] )
		? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		: '';

	if ( strncmp( $request_uri, '/~partytown/', 12 ) !== 0 ) {
		return;
	}

	// Resolve the physical file -- prevent directory traversal.
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
	// Required for the debug build: partytown-ww-atomics.js is loaded as a real URL
	// (not a blob). Chrome requires both CORP and COEP on worker script responses when
	// the creating context has COEP. Production is unaffected (uses a blob: URL).
	// COEP must match or be stricter than the page (site uses credentialless).
	header( 'Cross-Origin-Resource-Policy: same-origin' );
	header( 'Cross-Origin-Embedder-Policy: credentialless' );
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
// Partytown Script List and Script Blocks -- only hostnames the
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
function dc_swp_serve_partytown_proxy() {
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
	// List and Script Blocks -- only hostnames the site owner has opted into.
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
			'redirection' => 0, // No redirects -- prevents SSRF via open redirect.
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
	// Cache for 1 hour -- CDN script content rarely changes.
	header( 'Cache-Control: public, max-age=3600, stale-while-revalidate=300' );

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- proxied JS body from allowlisted CDN
	echo $body;
	exit();
}


// ============================================================
// PARTYTOWN SNIPPET IN FOOTER
// ============================================================

/**
 * Known Partytown path-rewrite rules, keyed by a hostname substring.
 *
 * Each entry maps one or more relative URL paths (as the web worker sees them
 * after the resolveUrl() rewrite) to the absolute CDN endpoint they must reach.
 * These rules are only injected into the Partytown config when the matching
 * hostname is actually present in the admin-configured Script List or Inline
 * Script Blocks -- so a site that has never added Ahrefs will never see the
 * Ahrefs rewrite shipped to its visitors.
 *
 * To add a new service here: find the relative path that the analytics script
 * POSTs or fetches at runtime (visible in DevTools → Network while the script
 * runs inside the Partytown worker) and add a hostname → [ path => absolute_url ]
 * entry below.
 *
 * @return array<string, array<string, string>>
 *   Keys are hostname substrings; values are path → absolute-URL maps.
 */
function dc_swp_get_known_path_rewrites() {
	return array(
		// Ahrefs Analytics -- script posts beacon events to /api/event on its
		// own domain; Partytown sees only the relative path so we must remap it.
		'analytics.ahrefs.com' => array(
			'/api/event' => 'https://analytics.ahrefs.com/api/event',
		),
	);
}

/**
 * Build the active path-rewrite map by scanning the admin-configured patterns
 * against the known-services lookup table, then allowing developer overrides.
 *
 * Only rewrites whose host appears in the active Script List (or Inline Blocks,
 * via dc_swp_get_proxy_allowed_hosts) are included, so pages never receive
 * rewrite rules for services that aren't configured on that site.
 *
 * @return array<string, string>  path => absolute-URL map ready for Partytown.
 */
function dc_swp_build_path_rewrites() {
	$active_patterns = dc_swp_get_partytown_patterns();
	// Also check inline-block sources so a hardcoded script URL there triggers
	// the same automatic detection as an entry in the Script List.
	$active_hosts = dc_swp_get_proxy_allowed_hosts();

	$rewrites = array();
	foreach ( dc_swp_get_known_path_rewrites() as $host => $host_rewrites ) {
		$host_active = false;
		// Match against raw patterns (may be partial paths, e.g. "analytics.ahrefs.com/analytics.js").
		foreach ( $active_patterns as $pattern ) {
			if ( str_contains( $pattern, $host ) ) {
				$host_active = true;
				break;
			}
		}
		// Fallback: match against resolved allow-listed hostnames.
		if ( ! $host_active ) {
			foreach ( $active_hosts as $allowed_host ) {
				if ( str_contains( $allowed_host, $host ) || str_contains( $host, $allowed_host ) ) {
					$host_active = true;
					break;
				}
			}
		}
		if ( $host_active ) {
			$rewrites = array_merge( $rewrites, $host_rewrites );
		}
	}

	/**
	 * Filter the Partytown path-rewrite map.
	 *
	 * Allows themes and plugins to add custom rewrites for services not in the
	 * built-in lookup table. Runs after auto-detection so custom entries always win.
	 *
	 * Example:
	 *   add_filter( 'dc_swp_partytown_path_rewrites', function( $map ) {
	 *       $map['/collect'] = 'https://analytics.example.com/collect';
	 *       return $map;
	 *   } );
	 *
	 * @param array<string,string> $rewrites path => absolute-URL map.
	 */
	return apply_filters(
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		'dc_swp_partytown_path_rewrites',
		$rewrites
	);
}

// ============================================================
// GOOGLE CONSENT MODE V2
// When enabled, injects gtag('consent','default',{...denied}) at
// wp_head priority 1 -- before Partytown and any analytics scripts
// -- so GTM / GA4 running in the Partytown worker see the consent
// defaults on startup.
//
// Partytown-managed scripts are always emitted as text/partytown
// (never text/plain). Google's own Consent Mode API handles
// measurement; the existing CMP fires
// gtag('consent','update',{...granted}) when the visitor consents.
// ============================================================

/**
 * Return true when consent-mode scripts should NOT be emitted for this request.
 *
 * Centralises the guard shared by dc_swp_inject_consent_mode_default() and
 * dc_swp_enqueue_consent_scripts() so future changes only need one edit.
 *
 * @since 2.0.0
 * @return bool
 */
function dc_swp_should_skip_consent_scripts(): bool {
	if ( dc_swp_is_bot_request() || is_admin() ) {
		return true;
	}
	if ( get_option( 'dc_swp_sw_enabled', 'yes' ) !== 'yes' ) {
		return true;
	}
	if ( ! dc_swp_is_consent_mode_enabled() ) {
		return true;
	}
	if ( get_option( 'dc_swp_gtm_mode', 'off' ) === 'off' ) {
		return true;
	}
	return false;
}

add_action( 'wp_head', 'dc_swp_inject_consent_mode_default', 1 );

/**
 * Inject the Google Consent Mode v2 default-denied state into <head>
 * before any Partytown or analytics scripts load.
 *
 * @return void
 */
function dc_swp_inject_consent_mode_default() {
	if ( dc_swp_should_skip_consent_scripts() ) {
		return;
	}

	$nonce      = dc_swp_get_csp_nonce();
	$nonce_attr = '' !== $nonce ? ' nonce="' . esc_attr( $nonce ) . '"' : '';

	// Fully static GCM v2 default stub -- always all-denied.
	// Safe for full-page caching: no cookie reading, no server-side logic.
	// The external consent-update.js listens to CMP events (WP Consent API,
	// Complianz cmplz_fire_categories / cmplz_revoke) and calls
	// gtag('consent','update',{...}) once actual consent is known.
	$stub  = "window.dataLayer=window.dataLayer||[];\n";
	$stub .= "function gtag(){dataLayer.push(arguments);}\n";
	$stub .= "gtag('consent','default',{\n";
	$stub .= "\tsecurity_storage:'granted',\n";
	$stub .= "\tfunctionality_storage:'granted',\n";
	$stub .= "\tpersonalization_storage:'denied',\n";
	$stub .= "\tanalytics_storage:'denied',\n";
	$stub .= "\tad_storage:'denied',\n";
	$stub .= "\tad_user_data:'denied',\n";
	$stub .= "\tad_personalization:'denied',\n";
	$stub .= "\twait_for_update:500\n";
	$stub .= "});\n";
	$stub .= "dataLayer.push({event:'default_consent'});\n";

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully static JS stub; nonce is pre-escaped via esc_attr.
	echo '<script' . $nonce_attr . ">\n" . $stub . "</script>\n";
}

// ============================================================
// GCM v2 CONSENT UPDATE LISTENER (external enqueued script)
// consent-update.js reads consent via wp_has_consent() (WP Consent API)
// on DOMContentLoaded, then fires gtag('consent','update',{...}).
// Loaded in the footer so its DOMContentLoaded handler fires after any
// <head> CMP scripts, making our update the authoritative last word.
// Also listens to wp_listen_for_consent_change for live banner changes.
// Enqueued by dc_swp_enqueue_consent_scripts() below.
// ============================================================

add_action( 'wp_enqueue_scripts', 'dc_swp_enqueue_consent_scripts', 99 );

/**
 * Enqueue the GCM v2 consent update script.
 *
 * Reads consent state via wp_has_consent() (WP Consent API) on
 * DOMContentLoaded and translates each category into a
 * gtag('consent','update',...) call.  Loaded in the footer so its
 * DOMContentLoaded handler is registered after all <head> CMP scripts
 * and therefore fires last, making its update authoritative.
 *
 * @return void
 */
function dc_swp_enqueue_consent_scripts() {
	if ( dc_swp_should_skip_consent_scripts() ) {
		return;
	}

	// Depend on WP Consent API if available, so our script loads AFTER theirs.
	// This ensures wp_has_consent() exists when consent-update.js runs.
	$deps = array();
	if ( wp_script_is( 'wp-consent-api', 'registered' ) || wp_script_is( 'wp-consent-api', 'enqueued' ) ) {
		$deps[] = 'wp-consent-api';
	}

	wp_register_script(
		'dc-swp-consent-update',
		plugins_url( 'assets/js/consent-update.js', __FILE__ ),
		$deps,
		DC_SWP_VERSION,
		array( 'in_footer' => true )
	);
	wp_enqueue_script( 'dc-swp-consent-update' );

	// Expose Meta LDU state so consent-update.js can call fbq('consent',...)
	// and fbq('dataProcessingOptions',...) dynamically when consent changes.
	wp_localize_script(
		'dc-swp-consent-update',
		'dcSwpMeta',
		array(
			'ldu'         => dc_swp_is_meta_ldu_enabled() ? '1' : '0',
			'consentGate' => dc_swp_is_consent_gate_enabled() ? '1' : '0',
		)
	);

	// Stamp the CSP nonce so strict-dynamic CSP allows the script.
	$nonce = dc_swp_get_csp_nonce();
	if ( '' !== $nonce ) {
		add_filter(
			'wp_script_attributes',
			static function ( array $attrs ) use ( $nonce ) {
				if ( ( $attrs['id'] ?? '' ) === 'dc-swp-consent-update-js' ) {
					$attrs['nonce'] = $nonce;
				}
				return $attrs;
			}
		);
	}
}

// ============================================================
// CONSENT GATE -- CLIENT-SIDE UNBLOCKING SCRIPT
// When the Consent Gate is enabled, scripts blocked as text/plain
// carry a data-wp-consent-category attribute. consent-gate.js
// listens for WP Consent API consent changes and swaps them to
// text/partytown once the required category is granted.
// ============================================================

add_action( 'wp_enqueue_scripts', 'dc_swp_enqueue_consent_gate_script', 100 );

/**
 * Enqueue the consent-gate.js unblocking script when the Consent Gate is enabled.
 *
 * @since 1.9.0
 * @return void
 */
function dc_swp_enqueue_consent_gate_script() {
	if ( dc_swp_is_bot_request() || is_admin() ) {
		return;
	}
	if ( get_option( 'dc_swp_sw_enabled', 'yes' ) !== 'yes' ) {
		return;
	}
	if ( ! dc_swp_is_consent_gate_enabled() ) {
		return;
	}

	// Depend on WP Consent API if available.
	$deps = array();
	if ( wp_script_is( 'wp-consent-api', 'registered' ) || wp_script_is( 'wp-consent-api', 'enqueued' ) ) {
		$deps[] = 'wp-consent-api';
	}

	wp_register_script(
		'dc-swp-consent-gate',
		plugins_url( 'assets/js/consent-gate.js', __FILE__ ),
		$deps,
		DC_SWP_VERSION,
		array( 'in_footer' => true )
	);
	wp_enqueue_script( 'dc-swp-consent-gate' );

	$nonce = dc_swp_get_csp_nonce();
	if ( '' !== $nonce ) {
		add_filter(
			'wp_script_attributes',
			static function ( array $attrs ) use ( $nonce ) {
				if ( ( $attrs['id'] ?? '' ) === 'dc-swp-consent-gate-js' ) {
					$attrs['nonce'] = $nonce;
				}
				return $attrs;
			}
		);
	}
}

// ============================================================
// GOOGLE TAG MANAGEMENT
// Supports three active modes:
//
// own     -- user-supplied GTM container ID or GA4 measurement ID.
// Plugin injects the snippet in <head> at priority 5
// (after the GCM v2 consent default at priority 1) and
// the <noscript> iframe at wp_body_open.
//
// detect  -- scans known plugin options for an existing GTM/GA4
// tag; no injection (the other plugin handles it).
// GCM v2 consent default fires before any tag regardless.
//
// managed -- identical to "own" but the admin reaches the Container
// ID via the guided onboarding wizard in the admin UI.
//
// off     -- tag management disabled; GCM v2 still works independently.
//
// Validated ID formats:  GTM-XXXXXXX  |  G-XXXXXXXXXX  |  UA-XXXXX-X
// ============================================================

/**
 * Return true when the given string is a valid Google Tag ID.
 *
 * Accepts GTM-XXXXXXX, G-XXXXXXXXXX (GA4), and UA-XXXXX-X (legacy UA).
 *
 * @param string $id Raw tag ID string.
 * @return bool
 */
function dc_swp_is_valid_gtm_id( string $id ): bool {
	return (bool) preg_match( '/^(GTM-[A-Z0-9]{4,10}|G-[A-Z0-9]{6,}|UA-\d{4,}-\d+)$/i', $id );
}

/**
 * Return true when the given string is a valid Meta Pixel ID.
 *
 * Meta Pixel IDs are purely numeric, 10–20 digits.
 *
 * @param string $id Raw Pixel ID string.
 * @return bool
 */
function dc_swp_is_valid_pixel_id( string $id ): bool {
	return (bool) preg_match( '/^\d{10,20}$/', $id );
}

/**
 * Detect a live Google Tag ID by fetching and parsing the homepage HTML.
 *
 * Fetches the site's homepage via wp_remote_get() and scans the rendered
 * HTML for actual Google Tag script elements -- GTM containers, GA4
 * measurement IDs, and legacy UA IDs. This detects what is truly active
 * on the front-end, regardless of which plugin injected it.
 *
 * @return array{id: string, source: string}|array{} Non-empty when a tag is found.
 */
function dc_swp_detect_existing_gtm_id(): array {
	$response = wp_remote_get(
		home_url( '/' ),
		array(
			'timeout'    => 15,
			'sslverify'  => true,
			'user-agent' => 'Mozilla/5.0 (DCSwPrefetch/1.0; GTM-Detect)',
			'cookies'    => array(),
		)
	);
	if ( is_wp_error( $response ) ) {
		return array();
	}

	$body = wp_remote_retrieve_body( $response );
	if ( empty( $body ) ) {
		return array();
	}

	// 1. GTM container -- <script ... src="...googletagmanager.com/gtm.js?id=GTM-XXXXXXX">
	if ( preg_match( '/googletagmanager\.com\/gtm\.js\?id=(GTM-[A-Z0-9]{4,10})/i', $body, $m ) ) {
		return array(
			'id'     => strtoupper( sanitize_text_field( $m[1] ) ),
			'source' => 'gtm.js (Google Tag Manager)',
		);
	}

	// 2. GA4 / gtag.js -- <script ... src="...googletagmanager.com/gtag/js?id=G-XXXXXXXXXX">
	if ( preg_match( '/googletagmanager\.com\/gtag\/js\?id=(G-[A-Z0-9]{6,})/i', $body, $m ) ) {
		return array(
			'id'     => strtoupper( sanitize_text_field( $m[1] ) ),
			'source' => 'gtag.js (GA4)',
		);
	}

	// 3. Legacy Universal Analytics -- UA-XXXXXX-X anywhere in a gtag/analytics context.
	if ( preg_match( '/google(?:tagmanager|-analytics)\.com\/(?:gtag\/js|analytics\.js)\?id=(UA-\d{4,}-\d+)/i', $body, $m ) ) {
		return array(
			'id'     => strtoupper( sanitize_text_field( $m[1] ) ),
			'source' => 'analytics.js (Universal Analytics)',
		);
	}

	// 4. Inline gtag('config','G-...') or gtag('config','UA-...') without a matching src.
	if ( preg_match( '/gtag\s*\(\s*["\']config["\']\s*,\s*["\']((?:G|UA)-[A-Z0-9-]+)["\']/i', $body, $m ) ) {
		$tag = strtoupper( sanitize_text_field( $m[1] ) );
		if ( dc_swp_is_valid_gtm_id( $tag ) ) {
			$label = str_starts_with( $tag, 'G-' ) ? 'gtag config (GA4)' : 'gtag config (UA)';
			return array(
				'id'     => $tag,
				'source' => $label,
			);
		}
	}

	return array();
}

/**
 * Modify <script> tags for handles registered with 'dc_swp_type' data.
 *
 * Replaces the default type attribute with the Partytown type (text/partytown
 * or text/plain) and appends a CSP nonce when one is active. Applied to all
 * enqueued external scripts -- no direct echo of <script> tags is needed.
 *
 * @param string $tag    The HTML <script> tag string.
 * @param string $handle The registered script handle.
 * @return string Modified tag.
 */
function dc_swp_script_loader_tag( string $tag, string $handle ): string {
	$scripts     = wp_scripts();
	$type        = $scripts->get_data( $handle, 'dc_swp_type' );
	$consent_cat = $scripts->get_data( $handle, 'dc_swp_consent_cat' );
	$nonce       = dc_swp_get_csp_nonce();

	if ( ! $type && ! $nonce ) {
		return $tag;
	}

	if ( $type ) {
		// Remove any existing type attribute (WP 6.2+ omits type="text/javascript",
		// but keep the replacement consistent for older themes that might add it).
		$tag = preg_replace( '/\s+type=["\'][^"\']*["\']/', '', $tag );
		$tag = str_replace( '<script ', '<script type="' . esc_attr( $type ) . '" ', $tag );
	}

	if ( $consent_cat && 'text/plain' === $type ) {
		$tag = str_replace( '<script ', '<script data-wp-consent-category="' . esc_attr( $consent_cat ) . '" ', $tag );
	}

	if ( $nonce ) {
		$tag = str_replace( '<script ', '<script nonce="' . esc_attr( $nonce ) . '" ', $tag );
	}

	return $tag;
}
add_filter( 'script_loader_tag', 'dc_swp_script_loader_tag', 10, 2 );

add_action( 'wp_enqueue_scripts', 'dc_swp_enqueue_gtm', 5 );

/**
 * Enqueue the GTM / GA4 container script via the WordPress enqueue API.
 *
 * Registered at wp_enqueue_scripts priority 5 -- after the GCM v2 consent
 * default -- so the consent state is set before the container loads. The
 * dc_swp_script_loader_tag filter rewrites the emitted <script> tag to use
 * type="text/partytown" so the container runs in the Partytown web worker.
 *
 * @return void
 */
function dc_swp_enqueue_gtm(): void {
	if ( dc_swp_is_bot_request() || is_admin() ) {
		return;
	}
	$mode = get_option( 'dc_swp_gtm_mode', 'off' );
	if ( 'own' !== $mode && 'managed' !== $mode ) {
		return;
	}
	$tag_id = sanitize_text_field( get_option( 'dc_swp_gtm_id', '' ) );
	if ( empty( $tag_id ) || ! dc_swp_is_valid_gtm_id( $tag_id ) ) {
		return;
	}
	if ( dc_swp_is_excluded_url() ) {
		return;
	}

	// Ensure dataLayer and gtag() exist on the main thread before the Partytown
	// worker script loads. Registered as a virtual (no-src) handle so WP outputs
	// only the inline script (no external request).
	$dl_handle = 'dc-swp-gtm-datalayer';
	if ( ! wp_script_is( $dl_handle, 'registered' ) ) {
		wp_register_script( $dl_handle, false, array(), null, array( 'in_footer' => false ) );
		wp_add_inline_script( $dl_handle, 'window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}' );
	}
	wp_enqueue_script( $dl_handle );

	if ( 0 === stripos( $tag_id, 'GTM-' ) ) {
		// GTM container -- offloaded to Partytown web worker.
		$handle = 'dc-swp-gtm-container';
		$src    = 'https://www.googletagmanager.com/gtm.js?id=' . rawurlencode( $tag_id );
	} else {
		// GA4 / UA measurement ID -- offloaded to Partytown web worker.
		// The inline gtag('config',...) call runs on the main thread and is
		// forwarded to the worker via the dataLayer.push proxy.
		$handle  = 'dc-swp-gtag-js';
		$src     = 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $tag_id );
		$safe_id = esc_js( $tag_id );
		wp_add_inline_script( $handle, "gtag('js',new Date());gtag('config','" . $safe_id . "');", 'after' );
	}

	wp_register_script( $handle, $src, array( $dl_handle, 'dc-swp-partytown-config' ), null, array( 'in_footer' => false ) );
	wp_script_add_data( $handle, 'dc_swp_type', 'text/partytown' );
	wp_enqueue_script( $handle );
}

add_action( 'wp_body_open', 'dc_swp_inject_gtm_body', 1 );

/**
 * Inject the GTM <noscript> iframe fallback immediately after <body>.
 *
 * Only relevant for GTM-XXXXXXX container IDs (not GA4 measurement IDs).
 * Requires the active theme to call wp_body_open() -- supported by all
 * themes that follow the WordPress 5.2+ standards.
 *
 * @return void
 */
function dc_swp_inject_gtm_body() {
	if ( dc_swp_is_bot_request() || is_admin() ) {
		return;
	}
	$mode = get_option( 'dc_swp_gtm_mode', 'off' );
	if ( 'own' !== $mode && 'managed' !== $mode ) {
		return;
	}
	$tag_id = sanitize_text_field( get_option( 'dc_swp_gtm_id', '' ) );
	if ( empty( $tag_id ) || 0 !== stripos( $tag_id, 'GTM-' ) || ! dc_swp_is_valid_gtm_id( $tag_id ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tag ID regex-validated; esc_attr applied; static HTML template.
	echo '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . esc_attr( $tag_id ) . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n";
}

add_action( 'wp_ajax_dc_swp_detect_gtm', 'dc_swp_ajax_detect_gtm' );

/**
 * AJAX handler: detect a live Google Tag ID by scanning the homepage HTML.
 *
 * @return void
 */
function dc_swp_ajax_detect_gtm() {
	check_ajax_referer( 'dc_swp_detect_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
	}
	wp_send_json_success( dc_swp_detect_existing_gtm_id() );
}

// ============================================================
// META / FACEBOOK PIXEL -- MODE SYSTEM
// Mirrors the GTM 4-mode pattern (off / own / detect / managed).
// When pixel_mode is not 'off', dc_swp_inject_pixel_head() fires
// at wp_head priority 1 and injects the full Pixel base code
// (fbq stub + fbevents.js as text/partytown + init + PageView).
// The legacy dc_swp_inject_meta_ldu_default() is kept for sites
// that load Meta Pixel from another plugin -- it skips when the
// new mode is active to avoid double injection.
// ============================================================

/**
 * Detect a live Meta Pixel ID by fetching and parsing the homepage HTML.
 *
 * Scans for fbq('init','PIXEL_ID') inline calls and connect.facebook.net script tags.
 *
 * @return string First found Pixel ID (digits only), or '' if not found.
 */
function dc_swp_detect_existing_pixel_id(): string {
	$cached = get_transient( 'dc_swp_pixel_detect_result' );
	if ( false !== $cached ) {
		return (string) $cached;
	}

	$response = wp_remote_get(
		home_url( '/' ),
		array(
			'timeout'    => 15,
			'sslverify'  => true,
			'user-agent' => 'Mozilla/5.0 (DCSwPrefetch/1.0; Pixel-Detect)',
		)
	);

	$found = '';

	if ( ! is_wp_error( $response ) ) {
		$body = wp_remote_retrieve_body( $response );

		// 1. Script tag loading fbevents.js with a pixel_id query parameter.
		if ( '' === $found && preg_match( '/connect\.facebook\.net[^"\']*?[?&]pixel_id=(\d{10,20})/i', $body, $m ) ) {
			$found = $m[1];
		}

		// 2. Inline fbq init call using single or double quotes.
		if ( '' === $found && preg_match( '/fbq\s*\(\s*["\']init["\']\s*,\s*["\'](\d{10,20})["\']/i', $body, $m ) ) {
			$found = $m[1];
		}
	}

	set_transient( 'dc_swp_pixel_detect_result', $found, 5 * MINUTE_IN_SECONDS );
	return $found;
}

add_action( 'wp_ajax_dc_swp_detect_pixel', 'dc_swp_ajax_detect_pixel' );

/**
 * AJAX handler: detect a live Meta Pixel ID by scanning the homepage HTML.
 *
 * @return void
 */
function dc_swp_ajax_detect_pixel(): void {
	check_ajax_referer( 'dc_swp_detect_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
	}
	$id = dc_swp_detect_existing_pixel_id();
	wp_send_json_success(
		array(
			'id'    => $id,
			'found' => '' !== $id,
		)
	);
}

// ============================================================
// META / FACEBOOK PIXEL -- PIXEL HEAD INJECTION (new mode path)
// ============================================================

add_action( 'wp_enqueue_scripts', 'dc_swp_enqueue_pixel', 1 );

/**
 * Enqueue the Meta Pixel (fbevents.js) via the WordPress enqueue API.
 *
 * Emits the fbq stub as an inline script on the main thread, and registers
 * fbevents.js as a Partytown-offloaded external script. The dc_swp_script_loader_tag
 * filter rewrites its <script> tag to type="text/partytown".
 *
 * @return void
 */
function dc_swp_enqueue_pixel(): void {
	if ( dc_swp_is_bot_request() || is_admin() ) {
		return;
	}
	if ( get_option( 'dc_swp_sw_enabled', 'yes' ) !== 'yes' ) {
		return;
	}

	$pixel_mode = get_option( 'dc_swp_pixel_mode', 'off' );
	if ( 'off' === $pixel_mode ) {
		return;
	}

	$pixel_id = get_option( 'dc_swp_pixel_id', '' );
	if ( '' === $pixel_id || ! dc_swp_is_valid_pixel_id( $pixel_id ) ) {
		return;
	}

	if ( dc_swp_is_excluded_url() ) {
		return;
	}

	// If an enabled Script Block already loads fbevents.js via Partytown, skip
	// injecting a second copy -- two instances in the worker cause a proxy error.
	$fbevents_in_block = false;
	$raw_blocks        = get_option( 'dc_swp_inline_scripts', '' );
	if ( '' !== $raw_blocks ) {
		$blocks = json_decode( $raw_blocks, true );
		if ( is_array( $blocks ) ) {
			foreach ( $blocks as $blk ) {
				if ( ! empty( $blk['enabled'] ) && ! empty( $blk['src'] ) &&
					str_contains( (string) $blk['src'], 'connect.facebook.net' ) ) {
					$fbevents_in_block = true;
					break;
				}
			}
		}
	}

	$ldu_on        = dc_swp_is_meta_ldu_enabled();
	$consent_aware = dc_swp_is_consent_gate_enabled() && function_exists( 'wp_has_consent' );

	// -- fbq stub + optional LDU/consent signals (main thread, priority 1) ------
	$stub  = "window._fbq=window._fbq||[];\n";
	$stub .= "window.fbq=window.fbq||function(){\n";
	$stub .= "  window._fbq.push?window._fbq.push(arguments):window._fbq.que.push(arguments);\n";
	$stub .= "};\n";
	$stub .= "window.fbq.push=window.fbq;\n";
	$stub .= "window.fbq.loaded=true;\n";
	$stub .= "window.fbq.version='2.0';\n";
	$stub .= "window.fbq.queue=[];\n";

	if ( $consent_aware ) {
		if ( wp_has_consent( 'marketing' ) ) {
			$stub .= "fbq('consent','grant');\n";
			if ( $ldu_on ) {
				$stub .= "fbq('dataProcessingOptions',[],0,0);\n";
			}
		} else {
			$stub .= "fbq('consent','revoke');\n";
			if ( $ldu_on ) {
				$stub .= "fbq('dataProcessingOptions',['LDU'],0,0);\n";
			}
		}
	} elseif ( $ldu_on ) {
		$stub .= "fbq('dataProcessingOptions',['LDU'],0,0);\n";
	}

	$stub_handle = 'dc-swp-fbq-stub';
	if ( ! wp_script_is( $stub_handle, 'registered' ) ) {
		wp_register_script( $stub_handle, false, array(), null, array( 'in_footer' => false ) );
		wp_add_inline_script( $stub_handle, $stub );
	}
	wp_enqueue_script( $stub_handle );

	// -- fbevents.js via Partytown worker ----------------------------------------
	// Skip if a Script Block already loads fbevents.js -- loading it twice in the
	// Partytown worker causes "Cannot set properties of undefined (setting 'version')".
	if ( ! $fbevents_in_block ) {
		$safe_id = esc_js( $pixel_id );
		$handle  = 'dc-swp-fbevents';
		wp_register_script( $handle, 'https://connect.facebook.net/en_US/fbevents.js', array( $stub_handle, 'dc-swp-partytown-config' ), null, array( 'in_footer' => false ) );
		wp_script_add_data( $handle, 'dc_swp_type', 'text/partytown' );
		// Main-thread init + PageView forwarded to worker via fbq forward config.
		wp_add_inline_script( $handle, "fbq('init','" . $safe_id . "');fbq('track','PageView');", 'after' );
		wp_enqueue_script( $handle );
	}
}

/**
 * Auto-add connect.facebook.net to the Partytown proxy allowlist when the Pixel
 * mode is active, so the worker can fetch fbevents.js through the CORS proxy.
 *
 * Hooks into dc_swp_extra_proxy_hosts (same pattern as includes/integrations.php).
 */
add_filter(
	'dc_swp_extra_proxy_hosts',
	static function ( array $hosts ): array {
		$mode = get_option( 'dc_swp_pixel_mode', 'off' );
		if ( 'off' !== $mode ) {
			$id = get_option( 'dc_swp_pixel_id', '' );
			if ( '' !== $id && dc_swp_is_valid_pixel_id( $id ) ) {
				$hosts[] = 'connect.facebook.net';
			}
		}
		return $hosts;
	}
);

// ============================================================
// META / FACEBOOK PIXEL -- LIMITED DATA USE (LDU) MODE
// When enabled, injects the fbq stub + dataProcessingOptions
// at wp_head priority 1, before Partytown and any fbq scripts.
// The pixel always fires (type="text/partytown") and Meta
// applies LDU restrictions internally; the CMP does not need
// to block the script via text/plain.
// ============================================================

add_action( 'wp_head', 'dc_swp_inject_meta_ldu_default', 1 );

/**
 * Inject the Meta Pixel LDU initialization stub into <head>
 * before any Partytown or fbq scripts load.
 *
 * @return void
 */
function dc_swp_inject_meta_ldu_default() {
	if ( dc_swp_is_bot_request() ) {
		return;
	}
	if ( is_admin() ) {
		return;
	}
	if ( get_option( 'dc_swp_sw_enabled', 'yes' ) !== 'yes' ) {
		return;
	}

	// New pixel mode is active -- dc_swp_inject_pixel_head() handles everything.
	if ( get_option( 'dc_swp_pixel_mode', 'off' ) !== 'off' ) {
		return;
	}

	$ldu_on = dc_swp_is_meta_ldu_enabled();
	// WP Consent API is "active" when the gate is on AND the API function exists.
	$consent_aware = dc_swp_is_consent_gate_enabled() && function_exists( 'wp_has_consent' );

	// Nothing to inject if LDU is off and we have no consent API to signal.
	if ( ! $ldu_on && ! $consent_aware ) {
		return;
	}

	$nonce      = dc_swp_get_csp_nonce();
	$nonce_attr = '' !== $nonce ? ' nonce="' . esc_attr( $nonce ) . '"' : '';

	// Minimal fbq stub so calls below are queued before the full Pixel loads.
	$ldu_js  = "window._fbq=window._fbq||[];\n";
	$ldu_js .= "window.fbq=window.fbq||function(){\n";
	$ldu_js .= "  window._fbq.push?window._fbq.push(arguments):window._fbq.que.push(arguments);\n";
	$ldu_js .= "};\n";
	$ldu_js .= "window.fbq.push=window.fbq;\n";
	$ldu_js .= "window.fbq.loaded=true;\n";
	$ldu_js .= "window.fbq.version='2.0';\n";
	$ldu_js .= "window.fbq.queue=[];\n";

	if ( $consent_aware ) {
		// WP Consent API is active: emit per-visitor Consent Mode + conditional LDU.
		if ( wp_has_consent( 'marketing' ) ) {
			$ldu_js .= "fbq('consent','grant');\n";
			if ( $ldu_on ) {
				// Consented visitor — clear any LDU restriction.
				$ldu_js .= "fbq('dataProcessingOptions',[],0,0);\n";
			}
		} else {
			$ldu_js .= "fbq('consent','revoke');\n";
			if ( $ldu_on ) {
				$ldu_js .= "fbq('dataProcessingOptions',['LDU'],0,0);\n";
			}
		}
	} elseif ( $ldu_on ) {
		// No WP Consent API — apply LDU unconditionally (legacy behaviour).
		$ldu_js .= "fbq('dataProcessingOptions',['LDU'],0,0);\n";
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully static JS; nonce is pre-escaped via esc_attr.
	echo '<script' . $nonce_attr . ">\n" . $ldu_js . "</script>\n";
}

add_action( 'wp_enqueue_scripts', 'dc_swp_partytown_config', 2 );

/**
 * Return a stable per-request CSP nonce for Partytown inline scripts.
 *
 * The same nonce is reused for both the config <script> and the snippet
 * <script>, and is passed to window.partytown.nonce so Partytown stamps it
 * on every worker script it creates. CSP-hardened sites can read this value
 * via the 'dc_swp_csp_nonce' filter and include it in their
 * Content-Security-Policy: script-src 'nonce-...' header.
 *
 * @return string Base64-safe nonce, or empty string on failure.
 */
function dc_swp_get_csp_nonce() {
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
 * Detect whether FullStory is configured in the Partytown Script List or Inline Script Blocks.
 *
 * Used to auto-enable strictProxyHas -- required when FullStory is loaded via GTM to prevent
 * false namespace-conflict detection caused by Partytown's default `in` operator behaviour.
 * Follows the same dual-source scan pattern as dc_swp_get_proxy_allowed_hosts().
 *
 * @return bool
 */
function dc_swp_has_fullstory_configured() {
	// -- 1. Script List patterns ----------------------------------------------
	foreach ( dc_swp_get_partytown_patterns() as $pattern ) {
		if ( str_contains( strtolower( $pattern ), 'fullstory' ) ) {
			return true;
		}
	}

	// -- 2. Inline Script Blocks ----------------------------------------------
	$raw_stored = (string) get_option( 'dc_swp_inline_scripts', '' );
	if ( '' !== $raw_stored ) {
		$decoded = json_decode( $raw_stored, true );
		if ( is_array( $decoded ) ) {
			foreach ( $decoded as $blk ) {
				if ( ! empty( $blk['enabled'] ) && str_contains( strtolower( (string) ( $blk['code'] ?? '' ) ), 'fullstory' ) ) {
					return true;
				}
			}
		}
	}

	return false;
}

/**
 * Check whether FullStory is configured via the Integrations tab.
 *
 * Extends the auto-detection in dc_swp_has_fullstory_configured() so that
 * strictProxyHas is enabled when FullStory is set up through the dedicated
 * Integrations settings, not only when the user manually adds a FullStory
 * snippet to the Script List or Inline Blocks.
 *
 * @return bool
 */
function dc_swp_has_fullstory_via_integrations(): bool {
	return '' !== get_option( 'dc_swp_fullstory_org_id', '' );
}

/**
 * Emit the Partytown config object and the inline snippet in <head>.
 * Must run before any type="text/partytown" scripts.
 */
function dc_swp_partytown_config() {
	if ( dc_swp_is_bot_request() ) {
		return;
	}
	if ( is_admin() ) {
		return;
	}

	$pt_enabled = get_option( 'dc_swp_sw_enabled', 'yes' ) === 'yes';
	if ( ! $pt_enabled ) {
		return;
	}

	if ( dc_swp_is_excluded_url() ) {
		return;
	}

	$debug_mode = get_option( 'dc_swp_debug_mode', 'no' ) === 'yes';

	// Inline Partytown snippet -- serves workers from /~partytown/.
	// Debug mode uses the unminified build from assets/partytown/debug/; the serve
	// endpoint already handles /~partytown/debug/* via its realpath security check.
	$snippet_file = plugin_dir_path( __FILE__ ) . 'assets/partytown/' . ( $debug_mode ? 'debug/' : '' ) . 'partytown.js';
	if ( ! file_exists( $snippet_file ) ) {
		return;
	}

	global $wp_filesystem;
	if ( empty( $wp_filesystem ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
	}
	if ( empty( $wp_filesystem ) ) {
		return;
	}
	$snippet = $wp_filesystem->get_contents( wp_normalize_path( $snippet_file ) );

	// Build the Partytown config as a PHP structure so it is always valid JSON.
	// forward list -- only officially tested services from https://partytown.qwik.dev/common-services/
	// Note: 'gtag' is intentionally excluded -- it is defined as an inline wrapper that calls
	// dataLayer.push(), which is already forwarded. Forwarding 'gtag' separately is redundant.
	// 'lintrk' (LinkedIn) and 'twq' (Twitter/X) are excluded -- not on the officially tested list.
	$config = array(
		'lib'     => '/~partytown/' . ( $debug_mode ? 'debug/' : '' ),
		'debug'   => $debug_mode,
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
			'FS.identify',    // FullStory.
			'FS.event',
		),
	);

	// Feature 4: loadScriptsOnMainThread -- scripts dynamically injected inside the
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
	// Feature 2: Pass a per-request CSP nonce into the Partytown config.
	// Partytown will stamp this nonce on every <script> element it creates,
	// allowing sites with strict CSP to whitelist exactly these scripts.
	$nonce = dc_swp_get_csp_nonce();
	if ( '' !== $nonce ) {
		$config['nonce'] = $nonce;
	}

	// Auto-enable strictProxyHas when FullStory is configured -- required to prevent
	// the `in` operator from falsely reporting a namespace conflict that blocks
	// FullStory initialisation when loaded via a GTM Custom HTML tag.
	if ( dc_swp_has_fullstory_configured() || dc_swp_has_fullstory_via_integrations() ) {
		$config['strictProxyHas'] = true;
	}

	// Debug mode: enable all seven Partytown log flags for DevTools Verbose output.
	if ( $debug_mode ) {
		$config['logCalls']              = true;
		$config['logGetters']            = true;
		$config['logSetters']            = true;
		$config['logImageRequests']      = true;
		$config['logScriptExecution']    = true;
		$config['logSendBeaconRequests'] = true;
		$config['logStackTraces']        = true;
	}

	// Path-rewrite map: auto-detected from the known-services lookup table based
	// on which hostnames are active in the Script List. Developer overrides via
	// the 'dc_swp_partytown_path_rewrites' filter (handled inside dc_swp_build_path_rewrites).
	$path_rewrites = dc_swp_build_path_rewrites();

	$coi_active = get_option( 'dc_swp_coi_headers', 'no' ) === 'yes';

	// Partytown config must load in <head> before any type="text/partytown" scripts.
	// partytown-config.js reads dcSwpPartytownData (injected below) to set window.partytown
	// and window.partytown.resolveUrl. resolveUrl uses only `this.*` properties (not the
	// dcSwpPartytownData variable) so it remains self-contained after Partytown serialises
	// it to a string and reconstructs it with new Function() inside the web worker.
	wp_register_script( 'dc-swp-partytown-config', plugins_url( 'assets/js/partytown-config.js', __FILE__ ), array(), DC_SWP_VERSION, array( 'in_footer' => false ) );
	wp_localize_script(
		'dc-swp-partytown-config',
		'dcSwpPartytownData',
		array(
			'config'            => $config,
			'pathRewrites'      => $path_rewrites,
			'proxyUrl'          => home_url( '/~partytown-proxy' ),
			'proxyAllowedHosts' => dc_swp_get_proxy_allowed_hosts(),
			'isSafePage'        => dc_swp_is_safe_page(),
		)
	);
	wp_enqueue_script( 'dc-swp-partytown-config' );

	// Partytown inline snippet -- initializes the service worker bridge.
	// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- inline-only handle, no file to version.
	wp_register_script( 'dc-swp-partytown', false, array( 'dc-swp-partytown-config' ), null, array( 'in_footer' => false ) );
	wp_add_inline_script( 'dc-swp-partytown', $snippet );
	wp_enqueue_script( 'dc-swp-partytown' );

	// When Cross-Origin isolation (COEP) is active, every cross-origin iframe
	// needs the `credentialless` attribute before its src navigation begins --
	// otherwise the browser enforces CORP and blocks it (e.g. Trustpilot).
	//
	// A MutationObserver fires too late: the browser starts the iframe navigation
	// synchronously when the element is appended with a src, before any microtask
	// can run. We therefore intercept HTMLIFrameElement.prototype src setter and
	// setAttribute so `credentialless` is stamped BEFORE the src is applied.
	// The MutationObserver is kept as a fallback for iframes inserted via innerHTML
	// or DOMParser, where navigation is deferred to a separate task.
	if ( $coi_active ) {
		wp_register_script( 'dc-swp-coi-iframe', plugins_url( 'assets/js/coi-iframe.js', __FILE__ ), array( 'dc-swp-partytown' ), DC_SWP_VERSION, array( 'in_footer' => false ) );
		wp_enqueue_script( 'dc-swp-coi-iframe' );
	}

	// Stamp the CSP nonce on all Partytown inline scripts for strict-dynamic CSP compatibility.
	if ( '' !== $nonce ) {
		$pt_handles = $coi_active
			? array( 'dc-swp-partytown-config', 'dc-swp-partytown', 'dc-swp-coi-iframe' )
			: array( 'dc-swp-partytown-config', 'dc-swp-partytown' );
		add_filter(
			'wp_inline_script_attributes',
			static function ( array $attrs ) use ( $nonce, $pt_handles ) {
				$id = $attrs['id'] ?? '';
				foreach ( $pt_handles as $h ) {
					if ( str_starts_with( $id, $h . '-js' ) ) {
						$attrs['nonce'] = $nonce;
						break;
					}
				}
				return $attrs;
			}
		);
	}
}


// ============================================================
// PARTYTOWN SCRIPT OFFLOADING
// Reads the admin-configured list of URL patterns and marks
// matching <script src="..."> tags as type="text/partytown" at
// runtime via the wp_script_attributes filter -- no manual code
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
/**
 * Canonical list of third-party script hostnames verified to work with Partytown.
 * Sourced from https://partytown.qwik.dev/common-services.
 *
 * Used by:
 *  - The Script Block output logic (dc_swp_url_matches_known_service()) to decide
 *    whether a pasted src= script should run in the worker or on the main thread.
 *  - The autodetect AJAX handler to badge detected scripts as known/unknown.
 *
 * @return string[]
 */
function dc_swp_get_known_services() {
	return array(
		'googletagmanager.com',
		'google-analytics.com',
		'analytics.google.com',
		'connect.facebook.net',
		'js.hs-scripts.com',
		'js.hsforms.net',
		'js.hscollectedforms.net',
		'js.hubspot.com',
		'widget.intercom.io',
		'js.intercomcdn.com',
		'static.klaviyo.com',
		'analytics.tiktok.com',
		'cdn.mxpnl.com',
		'cdn4.mxpnl.com',
		'clarity.ms',
		'static.hotjar.com',
		'script.hotjar.com',
		'snap.licdn.com',
		'static.ads-twitter.com',
		'cdn.segment.com',
	);
}

/**
 * Return a flat hostname → WP Consent API category map for the admin JS.
 *
 * Exposes the same mapping used by dc_swp_get_service_category() so that
 * the admin page can suggest the correct category when a new script list
 * entry is added (via auto-detect or manually).
 *
 * @return array<string, string> hostname_substring => category
 */
function dc_swp_get_service_category_map() {
	return array(
		'js.hs-scripts.com'       => 'marketing',
		'js.hsforms.net'          => 'marketing',
		'js.hscollectedforms.net' => 'marketing',
		'js.hubspot.com'          => 'marketing',
		'static.klaviyo.com'      => 'marketing',
		'static.ads-twitter.com'  => 'marketing',
		'snap.licdn.com'          => 'marketing',
		'googletagmanager.com'    => 'marketing',
		'google-analytics.com'    => 'statistics',
		'analytics.google.com'    => 'statistics',
		'connect.facebook.net'    => 'marketing',
		'analytics.tiktok.com'    => 'marketing',
		'cdn.mxpnl.com'           => 'statistics',
		'cdn4.mxpnl.com'          => 'statistics',
		'cdn.segment.com'         => 'statistics',
		'static.hotjar.com'       => 'statistics',
		'script.hotjar.com'       => 'statistics',
		'clarity.ms'              => 'statistics',
		'widget.intercom.io'      => 'functional',
		'js.intercomcdn.com'      => 'functional',
	);
}

/**
 * Return true if $url's hostname is on Partytown's verified-compatible service list.
 *
 * Used exclusively by the Script Block output logic to decide whether an
 * explicitly pasted src= script should run in the Partytown worker or on the
 * main thread. Autodetection (buffer rewrite / wp_script_attributes) has its
 * own pattern loop and never calls this function.
 *
 * @param string $url Absolute URL to test.
 * @return bool
 */
function dc_swp_url_matches_known_service( $url ) {
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	foreach ( dc_swp_get_known_services() as $pattern ) {
		if ( false !== stripos( $host, $pattern ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Return true if a raw inline script body references any Partytown-verified service.
 *
 * Scans for https?:// URLs embedded as string literals in the code (e.g. Meta Pixel,
 * GTM snippet) and checks each hostname against dc_swp_get_known_services().
 *
 * @param string $code Raw inline JS content (between <script> tags).
 * @return bool
 */
function dc_swp_inline_matches_known_service( $code ) {
	if ( ! preg_match_all( '/https?:\/\/([a-zA-Z0-9][a-zA-Z0-9.\-]+)/i', $code, $m ) ) {
		return false;
	}
	foreach ( $m[1] as $host ) {
		$host = strtolower( $host );
		foreach ( dc_swp_get_known_services() as $pattern ) {
			if ( false !== stripos( $host, $pattern ) ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Return the list of third-party script hostnames that natively implement
 * Google Consent Mode v2 (GCM v2).
 *
 * When GCM v2 is active in the plugin, scripts for these services are always
 * offloaded via Partytown -- each service reads the GCM v2 consent state
 * (analytics_storage, ad_storage, etc.) and self-restricts data collection
 * when consent is denied, so no text/plain gate is needed.
 *
 * Meta/Facebook Pixel is intentionally excluded: it uses its own proprietary
 * LDU consent mechanism, not the GCM v2 API.
 *
 * Use the 'dc_swp_gcm_v2_aware_services' filter to add custom entries.
 *
 * @return string[] Lowercase hostname substrings.
 */
function dc_swp_get_gcm_v2_aware_services() {
	$services = array(
		'googletagmanager.com', // Google Tag Manager -- owns the GCM v2 API.
		'google-analytics.com', // Google Analytics (UA / GA4).
		'analytics.google.com', // Google Analytics (GA4 data collection endpoint).
		'static.hotjar.com',    // Hotjar -- respects analytics_storage since 2024.
		'script.hotjar.com',    // Hotjar (alternate CDN).
		'clarity.ms',           // Microsoft Clarity -- native GCM v2 integration.
		'snap.licdn.com',       // LinkedIn Insight Tag v3 -- GCM v2 support.
		'analytics.tiktok.com', // TikTok Pixel -- GCM v2 support.
	);

	/**
	 * Filter the list of GCM v2-aware service hostnames.
	 *
	 * Add custom services whose scripts natively check the GCM v2 consent state
	 * and restrict data collection on their own when consent is denied.
	 *
	 * @param string[] $services Lowercase hostname substrings.
	 */
	return (array) apply_filters(
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		'dc_swp_gcm_v2_aware_services',
		$services
	);
}

/**
 * Return true if a script src URL belongs to a GCM v2-aware service.
 *
 * Used to decide whether a script may bypass the CMP marketing-consent cookie
 * gate when Google Consent Mode v2 is active in the plugin.
 *
 * @param string $url Script src URL to test.
 * @return bool
 */
function dc_swp_script_uses_gcm_v2( $url ) {
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	foreach ( dc_swp_get_gcm_v2_aware_services() as $service ) {
		if ( false !== stripos( $host, $service ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Return true if a script src URL belongs to the Meta/Facebook Pixel CDN.
 *
 * Meta Pixel does not support GCM v2; it uses its own Limited Data Use (LDU)
 * consent mechanism. This helper gates LDU bypass on the correct service.
 *
 * @param string $url Script src URL to test.
 * @return bool
 */
function dc_swp_is_meta_script( $url ) {
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	return str_contains( $host, 'connect.facebook.net' );
}

/**
 * Return true if an inline script body references a GCM v2-aware service URL.
 *
 * Scans for https?:// URLs embedded in the code and checks each hostname against
 * dc_swp_get_gcm_v2_aware_services(). Works reliably for standard inline snippets
 * (GTM, Hotjar, LinkedIn, TikTok) which all embed their CDN URL.
 *
 * @param string $code Inline JS content.
 * @return bool
 */
function dc_swp_inline_uses_gcm_v2( $code ) {
	if ( ! preg_match_all( '/https?:\/\/([a-zA-Z0-9][a-zA-Z0-9.\-]+)/i', $code, $m ) ) {
		return false;
	}
	foreach ( $m[1] as $host ) {
		$host = strtolower( $host );
		foreach ( dc_swp_get_gcm_v2_aware_services() as $service ) {
			if ( false !== stripos( $host, $service ) ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Return true if an inline script body references the Meta/Facebook Pixel.
 *
 * Checks for connect.facebook.net or the fbevents script name that appears in
 * the standard Meta Pixel inline snippet.
 *
 * @param string $code Inline JS content.
 * @return bool
 */
function dc_swp_inline_is_meta( $code ) {
	return str_contains( $code, 'connect.facebook.net' ) || str_contains( $code, 'fbevents' );
}

/**
 * Return the Script List entries as structured objects.
 *
 * Each entry is an associative array with keys:
 *   - 'pattern'  (string) URL substring to match against script src.
 *   - 'category' (string) WP Consent API category for this pattern.
 *
 * Handles two storage formats transparently:
 *   - New: JSON array of {pattern, category} objects (since 1.10.0).
 *   - Legacy: plain newline-separated pattern strings (migrates to new format).
 *
 * @param bool $reset If true, clear the static cache and re-parse the option.
 * @return array<int, array{pattern: string, category: string}>
 */
function dc_swp_get_script_list_entries( bool $reset = false ) {
	static $entries = null;
	if ( $reset ) {
		$entries = null;
		wp_cache_delete( 'script_list_entries', 'dc_swp' );
	}
	if ( null !== $entries ) {
		return $entries;
	}
	$cached = wp_cache_get( 'script_list_entries', 'dc_swp' );
	if ( false !== $cached ) {
		$entries = $cached;
		return $entries;
	}
	$valid_cats = array( 'marketing', 'statistics', 'statistics-anonymous', 'functional', 'preferences' );
	$raw        = (string) get_option( 'dc_swp_partytown_scripts', '' );
	$entries    = array();
	if ( '' !== $raw ) {
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			// New JSON format.
			foreach ( $decoded as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$pattern = trim( (string) ( $item['pattern'] ?? '' ) );
				if ( '' === $pattern ) {
					continue;
				}
				$cat       = $item['category'] ?? '';
				$entries[] = array(
					'pattern'  => $pattern,
					'category' => in_array( $cat, $valid_cats, true ) ? $cat : 'marketing',
				);
			}
		} else {
			// Legacy plain-text format -- migrate in memory; no DB write here
			// (admin save will persist the new format once the user next saves).
			foreach ( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) as $line ) {
				if ( '' !== $line ) {
					$entries[] = array(
						'pattern'  => $line,
						'category' => dc_swp_get_script_list_category(),
					);
				}
			}
		}
	}
	wp_cache_set( 'script_list_entries', $entries, 'dc_swp', HOUR_IN_SECONDS );
	return $entries;
}

/**
 * Return the list of Partytown include patterns from the admin option.
 *
 * @return string[]
 */
function dc_swp_get_partytown_patterns() {
	static $patterns = null;
	if ( null !== $patterns ) {
		return $patterns;
	}
	$cached = wp_cache_get( 'patterns', 'dc_swp' );
	if ( false !== $cached ) {
		$patterns = $cached;
		return $patterns;
	}
	$patterns = array_column( dc_swp_get_script_list_entries(), 'pattern' );
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
 * proxy -- no hard-coded vendor list.
 *
 * @return string[] Lowercase, unique hostnames eligible for proxy.
 */
function dc_swp_get_proxy_allowed_hosts() {
	// Static memoisation: consistent with dc_swp_get_partytown_patterns().
	// Mid-request option updates clear the object cache on the next request via
	// dc_swp_bust_page_cache(); the static variable intentionally holds for the
	// duration of the current request.
	static $hosts = null;
	if ( null !== $hosts ) {
		return $hosts;
	}

	$hosts = array();

	// -- 1. Script List patterns ----------------------------------------------
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

	// -- 2. Script Blocks (inline scripts) -----------------------------------
	// Inline snippets (e.g. Meta Pixel, TikTok Pixel) usually embed the CDN
	// URL directly -- extract every https:// hostname that appears in the code.
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

	// Allow integrations and other modules to register CDN hosts for auto-injected
	// services (e.g. TikTok Pixel, HubSpot, Klaviyo) without requiring users to
	// manually add each CDN to the Script List.
	$extra = (array) apply_filters( 'dc_swp_extra_proxy_hosts', array() );
	if ( ! empty( $extra ) ) {
		$hosts = array_values( array_unique( array_merge( $hosts, $extra ) ) );
	}

	return $hosts;
}

/**
 * Return patterns derived from the auto-detect GTM configuration.
 *
 * When the admin has selected "detect" mode and a valid Google Tag ID has been
 * saved, the other plugin (Site Kit, GTM4WP, MonsterInsights, etc.) owns the
 * script injection. We return 'googletagmanager.com' as a virtual pattern so
 * that both wp_script_attributes and the output-buffer rewriter will intercept
 * those scripts and rewrite them to type="text/partytown".
 *
 * Returns an empty array when detect mode is not active or no ID is saved.
 *
 * @return string[]
 */
function dc_swp_get_auto_detect_patterns(): array {
	if ( get_option( 'dc_swp_gtm_mode', 'off' ) !== 'detect' ) {
		return array();
	}
	$id = sanitize_text_field( get_option( 'dc_swp_gtm_id', '' ) );
	if ( empty( $id ) || ! dc_swp_is_valid_gtm_id( $id ) ) {
		return array();
	}
	return array( 'googletagmanager.com' );
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
function dc_swp_partytown_script_attrs( $attributes ) {
	if ( dc_swp_is_bot_request() ) {
		return $attributes;
	}
	$src = $attributes['src'] ?? '';
	if ( ! $src ) {
		return $attributes;
	}

	if ( dc_swp_is_excluded_url() ) {
		return $attributes;
	}

	if ( get_option( 'dc_swp_sw_enabled', 'yes' ) !== 'yes' ) {
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

	// GDPR guard: if an upstream filter (rare at priority 5 but possible) has already
	// set a non-standard type, leave it alone so the CMP's blocking is not disturbed.
	$current_type = strtolower( $attributes['type'] ?? '' );
	if ( '' !== $current_type && 'text/javascript' !== $current_type ) {
		return $attributes;
	}
	$all_patterns = array_merge( dc_swp_get_partytown_patterns(), dc_swp_get_auto_detect_patterns() );
	foreach ( $all_patterns as $pattern ) {
		if ( '' !== $pattern && str_contains( $src, $pattern ) ) {
			// Per-service consent gate via WP Consent API.
			[ $allowed, $cat ]  = dc_swp_resolve_script_consent( $src );
			$attributes['type'] = $allowed ? 'text/partytown' : 'text/plain';
			if ( ! $allowed ) {
				$attributes['data-wp-consent-category'] = $cat;
			}
			unset( $attributes['async'] ); // async is meaningless for Partytown scripts and must be removed.
			break; // First matched pattern wins -- no need to continue.
		}
	}
	return $attributes;
}

add_filter( 'wp_script_attributes', 'dc_swp_partytown_script_attrs_disabled', 9999 );

/**
 * Late-priority fallback (runs after CMP plugins at priority ~10) that restores
 * executability for Script List patterns when Partytown is in diagnostic/disabled mode.
 *
 * When Partytown is enabled, consent management is handled exclusively by
 * dc_swp_partytown_script_attrs() at priority 5 (before any CMP). When Partytown
 * is DISABLED the admin has chosen "diagnostic mode" which the UI describes as
 * "no consent gating" -- scripts render on the main thread. However, CMPs still run
 * their own wp_script_attributes hook (priority ~10) and stamp type="text/plain" on
 * scripts they recognise, silently blocking them. By running at priority 9999 we
 * override that and guarantee matched scripts are executable in diagnostic mode.
 *
 * Note: raw HTML scripts (not WP-enqueued) bypass wp_script_attributes entirely,
 * which is why the admin reports they "already work" -- this hook is the symmetric
 * fix for WP-enqueued scripts.
 *
 * @param array $attributes Script element attributes.
 * @return array Modified attributes.
 */
function dc_swp_partytown_script_attrs_disabled( $attributes ) {
	if ( get_option( 'dc_swp_sw_enabled', 'yes' ) === 'yes' ) {
		return $attributes; // Partytown enabled -- priority-5 hook owns this path entirely.
	}
	if ( dc_swp_is_bot_request() ) {
		return $attributes;
	}
	$src = $attributes['src'] ?? '';
	if ( ! $src ) {
		return $attributes;
	}
	foreach ( dc_swp_get_partytown_patterns() as $pattern ) {
		if ( '' !== $pattern && str_contains( $src, $pattern ) ) {
			// Diagnostic mode: remove any type the CMP stamped so the browser executes
			// the script on the main thread. Also ensure defer (no-async) is set.
			unset( $attributes['type'] );
			$attributes['defer'] = true;
			unset( $attributes['async'] );
			break;
		}
	}
	return $attributes;
}

// Bust the in-request static cache, object cache, and all known page caches when settings change.
add_action( 'update_option_dc_swp_partytown_scripts', 'dc_swp_bust_page_cache' );
add_action( 'update_option_dc_swp_inline_scripts', 'dc_swp_bust_page_cache' );
add_action( 'update_option_dc_swp_gtm_mode', 'dc_swp_bust_page_cache' );
add_action( 'update_option_dc_swp_gtm_id', 'dc_swp_bust_page_cache' );
add_action( 'update_option_dc_swp_pixel_mode', 'dc_swp_bust_page_cache' );
add_action( 'update_option_dc_swp_pixel_id', 'dc_swp_bust_page_cache' );
add_action( 'update_option_dc_swp_exclusion_patterns', 'dc_swp_bust_page_cache' );

/**
 * Delete all object-cache pattern keys and flush page caches for popular caching plugins,
 * so stale cached HTML with old script type= attributes is never served after a settings change.
 *
 * Covers: W3 Total Cache, WP Rocket, LiteSpeed Cache, WP Super Cache,
 * Nginx Helper, SG Optimizer, and WP Fastest Cache.
 */
function dc_swp_bust_page_cache() {
	wp_cache_delete( 'patterns', 'dc_swp' );
	delete_transient( 'dc_swp_gcm_conflict_result' );
	delete_transient( 'dc_swp_pixel_detect_result' );

	// W3 Total Cache.
	if ( function_exists( 'w3tc_pgcache_flush' ) ) {
		w3tc_pgcache_flush();
	}
	// WP Rocket.
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}
	// LiteSpeed Cache.
	if ( class_exists( 'LiteSpeed_Cache_API' ) ) {
		LiteSpeed_Cache_API::purge_all();
	}
	// WP Super Cache.
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
	}
	// Nginx Helper.
	if ( class_exists( 'Nginx_Helper' ) ) {
		do_action( 'rt_nginx_helper_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Nginx Helper's own action.
	}
	// SG Optimizer (SiteGround).
	if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
		sg_cachepress_purge_cache();
	}
	// WP Fastest Cache.
	if ( function_exists( 'wpfc_clear_all_cache' ) ) {
		wpfc_clear_all_cache();
	}
}


// ============================================================
// FEATURE 1 -- EARLY RESOURCE HINTS
// Auto-injects <link rel="preconnect"> and <link rel="dns-prefetch">
// for every unique third-party hostname configured in the Script List,
// Inline Blocks, and GTM detect mode. Reduces TCP+TLS latency for
// first-time visitors before the Partytown worker makes any fetch.
// ============================================================

/**
 * Collect the unique third-party hostnames that should receive resource hints.
 *
 * Pulls hostnames from dc_swp_get_proxy_allowed_hosts() (Script List + Inline
 * Blocks) and, when GTM mode is own/managed/detect with a valid tag ID,
 * appends www.googletagmanager.com. The site's own hostname is excluded.
 *
 * @since 2.0.0
 * @return string[] Deduplicated lowercase hostname array.
 */
function dc_swp_get_resource_hint_hosts(): array {
	$hosts    = dc_swp_get_proxy_allowed_hosts();
	$gtm_mode = get_option( 'dc_swp_gtm_mode', 'off' );
	$gtm_id   = sanitize_text_field( get_option( 'dc_swp_gtm_id', '' ) );

	if ( in_array( $gtm_mode, array( 'own', 'managed', 'detect' ), true ) && ! empty( $gtm_id ) && dc_swp_is_valid_gtm_id( $gtm_id ) ) {
		$hosts[] = 'www.googletagmanager.com';
	}

	$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$hosts     = array_filter(
		array_unique( array_map( 'strtolower', $hosts ) ),
		static function ( $h ) use ( $site_host ) {
			return '' !== $h && $h !== $site_host;
		}
	);

	return array_values( $hosts );
}

add_action( 'wp_head', 'dc_swp_inject_resource_hints', 2 );

/**
 * Emit <link rel="preconnect"> and <link rel="dns-prefetch"> tags for every
 * configured third-party hostname.
 *
 * Priority 2: after the GCM v2 stub at priority 1, before GTM injection at 5.
 *
 * @since 2.0.0
 * @return void
 */
function dc_swp_inject_resource_hints(): void {
	if ( dc_swp_is_bot_request() ) {
		return;
	}
	if ( is_admin() ) {
		return;
	}
	if ( get_option( 'dc_swp_sw_enabled', 'yes' ) !== 'yes' ) {
		return;
	}
	if ( get_option( 'dc_swp_resource_hints', 'yes' ) !== 'yes' ) {
		return;
	}
	if ( dc_swp_is_excluded_url() ) {
		return;
	}

	foreach ( dc_swp_get_resource_hint_hosts() as $host ) {
		echo '<link rel="preconnect" href="' . esc_url( 'https://' . $host ) . '" crossorigin />' . "\n";
		echo '<link rel="dns-prefetch" href="//' . esc_attr( $host ) . '" />' . "\n";
	}
}


// ============================================================
// FEATURE 2 -- PARTYTOWN HEALTH MONITOR
// Detects when a configured third-party service fails silently
// inside the Partytown worker and surfaces an admin notice.
// ============================================================

add_action( 'wp_enqueue_scripts', 'dc_swp_enqueue_health_monitor', 20 );

/**
 * Enqueue the Partytown health monitor JS on the front-end.
 *
 * Uses PerformanceObserver to track resource entries and reports
 * any configured hostnames that produced no network traffic after
 * a 15-second window to the dc_swp_health_report AJAX handler.
 *
 * @since 2.1.0
 * @return void
 */
function dc_swp_enqueue_health_monitor(): void {
	if ( dc_swp_is_bot_request() ) {
		return;
	}
	if ( is_admin() ) {
		return;
	}
	if ( get_option( 'dc_swp_sw_enabled', 'yes' ) !== 'yes' ) {
		return;
	}
	if ( get_option( 'dc_swp_health_monitor', 'yes' ) !== 'yes' ) {
		return;
	}
	if ( empty( dc_swp_get_partytown_patterns() ) ) {
		return;
	}

	wp_register_script(
		'dc-swp-health-monitor',
		plugins_url( 'assets/js/health-monitor.js', __FILE__ ),
		array(),
		DC_SWP_VERSION,
		array( 'in_footer' => true )
	);
	wp_localize_script(
		'dc-swp-health-monitor',
		'dcSwpHealthData',
		array(
			'hosts'   => dc_swp_get_proxy_allowed_hosts(),
			'nonce'   => wp_create_nonce( 'dc_swp_health_nonce' ),
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'timeout' => 15000,
		)
	);
	wp_enqueue_script( 'dc-swp-health-monitor' );
}

add_action( 'wp_ajax_dc_swp_health_report', 'dc_swp_ajax_health_report' );
add_action( 'wp_ajax_nopriv_dc_swp_health_report', 'dc_swp_ajax_health_report' );

/**
 * AJAX handler: receive a health-monitor failure report from the front-end.
 *
 * Anonymous -- no cap check required. Nonce + allowlist validation is sufficient.
 * Appends the failing hostname to the dc_swp_health_issues transient (24-hour TTL).
 *
 * Security note: this endpoint is intentionally public (wp_ajax_nopriv). The nonce
 * is embedded in every page load, making it effectively public knowledge.
 * Mitigation: per-IP/60 s rate-limit transient + hostname allowlist restricts what
 * data can be written and prevents single-source flooding. Distributed abuse is
 * accepted as low-risk given the low sensitivity of the stored data (hostnames only).
 *
 * @since 2.1.0
 * @return void
 */
function dc_swp_ajax_health_report(): void {
	check_ajax_referer( 'dc_swp_health_nonce', 'nonce' );

	// Rate-limit: one write per IP per 60 seconds to prevent transient flooding.
	$rl_key = 'dc_swp_hl_rl_' . md5( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ) );
	if ( get_transient( $rl_key ) ) {
		wp_send_json_success();
		return;
	}
	set_transient( $rl_key, 1, 60 );

	$host = sanitize_text_field( wp_unslash( $_POST['host'] ?? '' ) );
	if ( '' === $host ) {
		wp_send_json_error( array( 'message' => 'Missing host' ), 400 );
	}

	// Validate: host must be in the configured allowlist.
	if ( ! in_array( $host, dc_swp_get_proxy_allowed_hosts(), true ) ) {
		wp_send_json_error( array( 'message' => 'Invalid host' ), 400 );
	}

	$issues   = get_transient( 'dc_swp_health_issues' );
	$issues   = is_array( $issues ) ? $issues : array();
	$issues[] = $host;
	$issues   = array_unique( $issues );
	set_transient( 'dc_swp_health_issues', $issues, DAY_IN_SECONDS );

	wp_send_json_success();
}


// ============================================================
// FEATURE 3 -- PERFORMANCE METRICS DASHBOARD
// Collects anonymous front-end TBT and INP measurements and
// exposes rolling averages + P75 percentiles in the WP admin.
// ============================================================

add_action( 'wp_enqueue_scripts', 'dc_swp_enqueue_perf_reporter', 20 );

/**
 * Enqueue the front-end performance reporter script.
 *
 * Tracks TBT (Total Blocking Time) via PerformanceObserver longtask and
 * INP (Interaction to Next Paint) via PerformanceObserver event, then
 * POSTs anonymised values to the dc_swp_perf_report AJAX endpoint.
 *
 * @since 2.2.0
 * @return void
 */
function dc_swp_enqueue_perf_reporter(): void {
	if ( dc_swp_is_bot_request() ) {
		return;
	}
	if ( is_admin() ) {
		return;
	}
	if ( get_option( 'dc_swp_sw_enabled', 'yes' ) !== 'yes' ) {
		return;
	}
	if ( get_option( 'dc_swp_perf_monitor', 'yes' ) !== 'yes' ) {
		return;
	}

	wp_register_script(
		'dc-swp-perf-reporter',
		plugins_url( 'assets/js/perf-reporter.js', __FILE__ ),
		array(),
		DC_SWP_VERSION,
		array( 'in_footer' => true )
	);
	wp_localize_script(
		'dc-swp-perf-reporter',
		'dcSwpPerfData',
		array(
			'nonce'      => wp_create_nonce( 'dc_swp_perf_nonce' ),
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'sessionKey' => 'dc_swp_perf_reported',
		)
	);
	wp_enqueue_script( 'dc-swp-perf-reporter' );
}

add_action( 'wp_ajax_dc_swp_perf_report', 'dc_swp_ajax_perf_report' );
add_action( 'wp_ajax_nopriv_dc_swp_perf_report', 'dc_swp_ajax_perf_report' );

/**
 * AJAX handler: receive a performance measurement from the front-end.
 *
 * Anonymous -- no cap check required. Updates rolling averages and a sliding
 * window of 100 samples (for P75 computation) in non-autoloaded WP options.
 *
 * Security note: this endpoint is intentionally public (wp_ajax_nopriv). The nonce
 * is embedded in every page load, making it effectively public knowledge.
 * Mitigation: per-IP/60 s rate-limit transient prevents single-source flooding.
 * TBT/INP values are clamped numerically before storage so a distributed attacker
 * can only skew the performance dashboard -- accepted as low-risk.
 *
 * @since 2.2.0
 * @return void
 */
function dc_swp_ajax_perf_report(): void {
	check_ajax_referer( 'dc_swp_perf_nonce', 'nonce' );

	// Rate-limit: one write per IP per 60 seconds to prevent metric pollution.
	$rl_key = 'dc_swp_pr_rl_' . md5( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ) );
	if ( get_transient( $rl_key ) ) {
		wp_send_json_success();
		return;
	}
	set_transient( $rl_key, 1, 60 );

	// Clamp inputs: TBT 0–30 000 ms, INP 0–10 000 ms.
	$tbt = max( 0.0, min( 30000.0, (float) wp_unslash( $_POST['tbt'] ?? 0 ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- float cast is safe sanitization for numeric values.
	$inp = max( 0.0, min( 10000.0, (float) wp_unslash( $_POST['inp'] ?? 0 ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- float cast is safe sanitization for numeric values.

	// Read existing metrics.
	$metrics_raw = get_option( 'dc_swp_perf_metrics', '' );
	$metrics     = is_string( $metrics_raw ) && '' !== $metrics_raw ? json_decode( $metrics_raw, true ) : array();
	if ( ! is_array( $metrics ) ) {
		$metrics = array();
	}
	$samples_count = isset( $metrics['samples'] ) ? (int) $metrics['samples'] : 0;
	$tbt_avg       = isset( $metrics['tbt_avg'] ) ? (float) $metrics['tbt_avg'] : 0.0;
	$inp_avg       = isset( $metrics['inp_avg'] ) ? (float) $metrics['inp_avg'] : 0.0;

	// Update rolling averages (cumulative moving average).
	$new_samples = $samples_count + 1;
	$new_tbt_avg = ( $tbt_avg * $samples_count + $tbt ) / $new_samples;
	$new_inp_avg = ( $inp_avg * $samples_count + $inp ) / $new_samples;

	// Maintain sliding window of last 100 samples for P75.
	$samples_raw = get_option( 'dc_swp_perf_samples', '' );
	$samples     = is_string( $samples_raw ) && '' !== $samples_raw ? json_decode( $samples_raw, true ) : array();
	if ( ! is_array( $samples ) ) {
		$samples = array(
			'tbt' => array(),
			'inp' => array(),
		);
	}
	$tbt_arr = isset( $samples['tbt'] ) && is_array( $samples['tbt'] ) ? $samples['tbt'] : array();
	$inp_arr = isset( $samples['inp'] ) && is_array( $samples['inp'] ) ? $samples['inp'] : array();

	$tbt_arr[] = $tbt;
	$inp_arr[] = $inp;
	// Keep only last 100 values.
	if ( count( $tbt_arr ) > 100 ) {
		$tbt_arr = array_slice( $tbt_arr, -100 );
	}
	if ( count( $inp_arr ) > 100 ) {
		$inp_arr = array_slice( $inp_arr, -100 );
	}

	// Compute P75.
	$tbt_sorted = $tbt_arr;
	$inp_sorted = $inp_arr;
	sort( $tbt_sorted );
	sort( $inp_sorted );
	$p75_idx = max( 0, (int) ceil( 0.75 * count( $tbt_sorted ) ) - 1 );
	$tbt_p75 = isset( $tbt_sorted[ $p75_idx ] ) ? (float) $tbt_sorted[ $p75_idx ] : 0.0;
	$inp_p75 = isset( $inp_sorted[ $p75_idx ] ) ? (float) $inp_sorted[ $p75_idx ] : 0.0;

	$new_metrics      = array(
		'samples'      => $new_samples,
		'tbt_avg'      => round( $new_tbt_avg, 2 ),
		'inp_avg'      => round( $new_inp_avg, 2 ),
		'tbt_p75'      => round( $tbt_p75, 2 ),
		'inp_p75'      => round( $inp_p75, 2 ),
		'last_updated' => gmdate( 'c' ),
	);
	$new_samples_data = array(
		'tbt' => $tbt_arr,
		'inp' => $inp_arr,
	);

	update_option( 'dc_swp_perf_metrics', wp_json_encode( $new_metrics ), false );
	update_option( 'dc_swp_perf_samples', wp_json_encode( $new_samples_data ), false );

	wp_send_json_success();
}

add_action( 'wp_ajax_dc_swp_perf_reset', 'dc_swp_ajax_perf_reset' );

/**
 * AJAX handler: reset all stored performance metrics.
 *
 * Requires manage_options capability and a valid nonce.
 *
 * @since 2.2.0
 * @return void
 */
function dc_swp_ajax_perf_reset(): void {
	check_ajax_referer( 'dc_swp_perf_reset_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
	}
	delete_option( 'dc_swp_perf_metrics' );
	delete_option( 'dc_swp_perf_samples' );
	wp_send_json_success();
}


// ============================================================
// FEATURE 4 -- PER-PAGE SCRIPT EXCLUSION PATTERNS
// Lets admins define URL patterns where Partytown is completely
// skipped -- useful for pages with scripts incompatible with the
// Partytown worker (payment flows, specific landing pages, etc.).
// ============================================================

/**
 * Return the admin-configured per-page exclusion patterns.
 *
 * Reads the dc_swp_exclusion_patterns option (newline-separated URL
 * substrings, supports * wildcard), sanitizes each line, and caches
 * the result in the WP object cache for the duration of the request.
 *
 * @since 2.3.0
 * @return string[]
 */
function dc_swp_get_exclusion_patterns(): array {
	$cached = wp_cache_get( 'exclusion_patterns', 'dc_swp' );
	if ( false !== $cached ) {
		return (array) $cached;
	}

	$raw      = (string) get_option( 'dc_swp_exclusion_patterns', '' );
	$patterns = array_values(
		array_filter(
			array_map(
				'sanitize_text_field',
				explode( "\n", $raw )
			)
		)
	);

	wp_cache_set( 'exclusion_patterns', $patterns, 'dc_swp', HOUR_IN_SECONDS );
	return $patterns;
}

/**
 * Return true when the current page is a WooCommerce transactional page.
 *
 * Used to suppress Cross-Origin Isolation (COI) headers on cart, checkout,
 * and account pages -- the Atomics bridge is disabled here so that payment
 * gateway iframes are never broken. Partytown itself still loads on these
 * pages via the Service Worker bridge. Also passed to the client-side config
 * (dcSwpPartytownData.isSafePage) so partytown-config.js can override
 * SharedArrayBuffer as a belt-and-suspenders guarantee.
 *
 * Result is static-memoised so the check runs only once per request.
 *
 * @since 2.0.0
 * @return bool
 */
function dc_swp_is_safe_page(): bool {
	static $result = null;
	if ( null !== $result ) {
		return $result;
	}
	if ( function_exists( 'is_cart' ) && is_cart() ) {
		$result = true;
		return $result;
	}
	if ( function_exists( 'is_checkout' ) && is_checkout() ) {
		$result = true;
		return $result;
	}
	if ( function_exists( 'is_account_page' ) && is_account_page() ) {
		$result = true;
		return $result;
	}
	$result = false;
	return $result;
}

/**
 * Return true when the current request URI matches any exclusion pattern.
 *
 * Each pattern may contain a * wildcard (matches any characters). Patterns
 * without * are tested with str_contains(). Result is static-memoised so
 * the check runs only once per request regardless of how many callers invoke it.
 *
 * @since 2.3.0
 * @param string $request_uri Optional URI to test; defaults to SERVER['REQUEST_URI'].
 * @return bool
 */
function dc_swp_is_excluded_url( string $request_uri = '' ): bool {
	static $result = null;
	if ( null !== $result && '' === $request_uri ) {
		return $result;
	}

	// Sanitize once. esc_url_raw preserves path/query characters that
	// sanitize_text_field would strip (slashes, ?, &, =).
	$server_uri    = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
	$is_server_uri = '' === $request_uri;
	if ( $is_server_uri ) {
		$request_uri = $server_uri;
	}

	$patterns = dc_swp_get_exclusion_patterns();
	$matched  = false;

	foreach ( $patterns as $pattern ) {
		if ( '' === $pattern ) {
			continue;
		}
		if ( str_contains( $pattern, '*' ) ) {
			// Wildcard pattern -- escape for regex then replace escaped \* with .*.
			$regex = '#' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '#';
			if ( preg_match( $regex, $request_uri ) ) {
				$matched = true;
				break;
			}
		} elseif ( str_contains( $request_uri, $pattern ) ) {
			$matched = true;
			break;
		}
	}

	if ( $is_server_uri || $request_uri === $server_uri ) {
		$result = $matched;
	}

	return $matched;
}

// ============================================================
// OUTPUT BUFFER -- PARTYTOWN SCRIPT REWRITER
// Rewrites <script src> tags in the final HTML: when the src
// matches a configured pattern, sets type to text/partytown
// (marketing consent cookie present) or text/plain (no consent).
// Catches scripts injected via direct echo that bypass
// wp_script_attributes -- e.g. Ahrefs in functions.php.
// ============================================================

add_action( 'template_redirect', 'dc_swp_partytown_buffer_start', 2 );

/**
 * Start output buffering so dc_swp_partytown_buffer_rewrite()
 * can rewrite the full HTML response before it is sent.
 *
 * The buffer is explicitly closed by dc_swp_partytown_buffer_end() on the
 * WordPress `shutdown` action (priority 0) so it is never left open across
 * the request lifecycle, preventing buffer-stack misalignment with other
 * plugins or themes.
 */
function dc_swp_partytown_buffer_start() {
	if ( is_admin() ) {
		return;
	}
	if ( dc_swp_is_bot_request() ) {
		return;
	}
	if ( get_option( 'dc_swp_sw_enabled', 'yes' ) !== 'yes' ) {
		return;
	}
	if ( empty( dc_swp_get_partytown_patterns() ) ) {
		return;
	}
	if ( dc_swp_is_excluded_url() ) {
		return;
	}
	ob_start( 'dc_swp_partytown_buffer_rewrite' );
	add_action( 'shutdown', 'dc_swp_partytown_buffer_end', 0 );
}

/**
 * Explicitly close the output buffer opened by dc_swp_partytown_buffer_start().
 *
 * Runs on the `shutdown` action at priority 0 (before WordPress core and
 * other plugins tear down) so the buffer is never left open. ob_end_flush()
 * invokes dc_swp_partytown_buffer_rewrite() and sends the rewritten HTML.
 */
function dc_swp_partytown_buffer_end() {
	if ( ob_get_level() > 0 && false !== ob_get_length() ) {
		ob_end_flush();
	}
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
 * followed by an inline gtag() initializer -- without this, only the loader
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
function dc_swp_partytown_buffer_rewrite( $html ) {
	// Merge user-configured patterns with auto-detect GTM patterns so the buffer
	// rewriter also catches GTM scripts injected by other plugins when detect mode
	// is active -- even if the user's Script List is empty.
	$patterns = array_merge( dc_swp_get_partytown_patterns(), dc_swp_get_auto_detect_patterns() );
	if ( empty( $patterns ) ) {
		return $html;
	}

	/**
	 * Maps a src= URL substring to a regex that the following inline script's
	 * body must match before it is rewritten to type="text/partytown".
	 *
	 * Only services that genuinely emit a <script src=...> loader followed by
	 * an inline <script> initializer are listed here. Services that use a
	 * pure inline embed (Facebook Pixel, TikTok Pixel, GTM container snippet)
	 * or a standalone src= tag (Ahrefs, HubSpot) must NOT be in this map --
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
			// <script src=".../gtag/js?id=G-..."></script>
			// <script>window.dataLayer=...;function gtag(){...}</script>.
			'googletagmanager.com/gtag/js' => '/\bdataLayer\b|\bgtag\s*\(/i',
		)
	);

	// Pending companion state:
	// null  → previous matched script has no known inline companion.
	// array → [ 'type' => 'text/partytown'|'text/plain', 'validator' => '/regex/' ].
	$pending_companion = null;

	$html = preg_replace_callback(
		'/<script\b([^>]*)>((?>[^<]|<(?!\/script>))*)<\/script>/i',
		static function ( $matches ) use ( $patterns, $companion_map, &$pending_companion ) {
			$tag_inner = $matches[1];
			$body      = $matches[2];

			// -- Inline script (no src=) --------------------------------------
			if ( ! preg_match( '/\bsrc=(["\'])([^"\']+)\1/i', $tag_inner, $src_match ) ) {
				if ( null !== $pending_companion ) {
					$carry             = $pending_companion;
					$pending_companion = null; // Consume -- one companion per src= script.

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

				// Unrelated inline -- leave untouched.
				return $matches[0];
			}

			// -- External (src=) script ---------------------------------------
			$src = $src_match[2];

			foreach ( $patterns as $pattern ) {
				if ( '' === $pattern || ! str_contains( $src, $pattern ) ) {
					continue;
				}

				$existing_type_val = '';
				if ( preg_match( '/\btype=(["\'])([^"\']+)\1/i', $tag_inner, $type_match ) ) {
					$existing_type_val = strtolower( $type_match[2] );
				}

				// wp_script_attributes already set text/partytown -- honour it and
				// still arm the companion state in case this src has a known companion.
				if ( 'text/partytown' === $existing_type_val ) {
					$tag_inner         = preg_replace( '/\s+async(?:=["\'][^"\']*["\'])?/i', '', $tag_inner );
					$pending_companion = dc_swp_resolve_companion( $src, 'text/partytown', $companion_map );
					return '<script' . $tag_inner . '>' . $body . '</script>';
				}

				// GDPR guard: CMP has blocked this script -- leave untouched entirely.
				if ( '' !== $existing_type_val && 'text/javascript' !== $existing_type_val ) {
					$pending_companion = null;
					return $matches[0];
				}

				// Per-service consent gate via WP Consent API.
				[ $allowed, $cat ] = dc_swp_resolve_script_consent( $src );
				$new_type          = $allowed ? 'text/partytown' : 'text/plain';
				$tag_inner         = preg_replace( '/\s+async(?:=["\'][^"\']*["\'])?/i', '', $tag_inner );
				// Stamp blocked scripts with their consent category for client-side unblocking.
				if ( ! $allowed && ! preg_match( '/\bdata-wp-consent-category=/i', $tag_inner ) ) {
					$tag_inner .= ' data-wp-consent-category="' . esc_attr( $cat ) . '"';
				}

				// Inject or replace the type attribute so the browser sees the correct consent state.
				// Critical: raw-echoed scripts bypass wp_script_attributes and arrive here with no type
				// (or type="text/javascript"). Without this step they would execute on the main thread
				// regardless of the consent decision above.
				if ( preg_match( '/\btype=["\'][^"\']*["\']/i', $tag_inner ) ) {
					$tag_inner = preg_replace( '/\btype=["\'][^"\']*["\']/i', 'type="' . $new_type . '"', $tag_inner );
				} else {
					$tag_inner .= ' type="' . $new_type . '"';
				}

				// Arm companion state only for services with a known inline companion.
				$pending_companion = dc_swp_resolve_companion( $src, $new_type, $companion_map );

				return '<script' . $tag_inner . '>' . $body . '</script>';
			}

			// No pattern matched -- clear any pending companion state.
			$pending_companion = null;
			return $matches[0];
		},
		$html
	);

	// -- Cross-origin iframe: inject `credentialless` attribute ------------
	// Under COEP: credentialless, the browser blocks cross-origin iframes
	// whose response carries Cross-Origin-Resource-Policy: same-origin (or
	// no COEP opt-in at all). The HTML `credentialless` attribute on <iframe>
	// instructs Chrome to load the iframe without cookies -- bypassing CORP
	// enforcement for read-only embeds like Trustpilot TrustScore widgets.
	// Firefox / Safari do not implement COEP credentialless and are unaffected.
	if ( get_option( 'dc_swp_coi_headers', 'no' ) === 'yes' ) {
		$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$html      = preg_replace_callback(
			'/<iframe\b[^>]*>/i',
			static function ( $iframe_match ) use ( $site_host ) {
				$tag = $iframe_match[0];
				// Already has the attribute -- nothing to do.
				if ( preg_match( '/\bcredentialless\b/i', $tag ) ) {
					return $tag;
				}
				// Must have a src= pointing to a different origin.
				if ( ! preg_match( '/\bsrc=(["\'])([^"\']+)\1/i', $tag, $src_m ) ) {
					return $tag;
				}
						$iframe_host = (string) wp_parse_url( $src_m[2], PHP_URL_HOST );
				if ( '' === $iframe_host || $site_host === $iframe_host ) {
					return $tag;
				}
				// Insert before closing >.
				return substr( $tag, 0, -1 ) . ' credentialless>';
			},
			$html
		);

		// -- Cross-origin scripts: inject `crossorigin="anonymous"` -------
		// COEP: credentialless exempts no-cors subresources from needing a
		// CORP header only when the CDN sends no CORP at all. A CDN that
		// explicitly sends `Cross-Origin-Resource-Policy: same-origin`
		// (e.g. Trustpilot's widget CDN for tp.widget.bootstrap.js) will
		// still be blocked even in credentialless mode because CORP: same-origin
		// is an explicit restriction.
		//
		// Adding crossorigin="anonymous" converts the load to a CORS request.
		// If the CDN responds with Access-Control-Allow-Origin: * (Trustpilot's
		// CDN does), the CORS response satisfies COEP regardless of CORP.
		//
		// Only applied to patterns listed in the filter -- keeps the change
		// conservative so scripts without CORS support are never broken.
		//
		// Usage: add_filter( 'dc_swp_coi_crossorigin_patterns', function( $p ) {
		// $p[] = 'cdn.example.com'; return $p;
		// } );
		$crossorigin_patterns = (array) apply_filters(
			'dc_swp_coi_crossorigin_patterns', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			array(
				'widget.trustpilot.com',
			)
		);
		// Restrict crossorigin mutations to hosts that are part of the active
		// Partytown configuration (Script List + enabled Script Blocks). This
		// avoids touching unrelated third-party scripts that still run on the
		// main thread and should remain unaffected by Atomic Bridge hardening.
		$managed_hosts = dc_swp_get_proxy_allowed_hosts();
		if ( ! empty( $crossorigin_patterns ) ) {
			$html = preg_replace_callback(
				'/<script\b([^>]*)>/i',
				static function ( $s_match ) use ( $site_host, $crossorigin_patterns, $managed_hosts ) {
					$tag_inner = $s_match[1];
					// Need a cross-origin src=.
					if ( ! preg_match( '/\bsrc=(["\'])([^"\']+)\1/i', $tag_inner, $src_m ) ) {
						return $s_match[0];
					}
					$script_host = (string) wp_parse_url( $src_m[2], PHP_URL_HOST );
					if ( '' === $script_host || $site_host === $script_host ) {
						return $s_match[0];
					}
					// Only mutate hosts the plugin explicitly handles via
					// Partytown configuration/proxy allowlist.
					$is_managed = false;
					foreach ( $managed_hosts as $managed_host ) {
						if ( '' !== $managed_host && str_ends_with( $script_host, $managed_host ) ) {
							$is_managed = true;
							break;
						}
					}
					if ( ! $is_managed ) {
						return $s_match[0];
					}
					// Already has crossorigin -- leave untouched.
					if ( preg_match( '/\bcrossorigin\b/i', $tag_inner ) ) {
						return $s_match[0];
					}
					// Check against the allow-list.
					$matched = false;
					foreach ( $crossorigin_patterns as $pat ) {
						if ( '' !== $pat && str_contains( $script_host, $pat ) ) {
							$matched = true;
							break;
						}
					}
					if ( ! $matched ) {
						return $s_match[0];
					}
					return '<script' . $tag_inner . ' crossorigin="anonymous">';
				},
				$html
			);
		}
	}

	return $html;
}

/**
 * Resolve an inline companion script entry for a given external script src.
 *
 * @param string               $src           The script src= URL.
 * @param string               $type          'text/partytown' or 'text/plain'.
 * @param array<string,string> $companion_map Map of URL substring → body validator regex.
 * @return array{type:string,validator:string}|null
 */
function dc_swp_resolve_companion( $src, $type, $companion_map ) {
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
// INLINE SCRIPT BLOCKS -- PARTYTOWN WEB WORKER
// Allows admins to paste complete third-party script blocks
// (e.g. Meta Pixel, TikTok Pixel) directly into the admin UI.
// Inline <script> blocks are output with type="text/partytown"
// so Partytown executes them in a Web Worker. External src= scripts
// inside a block are also echoed directly (they only exist in the
// block -- not in functions.php or WP's script queue) with the
// consent-gated type so Partytown loads them in the worker too.
// When Partytown is disabled, all scripts (inline and src=) are
// echoed with defer on the main thread.
// ============================================================
// INLINE SCRIPT BLOCKS -- ENQUEUE & NOSCRIPT OUTPUT
// Script Blocks store a script URL (src) and optional noscript
// pixel URL. External scripts are enqueued via wp_enqueue_scripts;
// the dc_swp_script_loader_tag filter adds type="text/partytown"
// and the CSP nonce. Noscript pixels are echoed at wp_head priority
// 3 (after Partytown) when consent is present.
// ============================================================

add_action( 'wp_enqueue_scripts', 'dc_swp_enqueue_script_blocks', 3 );

/**
 * Enqueue Script Block external scripts via the WordPress enqueue API.
 *
 * Each block stores a `src` URL sanitized as esc_url_raw(). The script is
 * registered with dc_swp_type data so dc_swp_script_loader_tag() applies
 * type="text/partytown" (or type="text/plain" + data-wp-consent-category
 * when consent is absent). Blocks with a noscript_img_url are handled
 * separately at wp_head priority 3.
 *
 * @return void
 */
function dc_swp_enqueue_script_blocks(): void {
	if ( dc_swp_is_bot_request() || is_admin() ) {
		return;
	}
	if ( dc_swp_is_excluded_url() ) {
		return;
	}

	$raw_stored = (string) get_option( 'dc_swp_inline_scripts', '' );
	if ( '' === $raw_stored ) {
		return;
	}

	$decoded = json_decode( $raw_stored, true );
	if ( ! is_array( $decoded ) ) {
		return;
	}

	$pt_enabled = get_option( 'dc_swp_sw_enabled', 'yes' ) === 'yes';

	foreach ( $decoded as $blk ) {
		if ( empty( $blk['enabled'] ) ) {
			continue;
		}
		$src = esc_url_raw( $blk['src'] ?? '' );
		if ( '' === $src ) {
			continue;
		}
		if ( ! empty( $blk['skip_logged_in'] ) && is_user_logged_in() ) {
			continue;
		}

		$cat     = $blk['category'] ?? 'marketing';
		$handle  = 'dc-swp-block-' . sanitize_key( $blk['id'] ?? md5( $src ) );

		// Determine consent and type.
		if ( $pt_enabled ) {
			if ( '' !== $cat ) {
				$gcm_by  = dc_swp_is_consent_mode_enabled() && dc_swp_script_uses_gcm_v2( $src );
				$ldu_by  = dc_swp_is_meta_ldu_enabled() && dc_swp_is_meta_script( $src );
				$allowed = $gcm_by || $ldu_by || dc_swp_has_consent_for( $cat );
			} else {
				[ $allowed, $cat ] = dc_swp_resolve_script_consent( $src );
			}
			$type = ( $allowed || ! empty( $blk['force_partytown'] ) ) ? 'text/partytown' : 'text/plain';
		} else {
			$allowed = true;
			$type    = ''; // Partytown disabled: WP default type (deferred on main thread).
		}

		wp_register_script( $handle, $src, array( 'dc-swp-partytown-config' ), null, array( 'in_footer' => false ) );
		if ( $type ) {
			wp_script_add_data( $handle, 'dc_swp_type', $type );
		}
		if ( 'text/plain' === $type && '' !== $cat ) {
			wp_script_add_data( $handle, 'dc_swp_consent_cat', $cat );
		}
		if ( ! $type ) {
			// Partytown disabled: add defer attribute.
			wp_script_add_data( $handle, 'defer', true );
		}
		wp_enqueue_script( $handle );
	}
}

add_action( 'wp_head', 'dc_swp_output_script_block_noscripts', 3 );

/**
 * Emit <noscript> tracking pixels for Script Blocks that have a noscript_img_url.
 *
 * Only emitted when the visitor has granted consent for the block's category.
 * The URL is already sanitized as esc_url_raw() when stored; esc_url() is
 * applied again at output time for defence-in-depth.
 *
 * @return void
 */
function dc_swp_output_script_block_noscripts(): void {
	if ( dc_swp_is_bot_request() || is_admin() ) {
		return;
	}
	if ( dc_swp_is_excluded_url() ) {
		return;
	}

	$raw_stored = (string) get_option( 'dc_swp_inline_scripts', '' );
	if ( '' === $raw_stored ) {
		return;
	}

	$decoded = json_decode( $raw_stored, true );
	if ( ! is_array( $decoded ) ) {
		return;
	}

	foreach ( $decoded as $blk ) {
		if ( empty( $blk['enabled'] ) ) {
			continue;
		}
		$noscript_url = esc_url_raw( $blk['noscript_img_url'] ?? '' );
		if ( '' === $noscript_url ) {
			continue;
		}
		if ( ! empty( $blk['skip_logged_in'] ) && is_user_logged_in() ) {
			continue;
		}

		$cat = $blk['category'] ?? 'marketing';
		if ( ! dc_swp_has_consent_for( $cat ) ) {
			continue;
		}

		echo '<noscript><img src="' . esc_url( $noscript_url ) . '" width="1" height="1" style="display:none" alt=""></noscript>' . "\n";
	}
}

