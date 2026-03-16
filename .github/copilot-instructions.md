# Copilot Instructions

## Project Overview

**DC Service Worker Prefetcher** is a WordPress/WooCommerce plugin (PHP 8.0+, WordPress 6.8+) that:

1. Integrates [Partytown](https://github.com/QwikDev/partytown) to offload third-party scripts (Google Analytics, Meta Pixel, LinkedIn Insight, Twitter/X) into a dedicated service worker, keeping them off the browser main thread.
2. Provides viewport/pagination prefetching via `IntersectionObserver` so WooCommerce product pages load instantly from cache.
3. Includes WP emoji removal, WooCommerce LCP image preloading, bot detection, and a bilingual (EN/DA) admin UI.

The Partytown library is **vendored** in `assets/partytown/` — no npm build step is needed at runtime.

## Repository Structure

```
dc-sw-prefetch.php   — Main plugin file: bot detection, hooks, Partytown injection,
                       prefetch JS, SW endpoint, LCP preload, cache headers
admin.php            — Admin settings page (EN/DA bilingual)
uninstall.php        — Cleanup on plugin deletion
assets/partytown/    — Vendored Partytown lib files (do NOT hand-edit)
scripts/             — update-partytown.sh: vendor a new Partytown release
.github/workflows/   — deploy.yml (rsync + GitHub Release), update-partytown.yml (weekly bot)
phpcs.xml            — PHP_CodeSniffer config (WordPress ruleset)
package.json         — Tracks vendored Partytown version under "vendored"
languages/           — .pot translation template
```

## Coding Conventions

- **WordPress Coding Standards** are enforced via PHPCS (`phpcs.xml`). Run `vendor/bin/phpcs` to check.
- All global functions, classes, and constants **must** use the `dc_swp_` / `DC_SWP_` prefix.
- Escape all output with `esc_html()`, `esc_attr()`, `esc_url()`, etc. The only exception is the service worker JS endpoint output, which is excluded from the PHPCS escape rule.
- Use `sanitize_*` and `wp_unslash()` on all user-supplied input.
- Guard every file with `if ( ! defined( 'ABSPATH' ) ) { die(); }`.
- PHP 8.0+ features (named arguments, `str_contains`, etc.) are acceptable.
- Wrap functions in `function_exists()` checks where child-theme coexistence is required (see bot detection).
- Translations use `__()` / `esc_html__()` with the `dc-sw-prefetch` text domain.

## Key Patterns

- **Bot detection** (`dc_swp_is_bot_request()`): runs before any JS is emitted; bots receive no service worker or prefetch code.
- **Safe pages**: Partytown and prefetch JS are skipped on cart, checkout, and account pages (`dc_swp_is_safe_page()`).
- **Partytown endpoint**: served by WordPress via a rewrite rule + query var; PHP streams the vendored JS files directly.
- **Admin settings** are stored as a single serialised option (`dc_swp_options`) via `get_option` / `update_option`.
- **Cache headers** fall back to PHP `header()` calls when W3 Total Cache is not active.

## Build & Update Workflow

There is **no build step** for the plugin itself.

To update the vendored Partytown library:

```bash
# Latest release
bash scripts/update-partytown.sh

# Pin a specific version
bash scripts/update-partytown.sh 0.11.2
```

Then commit `assets/partytown/` and `package.json`. The weekly `update-partytown.yml` workflow does this automatically and opens a PR.

## Linting

```bash
# Install PHPCS + WordPress standards (first time only)
composer require --dev squizlabs/php_codesniffer wp-coding-standards/wpcs
vendor/bin/phpcs --config-set installed_paths vendor/wp-coding-standards/wpcs

# Run linter
vendor/bin/phpcs
```

There are no PHP unit tests in this repository. Validation is done manually on a WordPress instance and through PHPCS.

## Deployment

Pushing to `main` or creating a version tag triggers `deploy.yml`, which:
1. rsync-deploys to the production server via SSH.
2. Builds a WordPress.org–compatible ZIP.
3. Creates a GitHub Release with the ZIP attached.

## Important Constraints

- Do **not** hand-edit files inside `assets/partytown/` — always use the update script.
- Do **not** introduce npm/build dependencies into the plugin runtime; keep it vendor-only.
- Maintain backwards compatibility with the `dc_swp_options` option schema to avoid breaking existing installs on upgrade.
- Do not remove or break bilingual support (EN/DA); use `get_locale()` checks as the existing code does.
