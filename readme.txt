=== DC Service Worker Prefetcher ===
Contributors: lennilg
Tags: service worker, prefetch, partytown, performance, woocommerce
Requires at least: 6.8
Tested up to: 6.9
Requires PHP: 8.0
WC tested up to: 10.4.3
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Partytown service worker (third-party script offloading) + viewport/pagination prefetching for WooCommerce. Vendored — no npm required.

== Description ==

DC Service Worker Prefetcher integrates [Partytown](https://github.com/QwikDev/partytown) into WordPress as a vendored plugin.

The key distinction from `async`/`defer`: those attributes only delay *loading* — the script still *executes* on the browser's main thread and competes with layout, paint, and user interactions. Partytown moves script *execution* into a Web Worker entirely. The main thread is never touched — no layout jank, no Total Blocking Time (TBT) penalty, no competition with your code.

Officially tested compatible services: **Google Tag Manager** (GA4), **Facebook Pixel**, **HubSpot**, **Intercom**, **Klaviyo**, **TikTok Pixel**, and **Mixpanel**. See [partytown.qwik.dev/common-services](https://partytown.qwik.dev/common-services/) for the full reference list.

On top of Partytown, the plugin ships its own viewport/pagination prefetcher: products visible in the viewport are prefetched via `<link rel="prefetch">` so clicking a product loads it instantly from W3TC or the browser cache.

= Key features =

* **Partytown Web Worker execution** — unlike `async`/`defer` (which only delay loading, scripts still run on main thread), Partytown executes third-party scripts in a Web Worker. Officially tested: Google Tag Manager, Facebook Pixel, HubSpot, Intercom, Klaviyo, TikTok Pixel, Mixpanel.
* **Consent-aware loading** — reads marketing-consent cookies from 8 common WordPress CMPs (Complianz, Cookiebot, CookieYes, Borlabs, Cookie Notice, WebToffee, Cookie Information, Moove GDPR). Scripts get `type="text/partytown"` only after consent is granted; blocked with `type="text/plain"` until then.
* **Configured via URL patterns** — enter one URL pattern per line in the admin. Any `<script src>` whose src matches is automatically managed for consent + Partytown offloading. No manual code edits.
* **Auto-detect** — one-click scan of the homepage discovers all external scripts and lets you add them to the list.
* **Exclusion list** — built-in exclusions for Trustpilot, Stripe, PayPal, Braintree, Facebook SDK, Google Maps, and Reamaze; add your own patterns as needed.
* **Vendored lib** — Partytown's `lib/` files are bundled in `assets/partytown/`; no npm or build step needed on the server.
* **Automatic Partytown updates** — a weekly GitHub Actions workflow detects new Partytown releases and opens a PR with the updated vendor files.
* **Viewport prefetching** — IntersectionObserver watches visible products and issues `<link rel="prefetch">` before the user clicks.
* **Pagination prefetch** — next-page link is prefetched 2 s after page load.
* **WP emoji removal** — dequeues the emoji detection script and CSS (76 KB round-trip to s.w.org eliminated).
* **Bot detection** — bots never receive Partytown or prefetch JS, keeping crawl budget clean.
* **W3TC compatible** — HTML pages are cached by W3TC; Partytown handles only script execution.
* **Standalone mode** — when W3TC is absent, PHP fallback cache headers keep browsers and CDNs caching correctly.
* **Cart/checkout safe** — Partytown and the prefetcher are skipped on cart, checkout, and account pages.
* **Admin UI** — toggle Partytown, control viewport prefetch, see the vendored Partytown version at a glance.
* **Bilingual** — EN/DA auto-detection.
* **Optional footer credit** — easily disabled.

= Architecture =

| Layer | Handled by |
|---|---|
| Third-party scripts (GA, Pixel…) | Partytown service worker |
| HTML page caching | W3 Total Cache (or PHP fallback headers) |
| Product/pagination prefetch | DC Prefetch (IntersectionObserver) |

= Updating Partytown =

**Automatic:** the `update-partytown.yml` workflow runs every Monday at 08:00 UTC and opens a PR when a new release is detected.

**Manual:** run `bash scripts/update-partytown.sh` (or `bash scripts/update-partytown.sh 0.10.3` to pin a version). Then commit `assets/partytown/` and `package.json`.

= Using Partytown with third-party scripts =

The easiest way is the **Partytown Script List** in the admin settings. Enter one URL pattern per line (e.g. `analytics.ahrefs.com` or the full GTM URL). The plugin then:
1. Checks for marketing-consent cookies on every page request.
2. Sets `type="text/partytown"` when consent is present — Partytown runs the script off the main thread.
3. Sets `type="text/plain"` when consent is absent — the script is silently blocked.

Alternatively, you can manually tag a script:

    <script type="text/partytown" src="https://www.googletagmanager.com/gtag/js?id=G-XXXX"></script>

The `window.partytown.forward` array is pre-configured for all officially tested services: `dataLayer.push` (GTM), `fbq` (Facebook Pixel), `_hsq.push` (HubSpot), `Intercom`, `_learnq.push` (Klaviyo), `ttq.track`/`ttq.page`/`ttq.load` (TikTok Pixel), `mixpanel.track` (Mixpanel). See [partytown.qwik.dev/common-services](https://partytown.qwik.dev/common-services/).

= Supported consent plugins =

| Plugin | Cookie detected |
|---|---|
| Complianz | `cmplz_marketing = allow` |
| Cookiebot (Cybot) | `CookieConsent` contains `marketing:true` |
| CookieYes | `cookieyes-consent` contains `marketing:yes` |
| Borlabs Cookie | `borlabs-cookie` JSON `.consents.marketing` |
| Cookie Notice (dFactory) | `cookie_notice_accepted = true` |
| WebToffee GDPR | `cookie_cat_marketing = accept` |
| Cookie Information | `CookieInformationConsent` JSON consents array |
| Moove GDPR | `moove_gdpr_popup` JSON `.thirdparty = 1` |

== Installation ==

1. Upload the `dc-sw-prefetch` folder to `/wp-content/plugins/`.
2. Activate the plugin from the **Plugins** screen.
3. Go to **SW Prefetch** in the admin menu.
4. Enable Partytown and/or Viewport Preloading and save.

== Frequently Asked Questions ==

= Will this interfere with WooCommerce cart/checkout? =
No. Partytown and the prefetcher are completely disabled on cart, checkout, and account pages.

= Does it work without W3 Total Cache? =
Yes. PHP fallback cache headers are emitted for public pages when W3TC is not active.

= Will scripts load before the user gives consent? =
No. The plugin reads marketing-consent cookies server-side on every request. Scripts are output as `type="text/plain"` (browser-blocked) until a supported CMP cookie indicates consent, at which point they become `type="text/partytown"`.

= Which consent plugins are supported? =
Complianz, Cookiebot, CookieYes, Borlabs Cookie, Cookie Notice (dFactory), WebToffee GDPR, Cookie Information, and Moove GDPR. See the full cookie details in the Description section.

= How do I verify Partytown is running? =
Open DevTools → Application → Service Workers. You should see `partytown-sw.js` registered under `/~partytown/`. In the Console you should see no third-party scripts on the main thread.

= How do I update Partytown? =
Either let the weekly GitHub Action open a PR automatically, or run `bash scripts/update-partytown.sh` locally.

= What scripts does Partytown forward by default? =
All officially tested services from [partytown.qwik.dev/common-services](https://partytown.qwik.dev/common-services/): `dataLayer.push` (Google Tag Manager / GA4), `fbq` (Facebook Pixel), `_hsq.push` (HubSpot), `Intercom`, `_learnq.push` (Klaviyo), `ttq.track`/`ttq.page`/`ttq.load` (TikTok Pixel), `mixpanel.track` (Mixpanel). LinkedIn Insight Tag and Twitter/X Pixel are not on the official tested list and are excluded.

== Screenshots ==

1. Admin settings page (English) showing Partytown version and changelog link.
2. Admin settings page (Danish).
3. DevTools showing Partytown service worker registered at `/~partytown/`.

== Changelog ==

= 1.3.1 =
* Refactor: Move all inline JS to static files in `assets/js/` with `wp_localize_script()` for PHP data injection.
* Refactor: Add `DC_SWP_VERSION` constant for consistent script versioning and cache-busting.
* Tooling: Add ESLint configuration targeting `assets/js/`.

= 1.3.0 =
* **New:** Consent-aware script loading — reads marketing-consent cookies from 8 common WordPress CMPs (Complianz, Cookiebot, CookieYes, Borlabs, Cookie Notice, WebToffee, Cookie Information, Moove GDPR).
* Scripts in the Partytown list now output `type="text/partytown"` when consent is granted and `type="text/plain"` when it is not — no CMP hooks or DOM patching required.
* Removed the `dc_swp_cmp_intercept_script` Node.prototype hook introduced in 1.2.x (approach replaced by server-side consent detection).
* Version bump: 1.2.0 → 1.3.0.

= 1.2.0 =
* **New:** WP emoji removal — dequeues `print_emoji_detection_script` and `print_emoji_styles` saving ~76 KB and one s.w.org DNS lookup per page. Toggle in admin (default: on).
* Version bump: 1.1.0 → 1.2.0.

= 1.1.0 =
* **Breaking:** replaced custom `dc-sw.js` asset-caching service worker with vendored Partytown 0.10.3.
* Partytown lib files served from `/~partytown/` (plugin `assets/partytown/`).
* Added `scripts/update-partytown.sh` for manual vendor updates.
* Added `.github/workflows/update-partytown.yml` weekly auto-update bot.
* Added `package.json` to track vendored Partytown version.
* Removed offline-fallback-page setting (no longer needed without custom SW).
* Viewport/pagination prefetcher unchanged and fully retained.

= 1.0.0 =
* Initial release. Service worker renamed to `dc-sw.js`.
* Standalone fallback cache headers added (fires when W3TC is not active).
* Bot detection wrapped in `function_exists` for safe coexistence with child themes.
* Footer credit with object-cache → transient strategy caching.
* Bilingual admin UI (English default, Danish auto-detected).

== Upgrade Notice ==

= 1.0.0 =
Initial release.
