<?php
/**
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
 */

if ( ! defined( 'ABSPATH' ) ) { die(); }

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
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_swp_is_bot_request() {
	if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
		return false;
	}

	$ua = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) );

	// Common bots, crawlers and speed-test tools
	$bot_patterns = [
		'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider',
		'yandexbot', 'sogou', 'exabot', 'facebot', 'facebookexternalhit',
		'ia_archiver', 'semrushbot', 'ahrefsbot', 'mj12bot', 'dotbot',
		'rogerbot', 'seokicks', 'seznambot', 'blexbot', 'yeti',
		'naverbot', 'daumoa', 'applebot', 'twitterbot', 'linkedinbot',
		'pinterestbot', 'whatsapp', 'telegrambot', 'discordbot',
		'slackbot', 'embedly', 'quora link preview', 'outbrain',
		'screaming frog', 'seobilitybot', 'gptbot', 'chatgpt',
		'claudebot', 'anthropic', 'meta-externalagent',
		// Speed / audit tools
		'pagespeed', 'gtmetrix', 'pingdom', 'webpagetest',
		'lighthouse', 'chrome-lighthouse', 'calibre', 'dareboost',
		// Generic
		'spider', 'crawler', 'scraper', 'bot/', '/bot',
	];

	foreach ( $bot_patterns as $pattern ) {
		if ( str_contains( $ua, $pattern ) ) {
			return true;
		}
	}

	// Headless Chrome / Puppeteer / Playwright without a real UA
	if ( str_contains( $ua, 'headlesschrome' ) || str_contains( $ua, 'phantomjs' ) ) {
		return true;
	}

	return false;
}
endif; // function_exists( 'is_bot_request' )


// ============================================================
// MARKETING CONSENT DETECTION
// Reads the first-party cookies set by the most common WordPress
// consent management plugins. Returns true once the visitor has
// granted marketing / analytics consent, so we can safely load
// third-party scripts via Partytown.
//
// Covers:
//   Complianz            — cmplz_marketing = "allow"
//   CookieYes            — cookieyes-consent contains "marketing:yes"
//   Borlabs Cookie       — borlabs-cookie JSON .consents.marketing = true
//   Cookie Notice (GDPR) — cookie_notice_accepted = "true"
//   WebToffee GDPR       — cookie_cat_marketing = "accept"
//   Cookiebot (Cybot)    — CookieConsent contains "marketing:true"
//   Cookie Information   — CookieInformationConsent JSON consents_approved[] contains "cookie_cat_marketing"
//   Moove GDPR           — moove_gdpr_popup JSON .thirdparty = 1
// ============================================================

/**
 * Return true if the current visitor has granted marketing consent
 * according to any of the common CMP cookie conventions.
 */
