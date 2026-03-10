=== DC Service Worker Prefetcher ===
Contributors: dampcig
Tags: service worker, prefetch, cache, performance, w3tc
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight service worker that caches static assets and prefetches visible products in the viewport. Works standalone or with W3 Total Cache.

== Description ==

DC Service Worker Prefetcher installs a lean service worker (`/dc-sw.js`) that caches static assets (CSS, JS, fonts, images) locally in the browser using a Cache-First strategy. HTML pages are deliberately bypassed by the service worker so they are served fresh — either via W3 Total Cache or the plugin's own fallback cache headers.

On category and shop pages, products visible in the viewport are automatically prefetched via browser `<link rel="prefetch">`, so clicking a product loads it instantly.

= Key features =

* **Asset caching** — CSS, JS, fonts, and images cached locally via Cache-First strategy.
* **Viewport prefetching** — IntersectionObserver watches visible products and prefetches them before the user clicks.
* **Bot detection** — Bots and crawlers never receive the service worker, keeping crawl budget clean.
* **W3TC hybrid mode** — HTML pages are never intercepted by the SW; W3TC handles page caching.
* **Standalone mode** — When W3TC is not installed, the plugin emits `Cache-Control`, `Expires`, and `Vary` headers directly from PHP so browsers and CDNs can still cache responses.
* **Cart/checkout safe** — Service worker is unregistered on cart, checkout, and account pages.
* **Admin UI** — Toggle the service worker, configure the offline fallback URL, and control viewport preloading.
* **Bilingual** — Admin UI automatically switches between English and Danish (`da_DK`).
* **Optional footer credit** — Pre-checked, easily disabled. Defers to DC WebP Converter plugin if both are active.

= W3TC Hybrid Mode =

When W3 Total Cache is active:

* W3TC caches HTML pages (product pages, categories, homepage).
* This plugin caches static assets (CSS, JS, images) in the browser.
* No duplication, no conflicts.

= Standalone Mode (no W3TC) =

When W3 Total Cache is NOT active, the plugin automatically falls back to emitting PHP cache headers:

* Public pages: `Cache-Control: public, max-age=3600, stale-while-revalidate=60`
* Cart/checkout/logged-in: `Cache-Control: no-store`
* This works with any CDN or reverse proxy that respects standard HTTP cache headers.

== Installation ==

1. Upload the `dc-sw-prefetch` folder to `/wp-content/plugins/`.
2. Activate the plugin from the **Plugins** screen.
3. Go to **SW Prefetch** in the admin menu.
4. Verify settings and save. The service worker is active immediately.

== Frequently Asked Questions ==

= Will this interfere with WooCommerce cart/checkout? =
No. The service worker is automatically unregistered on cart, checkout, and account pages. Cache headers are also set to `no-store` on these pages.

= Does it work without W3 Total Cache? =
Yes — when W3TC is absent, the plugin emits PHP cache headers for public pages so browsers and CDNs cache responses correctly. The service worker handles all static asset caching.

= How do I verify the service worker is running? =
Open DevTools → Application → Service Workers. You should see `dc-sw.js` registered with scope `/`.

= What happens when a bot visits? =
The service worker is never registered for bots. Bot detection runs server-side before any JS is emitted.

= What headers does it add without W3TC? =
Public pages get: `Cache-Control: public, max-age=3600, stale-while-revalidate=60, stale-if-error=86400` and a matching `Expires` header. A debug header `X-Cache-Fallback: dc-sw-prefetch` is also added so you can verify in DevTools.

== Screenshots ==

1. Admin settings page (English).
2. Admin settings page (Danish).
3. DevTools Application panel showing registered service worker.

== Changelog ==

= 1.0.0 =
* Initial release. Service worker renamed to `dc-sw.js`.
* Standalone fallback cache headers added (fires when W3TC is not active).
* Bot detection wrapped in `function_exists` for safe coexistence with child themes.
* Footer credit with object-cache → transient strategy caching.
* Bilingual admin UI (English default, Danish auto-detected).

== Upgrade Notice ==

= 1.0.0 =
Initial release.

== Description ==

Service Worker Prefetcher installs a lean service worker (`/dampcig-sw.js`) that caches static assets (CSS, JS, fonts, images) locally in the browser using a Cache-First strategy. HTML pages are deliberately left to W3 Total Cache — no duplicated caching layers.

On category and shop pages, products visible in the viewport are automatically prefetched via browser `<link rel="prefetch">`, so clicking a product loads it instantly from the W3TC cache.

= Key features =

* **Asset caching** — CSS, JS, fonts, and images cached locally via Cache-First strategy.
* **Viewport prefetching** — IntersectionObserver watches visible products and prefetches them before the user clicks.
* **Bot detection** — Bots and crawlers never receive the service worker, keeping crawl budget clean.
* **W3TC hybrid mode** — HTML pages are never intercepted; W3TC handles page caching entirely.
* **Bot-safe** — Service worker is skipped entirely for known bots (Googlebot, PageSpeed, GTmetrix, etc.).
* **Cart/checkout safe** — Service worker is unregistered on cart, checkout, and account pages.
* **Admin UI** — Toggle the service worker, configure the offline fallback URL, and control viewport preloading.
* **Bilingual** — Admin UI automatically switches between English and Danish (`da_DK`).
* **Optional footer credit** — Pre-checked, easily disabled. Defers to Dampcig PNG→WebP plugin if both are active.

= W3TC Hybrid Mode =

This plugin is designed to work alongside W3 Total Cache:

* W3TC caches HTML pages (product pages, categories, homepage).
* This plugin caches static assets (CSS, JS, images) in the browser.
* No duplication, no conflicts.

== Installation ==

1. Upload the `dc-sw-prefetch` folder to `/wp-content/plugins/`.
2. Activate the plugin from the **Plugins** screen.
3. Go to **SW Prefetch** in the admin menu.
4. Verify settings and save. The service worker is active immediately.

== Frequently Asked Questions ==

= Will this interfere with WooCommerce cart/checkout? =
No. The service worker is automatically unregistered on cart, checkout, and account pages to prevent any interference with session handling.

= Does it work without W3 Total Cache? =
Yes — the asset caching and viewport prefetching work independently. Without W3TC, prefetched HTML pages will be fetched fresh from the server instead of from cache.

= How do I verify the service worker is running? =
Open DevTools → Application → Service Workers. You should see `dampcig-sw.js` registered with scope `/`.

= What happens when a bot visits? =
The service worker is never registered for bots. The bot detection runs server-side before any JS is emitted.

== Screenshots ==

1. Admin settings page (English).
2. Admin settings page (Danish).
3. DevTools Application panel showing registered service worker.

== Changelog ==

= 1.0.0 =
* Initial release. Extracted from ecommerce-gem-child theme.
* Bot detection wrapped in `function_exists` for safe coexistence with child themes.
* Footer credit with object-cache → transient strategy caching.
* Bilingual admin UI (English default, Danish auto-detected).

== Upgrade Notice ==

= 1.0.0 =
Initial release.
