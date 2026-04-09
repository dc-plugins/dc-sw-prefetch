# DC Script Worker Proxy

> Offload third-party scripts to a Web Worker via Partytown + consent-aware loading.

![Version](https://img.shields.io/badge/version-1.9.0-blue)
![WordPress](https://img.shields.io/badge/WordPress-6.8%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4)
![WooCommerce](https://img.shields.io/badge/WooCommerce-10.4%2B-96588a)
![License](https://img.shields.io/badge/license-GPL--2.0%2B-green)

Offload third-party scripts (GTM, Pixel, HubSpot…) to a Web Worker via Partytown + consent-aware loading. Fully vendored — no npm required.

---

## What it does

1. **Partytown Web Worker execution** — [Partytown](https://partytown.qwik.dev/) is a lazy-loaded library that relocates resource-intensive scripts into a web worker and off the main thread, dedicating the main thread to your code. Unlike `async`/`defer` (which still execute on the main thread and can block `window.onload`), Partytown executes third-party scripts entirely in a Web Worker. The browser main thread is freed from analytics and ad code — no layout jank, no TBT impact, no competition with user interactions. Officially tested compatible services: **Google Tag Manager**, **Facebook Pixel**, **HubSpot**, **Intercom**, **Klaviyo**, **TikTok Pixel**, **Mixpanel** ([full list](https://partytown.qwik.dev/common-services/)). Scripts are consent-gated: output as `type="text/partytown"` when consent is present, `type="text/plain"` (browser-blocked) when it is not.

> **Note:** Partytown is currently in beta. It is not guaranteed to work in every scenario. Review the [trade-offs](https://partytown.qwik.dev/trade-offs) before enabling on production.

2. **Google Consent Mode v2 (GCM v2) per-service gate** — Six GCM v2-aware services (Google Tag Manager, Google Analytics, Hotjar, Microsoft Clarity, LinkedIn Insight Tag, TikTok Pixel) always run as `type="text/partytown"` when GCM v2 is enabled; each service reads the consent state internally. Meta Pixel is handled separately via its own **Limited Data Use (LDU)** toggle. When the **Consent Gate** is enabled, all other services are blocked as `type="text/plain"` until the visitor grants consent via the [WP Consent API](https://wordpress.org/plugins/wp-consent-api/). Each script is assigned a consent category (marketing, statistics, functional, preferences). When the Consent Gate is disabled (default), all scripts load unconditionally.

3. **Bonus performance** — PHP fallback cache headers when W3 Total Cache is absent.

---

## Consent architecture

The plugin uses a **per-service consent gate** so that each script class is handled by the most appropriate mechanism:

| Script class | Condition | Output type |
|---|---|---|
| GCM v2-aware service | GCM v2 enabled | `text/partytown` (always — service self-restricts) |
| Meta Pixel | Meta LDU enabled | `text/partytown` (always — fbq LDU stub injected) |
| Any other service | Consent Gate ON + `wp_has_consent($category)` true | `text/partytown` |
| Any other service | Consent Gate ON + no consent | `text/plain` (browser blocked) |
| Any other service | Consent Gate OFF (default) | `text/partytown` (unconditional) |

### GCM v2-aware services

These services implement the [Google Consent Mode v2](https://developers.google.com/tag-platform/security/concepts/consent-mode) API and adjust their own data collection when consent is denied. The plugin always loads them into the Partytown worker and lets them handle consent internally:

`googletagmanager.com` · `google-analytics.com` · `static.hotjar.com` · `script.hotjar.com` · `clarity.ms` · `snap.licdn.com` (LinkedIn) · `analytics.tiktok.com`

Developers can extend this list via the `dc_swp_gcm_v2_aware_services` filter.

### Consent Gate — WP Consent API

The optional **Consent Gate** delegates consent decisions to the [WP Consent API](https://wordpress.org/plugins/wp-consent-api/) standard. When enabled:

- Scripts are output as `type="text/plain"` with a `data-wp-consent-category` attribute until the visitor grants consent for that category.
- A consent-change listener (`consent-gate.js`) dynamically unblocks scripts when consent is granted, swapping `text/plain` to `text/partytown`.
- Each inline script block has its own configurable consent category (marketing, statistics, statistics-anonymous, functional, preferences).
- The Script List default category is configurable in the admin settings.
- Any CMP that integrates with the WP Consent API is automatically supported — no per-CMP cookie reading code is required.

---

## How Partytown works

1. Scripts matching your configured patterns are output with `type="text/partytown"` — this tells the browser **not** to execute them on the main thread.
2. A **Web Worker** receives and executes those scripts entirely off the main thread.
3. **JavaScript Proxies** replicate main-thread APIs (DOM reads/writes) synchronously inside the worker — third-party scripts run exactly as coded, without modification.
4. Communication between the web worker and main thread uses one of two bridges:
   - **Atomics bridge** (`crossOriginIsolated` active) — worker writes a request into a `SharedArrayBuffer` slot and calls `Atomics.wait()` to block; main thread reads the slot, writes the response, and calls `Atomics.notify()` to wake the worker. No service worker involved. Roughly **10× faster** round-trips. Enabled via the **COI Headers** setting.
   - **Service Worker bridge** (fallback when COI is unavailable) — worker makes a synchronous XHR to a synthetic URL; a registered service worker intercepts it, relays the message to the main thread, and returns the response as the XHR body.

---

## Architecture

```
Page request (PHP)
  └─ dc_swp_partytown_script_attrs()  ← per-service consent gate
       ├─ GCM v2-aware service + GCM v2 enabled  → type="text/partytown" (service self-restricts)
       ├─ Meta Pixel + Meta LDU enabled           → type="text/partytown" (fbq LDU stub active)
       ├─ Other service + marketing consent       → type="text/partytown"
       └─ Other service + no consent              → type="text/plain"  (browser blocked)
```

```
Layer                    Handled by
───────────────────────  ──────────────────────────────────
Third-party scripts      Partytown Web Worker
Main-thread sync bridge  Atomics (COI) or SW relay (fallback)
HTML page caching        W3 Total Cache (or PHP fallback)
```

---

## Key features

- **No npm / no build step** — Partytown lib files are vendored in `assets/partytown/`
- **Auto-detect** — one-click scan in admin discovers external scripts on your homepage
- **Pattern-based** — enter one URL pattern per line; full URLs and partial patterns both work
- **Consent Gate (WP Consent API)** — optional toggle blocks scripts as `text/plain` until the visitor grants consent via any CMP that integrates with the WP Consent API; per-script consent category support
- **GCM v2 per-service gate** — GCM v2-aware services always run in the worker and self-restrict; Meta Pixel uses LDU
- **Consent Architecture panel** — collapsible admin panel shows GCM v2-aware services and Meta LDU badges (shields.io SVGs + offline CSS fallback)
- **Bot-safe** — bots receive no Partytown JS (clean HTML for crawlers)
- **Cart/checkout safe** — Partytown disabled on cart, checkout, and account pages
- **Bilingual admin** — English default, Danish auto-detected from WP locale
- **Weekly auto-updates** — GitHub Actions workflow opens a PR when a new Partytown release is detected

---

## Installation

1. Upload the `dc-sw-prefetch` folder to `/wp-content/plugins/`.
2. Activate from the **Plugins** screen.
3. Go to **SW Prefetch** in the admin menu.
4. Add URL patterns for any third-party scripts you want to offload (e.g. `analytics.ahrefs.com` or the full GTM URL). Use the **Auto-Detect** button to scan your homepage.
5. Save.

The `window.partytown.forward` array is pre-configured for all officially tested services: `dataLayer.push` (GTM), `fbq` (Facebook Pixel), `_hsq.push` (HubSpot), `Intercom`, `_learnq.push` (Klaviyo), `ttq.track`/`ttq.page`/`ttq.load` (TikTok Pixel), `mixpanel.track` (Mixpanel), `FS.identify`/`FS.event` (FullStory). See [partytown.qwik.dev/common-services](https://partytown.qwik.dev/common-services/) for details.

---

## Updating the vendored Partytown library

**Automatic:** the `update-partytown.yml` workflow runs every Monday at 08:00 UTC.

**Manual:**
```bash
# Latest release
bash scripts/update-partytown.sh

# Pin a specific version
bash scripts/update-partytown.sh 0.11.2
```

Then commit `assets/partytown/` and `package.json`.

---

## Repository structure

```
dc-sw-prefetch.php   — Main plugin file
admin.php            — Admin settings page (EN/DA)
uninstall.php        — Cleanup on deletion
assets/partytown/    — Vendored Partytown lib (do NOT hand-edit)
scripts/             — update-partytown.sh
.github/workflows/   — deploy.yml, update-partytown.yml
package.json         — Tracks vendored Partytown version
languages/           — .pot translation template
```

---

## External Services

This plugin is a **framework** for running administrator-configured third-party scripts off the browser's main thread using the vendored [Partytown](https://partytown.qwik.dev/) library. The plugin itself does not connect to any external service autonomously.

### Partytown library

The Partytown JavaScript library is fully **vendored** inside the plugin (`assets/partytown/`). No files are downloaded from any CDN at runtime; the library is loaded from your own server.

### CORS proxy (server-side script relay)

Some third-party CDNs do not send CORS headers, which prevents Partytown's sandbox from fetching their scripts directly from the browser. The plugin provides a `/~partytown-proxy` endpoint: WordPress makes a **server-side HTTP GET request** to the CDN and re-serves the script to the browser.

- **What is sent:** the script file request only — no visitor personal data.
- **When:** only when Partytown cannot fetch a script directly, and only for hostnames the administrator has added to the Partytown Script List.
- **Allowlist-only:** the proxy strictly rejects any hostname not configured by the administrator.

### Third-party analytics and marketing scripts (administrator-configured)

When the administrator adds a service's URL pattern to the Partytown Script List, that service's script loads inside a Web Worker (via Partytown) for visitors who have granted marketing consent. The visitor's browser (or the CORS proxy) contacts the service's CDN, and the service may receive visitor data per its own terms.

**Scripts are only loaded when:**
1. The administrator has added the service's URL pattern to the Partytown Script List or Inline Script Blocks.
2. If the **Consent Gate** is enabled, the visitor has granted consent for the script's category via the WP Consent API. Without consent, scripts are blocked (`type="text/plain"`). If the Consent Gate is disabled (default), scripts load unconditionally.

The plugin ships pre-configured forwarding for these officially tested services:

| Service | Provider | Data sent | Privacy | Terms |
|---|---|---|---|---|
| Google Tag Manager / GA4 | Google LLC | Page URL, visitor interactions | [Privacy](https://policies.google.com/privacy) | [Terms](https://policies.google.com/terms) |
| Meta (Facebook) Pixel | Meta Platforms, Inc. | Page URL, events, hashed identifiers | [Privacy](https://www.facebook.com/privacy/policy) | [Terms](https://www.facebook.com/terms) |
| HubSpot Analytics | HubSpot, Inc. | Page views, form interactions | [Privacy](https://legal.hubspot.com/privacy-policy) | [Terms](https://legal.hubspot.com/terms-of-service) |
| Intercom | Intercom R&D Unlimited Company | Visitor identity, page views, events | [Privacy](https://www.intercom.com/legal/privacy) | [Terms](https://www.intercom.com/legal/terms-and-policies) |
| Klaviyo | Klaviyo, Inc. | Page views, cart/checkout events, email | [Privacy](https://www.klaviyo.com/legal/privacy-notice) | [Terms](https://www.klaviyo.com/legal/terms-of-service) |
| TikTok Pixel | TikTok Inc. / ByteDance Ltd. | Page views, events, hashed identifiers | [Privacy](https://www.tiktok.com/legal/page/global/privacy-policy) | [Terms](https://ads.tiktok.com/i18n/official/policy/contractor) |
| Mixpanel | Mixpanel, Inc. | Page views, events, anonymous visitor ID | [Privacy](https://mixpanel.com/legal/privacy-policy/) | [Terms](https://mixpanel.com/legal/terms-of-use/) |
| FullStory | FullStory, Inc. | Session replay, events, visitor identity | [Privacy](https://www.fullstory.com/legal/privacy-policy/) | [Terms](https://www.fullstory.com/legal/terms-and-conditions/) |

The administrator may configure additional services via the Partytown Script List. Refer to each service's own privacy policy and terms of service for details on collected data.

---

## Changelog

### 1.9.0
- Feature: **Consent Gate (WP Consent API)** — optional admin toggle that delegates consent decisions to the WP Consent API standard. When enabled, scripts are output as `type="text/plain"` with `data-wp-consent-category` until consent is granted. A client-side listener (`consent-gate.js`) dynamically unblocks scripts by swapping `text/plain` to `text/partytown`. When disabled (default), all scripts load unconditionally — no breaking change for existing installs.
- Feature: Per-script **consent category** — each inline script block can be assigned a WP Consent API category (marketing, statistics, statistics-anonymous, functional, preferences). The Script List uses a configurable default category.
- Feature: Hostname-to-category mapping — known services are automatically assigned the correct consent category (e.g. Hotjar → statistics, Intercom → functional).
- Removed: 8 CMP-specific cookie detection functions (Complianz, CookieYes, Cookiebot, Cookie Information, Borlabs Cookie, WebToffee GDPR, Moove GDPR, Cookie Notice). Consent is now handled exclusively via the WP Consent API. Any CMP that integrates with the WP Consent API is automatically supported.
- Removed: CMP compatibility badges from the admin Consent Architecture panel.

### 1.8.2
- Fix: PHPCS — resolved 78 auto-fixable code style violations (function brace spacing, inline comment spacing, single-line associative arrays, double-quote usage, scope indentation). Zero errors/warnings remain under the WordPress coding standard.

### 1.8.1
- Fix: GTM and GA4 scripts now load via `type="text/partytown"` so they run entirely in a Partytown Web Worker off the main thread. A thin main-thread stub (`window.dataLayer||=[]`) is emitted before the Partytown tag; Partytown's `forward:['dataLayer.push',{preserveBehavior:true}]` relay ensures GCM v2 consent signals and all main-thread `gtag()` calls reach the worker correctly.

### 1.8.0
- Feature: Google Tag Management — three modes: Enter Tag ID, Auto-Detect (Site Kit / MonsterInsights / GTM4WP / CAOS / Analytify), Setup Guide (4-step onboarding wizard).
- Feature: GTM snippet injection in `<head>` at priority 5 (after GCM v2 consent default), `<noscript>` iframe via `wp_body_open`. Supports GTM-XXXXXXX, G-XXXXXXXXXX (GA4), UA-XXXXX-X.
- Feature: 4-step wizard with real-time Container ID validation, step indicator, and bilingual (EN/DA) UI.

### 1.7.0
- Enhancement: Google Consent Mode v2 — all 7 parameters now declared (`security_storage`, `functionality_storage`, `personalization_storage`, `analytics_storage`, `ad_storage`, `ad_user_data`, `ad_personalization`).
- Enhancement: GCM v2 consent defaults split per category: `analytics_storage` follows statistics consent, `personalization_storage` follows preferences consent, ad signals follow marketing consent.
- Enhancement: Consent revoke listener — fires `gtag('consent','update',{denied})` on `cmplz_revoke` / `dc_swp_consent_revoke` DOM events so withdrawn consent is immediately reflected.
- Enhancement: New admin options — `url_passthrough` and `ads_data_redaction` for GCM v2 cookieless measurement.
- Enhancement: Opt-out mode awareness in all three consent helpers — Complianz opt-out and CookieYes non-consent-region visitors default to granted; explicit denial cookies still honoured.

### 1.6.0
- Standards: Renamed all `dampcig_pwa_*` options to `dc_swp_*` prefix; existing settings are migrated automatically on activation.
- Standards: Removed global PHPCS EscapeOutput suppression; per-line justification comments used instead.
- Standards: Renamed `dc_footer_credit_owner` sentinel function to `dc_swp_footer_credit_owner`.
- Standards: Replaced `file_get_contents()` with the WP Filesystem API for local file reads.
- Security: `dc_swp_sanitize_js_code()` now applied to inline script code fields at save time.

### 1.5.3
- Fix: Google Consent Mode v2 — set `gtag('consent','default')` directly to `granted` for returning visitors whose CMP cookie is already present. Eliminates the redundant default-denied + update-granted pattern from 1.5.2. `wait_for_update:500` is only emitted when consent has not yet been given (new visitors), so the CMP JS can still fire its own `update` within the grace period.

### 1.5.2
- Fix: Google Consent Mode v2 — immediately follow the `gtag('consent','default',{denied})` stub with a `gtag('consent','update',{granted})` call when the visitor's CMP marketing cookie is already set. GTM/GA4 now receive the correct granted state on every page load for returning visitors, without waiting for the CMP JavaScript to fire.

### 1.5.1
- Compat: Declare WooCommerce HPOS (High-Performance Order Storage) compatibility via `FeaturesUtil::declare_compatibility('custom_order_tables', ...)`. Resolves the "Incompatible plugin" admin warning. The plugin does not touch the orders table.

### 1.5.0

**Google Consent Mode v2 — per-service consent architecture**

- Feature: Replaced the global GCM v2 bypass with a per-service consent gate. Six hostnames are classified GCM v2-aware (`googletagmanager.com`, `google-analytics.com`, `static.hotjar.com`, `script.hotjar.com`, `clarity.ms`, `snap.licdn.com`, `analytics.tiktok.com`): when GCM v2 is active these scripts always run as `type="text/partytown"` — each service reads the consent state and self-restricts data collection internally. Other scripts continue to gate on the CMP marketing cookie.
- Feature: Meta Pixel excluded from GCM v2 — uses its own Limited Data Use (LDU) API. The Meta LDU toggle is the correct gate for Meta Pixel regardless of GCM v2 state.
- Feature: New helper API — `dc_swp_get_gcm_v2_aware_services()` (filterable via `dc_swp_gcm_v2_aware_services`), `dc_swp_script_uses_gcm_v2()`, `dc_swp_is_meta_script()`, `dc_swp_inline_uses_gcm_v2()`, `dc_swp_inline_is_meta()`.
- Feature: Per-service gate applied to all three consent-check locations: `wp_script_attributes` filter (priority 5), output-buffer rewriter, and Inline Script Block output.
- Feature: Consent Architecture info panel in admin settings — collapsible `<details>` element with three badge groups: GCM v2-aware services, Meta Pixel LDU, and CMP compatibility (8 CMPs). Shields.io SVG badges with pure CSS offline fallback.
- Feature: CMP research completed — Complianz, CookieYes, Cookiebot, Cookie Information, Borlabs, WebToffee fire `gtag('consent','update',…)` natively; Moove GDPR requires premium; Cookie Notice (free) cannot fire GCM v2 update signals.
- Enhancement: `consent_mode_desc` and `meta_ldu_desc` admin strings updated in EN + DA to accurately describe the per-service architecture.
- Fix: `dc_swp_partytown_buffer_rewrite()` was computing `$new_type` but never writing it into `$tag_inner`. Raw-echoed `<script src>` tags (e.g. scripts added directly via `functions.php`) bypassed the consent gate entirely and executed on the main thread.
- Fix: Added `break` after type assignment in `dc_swp_partytown_script_attrs()` — loop now stops at the first matching pattern.
- Security: `sslverify => false` removed from auto-detect AJAX handler; SSL verification is always on (OWASP A02).
- Standards: Plugin header `License` updated to SPDX `GPL-2.0-or-later`; added `Update URI` and `WC tested up to: 10.4.3`.
- i18n: Badge and force-Partytown UI strings moved into `dc_swp_str()` with Danish translations; 8 new consent panel strings added (EN + DA).
- Cleanup: `dc_swp_debug_mode` option now removed on plugin uninstall.

### 1.4.2
- Feature: Script Block compatibility badges — each block now shows a **Supported | Partytown** or **Unsupported | Deferred** badge based on whether its scripts (src= or inline) reference a Partytown-verified service. Inline scripts (e.g. Meta Pixel) are scanned for embedded service URLs.
- Feature: **Force Enable Partytown** toggle for unsupported Script Block scripts — admin can force an unknown script into the Partytown worker, with a warning notice. Badge state changes to **Forced | Partytown** (orange).
- Feature: Badges load as shields.io SVG images with an automatic CSS text fallback for offline or firewalled admin environments.
- Fix: `force_partytown` flag was silently dropped on every save — added to both sanitize paths (`dc_swp_sanitize_inline_scripts_option` and the `$_POST` handler).
- Fix: Unknown inline Script Block scripts now fall back to deferred main-thread execution instead of unconditionally entering the Partytown worker.
- Fix: Unknown src= Script Block scripts now always run on the main thread (consent-gated), preventing the `about:srcdoc` sandbox error.
- Chore: Add `vendor/` and `node_modules/` exclude-patterns to `phpcs.xml`.
- Chore: Remove `eslint.config.mjs` from `.gitignore` (tracked source); add to `.distignore` instead.

### 1.4.1
- Chore: Rename plugin to "DC Script Worker Prefetcher" in all remaining source files (admin.php, uninstall.php, phpcs.xml, package.json, .pot file, copilot-instructions.md, PHP docblock headers).

### 1.4.0
- Feature: Add FullStory (`FS.identify`, `FS.event`) to the Partytown `forward` array — now on the officially tested services list.
- Feature: Auto-enable `strictProxyHas` when FullStory patterns are detected in the Script List or Inline Script Blocks, preventing the `in` operator false-positive that blocks FullStory initialisation via GTM.
- Feature: **Partytown Debug Mode** admin toggle. When enabled, loads the unminified `debug/partytown.js` build and sets all seven Partytown log flags (`logCalls`, `logGetters`, `logSetters`, `logImageRequests`, `logScriptExecution`, `logSendBeaconRequests`, `logStackTraces`). Output via `console.debug` — enable **Verbose** in DevTools. Worker-side logs require COI Headers to be active (Atomics Bridge). Bilingual UI (EN/DA).
- Feature: Expose `window.dcSwpPartytownUpdate()` JS helper that dispatches the `ptupdate` CustomEvent, enabling integrators to notify Partytown of dynamically appended `type="text/partytown"` scripts.
- Fix: Add `Cross-Origin-Resource-Policy: same-origin` and `Cross-Origin-Embedder-Policy: credentialless` headers to `/~partytown/` file responses. Required for the debug build's `partytown-ww-atomics.js`, which is loaded as a real URL (not a blob) and is blocked by COEP enforcement without these headers.
- Rename: Plugin display name updated to **DC Script Worker Prefetcher** — "Script Worker" accurately reflects the Web Worker (not service worker) architecture.

### 1.3.9
- Fix: Sanitize each field in `dc_swp_sanitize_inline_scripts_option()` register_setting callback.
- Fix: Explicitly close output buffer on `shutdown` (priority 0) via `dc_swp_partytown_buffer_end()` to prevent buffer-stack misalignment.
- Fix: `phpcs:ignore` comments moved to correct lines for `apply_filters` hook name tokens.
- Deploy: Add SSH cleanup step in `deploy.yml` to remove dev-only files from production server after rsync.
- Docs: Add External Services disclosure section with per-service privacy policy and terms links.
- Docs: Correct async/defer description — execution (not download) is the main thread cost.
- Docs: Add Partytown beta disclaimer, trade-offs link, and "How Partytown works" section.

### 1.3.8
- Refactor: Auto-detect scan now returns all third-party scripts found on the homepage.
- Scripts on Partytown's officially verified services list are pre-checked with a green compatibility badge.
- Unrecognised scripts are shown unchecked with an explicit compatibility warning.
- Removed auto-population of the exclude/blocklist in auto-detect; architecture is now allow-list first.

### 1.3.7
- Revert: Removed `script_loader_tag` patching of third-party plugin scripts.
- DC SW Prefetch now only manages scripts explicitly moved into Partytown.

### 1.3.6
- Fix: Removed `crossorigin="anonymous"` from Trustpilot `widget-bootstrap-js` when needed under `COEP: credentialless`.

### 1.3.5
- Revert: Restored `partytown-config.js` as a normal enqueued file.
- Removed unnecessary inline `file_get_contents()` output path.

### 1.3.4
- Fix: `resolveUrl` now uses `this.pathRewrites`, `this.proxyAllowedHosts`, and `this.proxyUrl`.
- Ensures the function is self-contained after Partytown serialises it into the worker.

### 1.3.3
- Fix: Prevent `ReferenceError: dcSwpPartytownData is not defined` in Partytown SW sandbox.

### 1.3.2
- Update: Vendored Partytown 0.13.1 (built from source, pre-release).

### 1.3.1
- Refactor: Moved inline JS into static files in `assets/js/` with `wp_localize_script()` data injection.
- Added `DC_SWP_VERSION` constant for consistent script versioning/cache-busting.
- Added ESLint tooling for `assets/js/`.

### 1.3.0
- New: Consent-aware script loading for 8 common WordPress CMP cookies.
- Scripts now render as `type="text/partytown"` with consent and `type="text/plain"` without consent.

### 1.2.0
- New: WP emoji removal (`print_emoji_detection_script` and `print_emoji_styles`).

### 1.1.0
- Replaced custom `dc-sw.js` service worker with vendored Partytown 0.10.3.
- Added `scripts/update-partytown.sh` and weekly auto-update workflow.

### 1.0.0
- Initial release.

---

## License

GPL-2.0-or-later — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html)

---

**Author:** [lennilg](https://github.com/lennilg) — manager@dampcig.dk
