<?php
/**
 * DC Script Worker Proxy -- Admin Interface
 * Partytown third-party script offloading + viewport/pagination prefetching
 * DampCig.dk
 *
 * @package DC_Service_Worker_Proxy
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	die();
}


// Admin footer -- only on this plugin's own page.
add_filter(
	'admin_footer_text',
	function ( $text ) {
		$screen = get_current_screen();
		if ( $screen && 'toplevel_page_dc-sw-prefetch' === $screen->id ) {
			return sprintf(
				/* translators: %s: linked name of the plugin author organisation */
				esc_html__( 'More plugins by %s', 'dc-sw-prefetch' ),
				'<a href="' . esc_url( 'https://github.com/dc-plugins' ) . '" target="_blank" rel="noopener">' . esc_html__( 'DC Plugins', 'dc-sw-prefetch' ) . '</a>'
			);
		}
		return $text;
	}
);

add_filter(
	'update_footer',
	function ( $text ) {
		$screen = get_current_screen();
		if ( $screen && 'toplevel_page_dc-sw-prefetch' === $screen->id ) {
			/* translators: %s: plugin version number */
			return sprintf( esc_html__( 'Version %s', 'dc-sw-prefetch' ), DC_SWP_VERSION );
		}
		return $text;
	},
	PHP_INT_MAX
);

// Add admin menu.
add_action( 'admin_menu', 'dc_swp_setup_menu' );
/**
 * Register the plugin admin menu page.
 *
 * @since 1.0.0
 * @return void
 */
function dc_swp_setup_menu() {
	add_menu_page(
		__( 'SW Proxy Settings', 'dc-sw-prefetch' ),
		'SW Proxy',
		'manage_options',
		'dc-sw-prefetch',
		'dc_swp_admin_page_html',
		'dashicons-performance'
	);
}

add_action( 'admin_enqueue_scripts', 'dc_swp_enqueue_admin_assets' );
/**
 * Enqueue admin page styles and register the admin script handle.
 *
 * @param string $hook Current admin page hook suffix.
 * @return void
 */
