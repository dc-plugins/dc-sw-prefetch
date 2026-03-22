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
            'info_body'         => 'I modsætning til async/defer — som kun forsinker indlæsning, men stadig kører scripts på main-tråden — afvikler Partytown tredjeparts-scripts i en Web Worker. Browser main-tråden berøres aldrig: ingen layout-jank, ingen TBT-straf, ingen konkurrence med brugerinteraktioner. Officielt testede og kompatible tjenester: Google Tag Manager, Facebook Pixel, HubSpot, Intercom, Klaviyo, TikTok Pixel og Mixpanel. Scripts offloades kun efter marketingsamtykke — CMP-cookies fra Complianz, Cookiebot, CookieYes og andre læses automatisk.',
            'sw_label'          => 'Aktiver Partytown',
            'sw_desc'           => 'Aktiver Partytown service worker til offloading af tredjeparts-scripts og viewport-prefetch.',
            'preload_label'     => 'Viewport Preloading',
            'preload_desc'      => '<strong>Anbefalet!</strong> Preloader automatisk produkter synlige i viewporten via browser prefetch. Benytter W3TC cache for øjeblikkelig indlæsning når brugeren klikker.',
            'strategy_title'    => 'Arkitektur',
            'html_label'        => 'Tredjeparts-scripts',
            'html_val'          => 'Afvikles i en Web Worker via Partytown (ikke på main-tråden)',
            'html_desc'         => 'I modsætning til async/defer (der kun forsinker indlæsning) afvikler Partytown scripts i en Web Worker. Browser main-tråden blokeres aldrig — brugerinteraktion og rendering påvirkes ikke, selv mens analytics fyres.',
            'static_label'      => 'HTML-sider',
            'static_val'        => 'Håndteres af W3 Total Cache',
            'static_desc'       => 'Produktsider og kategorier caches af W3TC — Partytown interfererer ikke med HTML-cachen.',
            'benefits_title'    => 'Fordele',
            'benefit_1'         => 'Analysescripts afvikles i en Web Worker — i modsætning til async kører de aldrig på main-tråden',
            'benefit_2'         => 'Viewport-prefetch preloader produktlinks automatisk',
            'benefit_3'         => 'Pagineringslink prefetches 2 s i forvejen',
            'benefit_4'         => 'Bots og crawlers modtager aldrig Partytown (rent HTML)',
            'benefit_5'         => 'Automatiske opdateringer via GitHub Actions workflow',
            'benefit_6'         => 'WP emoji-scripts fjernet — sparer et DNS-opslag og ~76 KB',
            'benefit_7'               => 'Tredjeparts-scripts auto-detekteres og offloades til Partytown med ét klik',
            'benefit_8'               => 'Samtykke-bevidst: scripts blokeres (text/plain) indtil marketingcookien er sat — understøtter Complianz, Cookiebot, CookieYes, Borlabs, Cookie Notice, WebToffee, Cookie Information og Moove GDPR',
            'partytown_scripts_label' => 'Partytown Script Liste',
            'partytown_scripts_desc'  => 'Angiv én URL eller søgestreng per linje. Matcher mod script src. Kun officielt testede tjenester anbefales: <strong>Google Tag Manager</strong> (<code>googletagmanager.com</code>), <strong>Facebook Pixel</strong> (<code>connect.facebook.net</code>), <strong>HubSpot</strong>, <strong>Intercom</strong>, <strong>Klaviyo</strong>, <strong>TikTok Pixel</strong>, <strong>Mixpanel</strong>. <a href="https://partytown.qwik.dev/common-services/" target="_blank" rel="noopener">Fuld liste ↗</a>',
            'partytown_autodetect_btn'  => '🔍 Auto-Detekter Tredjeparts-Scripts',
            'partytown_autodetect_none' => 'Ingen eksterne scripts fundet på forsiden.',
            'partytown_autodetect_add'  => 'Tilføj Valgte til Liste',
            'inline_scripts_label'      => 'Indlejrede Script Blokke',
            'inline_scripts_desc'       => 'Indsæt komplette tredjeparts-script-blokke her — inkl. &lt;script&gt;-tags og &lt;noscript&gt;-fallbacks (Meta Pixel, TikTok Pixel osv.). Plugin\'et konverterer dem automatisk til <code>type="text/partytown"</code> så de køres i en Web Worker og respekterer marketingsamtykke. <a href="https://partytown.qwik.dev/common-services/" target="_blank" rel="noopener">Kompatible tjenester ↗</a>',
            'partytown_exclude_label'   => 'Udelad Scripts (Ekskluderingsliste)',
            'partytown_exclude_desc'    => 'Én URL-mønster per linje. Scripts der matcher udelades fra Partytown-omskrivning — selv om de er på inkluderingslisten. Listen er forhåndsudfyldt med kendte inkompatible scripts. Rediger frit — fjern mønstre du ikke har brug for.',
            'emoji_label'             => 'Fjern WP Emoji',
            'emoji_desc'        => 'Fjerner WordPress emoji-detection script og tilhørende CSS (s.w.org fetch). Anbefalet — moderne browsere har native emoji.',
            'credit_label'      => 'Footer Kredit',
            'credit_checkbox'   => 'Vis kærlighed og støt udviklingen ved at tilføje et lille link i footeren',
            'credit_desc'       => 'Indsætter et diskret <a href="https://www.dampcig.dk" target="_blank">Dampcig.dk</a>-link i sidens footer ved at linke copyright-symbolet ©.',
            'save_button'       => 'Gem Indstillinger',
            'pt_version_label'  => 'Partytown Version',
            'product_base_label'      => 'Produkt-URL slug',
            'product_base_desc'       => 'URL-segmentet der identificerer produktsider, f.eks. <code>/product/</code> eller <code>/produkt/</code>. Lad feltet være tomt for at bruge den auto-detekterede WooCommerce-indstilling.',
            'product_base_detected'   => 'Auto-detekteret fra WooCommerce',
        ] : [
            'page_title'        => 'SW Prefetch Settings',
            'saved'             => 'Settings saved!',
            'info_title'        => 'Partytown Integration',
            'info_body'         => 'Unlike async/defer — which only delay loading but still execute scripts on the main thread — Partytown runs third-party scripts entirely in a Web Worker. The browser main thread is never touched: no layout jank, no TBT penalty, no competition with user interactions. Officially tested compatible services: Google Tag Manager, Facebook Pixel, HubSpot, Intercom, Klaviyo, TikTok Pixel, and Mixpanel. Scripts are offloaded only after marketing consent — CMP cookies from Complianz, Cookiebot, CookieYes, and others are read automatically.',
            'sw_label'          => 'Enable Partytown',
            'sw_desc'           => 'Activate Partytown service worker for third-party script offloading and viewport prefetch.',
            'preload_label'     => 'Viewport Preloading',
            'preload_desc'      => '<strong>Recommended!</strong> Automatically prefetches products visible in the viewport via browser prefetch, leveraging W3TC cache for instant loading when the user clicks.',
            'strategy_title'    => 'Architecture',
            'html_label'        => 'Third-party Scripts',
            'html_val'          => 'Executed in a Web Worker via Partytown (never on the main thread)',
            'html_desc'         => 'Unlike async/defer (which only delay loading), Partytown executes scripts in a Web Worker. The browser main thread is never blocked — user interactions and rendering are unaffected even while analytics fire.',
            'static_label'      => 'HTML Pages',
            'static_val'        => 'Handled by W3 Total Cache',
            'static_desc'       => 'Product pages and categories are cached by W3TC — Partytown does not interfere with HTML caching.',
            'benefits_title'    => 'Benefits',
            'benefit_1'         => 'Analytics scripts run in a Web Worker — unlike async, they never execute on the browser main thread',
            'benefit_2'         => 'Viewport prefetch pre-loads product links automatically',
            'benefit_3'         => 'Pagination next-page link prefetched 2 s ahead',
            'benefit_4'         => 'Bots and crawlers never receive Partytown (clean HTML)',
            'benefit_5'         => 'Automatic updates via GitHub Actions workflow',
            'benefit_6'         => 'WP emoji scripts removed — saves a DNS lookup and ~76 KB',
            'benefit_7'               => 'Third-party scripts auto-detected and offloaded to Partytown in one click',
            'benefit_8'               => 'Consent-aware: scripts blocked (text/plain) until marketing consent cookie is set — supports Complianz, Cookiebot, CookieYes, Borlabs, Cookie Notice, WebToffee, Cookie Information & Moove GDPR',
            'partytown_scripts_label' => 'Partytown Script List',
            'partytown_scripts_desc'  => 'Enter one URL or pattern per line. Matched against the script <code>src</code> attribute. Only officially tested services are recommended: <strong>Google Tag Manager</strong> (<code>googletagmanager.com</code>), <strong>Facebook Pixel</strong> (<code>connect.facebook.net</code>), <strong>HubSpot</strong>, <strong>Intercom</strong>, <strong>Klaviyo</strong>, <strong>TikTok Pixel</strong>, <strong>Mixpanel</strong>. <a href="https://partytown.qwik.dev/common-services/" target="_blank" rel="noopener">Full list ↗</a>',
            'partytown_autodetect_btn'  => '🔍 Auto-Detect Third-Party Scripts',
            'partytown_autodetect_none' => 'No external scripts found on the homepage.',
            'partytown_autodetect_add'  => 'Add Selected to List',
            'inline_scripts_label'      => 'Inline Script Blocks',
            'inline_scripts_desc'       => 'Paste complete third-party script blocks here — including &lt;script&gt; tags and &lt;noscript&gt; fallbacks (Meta Pixel, TikTok Pixel, etc.). The plugin automatically converts them to <code>type="text/partytown"</code> so they run in a Web Worker and respect marketing consent. <a href="https://partytown.qwik.dev/common-services/" target="_blank" rel="noopener">Compatible services ↗</a>',
            'partytown_exclude_label'   => 'Exclude Scripts (Blocklist)',
            'partytown_exclude_desc'    => 'One URL pattern per line. Scripts matching these patterns are never rewritten to Partytown — even if they appear on the include list. Pre-filled with known incompatible scripts. Edit freely — remove patterns you do not need.',
            'emoji_label'             => 'Remove WP Emoji',
            'emoji_desc'        => 'Removes the WordPress emoji detection script and its CSS (s.w.org fetch). Recommended — modern browsers have native emoji support.',
            'credit_label'      => 'Footer Credit',
            'credit_checkbox'   => 'Show some love and support development by adding a small link in the footer',
            'credit_desc'       => 'Inserts a discreet <a href="https://www.dampcig.dk" target="_blank">Dampcig.dk</a> link in the footer by linking the copyright symbol ©.',
            'save_button'       => 'Save Settings',
            'pt_version_label'  => 'Partytown Version',
            'product_base_label'      => 'Product URL slug',
            'product_base_desc'       => 'The URL segment that identifies product pages, e.g. <code>/product/</code> or <code>/shop/</code>. Leave blank to use the auto-detected WooCommerce setting.',
            'product_base_detected'   => 'Auto-detected from WooCommerce',
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
    register_setting( 'dc-sw-prefetch-settings', 'dc_swp_partytown_scripts',     [ 'sanitize_callback' => 'sanitize_textarea_field' ] );
    register_setting( 'dc-sw-prefetch-settings', 'dc_swp_partytown_exclude',     [ 'sanitize_callback' => 'sanitize_textarea_field' ] );
    // Inline script blocks — admin-only JS content; no HTML sanitization applied (trusted manage_options user).
    register_setting( 'dc-sw-prefetch-settings', 'dc_swp_inline_scripts' );
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
        update_option( 'dc_swp_partytown_scripts',     sanitize_textarea_field( wp_unslash( $_POST['dc_swp_partytown_scripts'] ?? '' ) ) );
        update_option( 'dc_swp_partytown_exclude',     sanitize_textarea_field( wp_unslash( $_POST['dc_swp_partytown_exclude'] ?? '' ) ) );
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- admin-only inline JS; trusted via nonce + manage_options.
        update_option( 'dc_swp_inline_scripts', wp_unslash( $_POST['dc_swp_inline_scripts'] ?? '' ) );
        echo '<div class="notice notice-success"><p>' . esc_html( dc_swp_str( 'saved' ) ) . '</p></div>';
    }

    $sw_enabled       = get_option( 'dampcig_pwa_sw_enabled',      'yes' ) === 'yes';
    $preload_products = get_option( 'dampcig_pwa_preload_products', 'yes' ) === 'yes';
    $disable_emoji      = get_option( 'dc_swp_disable_emoji',         'yes' ) === 'yes';
    $partytown_scripts  = get_option( 'dc_swp_partytown_scripts',    '' );
    $partytown_exclude  = get_option( 'dc_swp_partytown_exclude',    '' );
    $inline_scripts     = get_option( 'dc_swp_inline_scripts',       '' );
    $product_base_val   = get_option( 'dampcig_pwa_product_base',    '' );
    $footer_credit    = get_option( 'dampcig_pwa_footer_credit',   'no' ) === 'yes';

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
                    <th scope="row"><?php echo esc_html( dc_swp_str( 'partytown_scripts_label' ) ); ?></th>
                    <td>
                        <textarea name="dc_swp_partytown_scripts" rows="5" class="large-text code"
                            placeholder="e.g. analytics.ahrefs.com&#10;https://www.googletagmanager.com/gtag/js"
                        ><?php echo esc_textarea( $partytown_scripts ); ?></textarea>
                        <p class="description"><?php echo wp_kses_post( dc_swp_str( 'partytown_scripts_desc' ) ); ?></p>
                        <p style="margin-top:8px;">
                            <button type="button" id="dc-swp-autodetect-btn" class="button button-secondary">
                                <?php echo esc_html( dc_swp_str( 'partytown_autodetect_btn' ) ); ?>
                            </button>
                            <span id="dc-swp-autodetect-spinner" class="spinner" style="float:none;margin-left:4px;display:none;"></span>
                        </p>
                        <div id="dc-swp-autodetect-results" style="display:none;margin-top:8px;padding:10px;background:#f9f9f9;border:1px solid #ddd;border-radius:3px;">
                            <p style="margin:0 0 8px;"><strong><?php esc_html_e( 'Detected external scripts', 'dc-sw-prefetch' ); ?>:</strong></p>
                            <div id="dc-swp-autodetect-list" style="margin-bottom:8px;"></div>
                            <button type="button" id="dc-swp-add-selected" class="button button-primary" style="display:none;">
                                <?php echo esc_html( dc_swp_str( 'partytown_autodetect_add' ) ); ?>
                            </button>
                        </div>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php echo esc_html( dc_swp_str( 'inline_scripts_label' ) ); ?></th>
                    <td>
                        <textarea name="dc_swp_inline_scripts" rows="10" class="large-text code"
                            placeholder="<!-- Meta Pixel Code -->&#10;<script>&#10;  !function(f,b,e,v,n,t,s){...}(window, document,'script',&#10;  'https://connect.facebook.net/en_US/fbevents.js');&#10;  fbq('init', '000000000000000');&#10;  fbq('track', 'PageView');&#10;</script>&#10;<noscript><img height=&quot;1&quot; width=&quot;1&quot; style=&quot;display:none&quot; src=&quot;https://www.facebook.com/tr?id=000000000000000&amp;ev=PageView&amp;noscript=1&quot;/></noscript>&#10;<!-- End Meta Pixel Code -->"
                        ><?php echo esc_textarea( $inline_scripts ); ?></textarea>
                        <p class="description"><?php echo wp_kses_post( dc_swp_str( 'inline_scripts_desc' ) ); ?></p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php echo esc_html( dc_swp_str( 'partytown_exclude_label' ) ); ?></th>
                    <td>
                        <textarea name="dc_swp_partytown_exclude" rows="4" class="large-text code"
                            placeholder="e.g. widget.trustpilot.com&#10;js.stripe.com"
                        ><?php echo esc_textarea( $partytown_exclude ); ?></textarea>
                        <p class="description"><?php echo esc_html( dc_swp_str( 'partytown_exclude_desc' ) ); ?></p>
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
                <?php foreach ( [ 'benefit_1','benefit_2','benefit_3','benefit_4','benefit_5','benefit_6','benefit_7','benefit_8' ] as $b ) : ?>
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
    <script type="text/javascript">
    jQuery(function($){
        var nonce        = <?php echo wp_json_encode( wp_create_nonce( 'dc_swp_detect_nonce' ) ); ?>;
        var noScriptsMsg = <?php echo wp_json_encode( dc_swp_str( 'partytown_autodetect_none' ) ); ?>;
        $('#dc-swp-autodetect-btn').on('click', function(){
            var $btn  = $(this),
                $spin = $('#dc-swp-autodetect-spinner'),
                $res  = $('#dc-swp-autodetect-results'),
                $list = $('#dc-swp-autodetect-list');
            $btn.prop('disabled', true);
            $spin.css('display','inline-block');
            $res.hide();
            $.post(ajaxurl, {action:'dc_swp_detect_scripts', nonce:nonce}, function(r){
                $btn.prop('disabled', false);
                $spin.hide();
                var compatible   = (r.success && r.data && r.data.compatible)   ? r.data.compatible   : [];
                var incompatible = (r.success && r.data && r.data.incompatible) ? r.data.incompatible : [];
                // Auto-merge only the incompatible scripts that were actually found on the site.
                if ( incompatible.length ) {
                    var $excl      = $('textarea[name="dc_swp_partytown_exclude"]');
                    var existingEx = $excl.val().split('\n').map(function(s){ return s.trim(); }).filter(Boolean);
                    var toExclude  = incompatible.filter(function(p){ return existingEx.indexOf(p) === -1; });
                    if ( toExclude.length ) {
                        $excl.val( existingEx.concat(toExclude).join('\n') );
                    }
                }
                // Show compatible scripts as checkboxes for the include list.
                if ( !compatible.length ) {
                    $list.html('<em>' + $('<span>').text(noScriptsMsg).html() + '</em>');
                    $('#dc-swp-add-selected').hide();
                } else {
                    var html = '';
                    $.each(compatible, function(i, url){
                        var safe = $('<span>').text(url).html();
                        html += '<label style="display:block;margin:2px 0"><input type="checkbox" value="'+safe+'" checked> <code>'+safe+'</code></label>';
                    });
                    $list.html(html);
                    $('#dc-swp-add-selected').show();
                }
                $res.show();
            }).fail(function(){ $btn.prop('disabled',false); $spin.hide(); });
        });
        $('#dc-swp-add-selected').on('click', function(){
            var $ta       = $('textarea[name="dc_swp_partytown_scripts"]');
            var $list     = $('#dc-swp-autodetect-list');
            var existing  = $ta.val().split('\n').map(function(s){ return s.trim(); }).filter(Boolean);
            var toAdd     = [];
            $list.find('input[type="checkbox"]:checked').each(function(){
                var url = $(this).val();
                if ( existing.indexOf(url) === -1 ) { toAdd.push(url); }
            });
            if ( toAdd.length ) {
                $ta.val( existing.concat(toAdd).join('\n') );
            }
            $('#dc-swp-autodetect-results').fadeOut();
        });
    });
    </script>
    <?php 
}

