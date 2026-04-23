# Reply to plugin review — DC Script Worker Proxy (dc-script-worker-prefetcher)

**Subject:** Re: [Plugin Review] DC Script Worker Proxy — issues addressed in 3.1.0

Hi reviewers,

Thank you for the detailed feedback. I have published a new version (**3.1.0**) that
addresses every point in your e-mail. A walkthrough of each item below.

ZIP: <link to your hosted 3.1.0 zip / GitHub release>
Diff: https://github.com/dc-plugins/dc-sw-prefetch/compare/v3.0.1...v3.1.0

---

## 1. "Allowing arbitrary script insertion" (Inline Script Blocks)

You correctly flagged the previous Inline Script Blocks panel as an arbitrary-JS
injector. I have rebuilt that feature as a **Script Center** that follows the same
pattern as Complianz GDPR's Script Center (an accepted wp.org plugin):

- Pasted JS is sanitised at save time with `sanitize_text_field()`. This strips
  `<`, `>`, line breaks and tags, so the stored value can never break out of the
  wrapping `<script>` element.
  See `dc_swp_sanitize_js_code()` and `dc_swp_sanitize_inline_scripts_option()`
  in `admin.php`.
- At output time the JS body is **always** rendered inside an inert
  `<script type="text/plain" data-wp-consent-category="…">…</script>` block.
  Browsers do not execute `text/plain` script tags. A consent layer (the WP
  Consent API listener / Partytown) is the only thing that can later flip the
  type to `text/javascript` (or `text/partytown`) — and only after the visitor
  grants consent for the matching category.
  See `dc_swp_output_inline_scripts()` in `dc-sw-prefetch.php`.
- Each row is gated on a required consent **category** (marketing, statistics,
  statistics-anonymous, functional, preferences). The administrator picks the
  category; the visitor decides whether to grant it.
- The `force_partytown` per-row override has been removed entirely. There is no
  longer any path that causes a stored JS body to be `echo`'d as live
  `<script>…</script>`.

The capability gate (`manage_options`) and nonce check on the Save handler
remain unchanged.

## 2. "Calling files remotely" (PayPal pixel + shields.io)

All remote calls outside of administrator-configured analytics services have been
removed:

- **PayPal donate panel** (`admin.php`): the
  `https://www.paypal.com/en_DK/i/scr/pixel.gif` 1×1 tracking pixel has been
  removed completely. The PayPal `btn_donate_LG.gif` from
  `paypalobjects.com` is gone too — the donate link is now a plain styled
  `<a class="button button-primary">` text button (no remote image).
- **shields.io badges** (`admin.php`, `assets/js/admin.js`): every
  `https://img.shields.io/badge/...` request has been removed. The Consent
  Architecture admin panel now renders pure-CSS badges from `data-label` /
  `data-msg` attributes — no network requests at all. The JS helper
  `setBadgeImg()` (which used `new Image()`) is replaced by a CSS-only
  `setBadge()` helper.

## 3. "External services not documented" (FullStory)

`readme.txt` now has an explicit FullStory entry under **External Services**:

> **FullStory** — Provided by FullStory, Inc. Sends session-replay event data,
> page views, and DOM mutations from `edge.fullstory.com/s/fs.js`. Loaded only
> when the administrator has added a `fullstory.com` pattern to the Script List
> or Script Center, and after marketing consent.
> Privacy Policy: https://www.fullstory.com/legal/privacy/
> Terms of Service: https://www.fullstory.com/legal/terms-and-conditions/

The shields.io references in **External Services** are no longer relevant — the
plugin no longer contacts shields.io. (They appeared only in old changelog
entries; the runtime no longer makes any such request.)

## 4. "Echoing scripts directly" / `wp_enqueue_script`

Every external `<script src="…">` that was previously echoed by the plugin has
been migrated to `wp_register_script()` + `wp_enqueue_script()` with versioned
handles prefixed `dc-swp-pt-*` (Partytown) or `dc-swp-sc-*` (Script Center src):

