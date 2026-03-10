<?php
/**
 * @wordpress-plugin
 * Plugin Name: DC Service Worker Prefetcher
 * Plugin URI:  https://www.dampcig.dk
 * Description: Service worker asset caching with W3TC hybrid mode, viewport-based product prefetching, and bot detection.
 * Version:     1.0.0
 * Author:      Dampcig
 * Author URI:  https://www.dampcig.dk
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dc-sw-prefetch
 * Domain Path: /languages
 * Requires at least: 6.0
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
	if ( get_option( 'dampcig_pwa_footer_credit', 'yes' ) !== 'yes' ) return;
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
// SERVICE WORKER — serve /dc-sw.js on 'init'
// ============================================================

add_action( 'init', 'dc_swp_serve_service_worker', 1 );

/**
 * Intercept requests for /dc-sw.js and stream the service worker JS.
 * Caches static assets (CSS, JS, fonts, images).
 * HTML pages are handled by W3 Total Cache (or fallback headers above).
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_swp_serve_service_worker() {
	$request_uri = isset( $_SERVER['REQUEST_URI'] )
		? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		: '';

	if ( $request_uri !== '/dc-sw.js' ) {
		return;
	}

	if ( dc_swp_is_bot_request() ) {
		status_header( 404 );
		exit();
	}

	$sw_enabled   = get_option( 'dampcig_pwa_sw_enabled', 'yes' ) === 'yes';
	$offline_page = get_option( 'dampcig_pwa_offline_page', '/offline/' );

	status_header( 200 );
	header( 'Content-Type: application/javascript; charset=utf-8' );
	header( 'Service-Worker-Allowed: /' );
	header( 'X-Robots-Tag: none' );
	header( 'Cache-Control: no-cache, no-store, must-revalidate' );
	header( 'Pragma: no-cache' );
	header( 'Expires: 0' );

	if ( ! $sw_enabled ) {
		echo "// Service Worker disabled\nself.addEventListener('install', () => self.skipWaiting());";
		exit();
	}

	$sw_content = <<<'SWJS'
'use strict';

// ===================================================================
// DC SERVICE WORKER - ASSET CACHING MODE
// Assets (CSS, JS, fonts, images) cached locally.
// HTML pages bypassed to W3TC (or browser/CDN via fallback headers).
// ===================================================================

const CACHE_VERSION  = 'dc-sw-assets-v2';
const OFFLINE_PAGE   = '/offline/';

// Only cache static assets (NOT HTML pages)
const CACHE_EXTENSIONS = [
    '.css', '.js',
    '.woff2', '.woff', '.ttf', '.eot',
    '.svg', '.png', '.jpg', '.jpeg', '.gif', '.webp', '.ico'
];

// Never cache these paths
const EXCLUDED_PATHS = [
    '/wp-admin', '/wp-login', '/kurv', '/kassen', '/min-konto', '/ajax'
];

function shouldCacheAsset(url) {
    try {
        const urlObj = new URL(url);
        if (urlObj.origin !== location.origin) return false;
        for (let path of EXCLUDED_PATHS) {
            if (urlObj.pathname.includes(path)) return false;
        }
        return CACHE_EXTENSIONS.some(ext => urlObj.pathname.toLowerCase().endsWith(ext));
    } catch (e) {
        return false;
    }
}

// Install — minimal precaching
self.addEventListener('install', (event) => {
    console.log('[SW] Installing — DC Service Worker Prefetcher');
    event.waitUntil(
        caches.open(CACHE_VERSION).then((cache) => {
            return fetch(OFFLINE_PAGE, { method: 'HEAD' })
                .then(() => cache.add(OFFLINE_PAGE))
                .catch(() => console.log('[PWA] No offline page found, skipping precache'));
        })
    );
    self.skipWaiting();
});

// Activate — purge old caches
self.addEventListener('activate', (event) => {
    console.log('[SW] Activating — purging old caches');
    event.waitUntil(
        caches.keys().then((cacheNames) => Promise.all(
            cacheNames
                .filter(name => name !== CACHE_VERSION)
                .map(name => { console.log('[SW] Deleting old cache:', name); return caches.delete(name); })
        ))
    );
    return self.clients.claim();
});

// Fetch — assets cached, HTML bypassed
self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') return;

    const urlObj = new URL(request.url);
    const isExcluded = EXCLUDED_PATHS.some(path => urlObj.pathname.includes(path));
    if (isExcluded) return;

    if (urlObj.searchParams.has('remove_item')  ||
        urlObj.searchParams.has('add-to-cart')  ||
        urlObj.searchParams.has('update_cart'))  return;

    if (urlObj.origin !== self.location.origin) return;

    if (shouldCacheAsset(request.url)) {
        event.respondWith(
            caches.match(request).then((cached) => {
                if (cached) return cached;
                return fetch(request).then((response) => {
                    if (response && response.status === 200) {
                        const clone = response.clone();
                        caches.open(CACHE_VERSION).then(cache => cache.put(request, clone));
                    }
                    return response;
                }).catch(err => { console.error('[SW] Fetch failed:', request.url); throw err; });
            })
        );
    } else {
        event.respondWith(
            fetch(request).catch(() => {
                if (request.destination === 'document') {
                    console.log('[SW] Network failed, showing offline page');
                    return caches.match(OFFLINE_PAGE);
                }
            })
        );
    }
});

// Messages
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') self.skipWaiting();
});

console.log('[SW] DC Service Worker Prefetcher loaded');
SWJS;

	echo $sw_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- intentional raw JS output
	exit();
}


// ============================================================
// REGISTER SERVICE WORKER IN FOOTER + VIEWPORT PRELOADING
// ============================================================

add_action( 'wp_footer', 'dc_swp_register_sw', 9999 );

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_swp_register_sw() {
	if ( dc_swp_is_bot_request() ) {
		return;
	}

	if ( is_cart() || is_checkout() || is_account_page() ) {
		?>
		<script>
		if ('serviceWorker' in navigator) {
			navigator.serviceWorker.getRegistrations().then(registrations => {
				registrations.forEach(r => {
				if (r.active && r.active.scriptURL.includes('dc-sw.js')) {
					console.log('[SW] Service worker disabled on this page');
					}
				});
			});
		}
		</script>
		<?php
		return;
	}

	$preload_enabled = get_option( 'dampcig_pwa_preload_products', 'yes' ) === 'yes';
	?>
	<script>
	<?php if ( $preload_enabled ) : ?>
	function dampcigPreloadVisibleProducts() {
		const hasProducts = document.querySelector('.products .product, ul.products li.product, .woocommerce-loop-product__link');
		if (!hasProducts) return;

		const prefetchedUrls = new Set();
		const prefetchProduct = (url) => {
			if (prefetchedUrls.has(url)) return;
			prefetchedUrls.add(url);
			const link = document.createElement('link');
			link.rel  = 'prefetch';
			link.href = url;
			link.as   = 'document';
			document.head.appendChild(link);
			console.log('[SW] Prefetching:', url);
		};

		if ('IntersectionObserver' in window) {
			const observer = new IntersectionObserver((entries) => {
				entries.forEach(entry => {
					if (!entry.isIntersecting) return;
					let a = entry.target.querySelector('a.woocommerce-loop-product__link')
					     || entry.target.querySelector('a.product-link')
					     || entry.target.querySelector(':scope > a');
					if (a && a.href && !a.href.includes('add-to-cart') && !a.href.includes('#') && !a.href.includes('?remove_item')) {
						setTimeout(() => { if (entry.isIntersecting) prefetchProduct(a.href); }, 500);
					}
				});
			}, { rootMargin: '50px', threshold: 0.1 });

			const items = document.querySelectorAll('.products .product, ul.products li.product, .product-item');
			items.forEach(item => observer.observe(item));

			const nextPage = document.querySelector('.woocommerce-pagination a.next, .next.page-numbers, a.next-page');
			if (nextPage && nextPage.href) {
				setTimeout(() => prefetchProduct(nextPage.href), 2000);
			}
					console.log(`[SW] Monitoring ${items.length} products for viewport prefetching`);
		} else {
			const vh = window.innerHeight;
			document.querySelectorAll('.products .product a.woocommerce-loop-product__link, ul.products li.product > a').forEach(a => {
				if (!a.href || a.href.includes('add-to-cart') || a.href.includes('#')) return;
				const rect = a.getBoundingClientRect();
				if (rect.top >= 0 && rect.top <= vh) prefetchProduct(a.href);
			});
			const next = document.querySelector('.woocommerce-pagination a.next, .next.page-numbers');
			if (next && next.href) prefetchProduct(next.href);
		}
	}
	<?php endif; ?>

	if ('serviceWorker' in navigator) {
		window.addEventListener('load', () => {
			navigator.serviceWorker.register('/dc-sw.js', { scope: '/' })
				.then(registration => {
					console.log('[SW] Service Worker registered');
					if (registration.active) registration.update();
					<?php if ( $preload_enabled ) : ?>
					dampcigPreloadVisibleProducts();
					<?php endif; ?>
				})
				.catch(err => console.error('[SW] Service Worker registration failed:', err));
		});
	}
	</script>
	<?php
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