// ============================================================
// AJAX — Auto-detect third-party scripts on the homepage
// ============================================================

add_action( 'wp_ajax_dc_swp_detect_scripts', 'dc_swp_ajax_detect_scripts' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_swp_ajax_detect_scripts() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'dc_swp_detect_nonce' ) ) {
        wp_send_json_error( 'Invalid nonce' );
    }

    $response = wp_remote_get( home_url( '/' ), array(
        'timeout'    => 15,
        'sslverify'  => false,
        'user-agent' => 'Mozilla/5.0 (DCSwPrefetch/1.0; Auto-Detect)',
    ) );
    if ( is_wp_error( $response ) ) {
        wp_send_json_error( $response->get_error_message() );
    }

    $body      = wp_remote_retrieve_body( $response );
    $site_host = wp_parse_url( home_url(), PHP_URL_HOST );

    preg_match_all( '/<script[^>]+\bsrc=["\'](https?:[^"\']+|[\/][^"\']+)["\']/i', $body, $matches );

    $compatible   = array();
    $incompatible = array();
    $incompatible_patterns = array_filter( array_map( 'trim', explode( "\n", dc_swp_default_exclude_list() ) ), static fn( $l ) => $l !== '' );
    foreach ( (array) $matches[1] as $src ) {
        if ( str_starts_with( $src, '//' ) ) {
            $src = 'https:' . $src;
        }
        if ( str_starts_with( $src, '/' ) ) {
            continue; // on-site relative URL
        }
        $parsed = wp_parse_url( $src );
        if ( empty( $parsed['host'] ) || $parsed['host'] === $site_host ) {
            continue;
        }
            // Store only the hostname as the pattern (e.g. "googletagmanager.com")
        $host = $parsed['host'];

        $is_incompatible = false;
        foreach ( $incompatible_patterns as $excl ) {
            if ( str_contains( $host, $excl ) || str_contains( $excl, $host ) ) {
                $is_incompatible = true;
                break;
            }
        }

        if ( $is_incompatible ) {
            $incompatible[] = $host;
        } else {
            $compatible[] = $host;
        }
    }

    wp_send_json_success( [
        'compatible'   => array_values( array_unique( $compatible ) ),
        'incompatible' => array_values( array_unique( $incompatible ) ),
    ] );
}
