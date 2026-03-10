<?php
/**
 * DC Service Worker Prefetcher — Admin Interface
 * Asset-only caching (HTML handled by W3TC), viewport prefetching controls
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
            'info_title'        => 'W3TC Hybrid Integration',
            'info_body'         => 'Service workeren cacher kun statiske filer (CSS, JS, billeder). HTML-sider håndteres af W3 Total Cache for bedre performance og cache-kontrol.',
            'sw_label'          => 'Aktiver Service Worker',
            'sw_desc'           => 'Aktiver service workeren til caching af statiske filer (CSS, JS, fonter, billeder).',
            'offline_label'     => 'Offline Fallback Side',
            'offline_desc'      => 'URL til offline-siden der vises når netværket er utilgængeligt.',
            'preload_label'     => 'Viewport Preloading',
            'preload_desc'      => '<strong>Anbefalet!</strong> Preloader automatisk produkter synlige i viewporten via browser prefetch. Benytter W3TC cache for øjeblikkelig indlæsning når brugeren klikker.',
            'strategy_title'    => 'Cache Strategi',
            'html_label'        => 'HTML Sider',
            'html_val'          => 'Håndteres af W3 Total Cache',
            'html_desc'         => 'Alle produktsider, kategorier og andre HTML-sider caches af W3TC for optimal performance.',
            'static_label'      => 'Statiske Filer',
            'static_val'        => 'Cache-First (Service Worker)',
            'static_desc'       => 'CSS, JavaScript, billeder og fonter caches lokalt i browseren for hurtigere indlæsning.',
            'benefits_title'    => 'Fordele ved W3TC Integration',
            'benefit_1'         => 'Ingen duplikeret cache (HTML kun i W3TC)',
            'benefit_2'         => 'W3TC\'s cache invalidation respekteres',
            'benefit_3'         => 'Hurtigere sider (færre cache-lag)',
            'benefit_4'         => 'Bedre cache hit ratio',
            'benefit_5'         => 'Mindre lagerplads brugt i browser',
            'credit_label'      => 'Footer Kredit',
            'credit_checkbox'   => 'Vis kærlighed og støt udviklingen ved at tilføje et lille link i footeren',
            'credit_desc'       => 'Indsætter et diskret <a href="https://www.dampcig.dk" target="_blank">Dampcig.dk</a>-link i sidens footer ved at linke copyright-symbolet ©.',
            'save_button'       => 'Gem Indstillinger',
        ] : [
            'page_title'        => 'SW Prefetch Settings',
            'saved'             => 'Settings saved!',
            'info_title'        => 'W3TC Hybrid Integration',
            'info_body'         => 'The service worker caches only static assets (CSS, JS, images). HTML pages are handled by W3 Total Cache for better performance and cache control.',
            'sw_label'          => 'Enable Service Worker',
            'sw_desc'           => 'Activate the service worker for caching static assets (CSS, JS, fonts, images).',
            'offline_label'     => 'Offline Fallback Page',
            'offline_desc'      => 'URL of the page shown when the network is unavailable.',
            'preload_label'     => 'Viewport Preloading',
            'preload_desc'      => '<strong>Recommended!</strong> Automatically prefetches products visible in the viewport via browser prefetch, leveraging W3TC cache for instant loading when the user clicks.',
            'strategy_title'    => 'Cache Strategy',
            'html_label'        => 'HTML Pages',
            'html_val'          => 'Handled by W3 Total Cache',
            'html_desc'         => 'All product pages, categories and other HTML pages are cached by W3TC for optimal performance.',
            'static_label'      => 'Static Assets',
            'static_val'        => 'Cache-First (Service Worker)',
            'static_desc'       => 'CSS, JavaScript, images and fonts are cached locally in the browser for faster loading.',
            'benefits_title'    => 'Benefits of W3TC Integration',
            'benefit_1'         => 'No duplicated cache (HTML only in W3TC)',
            'benefit_2'         => 'W3TC cache invalidation is respected',
            'benefit_3'         => 'Faster pages (fewer cache layers)',
            'benefit_4'         => 'Better cache hit ratio',
            'benefit_5'         => 'Less storage used in the browser',
            'credit_label'      => 'Footer Credit',
            'credit_checkbox'   => 'Show some love and support development by adding a small link in the footer',
            'credit_desc'       => 'Inserts a discreet <a href="https://www.dampcig.dk" target="_blank">Dampcig.dk</a> link in the footer by linking the copyright symbol ©.',
            'save_button'       => 'Save Settings',
        ];
    }
    return $s[ $key ] ?? $key;
}
// ─────────────────────────────────────────────────────────────────────────────

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
    register_setting( 'dc-sw-prefetch-settings', 'dampcig_pwa_sw_enabled',      [ 'sanitize_callback' => 'sanitize_text_field' ] );
    register_setting( 'dc-sw-prefetch-settings', 'dampcig_pwa_offline_page',    [ 'sanitize_callback' => 'sanitize_text_field' ] );
    register_setting( 'dc-sw-prefetch-settings', 'dampcig_pwa_preload_products', [ 'sanitize_callback' => 'sanitize_text_field' ] );
    register_setting( 'dc-sw-prefetch-settings', 'dampcig_pwa_footer_credit',   [ 'sanitize_callback' => 'sanitize_text_field' ] );
}

// Admin page HTML
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_swp_admin_page_html() {
    if ( ! current_user_can( 'manage_options' ) ) { return; }

    if ( isset( $_POST['dc_swp_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dc_swp_nonce'] ) ), 'dc_swp_save_settings' ) ) {
        update_option( 'dampcig_pwa_sw_enabled',       isset( $_POST['dampcig_pwa_sw_enabled'] )       ? 'yes' : 'no' );
        update_option( 'dampcig_pwa_offline_page',     sanitize_text_field( wp_unslash( $_POST['dampcig_pwa_offline_page'] ?? '' ) ) );
        update_option( 'dampcig_pwa_preload_products', isset( $_POST['dampcig_pwa_preload_products'] )  ? 'yes' : 'no' );
        update_option( 'dampcig_pwa_footer_credit',    isset( $_POST['dampcig_pwa_footer_credit'] )    ? 'yes' : 'no' );
        echo '<div class="notice notice-success"><p>' . esc_html( dc_swp_str( 'saved' ) ) . '</p></div>';
    }

    $sw_enabled       = get_option( 'dampcig_pwa_sw_enabled',      'yes' ) === 'yes';
    $offline_page     = get_option( 'dampcig_pwa_offline_page',    '/offline/' );
    $preload_products = get_option( 'dampcig_pwa_preload_products', 'yes' ) === 'yes';
    $footer_credit    = get_option( 'dampcig_pwa_footer_credit',   'yes' ) === 'yes';
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( dc_swp_str( 'page_title' ) ); ?></h1>

        <div class="notice notice-info">
            <p><strong>ℹ️ <?php echo esc_html( dc_swp_str( 'info_title' ) ); ?></strong></p>
            <p><?php echo esc_html( dc_swp_str( 'info_body' ) ); ?></p>
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
                    <th scope="row"><?php echo esc_html( dc_swp_str( 'offline_label' ) ); ?></th>
                    <td>
                        <input type="text" name="dampcig_pwa_offline_page" value="<?php echo esc_attr( $offline_page ); ?>" class="regular-text" />
                        <p class="description"><?php echo esc_html( dc_swp_str( 'offline_desc' ) ); ?></p>
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
                <?php foreach ( [ 'benefit_1','benefit_2','benefit_3','benefit_4','benefit_5' ] as $b ) : ?>
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
