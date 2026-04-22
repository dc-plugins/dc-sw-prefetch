=== DC Script Worker Proxy ===
Contributors: lennilg
Tags: thirdparty, performance, analytics, pagespeed, vitals
Requires at least: 6.8
Tested up to: 6.9
Requires PHP: 8.0
WC tested up to: 10.7.0
Stable tag: 3.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offload third-party scripts (GTM, Pixel, HubSpot...) to a web worker via Partytown + consent-aware loading. Vendored.

== Description ==

DC Script Worker Proxy integrates [Partytown](https://partytown.qwik.dev/) into WordPress. Partytown moves third-party scripts (analytics, ads, tracking) into a Web Worker so they never touch the browser's main thread.

Unlike `async`/`defer`, which still execute on the main thread and can block `window.onload`, Partytown executes scripts entirely off-thread. No layout jank, no TBT penalty, no competition with your code.

**Note:** Partytown is currently in beta and not guaranteed to work in every scenario. See the [Partytown FAQ](https://partytown.qwik.dev/faq) and [Trade-offs](https://partytown.qwik.dev/trade-offs) pages for more information before deploying to production.

Officially tested compatible services: **Google Tag Manager** (GA4), **Facebook Pixel**, **HubSpot**, **Intercom**, **Klaviyo**, **TikTok Pixel**, and **Mixpanel**. See [partytown.qwik.dev/common-services](https://partytown.qwik.dev/common-services/) for the full reference list.

= Key features =

* **Partytown Web Worker execution** -- unlike `async`/`defer` (which still execute on the main thread and can block `window.onload`), Partytown lazy-loads and executes third-party scripts entirely in a Web Worker. Officially tested: Google Tag Manager, Facebook Pixel, HubSpot, Intercom, Klaviyo, TikTok Pixel, Mixpanel.
* **Consent-aware loading** -- optional **Consent Gate** delegates consent decisions to the [WP Consent API](https://wordpress.org/plugins/wp-consent-api/). When enabled, scripts are blocked as `type="text/plain"` until the visitor grants consent for the script's category. When disabled (default), scripts load unconditionally. Any CMP that integrates with the WP Consent API is automatically supported.
* **Configured via URL patterns** -- enter one URL pattern per line in the admin. Any `<script src>` whose src matches is automatically managed for consent + Partytown offloading. No manual code edits.
* **Auto-detect** -- one-click scan of the homepage discovers all external scripts and lets you add them to the list.
* **Exclusion list** -- built-in exclusions for Trustpilot, Stripe, PayPal, Braintree, Facebook SDK, Google Maps, and Reamaze; add your own patterns as needed.
* **Vendored lib** -- Partytown's `lib/` files are bundled in `assets/partytown/`; no npm or build step needed on the server.
* **Automatic Partytown updates** -- a weekly GitHub Actions workflow detects new Partytown releases and opens a PR with the updated vendor files.
* **Bot detection** -- bots never receive Partytown JS, keeping crawl budget clean.
* **Cart/checkout safe** -- on WooCommerce cart, checkout, and account pages the Atomics bridge is auto-disabled; Partytown falls back to the Service Worker bridge so analytics scripts still fire without breaking payment gateways.
* **Admin UI** -- toggle Partytown, see the vendored Partytown version at a glance.
* **Bilingual** -- EN/DA auto-detection.
* **Optional footer credit** -- easily disabled.

= How Partytown works =

1. Scripts matching your configured patterns are output with `type="text/partytown"` -- this attribute tells the browser **not** to execute them on the main thread.
2. Partytown's **service worker** intercepts fetch requests from the web worker.
3. The **web worker** receives and executes the scripts entirely off the main thread.
4. **JavaScript Proxies** replicate main thread APIs (DOM reads/writes) synchronously inside the worker -- so third-party scripts run exactly as coded, without modification.
5. Communication between the web worker and main thread uses either:
   * **Synchronous XHR + Service Worker** (default fallback)
   * **Atomics bridge** when `crossOriginIsolated` is enabled -- roughly **10× faster**. Activated by enabling the **SharedArrayBuffer (Atomics Bridge)** option in settings, which sends the required `Cross-Origin-Opener-Policy` and `Cross-Origin-Embedder-Policy` headers.

= Updating Partytown =

**Automatic:** the `update-partytown.yml` workflow runs every Monday at 08:00 UTC and opens a PR when a new release is detected.

**Manual:** run `bash scripts/update-partytown.sh` (or `bash scripts/update-partytown.sh 0.10.3` to pin a version). Then commit `assets/partytown/` and `package.json`.

= Using Partytown with third-party scripts =

The easiest way is the **Partytown Script List** in the admin settings. Enter one URL pattern per line (e.g. `analytics.ahrefs.com` or the full GTM URL). The plugin then:
1. If the Consent Gate is enabled, checks consent via `wp_has_consent()` for the script's category.
2. Sets `type="text/partytown"` when consent is present (or gate is disabled) -- Partytown runs the script off the main thread.
3. Sets `type="text/plain"` with `data-wp-consent-category` when consent is absent -- the script is blocked until consent is granted.

Alternatively, you can manually tag a script:

    <script type="text/partytown" src="https://www.googletagmanager.com/gtag/js?id=G-XXXX"></script>

The `window.partytown.forward` array is pre-configured for all officially tested services: `dataLayer.push` (GTM), `fbq` (Facebook Pixel), `_hsq.push` (HubSpot), `Intercom`, `_learnq.push` (Klaviyo), `ttq.track`/`ttq.page`/`ttq.load` (TikTok Pixel), `mixpanel.track` (Mixpanel). See [partytown.qwik.dev/common-services](https://partytown.qwik.dev/common-services/).

= Consent Gate -- WP Consent API =

The optional Consent Gate delegates consent decisions to the [WP Consent API](https://wordpress.org/plugins/wp-consent-api/) standard. When enabled:

* Scripts are output as `type="text/plain"` with a `data-wp-consent-category` attribute until the visitor grants consent for that category.
* A consent-change listener dynamically unblocks scripts when consent is granted, swapping `text/plain` to `text/partytown`.
* Any CMP that integrates with the WP Consent API is automatically supported -- no per-CMP cookie reading required.
* Each inline script block has a configurable consent category: marketing (default), statistics, statistics-anonymous, functional, or preferences.
* GCM v2-aware services and Meta LDU scripts bypass the gate (they self-restrict internally).

== Screenshots ==

1. Admin settings -- Partytown Integration toggle, Consent Gate, and Script List configuration.
2. Admin settings -- Google Consent Mode v2 options including URL Passthrough and Ads Data Redaction.
3. DevTools Performance panel -- Third-party scripts (Facebook, Clarity, GTM, Ahrefs) running in Web Worker with minimal main thread impact.
4. Lighthouse Treemap comparison -- Before: GTM and Facebook dominate; After: Partytown offloads heavy scripts, jQuery remains first-party only.

== Installation ==

1. Upload the `dc-sw-prefetch` folder to `/wp-content/plugins/`.
2. Activate the plugin from the **Plugins** screen.
3. Go to **SW Prefetch** in the admin menu.
4. Enable Partytown and save.

== Frequently Asked Questions ==

= Are there any trade-offs or known limitations? =
Yes. Partytown is in beta. While it works well for the [officially tested services](https://partytown.qwik.dev/common-services/), some scripts may not be compatible -- particularly those that rely on APIs not yet proxied by Partytown, use synchronous `document.write()`, or require persistent event listeners on the main thread. Test in staging before enabling on production. See the [Partytown trade-offs page](https://partytown.qwik.dev/trade-offs) for a full list of known limitations.

= Will this interfere with WooCommerce cart/checkout? =
No. On cart, checkout, and account pages, the Atomics bridge is automatically disabled and Partytown falls back to the Service Worker bridge. Payment gateway iframes are never affected. Analytics and marketing scripts still fire normally on these pages.

= Will scripts load before the user gives consent? =
When the **Consent Gate** is enabled, no. Scripts are output as `type="text/plain"` (browser-blocked) until consent is granted via the WP Consent API. When the Consent Gate is disabled (default), scripts load unconditionally.

= Are there limitations with Meta Pixel consent and full-page caching? =
Two inherent limitations apply to Meta Pixel consent handling:

**Full-page caching:** The `fbq("consent","revoke"/"grant")` and LDU stubs are injected by PHP at request time. If a full-page cache plugin (WP Rocket, Nginx FastCGI, static HTML export) serves a cached page without invoking PHP, the stub is baked in with the consent state of whoever triggered the cache fill -- not the current visitor's actual consent state. Use per-visitor cache keys, fragment caching, or disable full-page caching for logged-out visitors to avoid serving stale consent state.

**No LDU and no Consent Gate:** If both the Meta LDU toggle and the Consent Gate are disabled, the Meta Pixel fires with no `fbq("consent",...)` signal. Meta receives data without any explicit consent declaration from this plugin. Enable Meta LDU, the Consent Gate, or both to emit meaningful consent signals.

= Which consent plugins are supported? =
Any CMP (Consent Management Platform) that integrates with the [WP Consent API](https://wordpress.org/plugins/wp-consent-api/) is automatically supported. This includes Complianz, Cookiebot, CookieYes, Borlabs Cookie, and many others.

= How do I verify Partytown is running? =
Open DevTools → Application → Service Workers. You should see `partytown-sw.js` registered under `/~partytown/`. In the Console you should see no third-party scripts on the main thread.

= How do I update Partytown? =
Either let the weekly GitHub Action open a PR automatically, or run `bash scripts/update-partytown.sh` locally.

= What scripts does Partytown forward by default? =
All officially tested services from [partytown.qwik.dev/common-services](https://partytown.qwik.dev/common-services/): `dataLayer.push` (Google Tag Manager / GA4), `fbq` (Facebook Pixel), `_hsq.push` (HubSpot), `Intercom`, `_learnq.push` (Klaviyo), `ttq.track`/`ttq.page`/`ttq.load` (TikTok Pixel), `mixpanel.track` (Mixpanel). LinkedIn Insight Tag and Twitter/X Pixel are not on the official tested list and are excluded.

== External Services ==

This plugin runs third-party scripts off the main thread using the vendored [Partytown](https://partytown.qwik.dev/) library. It only connects to services you explicitly configure.

= Partytown library =

The Partytown JavaScript library is fully vendored inside the plugin (assets/partytown/). No files are downloaded from any CDN at runtime. The library is loaded from your own server.

= CORS proxy (server-side script relay) =

Some third-party CDNs do not send CORS headers, which prevents Partytown's sandbox from fetching their scripts directly from the browser. To work around this, the plugin provides a /~partytown-proxy endpoint: WordPress makes a **server-side HTTP request** to the CDN and re-serves the script to the browser.

* **What is sent:** an HTTP GET request for the script file (no visitor personal data).
* **When:** only when Partytown cannot fetch the script directly due to missing CORS headers, and only for hostnames the administrator has added to the Partytown Script List.
* **Allowlist-only:** the proxy strictly rejects any hostname not configured by the administrator -- no hard-coded vendor list is contacted automatically.

= Third-party analytics and marketing scripts (site administrator configured) =

When the administrator adds a service's URL pattern to the Partytown Script List, that service's script is loaded and executed (inside a Web Worker via Partytown) for site visitors who have granted marketing consent. The visitor's browser (or the CORS proxy above) contacts the service's CDN, and the service may receive visitor data according to its own terms.

**Scripts are only loaded and data is only transmitted when:**
1. The administrator has added the service's URL pattern to the plugin's Partytown Script List or Inline Script Blocks.
2. If the Consent Gate is enabled, the visitor has granted consent for the script's category via the WP Consent API. Without consent, all configured scripts are blocked (output as type="text/plain"). If the Consent Gate is disabled (default), scripts load unconditionally.

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

**FullStory**
Provided by FullStory Inc. When a FullStory Org ID is configured in the plugin settings, the FullStory session-replay library (fs.js) is loaded from edge.fullstory.com. Sends session recordings, page interaction events, and visitor identity information (if the site calls FS.identify). Data is transmitted only when the visitor has granted marketing consent (or when the Consent Gate is disabled).
[Privacy Policy](https://www.fullstory.com/legal/privacy-policy/) | [Terms of Service](https://www.fullstory.com/legal/terms-of-service/)

The administrator may freely add other services through the Partytown Script List. The plugin imposes no restriction on which services can be configured, beyond the security allowlist that prevents the CORS proxy from being used as an open relay. Refer to each service's own privacy policy and terms of service for details on what data they collect.

== Changelog ==

= 3.1.0 =
* Fix: Text domain corrected to `dc-script-worker-prefetcher` to match plugin slug on WordPress.org.
* Fix: GTM and Meta Pixel scripts now enqueued via `wp_register_script()` / `wp_enqueue_script()` + `script_loader_tag` filter instead of direct `echo` output.
* Fix: HubSpot, Klaviyo, and FullStory integrations now enqueued via the WordPress script API.
* Fix: Inline Script Blocks redesigned -- free-form code textarea replaced with a Script URL field and optional Noscript Pixel URL field, eliminating arbitrary script injection.
* Fix: Noscript tracking pixels in Script Blocks are now output as fully escaped `<img>` tags rather than raw HTML.
* Fix: Removed external requests to img.shields.io and paypalobjects.com from the admin UI.
* Fix: Added FullStory to the External Services section of readme.txt.

= 3.0.1 =
* Fix: Removed debug console.log/debug/warn calls from the viewport prefetch script that were printing to every visitor's DevTools console on WooCommerce shop pages.
* Fix: "Tested up to" corrected to WordPress 6.8; WC tested up to updated to 10.7.0.
* Fix: Output buffer script regex replaced PCRE_DOTALL lazy quantifier with an atomic group, preventing potential catastrophic backtracking on malformed HTML.
* Fix: Settings save now also flushes WP Rocket, LiteSpeed Cache, WP Super Cache, Nginx Helper, SG Optimizer, and WP Fastest Cache (previously only W3 Total Cache was purged).

= 3.0.0 =
* Removed: Server-Side GA4 (SSGA4) — Measurement Protocol event pipeline removed. Plugin refocused on Partytown offloading and consent authority.
* Removed: Meta Conversions API (CAPI) — server-side Graph API event pipeline removed.
* Removed: TikTok Events API — server-side TikTok event pipeline removed. TikTok Pixel client-side offloading via Partytown is unaffected.
* Removed: UTM/click-ID attribution cookie (was used only by CAPI and TikTok Events API).
* Feature: GTM wizard now enforces GTM-only container IDs (rejects G- GA4 IDs at step 2).
* Feature: GCMv2 toggle is hard-gated behind a valid GTM Container ID — misconfiguration prevented at the admin level.
* Feature: Partytown now runs on WooCommerce cart, checkout, and account pages via the Service Worker bridge (Atomics bridge auto-disabled) — analytics scripts can capture ecommerce events without breaking payment gateways.

= 2.5.1 =
* Fix: CAPI access token moved from URL query string to Authorization: Bearer header (prevents credential appearing in server access logs).
* Fix: CAPI inline event-ID script now uses wp_add_inline_script() + wp_inline_script_attributes nonce filter instead of a raw echo.
* Fix: Uninstall now deletes all seven CAPI options, including the stored access token.
* Fix: DC_SWP_VERSION constant moved before require_once calls so included files can safely reference it at module level.
* Fix: admin footer translatable string no longer embeds raw HTML inside __(); markup and URL are escaped separately.
* Fix: $_SERVER['REMOTE_ADDR'] and HTTP_USER_AGENT now use sanitize_text_field( wp_unslash() ) consistently.
* Fix: Removed register_setting() / admin_init hook -- the custom save handler already sanitizes all fields inline; double-sanitization eliminated.
* Fix: Lone if inside else block at Meta LDU injection converted to elseif.
* Chore: ABSPATH guards and file doc comments added to all five includes/ stub files.
* Chore: 129x esc_html( __() ) calls in admin.php replaced with esc_html__() shorthand.
* Chore: four list() destructuring calls replaced with short [ ] syntax.
* Chore: phpcbf applied -- 35 auto-fixable PHPCS violations resolved.
* Chore: ESLint -- var replaced with const/let in tab and PT-deps IIFEs; history and dcSwpMeta globals reconciled.

= 2.5.0 =
* Feature: CAPI Getting Started wizard -- 5-step guided setup (Dataset creation, System User token, connection test, event selection, finalise) replaces the need for Meta's own Dataset Setup Events guide.
* Feature: Meta Pixel Consent Mode -- fbq('consent','grant'/'revoke') now fires per-visitor based on WP Consent API marketing consent, replacing the unconditional LDU stub.
* Feature: Consent-aware LDU -- when WP Consent API is active, consented visitors receive fbq('dataProcessingOptions',[]) (unrestricted) and non-consented visitors receive LDU; legacy unconditional behaviour preserved when WP Consent API is absent.
* Feature: Meta consent-change reactivity -- consent-update.js now handles wp_listen_for_consent_change for Meta Pixel (fbq consent + LDU) alongside existing GCM v2 updates.
* Feature: CAPI LDU parity -- Graph API payloads now include data_processing_options mirroring the client-side Pixel LDU state, keeping server-side and client-side declarations in sync.

= 2.4.0 =
* Feature: Server-Side Meta Conversions API (CAPI) -- sends WooCommerce events (Purchase, InitiateCheckout, AddToCart, ViewContent, AddPaymentInfo) directly from PHP to the Meta Graph API.
* Feature: Hashed PII support (email, phone, name, address) -- SHA-256 normalised per Meta spec, gated by a dedicated toggle.
* Feature: _fbp / _fbc cookie forwarding and ?fbclid= synthesis for improved match rate.
* Feature: Client-side deduplication bridge -- window.dcSwpCapiEventIds JSON blob lets the Meta Pixel send matching eventID values.
* Feature: Test Event Code support for validating events in Meta Events Manager without polluting production data.
* Feature: Auto-detect mode scans homepage source for an existing fbq('init', ...) Pixel ID.

= 2.3.5 =
* Fix: GCM v2 consent scripts now load after WP Consent API (proper script dependency).
* Fix: Consent polling now tracks actual state changes, not just API availability.
* Fix: Replace Unicode characters (box-drawing, ellipsis, em-dash) with ASCII equivalents for encoding safety.
* Chore: Add ESLint globals for browser APIs and WP Consent API functions.

= 2.3.1 =
* Removed: Legacy fallback cache headers function (out of scope for a script-offloading plugin).
* Removed: Architecture info section from admin UI.
* Cleanup: Removed orphaned translation strings and W3TC-specific documentation.
* Docs: Tightened readme text, simplified External Services section.

= 2.3.0 =
* Feature: Early Resource Hints (Feature 1) -- auto-injects `<link rel="preconnect">` and `<link rel="dns-prefetch">` for all configured third-party hosts in `<head>`, reducing TCP+TLS round-trip latency for first-visit page loads. Controlled by the new "Early Resource Hints" toggle (on by default).
* Feature: Partytown Health Monitor (Feature 2) -- uses `PerformanceObserver` to detect services that fail silently inside the Partytown worker (no network traffic observed within 15 seconds) and surfaces an admin notice. Reported via `sendBeacon` AJAX. Controlled by the new "Partytown Health Monitor" toggle (on by default).
* Feature: Performance Metrics Dashboard (Feature 3) -- collects anonymous TBT and INP measurements from real visitors using `PerformanceObserver`. Stores rolling averages and P75 percentiles in non-autoloaded WP options. Admin dashboard shows CSS progress bars. Includes reset button. Controlled by the new "Performance Metrics" toggle (on by default).
* Feature: Per-Page Script Exclusion Patterns (Feature 4) -- new "Advanced" section textarea allows admins to define URL patterns (one per line, supports `*` wildcard) where Partytown is completely skipped. Useful for landing pages or payment flows with scripts incompatible with the Partytown worker.

= 1.9.0 =
* Feature: Consent Gate (WP Consent API) -- optional admin toggle that delegates consent decisions to the WP Consent API standard. Scripts output as `type="text/plain"` with `data-wp-consent-category` until consent is granted. Client-side listener dynamically unblocks scripts. When disabled (default), all scripts load unconditionally.
* Feature: Per-script consent category -- each inline script block can be assigned a WP Consent API category (marketing, statistics, statistics-anonymous, functional, preferences). Script List uses a configurable default category.
* Feature: Hostname-to-category mapping -- known services automatically assigned the correct consent category.
* Removed: 8 CMP-specific cookie detection functions replaced by WP Consent API delegation.
* Removed: CMP compatibility badges from admin Consent Architecture panel.

= 1.8.2 =
* Fix: PHPCS -- resolved 78 auto-fixable code style violations (function brace spacing, inline comment spacing, single-line associative arrays, double-quote usage, scope indentation). Zero errors/warnings remain under the WordPress coding standard.

= 1.8.1 =
* Fix: GTM and GA4 scripts now load via `type="text/partytown"` so they run entirely in a Partytown Web Worker off the main thread, consistent with the plugin's core offloading principle. A thin main-thread stub (`window.dataLayer||=[];function gtag(){...}`) is emitted before the Partytown tag to support main-thread consent signals and CMP pushes; Partytown's existing `forward:['dataLayer.push',{preserveBehavior:true}]` config relays these into the worker automatically.

= 1.8.0 =
* Feature: Google Tag Management -- three modes for managing your Google Tag: Enter Tag ID (own GTM/GA4 ID), Auto-Detect (scans Site Kit, MonsterInsights, GTM4WP, CAOS), and Setup Guide (step-by-step onboarding wizard). GTM snippet injected in `<head>` at priority 5, after GCM v2 consent default; `<noscript>` iframe injected at `wp_body_open`.
* Feature: GTM onboarding wizard -- 4-step guided flow to create a GTM account and container, enter the Container ID with real-time validation, add tags in GTM (GA4, LinkedIn, TikTok etc.), and publish.
* Feature: Auto-detect scans plugin options (Site Kit, MonsterInsights, GTM4WP, CAOS, Analytify) for existing tags and confirms GCM v2 consent mode fires before them.

= 1.7.0 =
* Enhancement: Google Consent Mode v2 -- all 7 parameters now declared (`security_storage`, `functionality_storage`, `personalization_storage`, `analytics_storage`, `ad_storage`, `ad_user_data`, `ad_personalization`).
* Enhancement: GCM v2 consent defaults now split per category: `analytics_storage` follows statistics consent, `personalization_storage` follows preferences consent, ad signals follow marketing consent.
* Enhancement: Consent revoke listener -- fires `gtag('consent','update',{denied})` on `cmplz_revoke` and `dc_swp_consent_revoke` DOM events so withdrawn consent is immediately reflected without a page reload.
* Enhancement: New admin options -- `url_passthrough` and `ads_data_redaction` for GCM v2 cookieless measurement configuration.
* Enhancement: Opt-out mode awareness -- Complianz opt-out and CookieYes non-consent-region visitors correctly default to granted state; explicit denial cookies are still respected.

= 1.6.0 =
* Standards: Renamed all `dampcig_pwa_*` options to `dc_swp_*` prefix; existing settings are migrated automatically on activation.
* Standards: Removed global PHPCS EscapeOutput suppression; per-line justification comments used instead.
* Standards: Renamed `dc_footer_credit_owner` sentinel function to `dc_swp_footer_credit_owner`.
* Standards: Replaced `file_get_contents()` with the WP Filesystem API for local file reads.
* Security: `dc_swp_sanitize_js_code()` now applied to inline script code fields at save time.

= 1.5.3 =
* Fix: Google Consent Mode v2 -- set `gtag('consent','default')` directly to `granted` when the visitor's CMP cookie is already set, rather than emitting a redundant default-denied + update-granted pair. `wait_for_update` is only included when consent has not yet been given.

= 1.5.2 =
* Fix: Google Consent Mode v2 -- immediately follow the `gtag('consent','default',{denied})` stub with a `gtag('consent','update',{granted})` call when the visitor's CMP marketing cookie is already set, so GTM/GA4 receive the correct consent state without waiting for the CMP JavaScript to fire.

= 1.5.1 =
* Compat: Declare WooCommerce HPOS (High-Performance Order Storage / Custom Order Tables) compatibility. The plugin does not interact with the orders table, so it is unconditionally compatible. Resolves the "Incompatible plugin" warning in WooCommerce → Settings → Advanced → Features.

= 1.5.0 =
**Google Consent Mode v2 -- per-service consent architecture**

* Feature: Replaced the global GCM v2 bypass with a per-service consent gate. Six hostnames are now classified as GCM v2-aware (`googletagmanager.com`, `google-analytics.com`, `static.hotjar.com`, `script.hotjar.com`, `clarity.ms`, `snap.licdn.com`, `analytics.tiktok.com`): when GCM v2 is active these scripts always run as `type="text/partytown"` -- each service reads the consent state and self-restricts data collection internally. Unrelated scripts (HubSpot, Intercom, Mixpanel, etc.) continue to gate on the marketing consent cookie as before.
* Feature: Meta Pixel is intentionally excluded from GCM v2 -- it uses its own Limited Data Use (LDU) consent API. The existing Meta LDU toggle is now the correct gate for Meta Pixel regardless of GCM v2 state. Both mechanisms are fully independent.
* Feature: New helper API -- `dc_swp_get_gcm_v2_aware_services()` (filterable via `dc_swp_gcm_v2_aware_services`), `dc_swp_script_uses_gcm_v2()`, `dc_swp_is_meta_script()`, `dc_swp_inline_uses_gcm_v2()`, `dc_swp_inline_is_meta()`. Developers can register additional GCM v2-aware services via the filter without modifying plugin code.
* Feature: Per-service gate applied to all three consent-check locations: `wp_script_attributes` filter (priority 5), output-buffer rewriter, and inline Script Block output.
* Feature: Consent Architecture info panel added to the admin settings page -- collapsible `<details>` element showing three badge groups: GCM v2-aware services, Meta Pixel LDU, and CMP compatibility (8 CMPs). Badges use shields.io SVGs with a pure CSS fallback for offline/firewalled staging environments; the CSS pill renders from `data-label`/`data-msg` attributes and flips to the shields.io image via an `onload` swap -- zero flash, no broken images.
* Feature: CMP compatibility research completed and documented in the panel: Complianz, CookieYes, Cookiebot, Cookie Information, Borlabs Cookie, and WebToffee GDPR fire `gtag('consent','update',...)` natively without GTM; Moove GDPR requires the premium plan; Cookie Notice (free) cannot fire GCM v2 update signals and is marked as "fallback only".
* Enhancement: Updated `consent_mode_desc` and `meta_ldu_desc` admin strings in both EN and DA to accurately describe the per-service architecture and distinguish the two consent mechanisms.

**Bug fixes**

* Fix: `dc_swp_partytown_buffer_rewrite()` was computing `$new_type` but never writing it into `$tag_inner`. Raw-echoed `<script src>` tags (e.g. analytics scripts added directly via `functions.php` rather than `wp_enqueue_script`) bypassed the consent gate entirely and executed on the main thread. The rewriter now injects or replaces the `type` attribute before returning the tag.
* Fix: Added `break` after `$attributes['type']` is set in `dc_swp_partytown_script_attrs()`. The loop previously continued iterating all remaining patterns after a match, wasting cycles and allowing a later broader pattern to overwrite the type set by the more specific one.

**Security**

* Security: `sslverify => false` removed from the auto-detect AJAX handler (`dc_swp_ajax_detect_scripts`). Certificate verification is now always enabled -- a self-signed cert in staging is no longer a reason to globally bypass SSL verification (OWASP A02).

**Standards & housekeeping**

* Standards: Plugin main-file header brought fully in line with blueprint §2: `License` changed to SPDX identifier `GPL-2.0-or-later`; `Update URI` added (prevents accidental WordPress.org auto-update overwrite); `WC tested up to: 10.4.3` added to the PHP header (was previously only in `readme.txt`).
* i18n: Badge and force-Partytown UI strings (`badge_supported`, `badge_unsupported`, `force_pt_label`, `force_pt_notice`) were hardcoded English in `wp_localize_script`. Moved into `dc_swp_str()` with full Danish translations -- the admin UI is now fully bilingual for all dynamic strings.
* Cleanup: `dc_swp_debug_mode` option was the only plugin option not deleted on uninstall. Added to the `uninstall.php` cleanup list.

= 1.4.2 =
* Feature: Script Block compatibility badges -- each block now shows a "✓ Supported / Partytown" or "⚠ Unsupported / Deferred" badge based on whether its scripts (src= or inline) reference a Partytown-verified service.
* Feature: "Force Enable Partytown" toggle for unsupported Script Block scripts -- admin can force an unknown src= script into the Partytown worker, with a warning notice that render errors should be tested in debug mode.
* Fix: Script Block inline scripts (e.g. Meta Pixel) that reference a known-service URL are now correctly routed to the Partytown worker; unknown inline scripts fall back to deferred main-thread execution instead of always entering the worker.
* Fix: Script Block src= scripts with unknown hostnames now always run on the main thread (consent-gated), preventing the about:srcdoc sandbox error caused by Partytown trying to run iframe-dependent scripts (e.g. ContentSquare) in the worker.
* Refactor: `dc_swp_get_known_services()` is now the single source of truth for Partytown compatibility decisions -- shared by Script Block output, inline detection, and the auto-detect AJAX handler.
* Refactor: `dc_swp_inline_matches_known_service()` added to mirror the JS-side known-service scan for inline script bodies.
* Chore: Add vendor/ and node_modules/ exclude-patterns to phpcs.xml so `vendor/bin/phpcs` without arguments only lints plugin files.

= 1.4.1 =
* Chore: Rename plugin to "DC Script Worker Prefetcher" in all remaining source files (admin.php, uninstall.php, phpcs.xml, package.json, .pot file, copilot-instructions.md, PHP docblock headers).

= 1.4.0 =
* Feature: Add FullStory (`FS.identify`, `FS.event`) to the Partytown `forward` array -- now on the official tested-services list.
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
* Docs: Correct async/defer description -- scripts still execute on the main thread (and can block window.onload); the download is already off-thread.
* Docs: Add Partytown beta disclaimer and trade-offs link.
* Docs: Add "How Partytown works" section explaining type attribute, service worker, web worker, JS Proxies, and Atomics vs sync XHR communication.

= 1.3.8 =
* Refactor: Auto-detect scan now returns all third-party scripts found on the homepage. Scripts on Partytown's officially verified services list (https://partytown.qwik.dev/common-services/) are pre-checked and shown with a green badge; unrecognised scripts are shown unchecked with an "unknown compatibility" warning so admins make an explicit choice rather than having scripts silently accepted or rejected.
* Removed: The auto-detect feature no longer auto-populates the exclude/blocklist textarea when incompatible scripts are found -- the exclude-list concept is being phased out in favour of a pure allow-list architecture.

= 1.3.7 =
* Revert: Remove `script_loader_tag` filter that patched third-party plugin scripts. DC SW Prefetch only manages scripts it explicitly moves into Partytown; all other scripts are left entirely on the main thread as-is. Fixing compatibility issues in scripts belonging to other plugins is out of scope.

= 1.3.6 =
* Fix: Strip `crossorigin="anonymous"` from `widget-bootstrap-js` (Trustpilot bootstrap) via `script_loader_tag` filter. Under `COEP: credentialless`, scripts with `crossorigin` are fetched in CORS mode; since `widget.trustpilot.com` sends no `Access-Control-Allow-Origin` header the load is blocked. Without the attribute the browser uses no-cors mode, which succeeds. The Trustpilot plugin added `crossorigin` via the WP 6.3+ `$args` API; this filter removes it for scripts that are incompatible with enforced CORS.

= 1.3.5 =
* Revert: restore `partytown-config.js` as a normal enqueued file (the inline approach in 1.3.3 was a misdiagnosis -- the Partytown SW does not intercept regular script requests). The `file_get_contents()` load and unnecessary inline output are removed.

= 1.3.4 =
* Fix: `resolveUrl` in `partytown-config.js` now uses `this.pathRewrites` / `this.proxyAllowedHosts` / `this.proxyUrl` instead of referencing `dcSwpPartytownData` directly. Partytown serialises `resolveUrl` to a string and reconstructs it with `new Function()` inside the web worker, so closures are lost -- the function must be fully self-contained. Data is now stored as plain properties on `window.partytown` and accessed via `this`.

= 1.3.3 =
* Fix: Inline `partytown-config.js` output to prevent `ReferenceError: dcSwpPartytownData is not defined` inside the Partytown service worker sandbox. The config script must be inline -- when served as a separate file the Partytown SW intercepts the fetch and evaluates it in a context where `wp_localize_script` data is unavailable.

= 1.3.2 =
* Update: Vendor Partytown 0.13.1 (built from source, pre-release). Fixes Lighthouse deprecated-API warnings caused by Chrome Privacy Sandbox properties (SharedStorage, AttributionReporting) being accessed during window introspection.

= 1.3.1 =
* Refactor: Move all inline JS to static files in `assets/js/` with `wp_localize_script()` for PHP data injection.
* Refactor: Add `DC_SWP_VERSION` constant for consistent script versioning and cache-busting.
* Tooling: Add ESLint configuration targeting `assets/js/`.

= 1.3.0 =
* **New:** Consent-aware script loading -- reads marketing-consent cookies from 8 common WordPress CMPs (Complianz, Cookiebot, CookieYes, Borlabs, Cookie Notice, WebToffee, Cookie Information, Moove GDPR).
* Scripts in the Partytown list now output `type="text/partytown"` when consent is granted and `type="text/plain"` when it is not -- no CMP hooks or DOM patching required.
* Removed the `dc_swp_cmp_intercept_script` Node.prototype hook introduced in 1.2.x (approach replaced by server-side consent detection).
* Version bump: 1.2.0 → 1.3.0.

= 1.2.0 =
* **New:** WP emoji removal -- dequeues `print_emoji_detection_script` and `print_emoji_styles` saving ~76 KB and one s.w.org DNS lookup per page. Toggle in admin (default: on).
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
* Initial release.
* Bot detection wrapped in `function_exists` for safe coexistence with child themes.
* Footer credit with object-cache → transient strategy caching.
* Bilingual admin UI (English default, Danish auto-detected).

== Upgrade Notice ==

= 2.3.0 =
New: Early Resource Hints, Partytown Health Monitor, Performance Metrics Dashboard, and Per-Page Exclusion Patterns.

= 2.0.0 =
New: Server-Side GA4 (SSGA4) sends WooCommerce ecommerce events directly to GA4 via Measurement Protocol -- independent of browser consent and ad-blockers.

= 1.6.0 =
Breaking: All `dampcig_pwa_*` options renamed to `dc_swp_*`. Existing settings are migrated automatically on activation.

= 1.0.0 =
Initial release.