function dc_swp_enqueue_admin_assets( $hook ) {
	if ( 'toplevel_page_dc-sw-prefetch' !== $hook ) {
		return;
	}
	// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- inline-only handle, no file to version.
	wp_register_style( 'dc-swp-admin', false, array(), null );
	wp_add_inline_style(
		'dc-swp-admin',
		"
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
        content: \"\";
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
    /* -- Inline script blocks accordion --------------------------------- */
    .dc-swp-blk-item { border:1px solid #dcdcde; border-radius:3px; margin-bottom:5px; background:#fff; }
    .dc-swp-blk-item.dc-swp-blk-disabled { opacity:.5; }
    .dc-swp-blk-hdr { display:flex; align-items:center; gap:8px; padding:8px 10px; cursor:pointer; user-select:none; background:#f6f7f7; border-radius:3px; }
    .dc-swp-blk-item.dc-swp-blk-open > .dc-swp-blk-hdr { border-radius:3px 3px 0 0; }
    .dc-swp-blk-hdr:hover { background:#f0f0f1; }
    .dc-swp-blk-chevron { font-size:16px; color:#787c82; flex-shrink:0; transition:transform .15s; }
    .dc-swp-blk-label { flex:1; font-weight:500; color:#1d2327; outline:none; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; cursor:text; }
    .dc-swp-blk-label:focus { outline:1px dashed #2271b1; padding:0 3px; border-radius:2px; white-space:pre; overflow:visible; }
    .dc-swp-blk-body { display:none; padding:10px; border-top:1px solid #dcdcde; background:#fcfcfd; }
    .dc-swp-blk-body textarea { font-family:Consolas,'Courier New',monospace; font-size:12px; line-height:1.5; }
    .dc-swp-blk-toggle { width:36px !important; height:22px !important; margin:0; flex-shrink:0; }
    .dc-swp-blk-toggle .pwa-slider:before { height:14px; width:14px; left:4px; bottom:4px; }
    .dc-swp-blk-toggle input:checked + .pwa-slider:before { transform:translateX(14px); }
    .dc-swp-add-area { border:1px dashed #c3c4c7; padding:14px 14px 10px; border-radius:3px; background:#f6f7f7; margin-top:4px; }
    .dc-swp-add-area h4 { margin:0 0 9px; font-size:13px; font-weight:600; color:#1d2327; }
    .dc-swp-add-area textarea { font-family:Consolas,'Courier New',monospace; font-size:12px; }
    /* -- Consent Architecture info panel ----------------------------------- */
    .dc-swp-consent-info { border:1px solid #dcdcde; border-radius:3px; margin-top:10px; background:#fff; }
    .dc-swp-consent-info summary { padding:7px 11px; font-weight:600; cursor:pointer; color:#2271b1; font-size:12px; user-select:none; list-style:none; }
    .dc-swp-consent-info summary::-webkit-details-marker { display:none; }
    .dc-swp-consent-info summary::marker { display:none; }
    .dc-swp-consent-info summary::before { content:'\\25B6\\00A0'; font-size:9px; vertical-align:1px; }
    .dc-swp-consent-info[open] summary::before { content:'\\25BC\\00A0'; }
    .dc-swp-consent-info-body { padding:10px 13px 12px; border-top:1px solid #dcdcde; background:#fcfcfd; }
    .dc-swp-info-section { font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:.4px; color:#50575e; margin:12px 0 5px 0; }
    .dc-swp-info-section:first-child { margin-top:0; }
    .dc-swp-badges { display:flex; flex-wrap:wrap; gap:4px; margin:0 0 2px; }
    /* CSS badge -- always the visible default; shields.io img overlays it once loaded */
    .dc-swp-badge { display:inline-flex; font-size:11px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; border-radius:3px; overflow:hidden; line-height:18px; height:18px; vertical-align:middle; white-space:nowrap; }
    .dc-swp-badge::before { content:attr(data-label); background:#555; color:#fff; padding:0 6px; display:flex; align-items:center; }
    .dc-swp-badge::after  { content:attr(data-msg);   color:#fff; padding:0 6px; display:flex; align-items:center; }
    .dc-swp-badge-blue::after  { background:#0075ca; }
    .dc-swp-badge-green::after { background:#3cb034; }
    .dc-swp-badge-amber::after { background:#e08a00; }
    .dc-swp-badge-red::after   { background:#e05d44; }
    .dc-swp-badge-meta::after  { background:#1877f2; }
    /* When the shields.io img loads successfully, hide the CSS pseudo-content and show the img */
    .dc-swp-badge img { display:none; height:18px; border:0; vertical-align:top; }
    .dc-swp-badge.dc-swp-loaded { display:inline-block; height:auto; overflow:visible; }
    .dc-swp-badge.dc-swp-loaded::before,
    .dc-swp-badge.dc-swp-loaded::after { display:none; }
    .dc-swp-badge.dc-swp-loaded img { display:block; }
    /* -- GTM mode panels --------------------------------------------------- */
    .dc-swp-gtm-panel { margin-top:10px; padding:12px 14px; border:1px solid #dcdcde; border-radius:3px; background:#f9f9f9; }
    .dc-swp-gtm-valid   { color:#3cb034; font-weight:600; font-size:12px; margin-left:6px; }
    .dc-swp-gtm-invalid { color:#d63638; font-weight:600; font-size:12px; margin-left:6px; }
    /* -- GTM Onboarding Wizard -------------------------------------------- */
    .dc-swp-step-indicator { display:flex; gap:0; align-items:center; margin-bottom:16px; }
    .dc-swp-step-dot { width:28px; height:28px; border-radius:50%; background:#dcdcde; color:#50575e; font-size:11px; font-weight:700; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .dc-swp-step-dot.active { background:#2271b1; color:#fff; }
    .dc-swp-step-dot.done   { background:#3cb034; color:#fff; }
    .dc-swp-step-connector  { flex:1; height:2px; background:#dcdcde; min-width:16px; }
    .dc-swp-wizard-step { display:none; }
    .dc-swp-wizard-step.dc-swp-active { display:block; }
    .dc-swp-wizard-nav { display:flex; gap:8px; align-items:center; margin-top:14px; }
    /* -- Fieldset sections --------------------------------------------------- */
    .dc-swp-fieldset { border:0; padding:0; margin:0 0 20px 0; }
    .dc-swp-fieldset > legend { font-size:1.3em; font-weight:600; padding:10px 0 5px 0; margin:0; }
    /* -- Tab panels ------------------------------------------------------------ */
    .dc-swp-tab-panel { display:none; }
    .dc-swp-tab-panel.dc-swp-tab-active { display:block; }
    /* -- Partytown-dependent rows ---------------------------------------------- */
    .dc-swp-row-disabled { opacity:0.45; }
    .dc-swp-row-disabled .pwa-toggle { pointer-events:none; }
    /* -- Donation section ------------------------------------------------------ */
    .dc-swp-donate-wrap { max-width:560px; }
    .dc-swp-donate-speech { margin:8px 0 16px; color:#50575e; line-height:1.65; }
    .dc-swp-donate-row { display:flex; align-items:flex-start; gap:28px; flex-wrap:wrap; margin:10px 0 0; }
    .dc-swp-donate-qr img { display:block; border:3px solid #dcdcde; border-radius:4px; }
    .dc-swp-donate-qr .description { margin-top:6px; text-align:center; }
    .dc-swp-donate-actions { display:flex; flex-direction:column; gap:10px; justify-content:center; }
    "
	);
	wp_enqueue_style( 'dc-swp-admin' );
	wp_register_script( 'dc-swp-admin-script', plugins_url( 'assets/js/admin.js', __FILE__ ), array( 'jquery' ), DC_SWP_VERSION, array( 'in_footer' => true ) );
	wp_enqueue_script( 'dc-swp-admin-script' );
}
/**
 * Sanitize a raw JavaScript code block entered by an admin.
 *
 * The field intentionally stores raw JavaScript wrapped in <script>/<noscript>
 * tags; HTML-escaping would mangle JS operators. The only sanitization applied
 * is stripping PHP opening tags to prevent server-side execution if the value
 * is ever reflected outside the plugin's own echo context.
 *
 * @param string $code Raw JS code string supplied by an administrator.
 * @return string Sanitized code string.
 */
function dc_swp_sanitize_js_code( $code ) {
	// Strip PHP opening tags -- prevents server-side execution if the stored value
	// is ever used in a PHP-parsed context outside this plugin's own output path.
	return preg_replace( '/<\?(?:php|=)?/i', '', (string) $code );
}

/**
 * Sanitize callback for the dc_swp_inline_scripts option.
 *
 * The value is a JSON-encoded array of inline script block objects managed by
 * the admin UI. Each block field is sanitized individually: id via sanitize_key(),
 * label via sanitize_text_field(), enabled as a boolean, and code via
 * dc_swp_sanitize_js_code() (admin-only JS content; capability-gated by manage_options).
 *
 * @param mixed $value Raw option value (JSON string).
 * @return string Sanitized JSON string, or empty string if invalid.
 */
function dc_swp_sanitize_inline_scripts_option( $value ) {
	if ( '' === $value || null === $value ) {
		return '';
	}
	$decoded = json_decode( $value, true );
	if ( ! is_array( $decoded ) ) {
		return '';
	}
	$sanitized = array();
	foreach ( $decoded as $blk ) {
		if ( ! is_array( $blk ) ) {
			continue;
		}
		$sanitized[] = array(
			'id'              => sanitize_key( $blk['id'] ?? '' ),
			'label'           => sanitize_text_field( $blk['label'] ?? '' ),
			'code'            => dc_swp_sanitize_js_code( $blk['code'] ?? '' ),
			'enabled'         => ! empty( $blk['enabled'] ),
			'force_partytown' => ! empty( $blk['force_partytown'] ),
			'category'        => in_array( $blk['category'] ?? '', array( 'marketing', 'statistics', 'statistics-anonymous', 'functional', 'preferences' ), true ) ? $blk['category'] : 'marketing',
		);
	}
	return wp_json_encode( $sanitized );
}

// Admin page HTML.
/**
 * Output the admin settings page HTML.
 *
 * @since 1.0.0
 * @return void
 */
function dc_swp_admin_page_html() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return; }

	if ( isset( $_POST['dc_swp_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dc_swp_nonce'] ) ), 'dc_swp_save_settings' ) ) {
		update_option( 'dc_swp_sw_enabled', isset( $_POST['dc_swp_sw_enabled'] ) ? 'yes' : 'no' );
		update_option( 'dc_swp_footer_credit', isset( $_POST['dc_swp_footer_credit'] ) ? 'yes' : 'no' );
		// Partytown Script List -- JSON array of {pattern, category} objects managed by JS.
		$_raw_entries   = wp_unslash( $_POST['dc_swp_partytown_entries_json'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON envelope; each field sanitized individually below.
		$_valid_cats_sl = array( 'marketing', 'statistics', 'statistics-anonymous', 'functional', 'preferences' );
		$_clean_entries = array();
		if ( '' !== $_raw_entries ) {
			$_decoded_entries = json_decode( $_raw_entries, true );
			if ( is_array( $_decoded_entries ) ) {
				foreach ( $_decoded_entries as $_entry ) {
					if ( ! is_array( $_entry ) ) {
						continue;
					}
					$_pat = sanitize_text_field( $_entry['pattern'] ?? '' );
					if ( '' === $_pat ) {
						continue;
					}
					$_cat_e           = sanitize_text_field( $_entry['category'] ?? 'marketing' );
					$_clean_entries[] = array(
						'pattern'  => $_pat,
						'category' => in_array( $_cat_e, $_valid_cats_sl, true ) ? $_cat_e : 'marketing',
					);
				}
			}
		}
		update_option( 'dc_swp_partytown_scripts', wp_json_encode( $_clean_entries ) );
		// Bust the per-request static + object cache so the page renders fresh data
		// immediately after save (the wp_script_attributes filter populates the static
		// early during admin_head, before this save handler runs).
		dc_swp_get_script_list_entries( true );
		// Inline script blocks: decode the JS-managed JSON accordion payload.
		$raw_json_blocks  = wp_unslash( $_POST['dc_swp_inline_scripts_json'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON envelope; each field sanitized individually below.
		$sanitized_blocks = array();
		if ( '' !== $raw_json_blocks ) {
			$decoded_blocks = json_decode( $raw_json_blocks, true );
			if ( is_array( $decoded_blocks ) ) {
				foreach ( $decoded_blocks as $blk ) {
					if ( ! is_array( $blk ) ) {
						continue;
					}
					$sanitized_blocks[] = array(
						'id'              => sanitize_key( $blk['id'] ?? '' ),
						'label'           => sanitize_text_field( $blk['label'] ?? '' ),
						'code'            => dc_swp_sanitize_js_code( $blk['code'] ?? '' ),
						'enabled'         => ! empty( $blk['enabled'] ),
						'force_partytown' => ! empty( $blk['force_partytown'] ),
						'category'        => in_array( $blk['category'] ?? '', array( 'marketing', 'statistics', 'statistics-anonymous', 'functional', 'preferences' ), true ) ? $blk['category'] : 'marketing',
					);
				}
			}
		}
		update_option( 'dc_swp_inline_scripts', wp_json_encode( $sanitized_blocks ) );
		update_option( 'dc_swp_coi_headers', isset( $_POST['dc_swp_coi_headers'] ) ? 'yes' : 'no' );
		update_option( 'dc_swp_consent_mode', isset( $_POST['dc_swp_consent_mode'] ) ? 'yes' : 'no' );
		update_option( 'dc_swp_url_passthrough', isset( $_POST['dc_swp_url_passthrough'] ) ? 'yes' : 'no' );
		update_option( 'dc_swp_ads_data_redaction', isset( $_POST['dc_swp_ads_data_redaction'] ) ? 'yes' : 'no' );
		update_option( 'dc_swp_meta_ldu', isset( $_POST['dc_swp_meta_ldu'] ) ? 'yes' : 'no' );
		update_option( 'dc_swp_consent_gate', isset( $_POST['dc_swp_consent_gate'] ) ? 'yes' : 'no' );
		$_valid_cats = array( 'marketing', 'statistics', 'statistics-anonymous', 'functional', 'preferences' );
		$_cat_raw    = sanitize_text_field( wp_unslash( $_POST['dc_swp_script_list_category'] ?? 'marketing' ) );
		update_option( 'dc_swp_script_list_category', in_array( $_cat_raw, $_valid_cats, true ) ? $_cat_raw : 'marketing' );
		update_option( 'dc_swp_debug_mode', isset( $_POST['dc_swp_debug_mode'] ) ? 'yes' : 'no' );
		$_valid_gtm_modes = array( 'off', 'own', 'detect', 'managed' );
		$_gtm_mode_raw    = sanitize_text_field( wp_unslash( $_POST['dc_swp_gtm_mode'] ?? 'off' ) );
		update_option( 'dc_swp_gtm_mode', in_array( $_gtm_mode_raw, $_valid_gtm_modes, true ) ? $_gtm_mode_raw : 'off' );
		$_gtm_id_raw = sanitize_text_field( wp_unslash( $_POST['dc_swp_gtm_id'] ?? '' ) );
		// Accept empty string (disables injection) or a valid tag ID format.
		update_option( 'dc_swp_gtm_id', ( '' === $_gtm_id_raw || preg_match( '/^(GTM-[A-Z0-9]{4,10}|G-[A-Z0-9]{6,}|UA-\d{4,}-\d+)$/i', $_gtm_id_raw ) ) ? strtoupper( $_gtm_id_raw ) : '' );
		// Integrations (v2.6.0+).
		update_option( 'dc_swp_hubspot_portal_id', sanitize_text_field( wp_unslash( $_POST['dc_swp_hubspot_portal_id'] ?? '' ) ) );
		update_option( 'dc_swp_klaviyo_site_id', sanitize_text_field( wp_unslash( $_POST['dc_swp_klaviyo_site_id'] ?? '' ) ) );
		update_option( 'dc_swp_mixpanel_token', sanitize_text_field( wp_unslash( $_POST['dc_swp_mixpanel_token'] ?? '' ) ) );
		update_option( 'dc_swp_fullstory_org_id', sanitize_text_field( wp_unslash( $_POST['dc_swp_fullstory_org_id'] ?? '' ) ) );
		update_option( 'dc_swp_intercom_app_id', sanitize_text_field( wp_unslash( $_POST['dc_swp_intercom_app_id'] ?? '' ) ) );
		update_option( 'dc_swp_tt_pixel_id', preg_replace( '/[^A-Z0-9]/i', '', sanitize_text_field( wp_unslash( $_POST['dc_swp_tt_pixel_id'] ?? '' ) ) ) );
		update_option( 'dc_swp_resource_hints', isset( $_POST['dc_swp_resource_hints'] ) ? 'yes' : 'no' );
		update_option( 'dc_swp_health_monitor', isset( $_POST['dc_swp_health_monitor'] ) ? 'yes' : 'no' );
		update_option( 'dc_swp_perf_monitor', isset( $_POST['dc_swp_perf_monitor'] ) ? 'yes' : 'no' );
		// Exclusion patterns -- sanitize each line individually.
		$_raw_excl   = wp_unslash( $_POST['dc_swp_exclusion_patterns'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized line-by-line below.
		$_excl_lines = array_map( 'sanitize_text_field', explode( "\n", $_raw_excl ) );
		update_option( 'dc_swp_exclusion_patterns', implode( "\n", array_filter( $_excl_lines ) ) );
		delete_transient( 'dc_swp_health_issues' );
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved!', 'dc-sw-prefetch' ) . '</p></div>';
	}

	$sw_enabled          = get_option( 'dc_swp_sw_enabled', 'yes' ) === 'yes';
	$coi_headers         = get_option( 'dc_swp_coi_headers', 'no' ) === 'yes';
	$consent_mode        = get_option( 'dc_swp_consent_mode', 'no' ) === 'yes';
	$url_passthrough     = get_option( 'dc_swp_url_passthrough', 'no' ) === 'yes';
	$ads_data_redaction  = get_option( 'dc_swp_ads_data_redaction', 'no' ) === 'yes';
	$meta_ldu            = get_option( 'dc_swp_meta_ldu', 'no' ) === 'yes';
	$consent_gate        = get_option( 'dc_swp_consent_gate', 'no' ) === 'yes';
	$script_list_entries = dc_swp_get_script_list_entries();
	$debug_mode          = get_option( 'dc_swp_debug_mode', 'no' ) === 'yes';
	$gtm_mode            = get_option( 'dc_swp_gtm_mode', 'off' );
	$resource_hints      = get_option( 'dc_swp_resource_hints', 'yes' ) === 'yes';
	$health_monitor      = get_option( 'dc_swp_health_monitor', 'yes' ) === 'yes';
	$perf_monitor        = get_option( 'dc_swp_perf_monitor', 'yes' ) === 'yes';
	$exclusion_patterns  = get_option( 'dc_swp_exclusion_patterns', '' );
	// Performance metrics.
	$perf_metrics_raw = get_option( 'dc_swp_perf_metrics', '' );
	$perf_metrics     = ( '' !== $perf_metrics_raw ) ? json_decode( $perf_metrics_raw, true ) : null;
	// Integrations read vars (v2.6.0+).
	$hubspot_portal_id = get_option( 'dc_swp_hubspot_portal_id', '' );
	$klaviyo_site_id   = get_option( 'dc_swp_klaviyo_site_id', '' );
	$mixpanel_token    = get_option( 'dc_swp_mixpanel_token', '' );
	$fullstory_org_id  = get_option( 'dc_swp_fullstory_org_id', '' );
	$intercom_app_id   = get_option( 'dc_swp_intercom_app_id', '' );
	$tt_pixel_id       = get_option( 'dc_swp_tt_pixel_id', '' );
	// Inline script blocks -- decode JSON; auto-migrate legacy plain-text format.
	$inline_scripts_raw   = get_option( 'dc_swp_inline_scripts', '' );
	$inline_script_blocks = array();
	if ( '' !== $inline_scripts_raw ) {
		$decoded_blocks_raw = json_decode( $inline_scripts_raw, true );
		if ( is_array( $decoded_blocks_raw ) ) {
			$inline_script_blocks = $decoded_blocks_raw;
		} elseif ( preg_match( '/<script\b/i', $inline_scripts_raw ) ) {
			// Legacy plain-text format -- auto-migrate to the new JSON structure.
			$inline_script_blocks = array(
				array(
					'id'      => 'block_' . substr( md5( $inline_scripts_raw ), 0, 8 ),
					'label'   => __( 'Imported Scripts', 'dc-sw-prefetch' ),
					'code'    => $inline_scripts_raw,
					'enabled' => true,
				),
			);
			update_option( 'dc_swp_inline_scripts', wp_json_encode( $inline_script_blocks ) );
		}
	}
	$footer_credit = get_option( 'dc_swp_footer_credit', 'no' ) === 'yes';

	// Read vendored Partytown version from package.json using WP_Filesystem.
	$pkg_json   = plugin_dir_path( __FILE__ ) . 'package.json';
	$pt_version = 'unknown';
	if ( file_exists( $pkg_json ) ) {
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		$pkg_raw = $wp_filesystem->get_contents( $pkg_json );
		if ( false !== $pkg_raw ) {
			$pkg        = json_decode( $pkg_raw, true );
			$pt_version = $pkg['vendored']['@qwik.dev/partytown'] ?? 'unknown';
		}
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'SW Proxy Settings', 'dc-sw-prefetch' ); ?></h1>

		<div class="notice notice-info">
			<p><strong>ℹ️ <?php echo esc_html__( 'Partytown Integration', 'dc-sw-prefetch' ); ?></strong></p>
			<p><?php echo esc_html__( 'Unlike async/defer -- which only delay loading but still execute scripts on the main thread -- Partytown runs third-party scripts entirely in a Web Worker. The browser main thread is never touched: no layout jank, no TBT penalty, no competition with user interactions. Officially tested compatible services: Google Tag Manager, Facebook Pixel, HubSpot, Intercom, Klaviyo, TikTok Pixel, and Mixpanel. Enable the Consent Gate to block scripts until visitor consent is granted via the WP Consent API.', 'dc-sw-prefetch' ); ?></p>
			<p><?php echo esc_html__( 'Partytown Version', 'dc-sw-prefetch' ); ?>: <code><?php echo esc_html( $pt_version ); ?></code>
				&nbsp;--&nbsp;
				<a href="https://github.com/QwikDev/partytown/releases" target="_blank" rel="noopener">Changelog ↗</a></p>
		</div>

		<form method="post" action="" class="pwa-cache-settings">
			<?php wp_nonce_field( 'dc_swp_save_settings', 'dc_swp_nonce' ); ?>

		<nav class="nav-tab-wrapper" id="dc-swp-tabs" aria-label="<?php esc_attr_e( 'Settings sections', 'dc-sw-prefetch' ); ?>">
			<a href="#tab-scripts"     class="nav-tab"><?php esc_html_e( 'Scripts', 'dc-sw-prefetch' ); ?></a>
			<a href="#tab-analytics"   class="nav-tab"><?php esc_html_e( 'Tag Management', 'dc-sw-prefetch' ); ?></a>
			<a href="#tab-meta"        class="nav-tab"><?php esc_html_e( 'Meta Pixel', 'dc-sw-prefetch' ); ?></a>
			<a href="#tab-integrations"  class="nav-tab"><?php esc_html_e( 'Integrations', 'dc-sw-prefetch' ); ?></a>
			<a href="#tab-performance"  class="nav-tab"><?php esc_html_e( 'Performance', 'dc-sw-prefetch' ); ?></a>
			<a href="#tab-advanced"    class="nav-tab"><?php esc_html_e( 'Advanced', 'dc-sw-prefetch' ); ?></a>
		</nav>

		<!-- ===== TAB 1: SCRIPTS ===== -->
		<div id="tab-scripts" class="dc-swp-tab-panel">
			<fieldset class="dc-swp-fieldset">
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><?php echo esc_html__( 'Enable Partytown', 'dc-sw-prefetch' ); ?></th>
					<td>
						<label class="pwa-toggle">
							<input type="checkbox" id="dc_swp_sw_enabled" name="dc_swp_sw_enabled" value="yes" <?php checked( $sw_enabled, true ); ?>>
							<span class="pwa-slider"></span>
						</label>
						<p class="description"><?php echo esc_html__( 'Activate Partytown service worker for third-party script offloading and viewport prefetch. When disabled, scripts render directly on the main thread with defer -- useful for diagnosing Partytown issues (no Web Worker, no consent gating).', 'dc-sw-prefetch' ); ?></p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html__( 'Consent Gate (WP Consent API)', 'dc-sw-prefetch' ); ?></th>
					<td>
						<label class="pwa-toggle">
							<input type="checkbox" id="dc_swp_consent_gate" name="dc_swp_consent_gate" value="yes" <?php checked( $consent_gate, true ); ?>>
							<span class="pwa-slider"></span>
						</label>
						<p class="description">
						<?php
						/* translators: %s: URL to WP Consent API plugin installation page */
						echo wp_kses_post( sprintf( __( 'When enabled, scripts are blocked as <code>type="text/plain"</code> until the visitor grants consent via WP Consent API. Requires a CMP plugin that integrates with <a href="%s" target="_blank" rel="noopener">WP Consent API</a>. When disabled (default), all scripts load unconditionally.', 'dc-sw-prefetch' ), esc_url( admin_url( 'plugin-install.php?tab=plugin-information&plugin=wp-consent-api' ) ) ) );
						?>
						</p>
						<?php if ( $consent_gate && ! function_exists( 'wp_has_consent' ) ) : ?>
							<div class="notice notice-warning inline" style="margin-top:8px;padding:8px 12px">
								<p><?php echo esc_html__( '⚠️ Consent Gate is enabled but the WP Consent API plugin is not installed. Scripts will be blocked for all visitors.', 'dc-sw-prefetch' ); ?></p>
							</div>
						<?php endif; ?>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html__( 'Partytown Script List', 'dc-sw-prefetch' ); ?></th>
					<td>
						<input type="hidden" id="dc_swp_partytown_entries_json" name="dc_swp_partytown_entries_json" value="">
						<div id="dc-swp-script-list" style="margin-bottom:8px;"></div>
						<p style="margin-top:4px;">
							<button type="button" id="dc-swp-add-pattern-btn" class="button button-secondary">
								<?php esc_html_e( '+ Add Pattern', 'dc-sw-prefetch' ); ?>
							</button>
							&nbsp;
							<button type="button" id="dc-swp-autodetect-btn" class="button button-secondary">
								<?php echo esc_html__( '🔍 Auto-Detect Third-Party Scripts', 'dc-sw-prefetch' ); ?>
							</button>
							<span id="dc-swp-autodetect-spinner" class="spinner" style="float:none;margin-left:4px;display:none;"></span>
						</p>
						<div id="dc-swp-autodetect-results" style="display:none;margin-top:8px;padding:10px;background:#f9f9f9;border:1px solid #ddd;border-radius:3px;">
							<p style="margin:0 0 8px;"><strong><?php esc_html_e( 'Detected external scripts', 'dc-sw-prefetch' ); ?>:</strong></p>
							<div id="dc-swp-autodetect-list" style="margin-bottom:8px;"></div>
							<button type="button" id="dc-swp-add-selected" class="button button-primary" style="display:none;">
								<?php echo esc_html__( 'Add Selected to List', 'dc-sw-prefetch' ); ?>
							</button>
						</div>
						<p class="description" style="margin-top:8px"><?php echo wp_kses_post( __( 'Enter one URL or pattern per line. Matched against the script <code>src</code> attribute. Only officially tested services are recommended: <strong>HubSpot</strong>, <strong>Intercom</strong>, <strong>Klaviyo</strong>, <strong>TikTok Pixel</strong>, <strong>Mixpanel</strong>, <strong>FullStory</strong> (<code>fullstory.com</code>). <a href="https://partytown.qwik.dev/common-services/" target="_blank" rel="noopener">Full list ↗</a>', 'dc-sw-prefetch' ) ); ?></p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html__( 'Inline Script Blocks', 'dc-sw-prefetch' ); ?></th>
					<td>
						<input type="hidden" id="dc_swp_inline_scripts_json" name="dc_swp_inline_scripts_json" value="">
						<div id="dc-swp-block-list" style="margin-bottom:8px"></div>

						<div class="dc-swp-add-area">
							<h4><?php echo esc_html__( 'Add Script Block', 'dc-sw-prefetch' ); ?></h4>
							<input type="text" id="dc-swp-new-label"
								class="regular-text"
								style="width:100%;margin-bottom:8px;box-sizing:border-box"
								placeholder="<?php echo esc_attr( __( 'Label, e.g. Meta Pixel', 'dc-sw-prefetch' ) ); ?>">
							<textarea id="dc-swp-new-code" rows="8" class="large-text code"
								placeholder="&lt;!-- Paste the complete script block here, including &lt;script&gt; tags --&gt;"></textarea>
							<button type="button" id="dc-swp-add-block-btn" class="button button-secondary" style="margin-top:8px">
								<?php echo esc_html__( '+ Add Block', 'dc-sw-prefetch' ); ?>
							</button>
						</div>

						<p class="description" style="margin-top:8px"><?php echo wp_kses_post( __( 'Paste complete third-party script blocks here -- including &lt;script&gt; tags and &lt;noscript&gt; fallbacks (Meta Pixel, TikTok Pixel, etc.). The plugin automatically converts them to <code>type="text/partytown"</code> so they run in a Web Worker and respect marketing consent. <a href="https://partytown.qwik.dev/common-services/" target="_blank" rel="noopener">Compatible services ↗</a>', 'dc-sw-prefetch' ) ); ?></p>
					</td>
				</tr>
				<tr valign="top"
				<?php
				if ( ! $sw_enabled ) {
					echo ' class="dc-swp-row-disabled"';}
				?>
				>
					<th scope="row"><?php echo esc_html__( 'SharedArrayBuffer (Atomics Bridge)', 'dc-sw-prefetch' ); ?></th>
					<td>
						<label class="pwa-toggle">
							<input type="checkbox" name="dc_swp_coi_headers" value="yes" <?php checked( $coi_headers && $sw_enabled, true ); ?>
							<?php
							if ( ! $sw_enabled ) {
								echo ' disabled';}
							?>
							>
							<span class="pwa-slider"></span>
						</label>
						<p class="description"><?php echo wp_kses_post( __( 'Sends <code>Cross-Origin-Opener-Policy: same-origin</code> and <code>Cross-Origin-Embedder-Policy: credentialless</code> on public pages. Enables <code>crossOriginIsolated</code> in the browser so Partytown switches to the faster Atomics bridge instead of the sync-XHR bridge. Skipped for bots, logged-in users and WooCommerce transactional pages (cart, checkout, account) -- on those pages Partytown falls back to the Service Worker bridge automatically so analytics scripts still fire without affecting payment gateways. All cross-origin iframes are automatically given the <code>credentialless</code> attribute so they can load under COEP -- regardless of the exclusion list. <strong>Test in staging first -- can break OAuth popups or other cross-origin iframes.</strong>', 'dc-sw-prefetch' ) ); ?></p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html__( 'Early Resource Hints', 'dc-sw-prefetch' ); ?></th>
					<td>
						<label class="pwa-toggle">
							<input type="checkbox" name="dc_swp_resource_hints" value="yes" <?php checked( $resource_hints, true ); ?>>
							<span class="pwa-slider"></span>
						</label>
						<p class="description"><?php echo wp_kses_post( __( 'Emits <code>&lt;link rel="preconnect"&gt;</code> and <code>&lt;link rel="dns-prefetch"&gt;</code> for all configured third-party hosts in &lt;head&gt;. Reduces TCP+TLS round-trip latency for first-visit page loads.', 'dc-sw-prefetch' ) ); ?></p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html__( 'Partytown Exclusion Patterns', 'dc-sw-prefetch' ); ?></th>
					<td>
						<textarea name="dc_swp_exclusion_patterns" rows="5" class="large-text code"
							placeholder="/landing-page/&#10;/payment-flow/*"><?php echo esc_textarea( $exclusion_patterns ); ?></textarea>
						<p class="description"><?php echo wp_kses_post( __( 'URL patterns (one per line) where Partytown is completely skipped. Supports <code>*</code> wildcard. Useful for landing pages or payment flows with scripts incompatible with the Partytown worker.', 'dc-sw-prefetch' ) ); ?></p>
					</td>
				</tr>
			</table>
			</fieldset>
		</div><!-- /tab-scripts -->

		<!-- ===== TAB 2: ANALYTICS ===== -->
		<div id="tab-analytics" class="dc-swp-tab-panel">
			<fieldset class="dc-swp-fieldset">
			<legend><?php esc_html_e( 'Tag Management', 'dc-sw-prefetch' ); ?></legend>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><?php echo esc_html__( 'Google Tag Management', 'dc-sw-prefetch' ); ?></th>
					<td>
						<!-- Hidden field -- always submitted; JS syncs it from whichever panel is active -->
						<input type="hidden" name="dc_swp_gtm_id" id="dc_swp_gtm_id_field"
							value="<?php echo esc_attr( get_option( 'dc_swp_gtm_id', '' ) ); ?>">
						<fieldset>
						<?php
						$_gtm_modes = array(
							'off'     => __( 'Disabled -- no tag management', 'dc-sw-prefetch' ),
							'own'     => __( 'Enter Tag ID -- I have my own GTM or GA4 ID', 'dc-sw-prefetch' ),
							'detect'  => __( 'Auto-Detect -- find active tag in page source code', 'dc-sw-prefetch' ),
							'managed' => __( 'Setup Guide -- step-by-step GTM onboarding', 'dc-sw-prefetch' ),
						);
						foreach ( $_gtm_modes as $_gv => $_gl ) :
							?>
						<label style="display:block;margin-bottom:6px">
							<input type="radio" name="dc_swp_gtm_mode" value="<?php echo esc_attr( $_gv ); ?>"
								<?php checked( $gtm_mode, $_gv ); ?>>
							<?php echo esc_html( $_gl ); ?>
						</label>
						<?php endforeach; ?>
						</fieldset>

						<!-- Panel: own -->
						<div id="dc-swp-gtm-panel-own" class="dc-swp-gtm-panel" <?php echo 'own' !== $gtm_mode ? 'style="display:none"' : ''; ?>>
							<input type="text" id="dc-swp-gtm-id-own"
								class="regular-text" style="font-family:monospace"
								value="<?php echo esc_attr( get_option( 'dc_swp_gtm_id', '' ) ); ?>"
								placeholder="<?php echo esc_attr( __( 'GTM-XXXXXXX or G-XXXXXXXXXX', 'dc-sw-prefetch' ) ); ?>">
							<span id="dc-swp-gtm-id-status"></span>
							<p class="description" style="margin-top:6px"><?php echo wp_kses_post( __( 'Enter your GTM Container ID or GA4 Measurement ID. The plugin injects the snippet in <code>&lt;head&gt;</code> at the correct position -- after the GCM v2 consent default but before any other scripts.', 'dc-sw-prefetch' ) ); ?></p>
						</div>

						<!-- Panel: detect -->
						<div id="dc-swp-gtm-panel-detect" class="dc-swp-gtm-panel"
							data-saved-id="<?php echo esc_attr( get_option( 'dc_swp_gtm_id', '' ) ); ?>"
							<?php echo 'detect' !== $gtm_mode ? 'style="display:none"' : ''; ?>>
							<button type="button" id="dc-swp-gtm-detect-btn" class="button button-secondary">
								<?php echo esc_html__( 'Scan Website', 'dc-sw-prefetch' ); ?>
							</button>
							<span id="dc-swp-gtm-detect-spinner" class="spinner" style="float:none;margin-left:4px;display:none;"></span>
							<div id="dc-swp-gtm-detect-result" style="margin-top:8px"></div>
							<p class="description" style="margin-top:6px"><?php echo wp_kses_post( __( 'Fetches the page HTML source and scans for active Google Tags (GTM, GA4, UA). Only detects tags actually present in the rendered source -- not plugin settings. GCM v2 consent mode fires automatically before the detected tag.', 'dc-sw-prefetch' ) ); ?></p>
						</div>

						<!-- Panel: managed (wizard) -->
						<div id="dc-swp-gtm-panel-managed" class="dc-swp-gtm-panel" <?php echo 'managed' !== $gtm_mode ? 'style="display:none"' : ''; ?>>
							<div class="dc-swp-step-indicator">
							<?php for ( $_ws = 1; $_ws <= 4; $_ws++ ) : ?>
								<?php
								if ( $_ws > 1 ) :
									?>
									<span class="dc-swp-step-connector"></span><?php endif; ?>
									<span class="dc-swp-step-dot" data-step="<?php echo (int) $_ws; ?>"><?php echo (int) $_ws; ?></span>
							<?php endfor; ?>
							</div>
							<?php
							$_wiz_steps = array(
								1 => array( __( 'Step 1 -- Create GTM Account & Container', 'dc-sw-prefetch' ), __( 'Visit <a href="https://tagmanager.google.com" target="_blank" rel="noopener">tagmanager.google.com ↗</a>, sign in, click <strong>Create Account</strong>, enter an account name and country, add a Container (use your website URL as the name), select <strong>Web</strong> as the platform, then click <strong>Create</strong>.', 'dc-sw-prefetch' ) ),
								2 => array( __( 'Step 2 -- Enter Your Container ID', 'dc-sw-prefetch' ), __( 'Your <strong>Container ID</strong> appears in the top-right of the GTM interface (format: <code>GTM-XXXXXXX</code>). Copy it and paste it below.', 'dc-sw-prefetch' ) ),
								3 => array( __( 'Step 3 -- Add Tags in GTM', 'dc-sw-prefetch' ), __( 'Inside GTM, add tags such as <strong>Google Analytics 4</strong> (use the &ldquo;Google Tag&rdquo; configuration tag with your GA4 Measurement ID <code>G-XXXXXXXXXX</code>), <strong>LinkedIn Insight Tag</strong>, <strong>TikTok Pixel</strong>, etc. Set each tag to fire on trigger <em>All Pages</em>. GCM v2 consent mode automatically controls data collection per visitor consent.', 'dc-sw-prefetch' ) ),
								4 => array( __( 'Step 4 -- Publish & Confirm', 'dc-sw-prefetch' ), __( 'Click <strong>Submit</strong> → <strong>Publish</strong> in GTM to deploy your container. This plugin injects the GTM snippet in <code>&lt;head&gt;</code> with GCM v2 consent pre-configured. Click <strong>Complete Setup</strong> below to save your Container ID.', 'dc-sw-prefetch' ) ),
							);
							foreach ( $_wiz_steps as $_sn => $_wiz_step ) :
								$_st = $_wiz_step[0];
								$_sb = $_wiz_step[1];
								?>
								<div id="dc-swp-wizard-step-<?php echo (int) $_sn; ?>" class="dc-swp-wizard-step">
								<h4 style="margin-top:0"><?php echo esc_html( $_st ); ?></h4>
								<p><?php echo wp_kses_post( $_sb ); ?></p>
								<?php if ( 2 === $_sn ) : ?>
								<div style="margin:10px 0">
									<input type="text" id="dc-swp-gtm-wizard-id"
										class="regular-text" style="font-family:monospace"
										value="<?php echo esc_attr( get_option( 'dc_swp_gtm_id', '' ) ); ?>"
										placeholder="<?php echo esc_attr( __( 'GTM-XXXXXXX', 'dc-sw-prefetch' ) ); ?>">
									<span id="dc-swp-gtm-wizard-status"></span>
								</div>
								<?php endif; ?>
								<?php if ( 4 === $_sn ) : ?>
								<div id="dc-swp-wizard-summary" style="margin:10px 0;padding:10px;background:#f0f7f0;border:1px solid #3cb034;border-radius:3px;display:none">
									<strong><?php echo esc_html__( 'GTM Active', 'dc-sw-prefetch' ); ?>:</strong> <code id="dc-swp-wizard-summary-id"></code>
								</div>
								<?php endif; ?>
								<div class="dc-swp-wizard-nav">
									<?php if ( $_sn > 1 ) : ?>
									<button type="button" class="button dc-swp-wizard-btn" data-dir="prev" data-step="<?php echo (int) $_sn; ?>">
										<?php echo esc_html__( '← Back', 'dc-sw-prefetch' ); ?>
									</button>
									<?php endif; ?>
									<?php if ( $_sn < 4 ) : ?>
									<button type="button" class="button button-primary dc-swp-wizard-btn"
										data-dir="next" data-step="<?php echo (int) $_sn; ?>"
										<?php echo 2 === $_sn ? 'id="dc-swp-wizard-step2-next" disabled' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully static HTML attribute string. ?>>
										<?php echo esc_html__( 'Next →', 'dc-sw-prefetch' ); ?>
									</button>
									<?php else : ?>
									<button type="button" class="button button-primary" id="dc-swp-wizard-complete">
										<?php echo esc_html__( '✔ Complete Setup', 'dc-sw-prefetch' ); ?>
									</button>
									<?php endif; ?>
								</div>
							</div>
							<?php endforeach; ?>
							<p class="description" style="margin-top:10px"><?php echo wp_kses_post( __( 'Follow the step-by-step guide to create your GTM container and let this plugin inject and manage the snippet.', 'dc-sw-prefetch' ) ); ?></p>
						</div>
					</td>
				</tr>
				<tr valign="top" id="dc-swp-consent-mode-row"<?php echo 'off' === $gtm_mode ? ' style="display:none"' : ''; ?>>
					<th scope="row"><?php echo esc_html__( 'Google Consent Mode v2', 'dc-sw-prefetch' ); ?></th>
					<td>
						<label class="pwa-toggle">
							<input type="checkbox" name="dc_swp_consent_mode" value="yes" <?php checked( $consent_mode, true ); ?>>
							<span class="pwa-slider"></span>
						</label>
						<span id="dc-swp-gcm-prereq" style="display:none;margin-left:8px;color:#d63638;font-weight:600;font-size:12px"><?php esc_html_e( '⚠ Save a GTM Container ID first to enable Consent Mode.', 'dc-sw-prefetch' ); ?></span>
						<p class="description"><?php echo wp_kses_post( __( 'Global consent authority for all GCM v2-compatible services. Injects a full 7-parameter <code>gtag("consent","default",{...})</code> snippet in &lt;head&gt; before any scripts load -- with per-category consent signals (marketing → ads, statistics → analytics, preferences → personalisation). When active, scripts for GCM v2-aware services (Google Tag Manager, Hotjar, LinkedIn Insight, TikTok Pixel, Microsoft Clarity) always run as <code>text/partytown</code>. A revoke listener is automatically injected to fire <code>gtag("consent","update",{...denied})</code> if the visitor withdraws consent. <strong>Requires GTM or a gtag.js-based setup together with a GCM v2-compatible CMP.</strong>', 'dc-sw-prefetch' ) ); ?></p>
					<div id="dc-swp-gcm-notices"></div>
					<?php
					// -- Consent Architecture info panel ---------------------------------
					// CSS badges are always rendered as the fallback (pure CSS ::before/::after).
					// The shields.io <img> fires onload to swap in the real badge when available;
					// offline / firewalled environments automatically keep the CSS version.
					$_si = 'https://img.shields.io/badge/';
					$_sq = '?style=flat-square';
					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- badge HTML built with fully-escaped values; onload handler is static JS, no user data.
					$_badge = static function ( $label, $msg, $col, $url ) {
						$onload = "this.closest('.dc-swp-badge').classList.add('dc-swp-loaded')";
						return '<span class="dc-swp-badge dc-swp-badge-' . esc_attr( $col ) . '" '
							. 'data-label="' . esc_attr( $label ) . '" '
							. 'data-msg="' . esc_attr( $msg ) . '">'
							. '<img src="' . esc_url( $url ) . '" '
							. 'alt="' . esc_attr( $label . ' ' . $msg ) . '" '
							. 'loading="lazy" decoding="async" '
							. 'onload="' . esc_attr( $onload ) . '">'
							. '</span>';
					};
					$_gcm   = array(
						array( 'Google Tag Manager', 'GCM v2', 'blue', $_si . 'Google%20Tag%20Manager-GCM%20v2-0075ca' . $_sq ),
						array( 'Google Analytics', 'GCM v2', 'blue', $_si . 'Google%20Analytics-GCM%20v2-0075ca' . $_sq ),
						array( 'Hotjar', 'GCM v2', 'blue', $_si . 'Hotjar-GCM%20v2-0075ca' . $_sq ),
						array( 'MS Clarity', 'GCM v2', 'blue', $_si . 'MS%20Clarity-GCM%20v2-0075ca' . $_sq ),
						array( 'LinkedIn Insight', 'GCM v2', 'blue', $_si . 'LinkedIn%20Insight-GCM%20v2-0075ca' . $_sq ),
						array( 'TikTok Pixel', 'GCM v2', 'blue', $_si . 'TikTok%20Pixel-GCM%20v2-0075ca' . $_sq ),
					);
					// phpcs:enable
					?>
					<details class="dc-swp-consent-info">
						<summary><?php echo esc_html__( 'Consent Architecture & GCM v2 Services', 'dc-sw-prefetch' ); ?></summary>
						<div class="dc-swp-consent-info-body">

							<p class="dc-swp-info-section"><?php echo esc_html__( 'GCM v2-Aware Services', 'dc-sw-prefetch' ); ?></p>
							<p class="description" style="margin-bottom:6px"><?php echo esc_html__( 'These services natively read the GCM v2 consent state and self-restrict data collection -- no text/plain blocking is needed when GCM v2 is active.', 'dc-sw-prefetch' ); ?></p>
							<div class="dc-swp-badges">
								<?php
								foreach ( $_gcm as $_b ) {
									echo $_badge( $_b[0], $_b[1], $_b[2], $_b[3] ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped 
								?>
							</div>

						</div>
					</details>
					</td>
				</tr>
				<tr valign="top">					<th scope="row"><?php echo esc_html__( 'URL Passthrough', 'dc-sw-prefetch' ); ?></th>
					<td>
						<label class="pwa-toggle">
							<input type="checkbox" name="dc_swp_url_passthrough" value="yes" <?php checked( $url_passthrough, true ); ?>>
							<span class="pwa-slider"></span>
						</label>
						<p class="description"><?php echo wp_kses_post( __( 'Enables <code>gtag("set","url_passthrough",true)</code>. Preserves gclid / wbraid parameters in URLs so conversion attribution works cookieless -- even when <code>ad_storage</code> is denied. Recommended for Google Ads advertisers.', 'dc-sw-prefetch' ) ); ?></p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html__( 'Ads Data Redaction', 'dc-sw-prefetch' ); ?></th>
					<td>
						<label class="pwa-toggle">
							<input type="checkbox" name="dc_swp_ads_data_redaction" value="yes" <?php checked( $ads_data_redaction, true ); ?>>
							<span class="pwa-slider"></span>
						</label>
						<p class="description"><?php echo wp_kses_post( __( 'Enables <code>gtag("set","ads_data_redaction",true)</code>. Redacts click IDs (gclid, wbraid) from data sent to Google when <code>ad_storage</code> is denied -- enhanced privacy for visitors who have not granted marketing consent.', 'dc-sw-prefetch' ) ); ?></p>
					</td>
				</tr>
			</table>
			</fieldset>

		</div><!-- /tab-analytics -->

		<!-- ===== TAB 3: META ADS ===== -->
		<div id="tab-meta" class="dc-swp-tab-panel">
			<!-- -- Meta Pixel (Client-Side) -------------------------------------- -->
			<fieldset class="dc-swp-fieldset">
			<legend><?php esc_html_e( 'Meta Pixel (Client-Side)', 'dc-sw-prefetch' ); ?></legend>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><?php esc_html_e( 'Meta Pixel Limited Data Use (LDU)', 'dc-sw-prefetch' ); ?></th>
					<td>
						<p class="dc-swp-info-section"><?php esc_html_e( 'Meta Pixel -- Separate LDU Mechanism', 'dc-sw-prefetch' ); ?></p>
						<p class="description" style="margin-bottom:6px"><?php esc_html_e( 'Meta Pixel does not implement GCM v2. Enable Meta Pixel LDU below -- Meta applies Limited Data Use restrictions internally. Requires the Meta Pixel (fbevents.js) to be active on the frontend via the Partytown Script List.', 'dc-sw-prefetch' ); ?></p>
						<div class="dc-swp-badges">
							<?php echo $_badge( 'Meta Pixel', 'LDU API', 'meta', $_si . 'Meta%20Pixel-LDU%20API-1877f2' . $_sq ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
						<label class="pwa-toggle" style="margin-top:10px;display:inline-block">
							<input type="checkbox" name="dc_swp_meta_ldu" value="yes" <?php checked( $meta_ldu, true ); ?>>
							<span class="pwa-slider"></span>
						</label>
						<p class="description"><?php echo wp_kses_post( __( 'Meta/Facebook Pixel does not support Google Consent Mode v2 — it uses its own Limited Data Use (LDU) consent API. Injects an <code>fbq</code> stub + <code>fbq("dataProcessingOptions",["LDU"],0,0)</code> in &lt;head&gt; before Partytown and Facebook Pixel scripts load. The Meta Pixel always runs as <code>text/partytown</code> — Meta applies LDU restrictions internally (data not used for ad targeting). Your CMP does not need to block the script via <code>text/plain</code>. <strong>When the WP Consent API is active:</strong> LDU is applied conditionally — consented visitors receive <code>fbq("consent","grant")</code> + <code>fbq("dataProcessingOptions",[],0,0)</code> (unrestricted), while non-consented visitors receive <code>fbq("consent","revoke")</code> + full LDU. Requires Meta Pixel to be added via the Partytown Script List or an Inline Script Block.', 'dc-sw-prefetch' ) ); ?></p>
					</td>
				</tr>
			</table>
			</fieldset>
		</div><!-- /tab-meta -->


		<!-- ===== TAB 5: INTEGRATIONS ===== -->
		<div id="tab-integrations" class="dc-swp-tab-panel">

			<p><?php echo wp_kses_post( __( 'Enter a service ID to auto-inject its tracking snippet as <code>type="text/partytown"</code> — running it inside a web worker and adding its CDN domains to the Partytown proxy automatically. Leave blank to disable. All six services are confirmed in the <a href="https://partytown.qwik.dev/common-services/" target="_blank" rel="noopener">Partytown Common Services guide</a>.', 'dc-sw-prefetch' ) ); ?></p>

			<!-- -- HubSpot ------------------------------------------------------- -->
			<fieldset class="dc-swp-fieldset">
			<legend><?php esc_html_e( 'HubSpot', 'dc-sw-prefetch' ); ?></legend>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><?php esc_html_e( 'Portal ID', 'dc-sw-prefetch' ); ?></th>
					<td>
						<input type="text" name="dc_swp_hubspot_portal_id" value="<?php echo esc_attr( $hubspot_portal_id ); ?>"
							placeholder="12345678" style="width:200px;font-family:monospace">
						<p class="description"><?php echo wp_kses_post( __( 'Found in HubSpot &#8594; Settings &#8594; Account Setup &#8594; Account Defaults. Loads <code>js.hs-scripts.com/{id}.js</code> as a Partytown script. Leave blank to disable.', 'dc-sw-prefetch' ) ); ?></p>
					</td>
				</tr>
			</table>
			</fieldset>

			<!-- -- Klaviyo ------------------------------------------------------- -->
			<fieldset class="dc-swp-fieldset">
			<legend><?php esc_html_e( 'Klaviyo', 'dc-sw-prefetch' ); ?></legend>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><?php esc_html_e( 'Public API Key (Site ID)', 'dc-sw-prefetch' ); ?></th>
					<td>
						<input type="text" name="dc_swp_klaviyo_site_id" value="<?php echo esc_attr( $klaviyo_site_id ); ?>"
							placeholder="AbCdEf" style="width:200px;font-family:monospace">
						<p class="description"><?php echo wp_kses_post( __( 'Found in Klaviyo &#8594; Settings &#8594; API Keys. Loads the Klaviyo onsite JS as a Partytown script. Leave blank to disable.', 'dc-sw-prefetch' ) ); ?></p>
					</td>
				</tr>
			</table>
			</fieldset>

			<!-- -- Mixpanel ------------------------------------------------------ -->
			<fieldset class="dc-swp-fieldset">
			<legend><?php esc_html_e( 'Mixpanel', 'dc-sw-prefetch' ); ?></legend>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><?php esc_html_e( 'Project Token', 'dc-sw-prefetch' ); ?></th>
					<td>
						<input type="text" name="dc_swp_mixpanel_token" value="<?php echo esc_attr( $mixpanel_token ); ?>"
							placeholder="a1b2c3d4e5f6…" style="width:320px;font-family:monospace">
						<p class="description"><?php echo wp_kses_post( __( 'Found in Mixpanel &#8594; Settings &#8594; Project Settings &#8594; Project Token. Injects the Mixpanel stub + <code>mixpanel.init()</code> as a Partytown script. Leave blank to disable.', 'dc-sw-prefetch' ) ); ?></p>
					</td>
				</tr>
			</table>
			</fieldset>

			<!-- -- FullStory ----------------------------------------------------- -->
			<fieldset class="dc-swp-fieldset">
			<legend><?php esc_html_e( 'FullStory', 'dc-sw-prefetch' ); ?></legend>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><?php esc_html_e( 'Org ID', 'dc-sw-prefetch' ); ?></th>
					<td>
						<input type="text" name="dc_swp_fullstory_org_id" value="<?php echo esc_attr( $fullstory_org_id ); ?>"
							placeholder="ABCDE" style="width:200px;font-family:monospace">
						<p class="description"><?php echo wp_kses_post( __( 'Found in FullStory &#8594; Settings &#8594; General &#8594; General Settings. Injects the FullStory snippet via Partytown. <strong>strictProxyHas</strong> is automatically enabled to prevent false namespace conflicts. Leave blank to disable.', 'dc-sw-prefetch' ) ); ?></p>
					</td>
				</tr>
			</table>
			</fieldset>

			<!-- -- Intercom ------------------------------------------------------ -->
			<fieldset class="dc-swp-fieldset">
			<legend><?php esc_html_e( 'Intercom', 'dc-sw-prefetch' ); ?></legend>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><?php esc_html_e( 'App ID', 'dc-sw-prefetch' ); ?></th>
					<td>
						<input type="text" name="dc_swp_intercom_app_id" value="<?php echo esc_attr( $intercom_app_id ); ?>"
							placeholder="abc12345" style="width:200px;font-family:monospace">
						<p class="description"><?php echo wp_kses_post( __( 'Found in Intercom &#8594; Settings &#8594; Installation &#8594; Your App ID. Injects the Intercom loader + boot call as a Partytown script. Leave blank to disable.', 'dc-sw-prefetch' ) ); ?></p>
					</td>
				</tr>
			</table>
			</fieldset>

			<!-- -- TikTok Pixel -------------------------------------------------- -->
			<fieldset class="dc-swp-fieldset">
			<legend><?php esc_html_e( 'TikTok Pixel', 'dc-sw-prefetch' ); ?></legend>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><?php esc_html_e( 'Pixel ID', 'dc-sw-prefetch' ); ?></th>
					<td>
						<input type="text" name="dc_swp_tt_pixel_id" value="<?php echo esc_attr( $tt_pixel_id ); ?>"
							placeholder="ABCDEFGH1234567890" style="width:260px;font-family:monospace">
						<p class="description"><?php echo wp_kses_post( __( 'Found in TikTok Ads Manager &#8594; Assets &#8594; Events &#8594; Web Events &#8594; Pixel. Injects the TikTok base code as <code>type="text/partytown"</code> — offloading the Pixel to a web worker. <code>ttq.track</code>, <code>ttq.page</code>, and <code>ttq.load</code> are forwarded automatically. Leave blank to disable.', 'dc-sw-prefetch' ) ); ?></p>
					</td>
				</tr>
			</table>
			</fieldset>

		</div><!-- /tab-integrations -->

		<!-- ===== TAB 6: PERFORMANCE ===== -->
		<div id="tab-performance" class="dc-swp-tab-panel">
			<fieldset class="dc-swp-fieldset">
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><?php echo esc_html__( 'Partytown Health Monitor', 'dc-sw-prefetch' ); ?></th>
					<td>
						<label class="pwa-toggle">
							<input type="checkbox" name="dc_swp_health_monitor" value="yes" <?php checked( $health_monitor, true ); ?>>
							<span class="pwa-slider"></span>
						</label>
						<p class="description"><?php echo esc_html__( 'Detects services that fail silently inside the Partytown worker (no network traffic observed within 15 seconds) and surfaces an admin notice. Disable if you experience false positives.', 'dc-sw-prefetch' ); ?></p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html__( 'Performance Metrics', 'dc-sw-prefetch' ); ?></th>
					<td>
						<label class="pwa-toggle">
							<input type="checkbox" name="dc_swp_perf_monitor" value="yes" <?php checked( $perf_monitor, true ); ?>>
							<span class="pwa-slider"></span>
						</label>
						<p class="description"><?php echo esc_html__( 'Collects anonymous TBT (Total Blocking Time) and INP (Interaction to Next Paint) measurements from real visitors and shows rolling averages + P75 percentiles in the admin -- giving tangible proof of Partytown\'s main-thread offloading benefit.', 'dc-sw-prefetch' ); ?></p>
					</td>
				</tr>
			</table>
			</fieldset>

			<!-- -- Performance Dashboard ---------------------------------------- -->
			<fieldset class="dc-swp-fieldset">
			<legend><?php echo esc_html__( 'Performance Dashboard', 'dc-sw-prefetch' ); ?></legend>
		<?php if ( ! is_array( $perf_metrics ) || empty( $perf_metrics['samples'] ) ) : ?>
			<p class="description"><?php echo esc_html__( 'No performance data yet. Enable Performance Metrics and wait for visitor activity.', 'dc-sw-prefetch' ); ?></p>
		<?php else : ?>
			<?php
			$_tbt_avg    = (float) ( $perf_metrics['tbt_avg'] ?? 0 );
			$_inp_avg    = (float) ( $perf_metrics['inp_avg'] ?? 0 );
			$_tbt_p75    = (float) ( $perf_metrics['tbt_p75'] ?? 0 );
			$_inp_p75    = (float) ( $perf_metrics['inp_p75'] ?? 0 );
			$_perf_count = (int) ( $perf_metrics['samples'] ?? 0 );
			$_last_upd   = esc_html( $perf_metrics['last_updated'] ?? '' );
			// Progress bar widths: TBT capped at 300 ms, INP capped at 200 ms.
			$_tbt_avg_w = min( 100, (int) round( $_tbt_avg / 3 ) );
			$_inp_avg_w = min( 100, (int) round( $_inp_avg / 2 ) );
			$_tbt_p75_w = min( 100, (int) round( $_tbt_p75 / 3 ) );
			$_inp_p75_w = min( 100, (int) round( $_inp_p75 / 2 ) );
			?>
			<table class="form-table">
				<tr>
					<th scope="row"><?php echo esc_html__( 'Total Blocking Time (TBT)', 'dc-sw-prefetch' ); ?></th>
					<td>
						<p>Avg: <strong><?php echo esc_html( number_format( $_tbt_avg, 1 ) ); ?> ms</strong> &nbsp; P75: <strong><?php echo esc_html( number_format( $_tbt_p75, 1 ) ); ?> ms</strong></p>
						<div style="background:#dcdcde;border-radius:3px;height:10px;width:300px;margin-bottom:4px">
							<div style="background:#2271b1;height:10px;border-radius:3px;width:<?php echo (int) $_tbt_avg_w; ?>%"></div>
						</div>
						<p class="description">P75: <span style="display:inline-block;background:#dcdcde;border-radius:3px;height:8px;width:<?php echo (int) $_tbt_p75_w; ?>%;vertical-align:middle"></span> <?php echo esc_html( number_format( $_tbt_p75, 1 ) ); ?> ms (0–300 ms scale)</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Interaction to Next Paint (INP)', 'dc-sw-prefetch' ); ?></th>
					<td>
						<p>Avg: <strong><?php echo esc_html( number_format( $_inp_avg, 1 ) ); ?> ms</strong> &nbsp; P75: <strong><?php echo esc_html( number_format( $_inp_p75, 1 ) ); ?> ms</strong></p>
						<div style="background:#dcdcde;border-radius:3px;height:10px;width:300px;margin-bottom:4px">
							<div style="background:#3cb034;height:10px;border-radius:3px;width:<?php echo (int) $_inp_avg_w; ?>%"></div>
						</div>
						<p class="description">P75: <span style="display:inline-block;background:#dcdcde;border-radius:3px;height:8px;width:<?php echo (int) $_inp_p75_w; ?>%;vertical-align:middle"></span> <?php echo esc_html( number_format( $_inp_p75, 1 ) ); ?> ms (0–200 ms scale)</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Samples collected', 'dc-sw-prefetch' ); ?></th>
					<td>
						<strong><?php echo (int) $_perf_count; ?></strong>
						<?php if ( '' !== $_last_upd ) : ?>
							&nbsp;-- <?php echo esc_html__( 'Last updated', 'dc-sw-prefetch' ); ?>: <code><?php echo esc_html( $_last_upd ); ?></code>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"></th>
					<td>
						<button type="button" id="dc-swp-perf-reset-btn" class="button button-secondary">
							<?php echo esc_html__( 'Reset Metrics', 'dc-sw-prefetch' ); ?>
						</button>
						<span id="dc-swp-perf-reset-spinner" class="spinner" style="float:none;margin-left:4px;display:none;"></span>
						<span id="dc-swp-perf-reset-result" style="margin-left:6px;font-weight:600"></span>
					</td>
				</tr>
			</table>
		<?php endif; ?>
		</fieldset>
		</div><!-- /tab-performance -->

		<!-- ===== TAB 7: ADVANCED ===== -->
		<div id="tab-advanced" class="dc-swp-tab-panel">
			<fieldset class="dc-swp-fieldset">
			<table class="form-table">
				<tr valign="top"
				<?php
				if ( ! $sw_enabled ) {
					echo ' class="dc-swp-row-disabled"';}
				?>
				>
					<th scope="row"><?php echo esc_html__( 'Partytown Debug Mode', 'dc-sw-prefetch' ); ?></th>
					<td>
						<label class="pwa-toggle">
							<input type="checkbox" name="dc_swp_debug_mode" value="yes" <?php checked( $debug_mode && $sw_enabled, true ); ?>
							<?php
							if ( ! $sw_enabled ) {
								echo ' disabled';}
							?>
							>
							<span class="pwa-slider"></span>
						</label>
						<p class="description"><?php echo wp_kses_post( __( 'Loads the unminified debug build of Partytown and enables all log flags. Output is emitted via <code>console.debug()</code> -- you must enable the <strong>Verbose</strong> level in the DevTools Console filter (hidden by default). Worker-side logs only appear in <strong>Atomics Bridge</strong> mode, which requires the <em>COI Headers</em> option above to be enabled. <strong>Use only in staging or local development -- enables verbose logging for all visitors.</strong>', 'dc-sw-prefetch' ) ); ?></p>
					</td>
				</tr>
			</table>
			</fieldset>

			<fieldset class="dc-swp-fieldset">
			<legend><?php echo esc_html__( 'Benefits', 'dc-sw-prefetch' ); ?></legend>
			<ul style="list-style: disc; margin-left: 20px;">
				<li>&#x2705; <?php echo esc_html__( 'Third-party scripts run in a Web Worker -- unlike async/defer, they never execute on the browser main thread (no layout jank, no TBT penalty)', 'dc-sw-prefetch' ); ?></li>
				<li>&#x2705; <?php echo esc_html__( 'Google Consent Mode v2 (GCMv2) -- all 7 consent parameters injected before GTM loads; live update signals fired on banner interaction', 'dc-sw-prefetch' ); ?></li>
				<li>&#x2705; <?php echo esc_html__( 'Meta Pixel LDU -- fbq stub + consent-aware grant/revoke injected automatically; no CMP blocking of the pixel required', 'dc-sw-prefetch' ); ?></li>
				<li>&#x2705; <?php echo esc_html__( 'Consent Gate -- optional WP Consent API integration; any compatible CMP (Complianz, Cookiebot, CookieYes...) is supported automatically', 'dc-sw-prefetch' ); ?></li>
				<li>&#x2705; <?php echo esc_html__( 'Six pre-configured Partytown integrations: HubSpot, Klaviyo, Mixpanel, FullStory, Intercom, TikTok Pixel -- enter an ID and the snippet is auto-injected', 'dc-sw-prefetch' ); ?></li>
				<li>&#x2705; <?php echo esc_html__( 'GTM managed injection with GCMv2 pre-configuration and step-by-step setup wizard', 'dc-sw-prefetch' ); ?></li>
				<li>&#x2705; <?php echo esc_html__( 'Early Resource Hints -- preconnect and dns-prefetch links auto-emitted for all configured third-party hosts', 'dc-sw-prefetch' ); ?></li>
				<li>&#x2705; <?php echo esc_html__( 'Cart, checkout, and account pages use the Service Worker bridge (Atomics auto-disabled) -- analytics scripts still fire without breaking payment gateways', 'dc-sw-prefetch' ); ?></li>
				<li>&#x2705; <?php echo esc_html__( 'Bots and crawlers never receive Partytown scripts -- clean, unmodified HTML for search engines', 'dc-sw-prefetch' ); ?></li>
				<li>&#x2705; <?php echo esc_html__( 'Third-party scripts auto-detected in one click via homepage scan', 'dc-sw-prefetch' ); ?></li>
				<li>&#x2705; <?php echo esc_html__( 'Partytown library stays current via automated weekly GitHub Actions workflow', 'dc-sw-prefetch' ); ?></li>
			</ul>
			</fieldset>

			<fieldset class="dc-swp-fieldset">
			<legend><?php echo esc_html__( 'Known Limitations', 'dc-sw-prefetch' ); ?></legend>
			<ul style="list-style: disc; margin-left: 20px;">
				<li>&#9888; <?php echo wp_kses_post( __( '<strong>Full-page caching + Meta Pixel consent:</strong> The Meta Pixel LDU / <code>fbq("consent","revoke")</code> stub is injected by PHP at request time. If a full-page cache plugin (WP Rocket, Nginx FastCGI, static HTML export) serves a cached page without invoking PHP, the consent stub reflects the state of whoever filled the cache — not the current visitor\'s actual consent. Use per-visitor cache keys or disable full-page caching for logged-out visitors.', 'dc-sw-prefetch' ) ); ?></li>
				<li>&#9888; <?php echo wp_kses_post( __( '<strong>Meta Pixel with no LDU and no Consent Gate:</strong> If both the <em>Meta LDU</em> toggle and the <em>Consent Gate</em> are disabled, Meta Pixel fires with no <code>fbq("consent",...)</code> signal. Meta receives data without any explicit consent declaration from this plugin. Enable Meta LDU, the Consent Gate, or both to emit meaningful consent signals.', 'dc-sw-prefetch' ) ); ?></li>
			</ul>
			</fieldset>

			<fieldset class="dc-swp-fieldset">
			<legend><?php echo esc_html__( 'Footer Credit', 'dc-sw-prefetch' ); ?></legend>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><?php echo esc_html__( 'Footer Credit', 'dc-sw-prefetch' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="dc_swp_footer_credit" value="yes" <?php checked( $footer_credit, true ); ?>>
							<?php echo esc_html__( 'Show some love and support development by adding a small link in the footer', 'dc-sw-prefetch' ); ?>
						</label>
						<p class="description"><?php echo wp_kses_post( __( 'Inserts a discreet <a href="https://www.dampcig.dk" target="_blank">Dampcig.dk</a> link in the footer by linking the copyright symbol ©.', 'dc-sw-prefetch' ) ); ?></p>
					</td>
				</tr>
			</table>
			</fieldset>

			</fieldset>

			<fieldset class="dc-swp-fieldset">
			<legend><?php esc_html_e( 'Support Development', 'dc-sw-prefetch' ); ?></legend>
			<div class="dc-swp-donate-wrap">
				<p class="dc-swp-donate-speech">
					<?php
					echo wp_kses_post(
						__(
							'<strong>This plugin is free — and always will be.</strong> Building and maintaining it takes real time: fixing edge cases, keeping pace with WordPress updates, testing every WooCommerce release, and answering support questions. If it saved you an hour, spared you a headache, or just quietly made your store faster — please consider buying me a coffee or a treat for my dog. Every donation, no matter the size, keeps the motivation going and the updates coming. Thank you! 🐾',
							'dc-sw-prefetch'
						)
					);
					?>
				</p>
				<div class="dc-swp-donate-row">
					<div class="dc-swp-donate-qr">
						<img
							src="<?php echo esc_url( plugins_url( 'assets/img/paypal-qr.png', __FILE__ ) ); ?>"
							alt="<?php esc_attr_e( 'Scan to donate via PayPal', 'dc-sw-prefetch' ); ?>"
							width="150"
							height="150"
						>
						<p class="description"><?php esc_html_e( 'Scan with your phone camera', 'dc-sw-prefetch' ); ?></p>
					</div>
					<div class="dc-swp-donate-actions">
						<p><?php esc_html_e( 'Or click the button below:', 'dc-sw-prefetch' ); ?></p>
						<a
							href="<?php echo esc_url( 'https://www.paypal.com/donate?business=X2H3AGW3278BA&no_recurring=0&item_name=Support+my+development+of+free+to+use%2C+feature+rich+WooCommerce+Plugins.+Please+buy+me+a+coffee+or+treats+for+my+dog.&currency_code=DKK' ); ?>"
							target="_blank"
							rel="noopener noreferrer"
						><img
								src="<?php echo esc_url( 'https://www.paypalobjects.com/en_US/i/btn/btn_donate_LG.gif' ); ?>"
								alt="<?php esc_attr_e( 'Donate with PayPal button', 'dc-sw-prefetch' ); ?>"
								width="92"
								height="26"
							></a>
						<?php // PayPal tracking pixel — standard with all PayPal donate buttons. ?>
						<img
							alt=""
							src="<?php echo esc_url( 'https://www.paypal.com/en_DK/i/scr/pixel.gif' ); ?>"
							width="1"
							height="1"
							style="display:none"
						>
					</div>
				</div>
			</div>
			</fieldset>

		</div><!-- /tab-advanced -->

			<?php submit_button( __( 'Save Settings', 'dc-sw-prefetch' ) ); ?>
		</form>
	</div>

	<?php
	wp_localize_script(
		'dc-swp-admin-script',
		'dcSwpAdminData',
		array(
			'nonce'              => wp_create_nonce( 'dc_swp_detect_nonce' ),
			'noScriptsMsg'       => __( 'No external scripts found on the homepage.', 'dc-sw-prefetch' ),
			'unknownMsg'         => __( 'Compatibility unknown -- not on Partytown\'s verified services list. Test carefully before adding.', 'dc-sw-prefetch' ),
			'knownMsg'           => __( '✔ Verified compatible service', 'dc-sw-prefetch' ),
			'noBlocksMsg'        => __( 'No script blocks added yet.', 'dc-sw-prefetch' ),
			'noEntriesMsg'       => esc_attr__( 'No patterns added yet. Click “+ Add Pattern” or use Auto-Detect.', 'dc-sw-prefetch' ),
			'delMsg'             => __( 'Delete this script block?', 'dc-sw-prefetch' ),
			'blocks'             => $inline_script_blocks,
			'scriptListEntries'  => $script_list_entries,
			'knownServices'      => dc_swp_get_known_services(),
			'hostCategoryMap'    => dc_swp_get_service_category_map(),
			'badgeSupported'     => __( '✓ Supported | Partytown', 'dc-sw-prefetch' ),
			'badgeUnsupported'   => __( '⚠ Unsupported | Deferred', 'dc-sw-prefetch' ),
			'forcePtLabel'       => __( 'Force Enable Partytown', 'dc-sw-prefetch' ),
			'forcePtNotice'      => __( 'Running script with unknown Partytown compatibility -- test your site in debug mode to confirm no render errors.', 'dc-sw-prefetch' ),
			'blockCategoryLabel' => __( 'Consent category', 'dc-sw-prefetch' ),
			'consentGateEnabled' => $consent_gate,
			'consentCategories'  => array( 'marketing', 'statistics', 'statistics-anonymous', 'functional', 'preferences' ),
			'gtm'                => array(
				'valid'        => __( '✔ Valid tag ID', 'dc-sw-prefetch' ),
				'invalid'      => __( '⚠ Invalid format. Expected: GTM-XXXXXXX, G-XXXXXXXXXX, or UA-XXXXXX-X.', 'dc-sw-prefetch' ),
				'detected'     => __( 'Detected', 'dc-sw-prefetch' ),
				'use'          => __( 'Use This ID', 'dc-sw-prefetch' ),
				'none'         => __( 'No active Google Tag found in page source.', 'dc-sw-prefetch' ),
				'autoSwitched' => __( '\\u2714 Auto-Detect selected \\u2014 tag is already in the Partytown Script List.', 'dc-sw-prefetch' ),
				'willBeUsed'   => __( 'will be used on next save', 'dc-sw-prefetch' ),
				'active'       => __( 'Auto-detected and active', 'dc-sw-prefetch' ),
				'saved'        => __( '✔ Saved', 'dc-sw-prefetch' ),
			),
			'gcm'                => array(
				'checking'          => __( 'Checking for GCM v2 conflicts...', 'dc-sw-prefetch' ),
				'conflictTitle'     => __( '⚠ Existing GCM v2 stub detected', 'dc-sw-prefetch' ),
				'conflictBody'      => __( 'Another plugin or theme on your site already outputs a gtag(\'consent\',\'default\',...) call. Running two GCM v2 stubs simultaneously causes unpredictable consent behaviour -- whichever fires last wins, non-deterministically. Disable Google Consent Mode in the other plugin before enabling it here.', 'dc-sw-prefetch' ),
				'noConsentApiTitle' => __( 'WP Consent API not installed', 'dc-sw-prefetch' ),
				'noConsentApiBody'  => __( 'Our GCM v2 update script reads consent state via the WP Consent API plugin. Without it, consent signals cannot be delivered reliably to Google across different CMP plugins.', 'dc-sw-prefetch' ),
				'noConsentApiLink'  => __( 'Install WP Consent API ↗', 'dc-sw-prefetch' ),
				'wpConsentApiUrl'   => admin_url( 'plugin-install.php?tab=plugin-information&plugin=wp-consent-api' ),
			),
			'perf'               => array(
				'resetNonce' => wp_create_nonce( 'dc_swp_perf_reset_nonce' ),
				'resetted'   => '✔ ' . esc_html__( 'Metrics reset -- reload to confirm.', 'dc-sw-prefetch' ),
			),
		)
	);
}

// ============================================================
// ADMIN NOTICE -- Partytown Health Monitor Issues
// ============================================================

add_action( 'admin_notices', 'dc_swp_admin_health_notice' );

/**
 * Display an admin notice when the Partytown Health Monitor has flagged
 * one or more services as potentially failing inside the worker.
 *
 * @since 2.1.0
 * @return void
 */
function dc_swp_admin_health_notice(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$issues = get_transient( 'dc_swp_health_issues' );
	if ( ! is_array( $issues ) || empty( $issues ) ) {
		return;
	}
	$hosts_html = implode( ', ', array_map( 'esc_html', $issues ) );
	echo '<div class="notice notice-warning is-dismissible"><p>'
		. esc_html__( '⚠ Partytown Health Monitor: These hosts produced no observable network traffic. They may be failing inside the Partytown worker:', 'dc-sw-prefetch' )
		. ' <strong>' . $hosts_html . '</strong>'  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $hosts_html built exclusively from esc_html()-escaped values; implode does not introduce new HTML.
		. '</p></div>' . "\n";
}

// ============================================================
// AJAX -- Auto-detect third-party scripts on the homepage
// ============================================================

add_action( 'wp_ajax_dc_swp_detect_scripts', 'dc_swp_ajax_detect_scripts' );
/**
 * AJAX handler: detect third-party scripts on the homepage for the admin UI.
 *
 * @since 1.0.0
 * @return void
 */
function dc_swp_ajax_detect_scripts() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized' );
	}
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'dc_swp_detect_nonce' ) ) {
		wp_send_json_error( 'Invalid nonce' );
	}

	$response = wp_remote_get(
		home_url( '/' ),
		array(
			'timeout'    => 15,
			'sslverify'  => true,
			'user-agent' => 'Mozilla/5.0 (DCSwPrefetch/1.0; Auto-Detect)',
		)
	);
	if ( is_wp_error( $response ) ) {
		wp_send_json_error( $response->get_error_message() );
	}

	$body      = wp_remote_retrieve_body( $response );
	$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

	preg_match_all( '/<script[^>]+\bsrc=["\'](https?:[^"\']+|[\/][^"\']+)["\']/i', $body, $matches );

	// Patterns for services listed on the Partytown common-services page.
	// phpcs:ignore Squiz.Commenting.InlineComment.InvalidEndChar
	$known_patterns = dc_swp_get_known_services();

	// Hosts with dedicated plugin panels -- excluded from generic autodetect results.
	$dedicated_hosts = array( 'googletagmanager.com', 'connect.facebook.net' );

	// Already-configured hosts (include list + Script Block) -- never suggest these.
	$already_configured = dc_swp_get_proxy_allowed_hosts();

	$seen    = array();
	$scripts = array();
	foreach ( (array) $matches[1] as $src ) {
		if ( str_starts_with( $src, '//' ) ) {
			$src = 'https:' . $src;
		}
		if ( str_starts_with( $src, '/' ) ) {
			continue; // On-site relative URL -- skip.
		}
		$parsed = wp_parse_url( $src );
		if ( empty( $parsed['host'] ) || $parsed['host'] === $site_host ) {
			continue;
		}
		$host = strtolower( $parsed['host'] );
		if ( isset( $seen[ $host ] ) ) {
			continue; // Deduplicate by hostname.
		}
		$seen[ $host ] = true;

		// Skip hosts that have a dedicated plugin panel (GTM, Facebook Pixel).
		$is_dedicated = false;
		foreach ( $dedicated_hosts as $d_host ) {
			if ( str_contains( $host, $d_host ) ) {
				$is_dedicated = true;
				break;
			}
		}
		if ( $is_dedicated ) {
			continue;
		}

		// Skip hosts already in the include list or the Script Block.
		$already = false;
		foreach ( $already_configured as $configured_host ) {
			if ( str_contains( $host, $configured_host ) || str_contains( $configured_host, $host ) ) {
				$already = true;
				break;
			}
		}
		if ( $already ) {
			continue;
		}

		$is_known = false;
		foreach ( $known_patterns as $pat ) {
			if ( str_contains( $host, $pat ) || str_contains( $pat, $host ) ) {
				$is_known = true;
				break;
			}
		}
		$scripts[] = array(
			'host'  => $host,
			'known' => $is_known,
		);
	}

	wp_send_json_success( array( 'scripts' => $scripts ) );
}

// ============================================================
// AJAX -- Check for conflicting GCM v2 stubs on the homepage
// ============================================================

add_action( 'wp_ajax_dc_swp_check_gcm_conflict', 'dc_swp_ajax_check_gcm_conflict' );
/**
 * AJAX handler: fetch the homepage and detect any GCM v2 default stub
 * not produced by this plugin.
 *
 * Strategy: scan for gtag('consent','default',...) and exclude matches
 * that contain 'default_consent' nearby -- that string is the exclusive
 * fingerprint of our own stub (dataLayer.push({event:'default_consent'})).
 * Also reports whether the WP Consent API plugin is active so the admin
 * UI can prompt the user to install it if missing.
 *
 * @since 1.9.0
 * @return void
 */
function dc_swp_ajax_check_gcm_conflict() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'dc_swp_detect_nonce' ) ) {
		wp_send_json_error( 'Invalid nonce' );
	}

	// Serve cached result for 5 minutes to avoid a homepage HTTP fetch on every admin page load.
	$cached = get_transient( 'dc_swp_gcm_conflict_result' );
	if ( false !== $cached && is_array( $cached ) ) {
		wp_send_json_success( $cached );
		return;
	}

	$response = wp_remote_get(
		home_url( '/' ),
		array(
			'timeout'    => 15,
			'sslverify'  => true,
			'user-agent' => 'Mozilla/5.0 (DCSwPrefetch/1.0; GCM-Conflict-Check)',
		)
	);

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( $response->get_error_message() );
	}

	$body         = wp_remote_retrieve_body( $response );
	$has_conflict = false;

	/*
	 * Find every gtag('consent','default',...) call in the page source.
	 * Our own stub always emits `dataLayer.push({event:'default_consent'})`
	 * immediately after -- use that as the exclusion fingerprint so we never
	 * flag our own output as a conflict.
	 */
	if ( preg_match_all( "/gtag\s*\(\s*['\"]consent['\"]\s*,\s*['\"]default['\"]/i", $body, $m, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $m[0] as $hit ) {
			// Capture 850 chars starting 50 chars before the match to cover the full stub.
			$snippet = substr( $body, max( 0, $hit[1] - 50 ), 850 );
			// Absence of our fingerprint → foreign stub → conflict.
			if ( false === strpos( $snippet, 'default_consent' ) ) {
				$has_conflict = true;
				break;
			}
		}
	}

	$result = array(
		'conflict'       => $has_conflict,
		'wp_consent_api' => function_exists( 'wp_has_consent' ),
	);
	set_transient( 'dc_swp_gcm_conflict_result', $result, 5 * MINUTE_IN_SECONDS );
	wp_send_json_success( $result );
}
