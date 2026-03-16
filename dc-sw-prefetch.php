<?php
/**
 * @wordpress-plugin
 * Plugin Name: DC Service Worker Prefetcher
 * Plugin URI:  https://github.com/dc-plugins/dc-sw-prefetch
 * Description: Partytown service worker with viewport/pagination prefetching for WooCommerce. Offloads third-party scripts via Partytown and pre-fetches visible products & next pages.
 * Version:     1.2.0
 * Author:      Dampcig
 * Author URI:  https://www.dampcig.dk
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
	if ( $file === false || strncmp( $file, $real_base, strlen( $real_base ) ) !== 0 ) {
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

	// Inject window.partytown config before the snippet runs.
	$config_script = <<<'JS'
<script>
window.partytown = {
    lib: '/~partytown/',
    debug: false,
    forward: ['dataLayer.push', 'gtag', 'fbq', 'lintrk', 'twq']
};
</script>
JS;

	echo $config_script; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<script>' . $snippet . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
// WooCommerce LCP IMAGE OPTIMISATION
// Emits <link rel="preload" as="image" imagesrcset imagesizes>
// in <head> for the LCP product image so the browser starts
// fetching it immediately — before the parser reaches <body>.
// Also sets fetchpriority="high" + loading="eager" on the <img>
// so the browser knows it is the most important image on the page.
//
// Works on:
//   • Single product pages  → woocommerce_single size
//   • Category / shop pages → woocommerce_thumbnail of first product
//
// The imagesrcset attribute matches what WooCommerce outputs in the
// <img srcset>, so the preloaded resource is never discarded as
// unused (the PSI mobile viewport picks the 300w candidate).
// ============================================================

add_action( 'wp', 'dc_swp_setup_lcp_image' );

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_swp_setup_lcp_image() {
	if ( dc_swp_is_bot_request() ) return;
	if ( ! function_exists( 'is_product' ) ) return; // WooCommerce not active
	if ( get_option( 'dc_swp_lcp_preload', 'yes' ) !== 'yes' ) return;

	// ── Single product page ──────────────────────────────────────────────
	if ( is_singular( 'product' ) ) {
		$product_id   = get_queried_object_id();
		$thumbnail_id = get_post_thumbnail_id( $product_id );
		if ( ! $thumbnail_id ) return;

		add_action( 'wp_head', function() use ( $thumbnail_id ) {
			$src    = wp_get_attachment_image_src( $thumbnail_id, 'woocommerce_single' );
			$srcset = wp_get_attachment_image_srcset( $thumbnail_id, 'woocommerce_single' );
			$sizes  = wp_get_attachment_image_sizes( $thumbnail_id, 'woocommerce_single' );
			if ( ! $src ) return;
			echo '<link rel="preload" as="image" fetchpriority="high" href="' . esc_url( $src[0] ) . '"'
				. ( $srcset ? ' imagesrcset="' . esc_attr( $srcset ) . '"' : '' )
				. ( $sizes  ? ' imagesizes="'  . esc_attr( $sizes )  . '"' : '' )
				. ">\n";
		}, 1 );

		add_filter( 'wp_get_attachment_image_attributes', function( $attr, $attachment ) use ( $thumbnail_id ) {
			if ( (int) $attachment->ID === (int) $thumbnail_id ) {
				$attr['fetchpriority'] = 'high';
				$attr['loading']       = 'eager';
				unset( $attr['decoding'] );
			}
			return $attr;
		}, 20, 2 );
		return;
	}

	// ── Category / shop page ─────────────────────────────────────────────
	if ( is_product_category() || is_shop() ) {
		$args = [
			'post_type'      => 'product',
			'posts_per_page' => 1,
			'post_status'    => 'publish',
			'fields'         => 'ids',
		];
		if ( is_product_category() ) {
			$term = get_queried_object();
			if ( $term ) {
				$args['tax_query'] = [[ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => $term->term_id,
				]];
			}
		}
		$products     = get_posts( $args );
		if ( empty( $products ) ) return;
		$thumbnail_id = get_post_thumbnail_id( $products[0] );
		if ( ! $thumbnail_id ) return;

		add_action( 'wp_head', function() use ( $thumbnail_id ) {
			$src    = wp_get_attachment_image_src( $thumbnail_id, 'woocommerce_thumbnail' );
			$srcset = wp_get_attachment_image_srcset( $thumbnail_id, 'woocommerce_thumbnail' );
			$sizes  = wp_get_attachment_image_sizes( $thumbnail_id, 'woocommerce_thumbnail' );
			if ( ! $src ) return;
			echo '<link rel="preload" as="image" fetchpriority="high" href="' . esc_url( $src[0] ) . '"'
				. ( $srcset ? ' imagesrcset="' . esc_attr( $srcset ) . '"' : '' )
				. ( $sizes  ? ' imagesizes="'  . esc_attr( $sizes )  . '"' : '' )
				. ">\n";
		}, 1 );

		add_filter( 'wp_get_attachment_image_attributes', function( $attr, $attachment ) use ( $thumbnail_id ) {
			if ( (int) $attachment->ID === (int) $thumbnail_id ) {
				$attr['fetchpriority'] = 'high';
				$attr['loading']       = 'eager';
				unset( $attr['decoding'] );
			}
			return $attr;
		}, 20, 2 );
	}
}


// ============================================================
// PWA META TAGS
// ============================================================

add_action( 'wp_head', 'dc_swp_meta_tags', 1 );

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_swp_meta_tags() {
	?>
	<meta name="theme-color" content="#46b450">
	<meta name="mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
	<meta name="apple-mobile-web-app-title" content="<?php bloginfo( 'name' ); ?>">
	<?php
}
