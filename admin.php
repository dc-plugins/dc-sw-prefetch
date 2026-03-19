<?php
/**
 * DC Service Worker Prefetcher — Admin Interface
 * Partytown third-party script offloading + viewport/pagination prefetching
 * DampCig.dk
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) { die(); }

// ── Locale strings ────────────────────────────────────────────────────────────
// English is the default; Danish is used when WP locale starts with "da_".
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_swp_str( $key ) {
    static $s = null;
    if ( $s === null ) {
        $da = strncmp( get_locale(), 'da_', 3 ) === 0;
        $s  = $da ? [
            'page_title'        => 'SW Prefetch Indstillinger',
            'saved'             => 'Indstillinger gemt!',
            'info_title'        => 'Partytown Integration',
            'info_body'         => 'Partytown kører tredjeparts-scripts (Google Analytics, Meta Pixel osv.) i en service worker, så de ikke blokerer browser-tråden. Viewport-prefetch preloader produkter automatisk.',
            'sw_label'          => 'Aktiver Partytown',
            'sw_desc'           => 'Aktiver Partytown service worker til offloading af tredjeparts-scripts og viewport-prefetch.',
            'preload_label'     => 'Viewport Preloading',
            'preload_desc'      => '<strong>Anbefalet!</strong> Preloader automatisk produkter synlige i viewporten via browser prefetch. Benytter W3TC cache for øjeblikkelig indlæsning når brugeren klikker.',
            'strategy_title'    => 'Arkitektur',
            'html_label'        => 'Tredjeparts-scripts',
            'html_val'          => 'Offloaded via Partytown (service worker)',
            'html_desc'         => 'Scripts markeret med type="text/partytown" flyttes ud af browser-tråden og kører i service workeren.',
            'static_label'      => 'HTML-sider',
            'static_val'        => 'Håndteres af W3 Total Cache',
            'static_desc'       => 'Produktsider og kategorier caches af W3TC — Partytown interfererer ikke med HTML-cachen.',
            'benefits_title'    => 'Fordele',
            'benefit_1'         => 'Tunge annoncerings- og analysescripts blokerer ikke main-thread',
            'benefit_2'         => 'Viewport-prefetch preloader produktlinks automatisk',
            'benefit_3'         => 'Pagineringslink prefetches 2 s i forvejen',
            'benefit_4'         => 'Bots og crawlers modtager aldrig Partytown (rent HTML)',
            'benefit_5'         => 'Automatiske opdateringer via GitHub Actions workflow',
            'benefit_6'         => 'WP emoji-scripts fjernet — sparer et DNS-opslag og ~76 KB',
            'benefit_7'         => 'LCP-billede preloades med korrekt imagesrcset — ingen dobbelt-fetch på mobil',
            'emoji_label'       => 'Fjern WP Emoji',
            'emoji_desc'        => 'Fjerner WordPress emoji-detection script og tilhørende CSS (s.w.org fetch). Anbefalet — moderne browsere har native emoji.',
            'lcp_label'         => 'WooCommerce LCP Preload',
            'lcp_desc'          => 'Tilføjer <code>&lt;link rel=&quot;preload&quot; imagesrcset&gt;</code> for produktbilledet på produkt- og kategorisider. Matcher mobilbrowserens srcset-kandidat, så PSI-score stiger markant.',
            'credit_label'      => 'Footer Kredit',
            'credit_checkbox'   => 'Vis kærlighed og støt udviklingen ved at tilføje et lille link i footeren',
            'credit_desc'       => 'Indsætter et diskret <a href="https://www.dampcig.dk" target="_blank">Dampcig.dk</a>-link i sidens footer ved at linke copyright-symbolet ©.',
            'save_button'       => 'Gem Indstillinger',
            'pt_version_label'  => 'Partytown Version',
            'product_base_label'      => 'Produkt-URL slug',
            'product_base_desc'       => 'URL-segmentet der identificerer produktsider, f.eks. <code>/product/</code> eller <code>/produkt/</code>. Lad feltet være tomt for at bruge den auto-detekterede WooCommerce-indstilling.',
            'product_base_detected'   => 'Auto-detekteret fra WooCommerce',
            'proxy_label'           => 'Proxy-tilladelsesliste',
            'proxy_desc'            => 'Én vært pr. linje. Kun HTTPS-scripts fra disse domæner proxies — alle andre afvises (forhindrer misbrug). Partytown omdirigerer automatisk tredjeparts-scripts til denne proxy via <code>resolveUrl</code>.',
            'proxy_cache_note'      => 'Scripts caches server-side i 24 t og serveres til browseren med 7-dages Cache-Control.',
        ] : [
            'page_title'        => 'SW Prefetch Settings',
            'saved'             => 'Settings saved!',
            'info_title'        => 'Partytown Integration',
            'info_body'         => 'Partytown runs third-party scripts (Google Analytics, Meta Pixel, etc.) inside a service worker, moving them off the browser main thread. Viewport prefetch pre-loads product pages automatically.',
            'sw_label'          => 'Enable Partytown',
            'sw_desc'           => 'Activate Partytown service worker for third-party script offloading and viewport prefetch.',
            'preload_label'     => 'Viewport Preloading',
            'preload_desc'      => '<strong>Recommended!</strong> Automatically prefetches products visible in the viewport via browser prefetch, leveraging W3TC cache for instant loading when the user clicks.',
            'strategy_title'    => 'Architecture',
            'html_label'        => 'Third-party Scripts',
            'html_val'          => 'Offloaded via Partytown (service worker)',
            'html_desc'         => 'Scripts tagged with type="text/partytown" are moved off the main thread and run inside the service worker.',
            'static_label'      => 'HTML Pages',
            'static_val'        => 'Handled by W3 Total Cache',
            'static_desc'       => 'Product pages and categories are cached by W3TC — Partytown does not interfere with HTML caching.',
            'benefits_title'    => 'Benefits',
            'benefit_1'         => 'Heavy ad/analytics scripts never block the main thread',
            'benefit_2'         => 'Viewport prefetch pre-loads product links automatically',
            'benefit_3'         => 'Pagination next-page link prefetched 2 s ahead',
            'benefit_4'         => 'Bots and crawlers never receive Partytown (clean HTML)',
            'benefit_5'         => 'Automatic updates via GitHub Actions workflow',
            'benefit_6'         => 'WP emoji scripts removed — saves a DNS lookup and ~76 KB',
            'benefit_7'         => 'LCP image preloaded with correct imagesrcset — no double-fetch on mobile',
            'emoji_label'       => 'Remove WP Emoji',
            'emoji_desc'        => 'Removes the WordPress emoji detection script and its CSS (s.w.org fetch). Recommended — modern browsers have native emoji support.',
            'lcp_label'         => 'WooCommerce LCP Preload',
            'lcp_desc'          => 'Adds <code>&lt;link rel=&quot;preload&quot; imagesrcset&gt;</code> for the product image on single product and category pages. Matches the mobile browser\'s srcset candidate so the preload is never wasted.',
            'credit_label'      => 'Footer Credit',
            'credit_checkbox'   => 'Show some love and support development by adding a small link in the footer',
            'credit_desc'       => 'Inserts a discreet <a href="https://www.dampcig.dk" target="_blank">Dampcig.dk</a> link in the footer by linking the copyright symbol ©.',
            'save_button'       => 'Save Settings',
            'pt_version_label'  => 'Partytown Version',
            'product_base_label'      => 'Product URL slug',
            'product_base_desc'       => 'The URL segment that identifies product pages, e.g. <code>/product/</code> or <code>/shop/</code>. Leave blank to use the auto-detected WooCommerce setting.',
            'product_base_detected'   => 'Auto-detected from WooCommerce',
            'proxy_label'           => 'Proxy allowlist',
            'proxy_desc'            => 'One hostname per line. Only HTTPS scripts from these domains are proxied — all others are rejected (prevents open-proxy abuse). Partytown automatically routes third-party scripts through this proxy via <code>resolveUrl</code>.',
            'proxy_cache_note'      => 'Scripts are cached server-side for 24 h and served to the browser with a 7-day Cache-Control header.',
        ];
    }
    return $s[ $key ] ?? $key;
}
// ─────────────────────────────────────────────────────────────────────────────

// Admin footer — only on this plugin's own page
add_filter( 'admin_footer_text', function( $text ) {
    $screen = get_current_screen();
    if ( $screen && $screen->id === 'toplevel_page_dc-sw-prefetch' ) {
        return sprintf(
            /* translators: %s: URL to DC Plugins GitHub organisation */
            __( 'More plugins by <a href="%s" target="_blank" rel="noopener">DC Plugins</a>', 'dc-sw-prefetch' ),
            'https://github.com/dc-plugins'
        );
    }
    return $text;
} );

