=== DC Script Worker Prefetcher ===
Contributors: lennilg
Tags: thirdparty, performance, analytics, pagespeed, vitals
Requires at least: 6.8
Tested up to: 6.9
Requires PHP: 8.0
WC tested up to: 10.4.3
Stable tag: 1.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offload third-party scripts (GTM, Pixel, HubSpot…) to a web worker via Partytown + consent-aware loading + WooCommerce prefetching. Vendored.

== Description ==

DC Script Worker Prefetcher integrates [Partytown](https://partytown.qwik.dev/) into WordPress as a vendored plugin. Partytown is a **lazy-loaded library** designed to relocate resource-intensive scripts into a web worker and off the main thread — dedicating the main thread to your code while offloading third-party analytics, ads, and tracking scripts to a web worker.

The key distinction from `async`/`defer`: those attributes delay *when* a script executes relative to HTML parsing, but execution still happens on the browser's main thread, competing with layout, paint, and user interactions. Third-party scripts using `async`/`defer` can also still block `window.onload`. Partytown moves script *execution* into a Web Worker entirely — the main thread is never touched — no layout jank, no Total Blocking Time (TBT) penalty, no competition with your code.

**Note:** Partytown is currently in beta and not guaranteed to work in every scenario. See the [Partytown FAQ](https://partytown.qwik.dev/faq) and [Trade-offs](https://partytown.qwik.dev/trade-offs) pages for more information before deploying to production.

Officially tested compatible services: **Google Tag Manager** (GA4), **Facebook Pixel**, **HubSpot**, **Intercom**, **Klaviyo**, **TikTok Pixel**, and **Mixpanel**. See [partytown.qwik.dev/common-services](https://partytown.qwik.dev/common-services/) for the full reference list.

On top of Partytown, the plugin ships its own viewport/pagination prefetcher: products visible in the viewport are prefetched via `<link rel="prefetch">` so clicking a product loads it instantly from W3TC or the browser cache.

= Key features =

* **Partytown Web Worker execution** — unlike `async`/`defer` (which still execute on the main thread and can block `window.onload`), Partytown lazy-loads and executes third-party scripts entirely in a Web Worker. Officially tested: Google Tag Manager, Facebook Pixel, HubSpot, Intercom, Klaviyo, TikTok Pixel, Mixpanel.
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

= How Partytown works =

1. Scripts matching your configured patterns are output with `type="text/partytown"` — this attribute tells the browser **not** to execute them on the main thread.
2. Partytown's **service worker** intercepts fetch requests from the web worker.
3. The **web worker** receives and executes the scripts entirely off the main thread.
4. **JavaScript Proxies** replicate main thread APIs (DOM reads/writes) synchronously inside the worker — so third-party scripts run exactly as coded, without modification.
5. Communication between the web worker and main thread uses either:
   * **Synchronous XHR + Service Worker** (default fallback)
   * **Atomics bridge** when `crossOriginIsolated` is enabled — roughly **10× faster**. Activated by enabling the **SharedArrayBuffer (Atomics Bridge)** option in settings, which sends the required `Cross-Origin-Opener-Policy` and `Cross-Origin-Embedder-Policy` headers.

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

= Are there any trade-offs or known limitations? =
Yes. Partytown is in beta. While it works well for the [officially tested services](https://partytown.qwik.dev/common-services/), some scripts may not be compatible — particularly those that rely on APIs not yet proxied by Partytown, use synchronous `document.write()`, or require persistent event listeners on the main thread. Test in staging before enabling on production. See the [Partytown trade-offs page](https://partytown.qwik.dev/trade-offs) for a full list of known limitations.

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

== External Services ==

This plugin is a **framework** for running administrator-configured third-party scripts off the browser's main thread using the vendored [Partytown](https://partytown.qwik.dev/) library. The plugin itself does not connect to any external service autonomously; external connections occur only for services the site administrator explicitly enables through the plugin settings.

= Partytown library =

The Partytown JavaScript library is fully vendored inside the plugin (assets/partytown/). No files are downloaded from any CDN at runtime. The library is loaded from your own server.

= CORS proxy (server-side script relay) =

Some third-party CDNs do not send CORS headers, which prevents Partytown's sandbox from fetching their scripts directly from the browser. To work around this, the plugin provides a /~partytown-proxy endpoint: WordPress makes a **server-side HTTP request** to the CDN and re-serves the script to the browser.

* **What is sent:** an HTTP GET request for the script file (no visitor personal data).
* **When:** only when Partytown cannot fetch the script directly due to missing CORS headers, and only for hostnames the administrator has added to the Partytown Script List.
* **Allowlist-only:** the proxy strictly rejects any hostname not configured by the administrator — no hard-coded vendor list is contacted automatically.

= Third-party analytics and marketing scripts (site administrator configured) =

When the administrator adds a service's URL pattern to the Partytown Script List, that service's script is loaded and executed (inside a Web Worker via Partytown) for site visitors who have granted marketing consent. The visitor's browser (or the CORS proxy above) contacts the service's CDN, and the service may receive visitor data according to its own terms.

**Scripts are only loaded and data is only transmitted when:**
1. The administrator has added the service's URL pattern to the plugin's Partytown Script List or Inline Script Blocks.
2. The visitor has a valid marketing-consent cookie from a supported CMP (Complianz, Cookiebot, CookieYes, Borlabs Cookie, Cookie Notice, WebToffee, Cookie Information, or Moove GDPR). Without a consent cookie, all configured scripts are blocked (output as type="text/plain").

The plugin ships pre-configured forwarding for these officially tested services. When enabled by the administrator, each may receive visitor data:

**Google Tag Manager / Google Analytics 4**
Provided by Google LLC. Sends page URL and visitor interaction events to Google's servers when the administrator has added a googletagmanager.com or google-analytics.com pattern and the visitor has consented.
[Privacy Policy](https://policies.google.com/privacy) | [Terms of Service](https://policies.google.com/terms)

**Meta (Facebook) Pixel**
Provided by Meta Platforms, Inc. Sends page URL, conversion events, and (if configured by the site) hashed visitor identifiers.
[Privacy Policy](https://www.facebook.com/privacy/policy) | [Terms of Service](https://www.facebook.com/terms)

**HubSpot Analytics**
Provided by HubSpot, Inc. Sends page views and form interaction events.
[Privacy Policy](https://legal.hubspot.com/privacy-policy) | [Terms of Service](https://legal.hubspot.com/terms-of-service)

**Intercom**
Provided by Intercom R&D Unlimited Company. Sends visitor identity information, page views, and custom events.
[Privacy Policy](https://www.intercom.com/legal/privacy) | [Terms of Service](https://www.intercom.com/legal/terms-and-policies)

**Klaviyo**
Provided by Klaviyo, Inc. Sends page views, cart and checkout events, and (if provided by the site) visitor email address.
[Privacy Policy](https://www.klaviyo.com/legal/privacy-notice) | [Terms of Service](https://www.klaviyo.com/legal/terms-of-service)

**TikTok Pixel**
Provided by TikTok Inc. / ByteDance Ltd. Sends page views, conversion events, and (if configured) hashed visitor identifiers.
[Privacy Policy](https://www.tiktok.com/legal/page/global/privacy-policy) | [Terms of Service](https://ads.tiktok.com/i18n/official/policy/contractor)

**Mixpanel**
Provided by Mixpanel, Inc. Sends page views, custom events, and an anonymous visitor identifier.
[Privacy Policy](https://mixpanel.com/legal/privacy-policy/) | [Terms of Service](https://mixpanel.com/legal/terms-of-use/)

The administrator may freely add other services through the Partytown Script List. The plugin imposes no restriction on which services can be configured, beyond the security allowlist that prevents the CORS proxy from being used as an open relay. Refer to each service's own privacy policy and terms of service for details on what data they collect.

== Screenshots ==

1. Admin settings page (English) showing Partytown version and changelog link.
2. Admin settings page (Danish).
3. DevTools showing Partytown service worker registered at `/~partytown/`.

== Changelog ==

= 1.6.0 =
* Standards: Renamed all `dampcig_pwa_*` options to `dc_swp_*` prefix; existing settings are migrated automatically on activation.
* Standards: Removed global PHPCS EscapeOutput suppression; per-line justification comments used instead.
* Standards: Renamed `dc_footer_credit_owner` sentinel function to `dc_swp_footer_credit_owner`.
* Standards: Replaced `file_get_contents()` with the WP Filesystem API for local file reads.
* Security: `dc_swp_sanitize_js_code()` now applied to inline script code fields at save time.

= 1.5.3 =
* Fix: Google Consent Mode v2 — set `gtag('consent','default')` directly to `granted` when the visitor's CMP cookie is already set, rather than emitting a redundant default-denied + update-granted pair. `wait_for_update` is only included when consent has not yet been given.

= 1.5.2 =
* Fix: Google Consent Mode v2 — immediately follow the `gtag('consent','default',{denied})` stub with a `gtag('consent','update',{granted})` call when the visitor's CMP marketing cookie is already set, so GTM/GA4 receive the correct consent state without waiting for the CMP JavaScript to fire.

= 1.5.1 =
* Compat: Declare WooCommerce HPOS (High-Performance Order Storage / Custom Order Tables) compatibility. The plugin does not interact with the orders table, so it is unconditionally compatible. Resolves the "Incompatible plugin" warning in WooCommerce → Settings → Advanced → Features.

= 1.5.0 =
**Google Consent Mode v2 — per-service consent architecture**

* Feature: Replaced the global GCM v2 bypass with a per-service consent gate. Six hostnames are now classified as GCM v2-aware (`googletagmanager.com`, `google-analytics.com`, `static.hotjar.com`, `script.hotjar.com`, `clarity.ms`, `snap.licdn.com`, `analytics.tiktok.com`): when GCM v2 is active these scripts always run as `type="text/partytown"` — each service reads the consent state and self-restricts data collection internally. Unrelated scripts (HubSpot, Intercom, Mixpanel, etc.) continue to gate on the marketing consent cookie as before.
* Feature: Meta Pixel is intentionally excluded from GCM v2 — it uses its own Limited Data Use (LDU) consent API. The existing Meta LDU toggle is now the correct gate for Meta Pixel regardless of GCM v2 state. Both mechanisms are fully independent.
* Feature: New helper API — `dc_swp_get_gcm_v2_aware_services()` (filterable via `dc_swp_gcm_v2_aware_services`), `dc_swp_script_uses_gcm_v2()`, `dc_swp_is_meta_script()`, `dc_swp_inline_uses_gcm_v2()`, `dc_swp_inline_is_meta()`. Developers can register additional GCM v2-aware services via the filter without modifying plugin code.
* Feature: Per-service gate applied to all three consent-check locations: `wp_script_attributes` filter (priority 5), output-buffer rewriter, and inline Script Block output.
* Feature: Consent Architecture info panel added to the admin settings page — collapsible `<details>` element showing three badge groups: GCM v2-aware services, Meta Pixel LDU, and CMP compatibility (8 CMPs). Badges use shields.io SVGs with a pure CSS fallback for offline/firewalled staging environments; the CSS pill renders from `data-label`/`data-msg` attributes and flips to the shields.io image via an `onload` swap — zero flash, no broken images.
* Feature: CMP compatibility research completed and documented in the panel: Complianz, CookieYes, Cookiebot, Cookie Information, Borlabs Cookie, and WebToffee GDPR fire `gtag('consent','update',…)` natively without GTM; Moove GDPR requires the premium plan; Cookie Notice (free) cannot fire GCM v2 update signals and is marked as "fallback only".
* Enhancement: Updated `consent_mode_desc` and `meta_ldu_desc` admin strings in both EN and DA to accurately describe the per-service architecture and distinguish the two consent mechanisms.

**Bug fixes**

* Fix: `dc_swp_partytown_buffer_rewrite()` was computing `$new_type` but never writing it into `$tag_inner`. Raw-echoed `<script src>` tags (e.g. analytics scripts added directly via `functions.php` rather than `wp_enqueue_script`) bypassed the consent gate entirely and executed on the main thread. The rewriter now injects or replaces the `type` attribute before returning the tag.
* Fix: Added `break` after `$attributes['type']` is set in `dc_swp_partytown_script_attrs()`. The loop previously continued iterating all remaining patterns after a match, wasting cycles and allowing a later broader pattern to overwrite the type set by the more specific one.

**Security**

* Security: `sslverify => false` removed from the auto-detect AJAX handler (`dc_swp_ajax_detect_scripts`). Certificate verification is now always enabled — a self-signed cert in staging is no longer a reason to globally bypass SSL verification (OWASP A02).

**Standards & housekeeping**

* Standards: Plugin main-file header brought fully in line with blueprint §2: `License` changed to SPDX identifier `GPL-2.0-or-later`; `Update URI` added (prevents accidental WordPress.org auto-update overwrite); `WC tested up to: 10.4.3` added to the PHP header (was previously only in `readme.txt`).
* i18n: Badge and force-Partytown UI strings (`badge_supported`, `badge_unsupported`, `force_pt_label`, `force_pt_notice`) were hardcoded English in `wp_localize_script`. Moved into `dc_swp_str()` with full Danish translations — the admin UI is now fully bilingual for all dynamic strings.
* Cleanup: `dc_swp_debug_mode` option was the only plugin option not deleted on uninstall. Added to the `uninstall.php` cleanup list.

= 1.4.2 =
* Feature: Script Block compatibility badges — each block now shows a "✓ Supported / Partytown" or "⚠ Unsupported / Deferred" badge based on whether its scripts (src= or inline) reference a Partytown-verified service.
* Feature: "Force Enable Partytown" toggle for unsupported Script Block scripts — admin can force an unknown src= script into the Partytown worker, with a warning notice that render errors should be tested in debug mode.
* Fix: Script Block inline scripts (e.g. Meta Pixel) that reference a known-service URL are now correctly routed to the Partytown worker; unknown inline scripts fall back to deferred main-thread execution instead of always entering the worker.
* Fix: Script Block src= scripts with unknown hostnames now always run on the main thread (consent-gated), preventing the about:srcdoc sandbox error caused by Partytown trying to run iframe-dependent scripts (e.g. ContentSquare) in the worker.
* Refactor: `dc_swp_get_known_services()` is now the single source of truth for Partytown compatibility decisions — shared by Script Block output, inline detection, and the auto-detect AJAX handler.
* Refactor: `dc_swp_inline_matches_known_service()` added to mirror the JS-side known-service scan for inline script bodies.
* Chore: Add vendor/ and node_modules/ exclude-patterns to phpcs.xml so `vendor/bin/phpcs` without arguments only lints plugin files.

= 1.4.1 =
* Chore: Rename plugin to "DC Script Worker Prefetcher" in all remaining source files (admin.php, uninstall.php, phpcs.xml, package.json, .pot file, copilot-instructions.md, PHP docblock headers).

= 1.4.0 =
* Feature: Add FullStory (`FS.identify`, `FS.event`) to the Partytown `forward` array — now on the official tested-services list.
* Feature: Auto-enable `strictProxyHas` when FullStory patterns are detected in the Script List or Inline Script Blocks, preventing the `in` operator false-positive that blocks FullStory initialisation via GTM.
* Feature: Add **Partytown Debug Mode** admin toggle (`dc_swp_debug_mode`). When enabled, loads the unminified `debug/partytown.js` build and sets all seven Partytown log flags (`logCalls`, `logGetters`, `logSetters`, `logImageRequests`, `logScriptExecution`, `logSendBeaconRequests`, `logStackTraces`) for DevTools Verbose output. Bilingual UI (EN/DA).
* Feature: Expose `window.dcSwpPartytownUpdate()` JS helper in `partytown-config.js` that dispatches the `ptupdate` CustomEvent, enabling integrators to notify Partytown of dynamically appended `type="text/partytown"` scripts.

= 1.3.9 =
* Fix: Sanitize each field in `dc_swp_sanitize_inline_scripts_option()` register_setting callback (id via sanitize_key, label via sanitize_text_field, enabled as boolean).
* Fix: Explicitly close output buffer on WordPress `shutdown` action (priority 0) via `dc_swp_partytown_buffer_end()` to prevent buffer-stack misalignment with other plugins.
* Fix: Move `phpcs:ignore` comment to hook-name line for `dc_swp_coi_crossorigin_patterns` and `dc_swp_inline_companion_map` apply_filters calls.
* Fix: Add `phpcs:ignore` to `dc_swp_enqueue_admin_assets` function declaration.
* Deploy: Add SSH cleanup step to remove dev-only files (.distignore, scripts/, vendor/, etc.) from the production server after rsync.
* Docs: Add External Services section to readme.txt and README.md disclosing Partytown, CORS proxy, and all pre-configured third-party analytics services with privacy policy and terms links.
* Docs: Correct async/defer description — scripts still execute on the main thread (and can block window.onload); the download is already off-thread.
* Docs: Add Partytown beta disclaimer and trade-offs link.
* Docs: Add "How Partytown works" section explaining type attribute, service worker, web worker, JS Proxies, and Atomics vs sync XHR communication.

= 1.3.8 =
* Refactor: Auto-detect scan now returns all third-party scripts found on the homepage. Scripts on Partytown's officially verified services list (https://partytown.qwik.dev/common-services/) are pre-checked and shown with a green badge; unrecognised scripts are shown unchecked with an "unknown compatibility" warning so admins make an explicit choice rather than having scripts silently accepted or rejected.
* Removed: The auto-detect feature no longer auto-populates the exclude/blocklist textarea when incompatible scripts are found — the exclude-list concept is being phased out in favour of a pure allow-list architecture.

= 1.3.7 =
* Revert: Remove `script_loader_tag` filter that patched third-party plugin scripts. DC SW Prefetch only manages scripts it explicitly moves into Partytown; all other scripts are left entirely on the main thread as-is. Fixing compatibility issues in scripts belonging to other plugins is out of scope.

= 1.3.6 =
* Fix: Strip `crossorigin="anonymous"` from `widget-bootstrap-js` (Trustpilot bootstrap) via `script_loader_tag` filter. Under `COEP: credentialless`, scripts with `crossorigin` are fetched in CORS mode; since `widget.trustpilot.com` sends no `Access-Control-Allow-Origin` header the load is blocked. Without the attribute the browser uses no-cors mode, which succeeds. The Trustpilot plugin added `crossorigin` via the WP 6.3+ `$args` API; this filter removes it for scripts that are incompatible with enforced CORS.

= 1.3.5 =
* Revert: restore `partytown-config.js` as a normal enqueued file (the inline approach in 1.3.3 was a misdiagnosis — the Partytown SW does not intercept regular script requests). The `file_get_contents()` load and unnecessary inline output are removed.

= 1.3.4 =
* Fix: `resolveUrl` in `partytown-config.js` now uses `this.pathRewrites` / `this.proxyAllowedHosts` / `this.proxyUrl` instead of referencing `dcSwpPartytownData` directly. Partytown serialises `resolveUrl` to a string and reconstructs it with `new Function()` inside the web worker, so closures are lost — the function must be fully self-contained. Data is now stored as plain properties on `window.partytown` and accessed via `this`.

= 1.3.3 =
* Fix: Inline `partytown-config.js` output to prevent `ReferenceError: dcSwpPartytownData is not defined` inside the Partytown service worker sandbox. The config script must be inline — when served as a separate file the Partytown SW intercepts the fetch and evaluates it in a context where `wp_localize_script` data is unavailable.

= 1.3.2 =
* Update: Vendor Partytown 0.13.1 (built from source, pre-release). Fixes Lighthouse deprecated-API warnings caused by Chrome Privacy Sandbox properties (SharedStorage, AttributionReporting) being accessed during window introspection.

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