function dc_swp_has_marketing_consent() {
	// Complianz
	if ( isset( $_COOKIE['cmplz_marketing'] ) && $_COOKIE['cmplz_marketing'] === 'allow' ) {
		return true;
	}

	// CookieYes — cookie value looks like "consent:yes,marketing:yes,analytics:yes,..."
	if ( isset( $_COOKIE['cookieyes-consent'] ) ) {
		$cy = sanitize_text_field( wp_unslash( $_COOKIE['cookieyes-consent'] ) );
		if ( str_contains( $cy, 'marketing:yes' ) ) {
			return true;
		}
	}

	// Borlabs Cookie — JSON-encoded object; .consents.marketing === true
	if ( isset( $_COOKIE['borlabs-cookie'] ) ) {
		$raw = json_decode( stripslashes( $_COOKIE['borlabs-cookie'] ), true );
		if ( ! empty( $raw['consents']['marketing'] ) ) {
			return true;
		}
	}

	// Cookie Notice & Compliance for GDPR — single all-or-nothing cookie
	if ( isset( $_COOKIE['cookie_notice_accepted'] ) && $_COOKIE['cookie_notice_accepted'] === 'true' ) {
		return true;
	}

	// WebToffee GDPR Cookie Consent
	if ( isset( $_COOKIE['cookie_cat_marketing'] ) && $_COOKIE['cookie_cat_marketing'] === 'accept' ) {
		return true;
	}

	// Cookiebot (Cybot) — URL-encoded value contains "marketing:true"
	if ( isset( $_COOKIE['CookieConsent'] ) ) {
		$cc = sanitize_text_field( wp_unslash( $_COOKIE['CookieConsent'] ) );
		if ( str_contains( $cc, 'marketing:true' ) ) {
			return true;
		}
	}

	// Cookie Information (popular in Scandinavia) — JSON; consents_approved[] contains "cookie_cat_marketing"
	if ( isset( $_COOKIE['CookieInformationConsent'] ) ) {
		$ci = json_decode( stripslashes( $_COOKIE['CookieInformationConsent'] ), true );
		if ( ! empty( $ci['consents_approved'] ) && in_array( 'cookie_cat_marketing', $ci['consents_approved'], true ) ) {
			return true;
		}
	}

	// Moove GDPR Cookie Compliance — JSON; .thirdparty === 1
	if ( isset( $_COOKIE['moove_gdpr_popup'] ) ) {
		$mg = json_decode( stripslashes( $_COOKIE['moove_gdpr_popup'] ), true );
		if ( isset( $mg['thirdparty'] ) && (int) $mg['thirdparty'] === 1 ) {
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
// Replaces the first © inside <footer>…</footer> with a
// linked © pointing to dampcig.dk. Uses object-cache →
// transient to avoid regex overhead on every page load.
// Does nothing if no © is found — no fallback injection.
// ============================================================

define( 'DC_SWP_FOOTER_TRANSIENT', 'dc_swp_footer_strategy' ); // 'copyright' | 'none'

add_action( 'template_redirect', 'dc_swp_footer_credit_start' );

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_swp_footer_credit_start() {
	if ( is_admin() ) return;
	if ( get_option( 'dampcig_pwa_footer_credit', 'no' ) !== 'yes' ) return;
	// If DC Google Indexing is active, it owns the footer credit — defer to avoid duplicates.
	if ( function_exists( 'dc_gi_footer_credit_start' )
		&& ! empty( ( dc_gi_get_settings() )['footer_credit'] ) ) {
		return;
	}
	// If the PNG→WebP plugin is active, it owns the footer credit — always defer
	// to avoid duplicates, regardless of whether its setting has been saved to DB.
	if ( class_exists( 'DC_WebP_Converter' ) ) return;
	ob_start( 'dc_swp_footer_credit_process' );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_swp_footer_credit_process( $html ) {
	$url   = 'https://www.dampcig.dk';
	$title = 'Powered by Dampcig.dk';
	$link  = '<a href="' . $url . '" title="' . esc_attr( $title ) . '" target="_blank" rel="noopener noreferrer">&copy;</a>';
	$group = 'dc_swp';
	$key   = DC_SWP_FOOTER_TRANSIENT;

	// Object cache (in-memory, zero DB cost) → transient → detect.
	$strategy = wp_cache_get( $key, $group );
	if ( false === $strategy ) {
		$strategy = get_transient( $key );
	}

	// Cached: © exists in footer — replace it.
	if ( 'copyright' === $strategy ) {
		return dc_swp_do_copyright_replace( $html, $link );
	}

	// Cached: no © found on a previous run — do nothing.
	if ( 'none' === $strategy ) {
		return $html;
	}

	// First run: detect, cache, and act.
	$replaced = dc_swp_do_copyright_replace( $html, $link );
	if ( $replaced !== $html ) {
		dc_swp_cache_footer_strategy( 'copyright' );
		return $replaced;
	}

	// No © found — cache that fact and leave HTML untouched.
	dc_swp_cache_footer_strategy( 'none' );
	return $html;
}

/**
 * Replaces the first © (UTF-8 ©, &copy;, &#169;, &#xA9;) found inside
 * <footer>…</footer>. Returns $html unchanged if no © or no <footer>.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_swp_do_copyright_replace( $html, $link ) {
	if ( ! preg_match( '/(<footer[\s\S]*?<\/footer>)/i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
		return $html; // No <footer> tag — leave page untouched.
	}

	$footer_html = $m[0][0];
	$offset      = $m[0][1];
	$new_footer  = preg_replace( '/©|&copy;|&#169;|&#xA9;/u', $link, $footer_html, 1, $count );

	if ( ! $count ) {
		return $html; // No © in footer — leave page untouched.
	}

	return substr_replace( $html, $new_footer, $offset, strlen( $footer_html ) );
}

/** Persist the detected strategy in both object cache and transient. */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_swp_cache_footer_strategy( $strategy ) {
	$key   = DC_SWP_FOOTER_TRANSIENT;
	$group = 'dc_swp';
	wp_cache_set( $key, $strategy, $group, WEEK_IN_SECONDS );
	set_transient( $key, $strategy, WEEK_IN_SECONDS );
}

/** Invalidate cached footer strategy (call on theme switch or settings reset). */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_swp_clear_footer_strategy_cache() {
	wp_cache_delete( DC_SWP_FOOTER_TRANSIENT, 'dc_swp' );
	delete_transient( DC_SWP_FOOTER_TRANSIENT );
}


// ============================================================
// FALLBACK CACHE HEADERS (when W3 Total Cache is not active)
// ============================================================

add_action( 'send_headers', 'dc_swp_fallback_cache_headers' );

/**
 * When W3TC is absent, emit sensible Cache-Control / Expires / Vary
 * headers directly from PHP so the browser and any CDN can still cache responses.
 *
 * Skipped entirely if W3TC is loaded (W3TC owns its own header logic).
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_swp_fallback_cache_headers() {
	// W3TC is present — let it handle headers
	if ( defined( 'W3TC_DIR' ) || function_exists( 'w3tc_pgcache_flush' ) ) return;
	if ( is_admin() ) return;

	// Never cache personalised or transactional pages
	if ( is_user_logged_in()
		|| ( function_exists( 'is_cart' )     && is_cart() )
		|| ( function_exists( 'is_checkout' ) && is_checkout() )
		|| ( function_exists( 'is_account_page' ) && is_account_page() ) ) {
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		return;
	}

	// Cache public pages: 1 hour browser, stale-while-revalidate 60 s
	$max_age       = 3600;
	$is_cacheable  = is_front_page() || is_home()
		|| ( function_exists( 'is_shop' )             && is_shop() )
		|| ( function_exists( 'is_product_category' ) && is_product_category() )
		|| ( function_exists( 'is_product' )          && is_product() )
		|| is_page()
		|| is_singular()
		|| is_archive();

	if ( $is_cacheable ) {
		header( 'Cache-Control: public, max-age=' . $max_age . ', stale-while-revalidate=60, stale-if-error=86400' );
		header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + $max_age ) . ' GMT' );
		header( 'Vary: Accept-Encoding, Accept' );
		header( 'X-Cache-Fallback: dc-sw-prefetch' ); // debugging marker
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
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_swp_serve_partytown_files() {
	$request_uri = isset( $_SERVER['REQUEST_URI'] )
		? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		: '';

	if ( strncmp( $request_uri, '/~partytown/', 12 ) !== 0 ) {
		return;
	}

	// Resolve the physical file — prevent directory traversal.
	$relative  = ltrim( substr( $request_uri, strlen( '/~partytown/' ) ), '/' );
	// Strip query string just in case
	$relative  = strtok( $relative, '?' );
	$real_base = realpath( plugin_dir_path( __FILE__ ) . 'assets/partytown' );
	$file      = realpath( $real_base . '/' . $relative );

	// Security: must resolve inside the partytown assets directory.
	// Append directory separator to prevent matching sibling dirs like assets/partytown_other/.
	if ( $file === false || strncmp( $file, $real_base . DIRECTORY_SEPARATOR, strlen( $real_base ) + 1 ) !== 0 ) {
		status_header( 404 );
		exit();
	}

	if ( ! is_file( $file ) ) {
		status_header( 404 );
		exit();
	}

	$ext_map = [
		'js'   => 'application/javascript; charset=utf-8',
		'html' => 'text/html; charset=utf-8',
		'mjs'  => 'application/javascript; charset=utf-8',
	];
	$ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
	$mime = $ext_map[ $ext ] ?? 'application/octet-stream';

	status_header( 200 );
	header( 'Content-Type: ' . $mime );
	header( 'Service-Worker-Allowed: /' );
	header( 'X-Robots-Tag: none' );
	// Partytown files are versioned by the plugin; cache for 1 hour, revalidate.
	header( 'Cache-Control: public, max-age=3600, stale-while-revalidate=60' );

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- serving a binary-safe static file
	readfile( $file );
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
function dc_swp_get_product_base() {
	$override = trim( get_option( 'dampcig_pwa_product_base', '' ) );
	if ( $override !== '' ) {
		// Normalise: ensure leading and trailing slash
		return '/' . trim( $override, '/' ) . '/';
	}

	// Auto-detect from WooCommerce
	$wc = get_option( 'woocommerce_permalinks', [] );
	if ( ! empty( $wc['product_base'] ) ) {
		$base  = trim( $wc['product_base'], '/' );
		$parts = explode( '/', $base );
		// Use only the first path segment (ignore %product_cat% suffixes)
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
	$nonce = (string) apply_filters( 'dc_swp_csp_nonce', $nonce );
	return $nonce;
}

/**
 * Emit the Partytown config object and the inline snippet in <head>.
 * Must run before any type="text/partytown" scripts.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_swp_partytown_config() {
	if ( dc_swp_is_bot_request() ) return;
	if ( is_admin() ) return;

	$pt_enabled = get_option( 'dampcig_pwa_sw_enabled', 'yes' ) === 'yes';
	if ( ! $pt_enabled ) return;

	// Inline Partytown snippet — serves workers from /~partytown/
	$snippet_file = plugin_dir_path( __FILE__ ) . 'assets/partytown/partytown.js';
	if ( ! file_exists( $snippet_file ) ) return;

	$snippet = file_get_contents( $snippet_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	// Build the Partytown config as a PHP structure so it is always valid JSON.
	// forward list based on https://partytown.qwik.dev/common-services/
	$config = [
		'lib'   => '/~partytown/',
		'debug' => false,
		// Feature 1: preserveBehavior:true on dataLayer.push ensures GTM and
		// consent stacks also fire the original (main-thread) implementation,
		// keeping tag-manager event flow intact.
		// Feature 3: strictProxyHas prevents false-negative `in` operator checks
		// (needed by FullStory, GTM, and similar tools).
		'forward' => [
			// Array-of-arrays tuple format: ['forwardProp', {options}]
			// preserveBehavior:true ensures the original main-thread dataLayer.push
			// is also called, keeping GTM and consent stacks fully functional.
			[ 'dataLayer.push', [ 'preserveBehavior' => true ] ],
			'gtag',         // Google Analytics / GTM
			'fbq',          // Meta Pixel
			'lintrk',       // LinkedIn Insight
			'twq',          // Twitter/X Pixel
			'_hsq.push',    // HubSpot
			'Intercom',     // Intercom
			'_learnq.push', // Klaviyo
			'ttq.track',    // TikTok Pixel
			'ttq.page',
			'ttq.load',
			'mixpanel.track', // Mixpanel
		],
		'strictProxyHas' => true,
	];

	// Feature 4: Mirror the admin exclude list to loadScriptsOnMainThread so
	// scripts that are dynamically injected inside the worker and match an
	// excluded pattern are automatically routed back to the main thread.
	$exclude = dc_swp_get_partytown_exclude_patterns();
	if ( ! empty( $exclude ) ) {
		$config['loadScriptsOnMainThread'] = array_map(
			static fn( $p ) => [ 'string', $p ],
			$exclude
		);
	}

	// Feature 2: Pass a per-request CSP nonce into the Partytown config.
	// Partytown will stamp this nonce on every <script> element it creates,
	// allowing sites with strict CSP to whitelist exactly these scripts.
	$nonce = dc_swp_get_csp_nonce();
	if ( $nonce !== '' ) {
		$config['nonce'] = $nonce;
	}

	$nonce_attr  = $nonce !== '' ? ' nonce="' . esc_attr( $nonce ) . '"' : '';
	$config_json = wp_json_encode( $config, JSON_UNESCAPED_SLASHES );

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<script' . $nonce_attr . '>window.partytown=' . $config_json . ";</script>\n";
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<script' . $nonce_attr . '>' . $snippet . "</script>\n";
}


add_action( 'wp_footer', 'dc_swp_prefetch_footer', 9999 );

/**
 * Viewport/pagination prefetcher — runs in wp_footer.
 * Unchanged from original; does NOT depend on a service worker.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_swp_prefetch_footer() {
	if ( dc_swp_is_bot_request() ) {
		return;
	}

	if (
		( function_exists( 'is_cart' )         && is_cart() ) ||
		( function_exists( 'is_checkout' )     && is_checkout() ) ||
		( function_exists( 'is_account_page' ) && is_account_page() )
	) {
		return;
	}

	$preload_enabled = get_option( 'dampcig_pwa_preload_products', 'yes' ) === 'yes';
	if ( ! $preload_enabled ) return;

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

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_swp_maybe_remove_emoji() {
	if ( is_admin() ) return;
	if ( get_option( 'dc_swp_disable_emoji', 'yes' ) !== 'yes' ) return;

	remove_action( 'wp_head',             'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles',     'print_emoji_styles' );
	remove_filter( 'the_content_feed',    'wp_staticize_emoji' );
	remove_filter( 'comment_text_rss',    'wp_staticize_emoji' );
	remove_filter( 'wp_mail',             'wp_staticize_emoji_for_email' );
	add_filter( 'emoji_svg_url',          '__return_false' );

	// Size any emoji <img> that still renders (e.g. from cached markup)
	add_action( 'wp_head', function() {
		echo '<style>img.emoji{width:1em;height:1em;vertical-align:-0.1em}</style>' . "\n";
	}, 1 );
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
	$raw      = (string) get_option( 'dc_swp_partytown_scripts', '' );
	$patterns = array_values( array_filter(
		array_map( 'trim', explode( "\n", $raw ) ),
		static fn( $line ) => $line !== ''
	) );
	wp_cache_set( 'patterns', $patterns, 'dc_swp', HOUR_IN_SECONDS );
	return $patterns;
}

add_filter( 'wp_script_attributes', 'dc_swp_partytown_script_attrs', 5 );

/**
 * Mark any registered <script src> whose URL matches a configured
 * pattern as type="text/partytown", moving it off the main thread.
 * Priority 5 fires before per-script filters (default priority 10).
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_swp_partytown_script_attrs( $attributes ) {
	if ( dc_swp_is_bot_request() ) {
		return $attributes;
	}
	if ( get_option( 'dampcig_pwa_sw_enabled', 'yes' ) !== 'yes' ) {
		return $attributes;
	}
	$src = $attributes['src'] ?? '';
	if ( ! $src ) {
		return $attributes;
	}
	// Never mark excluded scripts as text/partytown.
	foreach ( dc_swp_get_partytown_exclude_patterns() as $excl ) {
		if ( $excl !== '' && str_contains( $src, $excl ) ) {
			return $attributes;
		}
	}
	// GDPR guard: if an upstream filter (rare at priority 5 but possible) has already
	// set a non-standard type, leave it alone so the CMP's blocking is not disturbed.
	$current_type = strtolower( $attributes['type'] ?? '' );
	if ( $current_type !== '' && $current_type !== 'text/javascript' ) {
		return $attributes;
	}
	foreach ( dc_swp_get_partytown_patterns() as $pattern ) {
		if ( $pattern !== '' && str_contains( $src, $pattern ) ) {
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

/**
 * Delete all object-cache pattern keys and flush W3TC page cache (if active),
 * so stale cached HTML with old type attributes is never served.
 */
function dc_swp_bust_page_cache() {
	wp_cache_delete( 'patterns', 'dc_swp' );
	wp_cache_delete( 'exclude_patterns', 'dc_swp' );
	// W3TC page cache flush
	if ( function_exists( 'w3tc_pgcache_flush' ) ) {
		w3tc_pgcache_flush();
	}
}

/**
 * Return admin-configured Partytown EXCLUSION patterns, object-cache memoised.
 * Scripts whose src matches any of these are never rewritten to text/partytown.
 *
 * @return string[]
 */
/**
 * Default exclude list: scripts known to be incompatible with Partytown.
 * Pre-populated into the admin textarea on first use (when option is empty).
 * Users can edit the list freely — remove patterns they do not need.
 */
function dc_swp_default_exclude_list() {
	return implode( "\n", [
		'widget.trustpilot.com',
		'invitejs.trustpilot.com',
		'tp.widget.bootstrap',
		'cdn.reamaze.com',
		'js.stripe.com',
		'js.braintreegateway.com',
		'checkout.paypal.com',
		'maps.googleapis.com',
		'connect.facebook.net/en_US/sdk',
		'analytics.ahrefs.com',
	] );
}

function dc_swp_get_partytown_exclude_patterns() {
	static $exclude = null;
	if ( null !== $exclude ) {
		return $exclude;
	}
	$cached = wp_cache_get( 'exclude_patterns', 'dc_swp' );
	if ( false !== $cached ) {
		$exclude = $cached;
		return $exclude;
	}
	$raw = (string) get_option( 'dc_swp_partytown_exclude', '' );
	// Fall back to defaults when the option has never been configured.
	if ( $raw === '' ) {
		$raw = dc_swp_default_exclude_list();
	}
	$exclude = array_values( array_filter(
		array_map( 'trim', explode( "\n", $raw ) ),
		static fn( $line ) => $line !== ''
	) );
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
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_swp_partytown_buffer_start() {
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
 * Output-buffer callback: walk every <script ...> opening tag and,
 * when its src= URL matches a configured pattern, swap the type
 * attribute to text/plain, add data-cmplz-category="marketing",
 * and strip async.
 *
 * @param string $html Full page HTML.
 * @return string Modified HTML.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_swp_partytown_buffer_rewrite( $html ) {
	$patterns = dc_swp_get_partytown_patterns();
	if ( empty( $patterns ) ) {
		return $html;
	}
	return preg_replace_callback(
		'/<script\b([^>]*)>/i',
		static function ( $matches ) use ( $patterns ) {
			$tag_inner = $matches[1];
			// Skip inline scripts (no src=)
			if ( ! preg_match( '/\bsrc=(["\'])([^"\']+)\1/i', $tag_inner, $src_match ) ) {
				return $matches[0];
			}
			$src = $src_match[2];
			// Check exclusion list first (hardcoded + user-defined)
			foreach ( dc_swp_get_partytown_exclude_patterns() as $excl ) {
				if ( $excl !== '' && str_contains( $src, $excl ) ) {
					return $matches[0]; // excluded — leave untouched
				}
			}
			foreach ( $patterns as $pattern ) {
				if ( $pattern !== '' && str_contains( $src, $pattern ) ) {
					// GDPR guard: if a CMP has already changed this script's type to a
					// consent-blocking value (text/cmplz-script, text/plain, etc.), leave
					// it untouched — consent has not been granted for this script yet.
					if ( preg_match( '/\btype=(["\'])([^"\']+)\1/i', $tag_inner, $type_match ) ) {
						$existing_type = strtolower( $type_match[2] );
						if ( $existing_type !== 'text/javascript' ) {
							// CMP-blocked — leave untouched.
							return $matches[0];
						}
					}
					// Consent granted → Partytown runs it off-thread; no consent → block silently.
					$new_type = dc_swp_has_marketing_consent() ? 'text/partytown' : 'text/plain';
					// Replace existing type="text/javascript" or prepend if no type attribute.
					if ( preg_match( '/\btype=["\'][^"\']*["\']/i', $tag_inner ) ) {
						$tag_inner = preg_replace( '/\btype=["\'][^"\']*["\']/i', 'type="' . $new_type . '"', $tag_inner );
					} else {
						$tag_inner = ' type="' . $new_type . '"' . $tag_inner;
					}
					// Strip async (boolean form or assigned form)
					$tag_inner = preg_replace( '/\s+async(?:=["\'][^"\']*["\'])?/i', '', $tag_inner );
					return '<script' . $tag_inner . '>';
				}
			}
			return $matches[0];
		},
		$html
	);
}