// Add admin menu
add_action( 'admin_menu', 'dc_swp_setup_menu' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_swp_setup_menu() {
    add_menu_page(
        dc_swp_str( 'page_title' ),
        'SW Prefetch',
        'manage_options',
        'dc-sw-prefetch',
        'dc_swp_admin_page_html',
        'dashicons-performance'
    );
}

// Register settings
add_action( 'admin_init', 'dc_swp_register_settings' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_swp_register_settings() {
    register_setting( 'dc-sw-prefetch-settings', 'dampcig_pwa_sw_enabled',       [ 'sanitize_callback' => 'sanitize_text_field' ] );
    register_setting( 'dc-sw-prefetch-settings', 'dampcig_pwa_preload_products',  [ 'sanitize_callback' => 'sanitize_text_field' ] );
    register_setting( 'dc-sw-prefetch-settings', 'dampcig_pwa_product_base',     [ 'sanitize_callback' => 'sanitize_text_field' ] );
    register_setting( 'dc-sw-prefetch-settings', 'dampcig_pwa_footer_credit',    [ 'sanitize_callback' => 'sanitize_text_field' ] );
    register_setting( 'dc-sw-prefetch-settings', 'dc_swp_disable_emoji',         [ 'sanitize_callback' => 'sanitize_text_field' ] );
    register_setting( 'dc-sw-prefetch-settings', 'dc_swp_lcp_preload',           [ 'sanitize_callback' => 'sanitize_text_field' ] );
    register_setting( 'dc-sw-prefetch-settings', 'dc_swp_proxy_allowlist',       [ 'sanitize_callback' => 'sanitize_textarea_field' ] );
}

// Admin page HTML
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_swp_admin_page_html() {
    if ( ! current_user_can( 'manage_options' ) ) { return; }

    if ( isset( $_POST['dc_swp_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dc_swp_nonce'] ) ), 'dc_swp_save_settings' ) ) {
        update_option( 'dampcig_pwa_sw_enabled',       isset( $_POST['dampcig_pwa_sw_enabled'] )       ? 'yes' : 'no' );
        update_option( 'dampcig_pwa_preload_products', isset( $_POST['dampcig_pwa_preload_products'] )  ? 'yes' : 'no' );
        update_option( 'dampcig_pwa_product_base',     sanitize_text_field( wp_unslash( $_POST['dampcig_pwa_product_base'] ?? '' ) ) );
        update_option( 'dampcig_pwa_footer_credit',    isset( $_POST['dampcig_pwa_footer_credit'] )    ? 'yes' : 'no' );
        update_option( 'dc_swp_disable_emoji',         isset( $_POST['dc_swp_disable_emoji'] )         ? 'yes' : 'no' );
        update_option( 'dc_swp_lcp_preload',           isset( $_POST['dc_swp_lcp_preload'] )           ? 'yes' : 'no' );
        update_option( 'dc_swp_proxy_allowlist',       sanitize_textarea_field( wp_unslash( $_POST['dc_swp_proxy_allowlist'] ?? '' ) ) );
        echo '<div class="notice notice-success"><p>' . esc_html( dc_swp_str( 'saved' ) ) . '</p></div>';
    }

    $sw_enabled       = get_option( 'dampcig_pwa_sw_enabled',      'yes' ) === 'yes';
    $preload_products = get_option( 'dampcig_pwa_preload_products', 'yes' ) === 'yes';
    $disable_emoji    = get_option( 'dc_swp_disable_emoji',         'yes' ) === 'yes';
    $lcp_preload      = get_option( 'dc_swp_lcp_preload',           'yes' ) === 'yes';
    $product_base_val   = get_option( 'dampcig_pwa_product_base',    '' );
    $footer_credit      = get_option( 'dampcig_pwa_footer_credit',   'no' ) === 'yes';
    $proxy_allowlist    = get_option( 'dc_swp_proxy_allowlist', implode( "\n", [
        'widget.trustpilot.com',
        'invitejs.trustpilot.com',
        'analytics.ahrefs.com',
        'www.googletagmanager.com',
        'www.google-analytics.com',
    ] ) );

    // Auto-detect for placeholder display
    $wc_perma         = get_option( 'woocommerce_permalinks', [] );
    $wc_base          = ! empty( $wc_perma['product_base'] ) ? '/' . explode( '/', trim( $wc_perma['product_base'], '/' ) )[0] . '/' : '/product/';

    // Read vendored Partytown version from package.json
    $pkg_json   = plugin_dir_path( __FILE__ ) . 'package.json';
    $pt_version = 'unknown';
    if ( file_exists( $pkg_json ) ) {
        $pkg = json_decode( file_get_contents( $pkg_json ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $pt_version = $pkg['vendored']['@qwik.dev/partytown'] ?? 'unknown';
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( dc_swp_str( 'page_title' ) ); ?></h1>

        <div class="notice notice-info">
            <p><strong>ℹ️ <?php echo esc_html( dc_swp_str( 'info_title' ) ); ?></strong></p>
            <p><?php echo esc_html( dc_swp_str( 'info_body' ) ); ?></p>
            <p><?php echo esc_html( dc_swp_str( 'pt_version_label' ) ); ?>: <code><?php echo esc_html( $pt_version ); ?></code>
               &nbsp;—&nbsp;
               <a href="https://github.com/QwikDev/partytown/releases" target="_blank" rel="noopener">Changelog ↗</a></p>
        </div>

        <form method="post" action="" class="pwa-cache-settings">
            <?php wp_nonce_field( 'dc_swp_save_settings', 'dc_swp_nonce' ); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><?php echo esc_html( dc_swp_str( 'sw_label' ) ); ?></th>
                    <td>
                        <label class="pwa-toggle">
                            <input type="checkbox" name="dampcig_pwa_sw_enabled" value="yes" <?php checked( $sw_enabled, true ); ?>>
                            <span class="pwa-slider"></span>
                        </label>
                        <p class="description"><?php echo esc_html( dc_swp_str( 'sw_desc' ) ); ?></p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php echo esc_html( dc_swp_str( 'preload_label' ) ); ?></th>
                    <td>
                        <label class="pwa-toggle">
                            <input type="checkbox" name="dampcig_pwa_preload_products" value="yes" <?php checked( $preload_products, true ); ?>>
                            <span class="pwa-slider"></span>
                        </label>
                        <p class="description"><?php echo wp_kses_post( dc_swp_str( 'preload_desc' ) ); ?></p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php echo esc_html( dc_swp_str( 'emoji_label' ) ); ?></th>
                    <td>
                        <label class="pwa-toggle">
                            <input type="checkbox" name="dc_swp_disable_emoji" value="yes" <?php checked( $disable_emoji, true ); ?>>
                            <span class="pwa-slider"></span>
                        </label>
                        <p class="description"><?php echo wp_kses_post( dc_swp_str( 'emoji_desc' ) ); ?></p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php echo esc_html( dc_swp_str( 'lcp_label' ) ); ?></th>
                    <td>
                        <label class="pwa-toggle">
                            <input type="checkbox" name="dc_swp_lcp_preload" value="yes" <?php checked( $lcp_preload, true ); ?>>
                            <span class="pwa-slider"></span>
                        </label>
                        <p class="description"><?php echo wp_kses_post( dc_swp_str( 'lcp_desc' ) ); ?></p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php echo esc_html( dc_swp_str( 'product_base_label' ) ); ?></th>
                    <td>
                        <input type="text" name="dampcig_pwa_product_base"
                               value="<?php echo esc_attr( $product_base_val ); ?>"
                               placeholder="<?php echo esc_attr( $wc_base ); ?>"
                               class="regular-text"
                               style="font-family: monospace;">
                        <p class="description"><?php echo wp_kses_post( dc_swp_str( 'product_base_desc' ) ); ?></p>
                        <p class="description"><?php echo esc_html( dc_swp_str( 'product_base_detected' ) ); ?>: <code><?php echo esc_html( $wc_base ); ?></code></p>
                    </td>
                </tr>
            </table>

                <tr valign="top">
                    <th scope="row"><?php echo esc_html( dc_swp_str( 'proxy_label' ) ); ?></th>
                    <td>
                        <textarea name="dc_swp_proxy_allowlist" rows="6" class="large-text code"
                                  style="font-family: monospace; white-space: nowrap;"><?php echo esc_textarea( $proxy_allowlist ); ?></textarea>
                        <p class="description"><?php echo wp_kses_post( dc_swp_str( 'proxy_desc' ) ); ?></p>
                        <p class="description"><em><?php echo esc_html( dc_swp_str( 'proxy_cache_note' ) ); ?></em></p>
                    </td>
                </tr>
            </table>

            <h2><?php echo esc_html( dc_swp_str( 'strategy_title' ) ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php echo esc_html( dc_swp_str( 'html_label' ) ); ?></th>
                    <td>
                        <p><strong><?php echo esc_html( dc_swp_str( 'html_val' ) ); ?></strong></p>
                        <p class="description"><?php echo esc_html( dc_swp_str( 'html_desc' ) ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html( dc_swp_str( 'static_label' ) ); ?></th>
                    <td>
                        <p><strong><?php echo esc_html( dc_swp_str( 'static_val' ) ); ?></strong></p>
                        <p class="description"><?php echo esc_html( dc_swp_str( 'static_desc' ) ); ?></p>
                    </td>
                </tr>
            </table>

            <h2><?php echo esc_html( dc_swp_str( 'benefits_title' ) ); ?></h2>
            <ul style="list-style: disc; margin-left: 20px;">
                <?php foreach ( [ 'benefit_1','benefit_2','benefit_3','benefit_4','benefit_5','benefit_6','benefit_7' ] as $b ) : ?>
                    <li>✅ <?php echo esc_html( dc_swp_str( $b ) ); ?></li>
                <?php endforeach; ?>
            </ul>

            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><?php echo esc_html( dc_swp_str( 'credit_label' ) ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="dampcig_pwa_footer_credit" value="yes" <?php checked( $footer_credit, true ); ?>>
                            <?php echo esc_html( dc_swp_str( 'credit_checkbox' ) ); ?>
                        </label>
                        <p class="description"><?php echo wp_kses_post( dc_swp_str( 'credit_desc' ) ); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button( dc_swp_str( 'save_button' ) ); ?>
        </form>
    </div>
    
    <style>
    .pwa-cache-settings .form-table th {
        width: 250px;
        font-weight: 600;
    }
    .pwa-toggle {
        position: relative;
        display: inline-block;
        width: 60px;
        height: 34px;
    }
    .pwa-toggle input {
        opacity: 0;
        width: 0;
        height: 0;
    }
    .pwa-slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #ccc;
        transition: .4s;
        border-radius: 34px;
    }
    .pwa-slider:before {
        position: absolute;
        content: "";
        height: 26px;
        width: 26px;
        left: 4px;
        bottom: 4px;
        background-color: white;
        transition: .4s;
        border-radius: 50%;
    }
    input:checked + .pwa-slider {
        background-color: #2271b1;
    }
    input:checked + .pwa-slider:before {
        transform: translateX(26px);
    }
    </style>
    <?php 
}
