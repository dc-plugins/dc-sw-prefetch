# DC Service Worker Prefetcher

**Version:** 1.3.0  
**Requires WordPress:** 6.8+  
**Requires PHP:** 8.0+  
**WooCommerce tested up to:** 10.4.3  
**License:** GPLv2 or later

Partytown service worker + consent-aware third-party script management + viewport/pagination prefetching for WooCommerce. Fully vendored — no npm required.

---

## What it does

1. **Consent-aware Partytown offloading** — reads marketing-consent cookies from 8 common WordPress CMPs on every PHP request. Matching scripts are output as `type="text/partytown"` when consent is present, or `type="text/plain"` (browser-blocked) when it is not. No hooks into the CMP, no DOM patching, no race conditions.

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

The `window.partytown.forward` array is pre-configured for `dataLayer.push`, `gtag`, `fbq`, `lintrk`, and `twq`.

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

## Changelog

### 1.3.0
- **New:** Consent-aware script loading — reads marketing-consent cookies from 8 common WordPress CMPs. Scripts output as `type="text/partytown"` after consent, `type="text/plain"` without.
- Removed `dc_swp_cmp_intercept_script` Node.prototype hook (replaced by server-side cookie check).
- Added `dc_swp_has_marketing_consent()` covering Complianz, Cookiebot, CookieYes, Borlabs, Cookie Notice, WebToffee, Cookie Information, and Moove GDPR.

### 1.2.0
- **New:** WP emoji removal — dequeues `print_emoji_detection_script` and `print_emoji_styles` saving ~76 KB.

### 1.1.0
- Replaced custom `dc-sw.js` service worker with vendored Partytown 0.10.3.
- Added `scripts/update-partytown.sh` for manual vendor updates.
- Added `.github/workflows/update-partytown.yml` weekly auto-update bot.

### 1.0.0
- Initial release.

---

## License

GPL-2.0-or-later — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html)

---

**Author:** [lennilg](https://github.com/lennilg) — manager@dampcig.dk
