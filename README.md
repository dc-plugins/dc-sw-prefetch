# DC Service Worker Prefetcher

**Version:** 1.3.8  
**Requires WordPress:** 6.8+  
**Requires PHP:** 8.0+  
**WooCommerce tested up to:** 10.4.3  
**License:** GPLv2 or later

Lazy-loaded Partytown library + consent-aware third-party script management + viewport/pagination prefetching for WooCommerce. Fully vendored — no npm required.

---

## What it does

1. **Partytown Web Worker execution** — [Partytown](https://partytown.qwik.dev/) is a lazy-loaded library that relocates resource-intensive scripts into a web worker and off the main thread, dedicating the main thread to your code. Unlike `async`/`defer` (which still execute on the main thread and can block `window.onload`), Partytown executes third-party scripts entirely in a Web Worker. The browser main thread is freed from analytics and ad code — no layout jank, no TBT impact, no competition with user interactions. Officially tested compatible services: **Google Tag Manager**, **Facebook Pixel**, **HubSpot**, **Intercom**, **Klaviyo**, **TikTok Pixel**, **Mixpanel** ([full list](https://partytown.qwik.dev/common-services/)). Scripts are consent-gated: output as `type="text/partytown"` when consent is present, `type="text/plain"` (browser-blocked) when it is not.

> **Note:** Partytown is currently in beta. It is not guaranteed to work in every scenario. Review the [trade-offs](https://partytown.qwik.dev/trade-offs) before enabling on production.

2. **Viewport/pagination prefetching** — `IntersectionObserver` watches visible WooCommerce products and issues `<link rel="prefetch">` before the user clicks. The next-page link is also prefetched 2 s after page load.

3. **Bonus performance** — WP emoji removal (saves ~76 KB + one DNS lookup), PHP fallback cache headers when W3 Total Cache is absent.

---

## Supported consent plugins

| Plugin | Cookie read |
|---|---|
| Complianz | `cmplz_marketing = allow` |
| Cookiebot (Cybot) | `CookieConsent` contains `marketing:true` |
| CookieYes | `cookieyes-consent` contains `marketing:yes` |
| Borlabs Cookie | `borlabs-cookie` JSON `.consents.marketing` |
| Cookie Notice (dFactory) | `cookie_notice_accepted = true` |
| WebToffee GDPR | `cookie_cat_marketing = accept` |
| Cookie Information | `CookieInformationConsent` JSON consents array |
| Moove GDPR | `moove_gdpr_popup` JSON `.thirdparty = 1` |

If no supported CMP cookie is found, scripts remain `type="text/plain"` — safe default.

---

## How Partytown works

1. Scripts matching your configured patterns are output with `type="text/partytown"` — this tells the browser **not** to execute them on the main thread.
2. Partytown's **service worker** intercepts fetch requests originating from the web worker.
3. A **web worker** receives and executes the scripts entirely off the main thread.
4. **JavaScript Proxies** replicate main thread APIs (DOM reads/writes) synchronously inside the worker — third-party scripts run exactly as coded, without modification.
5. Communication between the web worker and main thread uses either:
   - **Synchronous XHR + Service Worker** (default)
   - **Atomics bridge** when `crossOriginIsolated` is enabled — roughly **10× faster**. Enabled via the **SharedArrayBuffer** setting, which sends the required `Cross-Origin-Opener-Policy` and `Cross-Origin-Embedder-Policy` headers.

---

## Architecture

```
Page request (PHP)
  └─ dc_swp_has_marketing_consent()   ← reads CMP cookies
       ├─ consent present  → type="text/partytown"  → Partytown SW runs it off-thread
       └─ no consent       → type="text/plain"      → browser ignores it
```

```
Layer                    Handled by
───────────────────────  ──────────────────────────────────
Third-party scripts      Partytown service worker
HTML page caching        W3 Total Cache (or PHP fallback)
Product/page prefetch    DC Prefetch (IntersectionObserver)
```

---

## Key features

- **No npm / no build step** — Partytown lib files are vendored in `assets/partytown/`
- **Auto-detect** — one-click scan in admin discovers external scripts on your homepage
- **Pattern-based** — enter one URL pattern per line; full URLs and partial patterns both work
- **Exclusion list** — built-in exclusions for Trustpilot, Stripe, PayPal, Braintree, Facebook SDK, Google Maps, and Reamaze; add your own
- **Bot-safe** — bots receive no Partytown JS (clean HTML for crawlers)
- **Cart/checkout safe** — Partytown and prefetcher disabled on cart, checkout, account pages
- **Bilingual admin** — English default, Danish auto-detected from WP locale
- **Weekly auto-updates** — GitHub Actions workflow opens a PR when a new Partytown release is detected

---

## Installation

1. Upload the `dc-sw-prefetch` folder to `/wp-content/plugins/`.
2. Activate from the **Plugins** screen.
3. Go to **SW Prefetch** in the admin menu.
4. Add URL patterns for any third-party scripts you want to offload (e.g. `analytics.ahrefs.com` or the full GTM URL). Use the **Auto-Detect** button to scan your homepage.
5. Save.

The `window.partytown.forward` array is pre-configured for all officially tested services: `dataLayer.push` (GTM), `fbq` (Facebook Pixel), `_hsq.push` (HubSpot), `Intercom`, `_learnq.push` (Klaviyo), `ttq.track`/`ttq.page`/`ttq.load` (TikTok Pixel), `mixpanel.track` (Mixpanel). See [partytown.qwik.dev/common-services](https://partytown.qwik.dev/common-services/) for details.

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
2. The visitor has a valid marketing-consent cookie from a supported CMP. Without consent, all configured scripts are blocked (`type="text/plain"`).

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

The administrator may configure additional services via the Partytown Script List. Refer to each service's own privacy policy and terms of service for details on collected data.

---

## Changelog

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