| Service     | Handle                | Registered in                               |
|-------------|-----------------------|---------------------------------------------|
| GTM         | `dc-swp-pt-gtm`       | `dc-sw-prefetch.php` `dc_swp_inject_gtm_head()` |
| GA4 / gtag  | `dc-swp-pt-gtag`      | same                                        |
| Meta Pixel  | `dc-swp-pt-fbevents`  | `dc-sw-prefetch.php` Meta Pixel injector    |
| HubSpot     | `dc-swp-pt-hubspot`   | `includes/integrations.php`                 |
| Klaviyo     | `dc-swp-pt-klaviyo`   | `includes/integrations.php`                 |
| Script Center external src | `dc-swp-sc-{id}` | `dc_swp_enqueue_script_center_src()` |

A single `script_loader_tag` filter, `dc_swp_script_loader_tag()`, then
rewrites the WP-emitted `<script>` tag for any handle prefixed `dc-swp-`:

- pre-consent → `type="text/plain"` + `data-wp-consent-category="…"`
- post-consent + Partytown enabled → `type="text/partytown"`
- the CSP nonce attribute is appended when one is configured

The category is attached via `wp_script_add_data( $handle,
'dc_swp_consent_category', '…' )` so the filter can look it up without parsing
the URL again.

## 5. Text-domain mismatch

The WP.org slug is `dc-script-worker-prefetcher`. The code had been using
the older `dc-service-worker-prefetcher` text-domain in 193 places. All of
those have been corrected (193 → 0). The plugin header `Text Domain:` and
the `load_plugin_textdomain()` call now both use `dc-script-worker-prefetcher`,
and the language files match accordingly:

- `languages/dc-script-worker-prefetcher.pot`
- `languages/dc-script-worker-prefetcher-da_DK.po`
- `languages/dc-script-worker-prefetcher-da_DK.mo`

Note: the GitHub repository folder is named `dc-sw-prefetch` (its own slug);
the WP.org plugin slug is `dc-script-worker-prefetcher`. These differ by design.

## 6. Unescaped `$body` (proxy endpoint) and `$ns_content` (noscript)

- `$ns_content`: the `<noscript>…</noscript>` echo path was part of the old
  Inline Script Blocks renderer. That entire renderer is gone. Script Center
  no longer parses HTML and no longer emits `<noscript>` blocks at all, so
  there is no longer an unescaped HTML output path from administrator input.
- `$body` (proxy endpoint at `/~partytown-proxy`): this endpoint streams a
  JavaScript file fetched server-side from a CDN. HTML escaping the body would
  corrupt JavaScript operators (`<`, `>`, `&`, `'`, `"`) and break the script.
  An inline comment now documents the safety controls at the call site:
  - the upstream host is hard-allowlisted (`dc_swp_is_proxy_host_allowed`);
  - the `wp_remote_get()` call uses `redirection => 0`, so a redirect cannot
    swap in a body from a non-allowlisted origin;
  - the response `Content-Type` is forced to `application/javascript` so a
    hostile body cannot be re-interpreted as HTML by the browser.

If you would prefer a different approach for the proxy body, I'm happy to
adjust — e.g. wrap the response in a stricter MIME sniff guard or hash-pin
upstream URLs.

---

## Validation

- `vendor/bin/phpcs` exits with **0 errors / 0 warnings** on `admin.php`,
  `dc-sw-prefetch.php`, `includes/integrations.php`, `uninstall.php`.
- `php -l` clean on every modified file.
- Manually re-tested on a WordPress 6.8 + WooCommerce 10.7 site with WP
  Consent API installed, exercising the Script Center save flow, GTM
  injection, Meta Pixel injection, and the Donate panel.

I appreciate the time you have taken to review the plugin. Please let me know
if any of the above is still insufficient and I'll address it right away.

Best regards,
Lenni
