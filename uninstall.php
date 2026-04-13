<?php
/**
 * DC Script Worker Proxy -- Uninstall
 *
 * Fired when the plugin is deleted via the WordPress admin.
 * Removes all plugin-specific options and transients from the database.
 *
 * @package DC_Service_Worker_Proxy
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

/**
 * Remove all plugin data on uninstall.
 *
 * @since 1.0.0
 * @return void
 */
function dc_swp_uninstall() {
	// Current dc_swp_* option names.
	foreach ( array(
		'dc_swp_sw_enabled',
		'dc_swp_footer_credit',
		'dc_swp_partytown_scripts',
		'dc_swp_inline_scripts',
		'dc_swp_coi_headers',
		'dc_swp_consent_mode',
		'dc_swp_meta_ldu',
		'dc_swp_consent_gate',
		'dc_swp_script_list_category',
		'dc_swp_url_passthrough',
		'dc_swp_ads_data_redaction',
		'dc_swp_debug_mode',
		'dc_swp_gtm_mode',
		'dc_swp_gtm_id',
		'dc_swp_ssga4_enabled',
		'dc_swp_ssga4_mode',
		'dc_swp_ssga4_measurement_id',
		'dc_swp_ssga4_api_secret',
		'dc_swp_ssga4_events',
		'dc_swp_ga4_client_tag',
		'dc_swp_ga4_exclude_logged_in',
		'dc_swp_perf_metrics',
		'dc_swp_perf_samples',
		'dc_swp_resource_hints',
		'dc_swp_health_monitor',
		'dc_swp_perf_monitor',
		'dc_swp_exclusion_patterns',
		// CAPI options (v2.4.0+).
		'dc_swp_capi_mode',
		'dc_swp_capi_pixel_id',
		'dc_swp_capi_access_token',
		'dc_swp_capi_test_event_code',
		'dc_swp_capi_events',
		'dc_swp_capi_exclude_logged_in',
		'dc_swp_capi_send_pii',
		// Attribution & Enhanced Conversions (v2.6.0+).
		'dc_swp_attr_enabled',
		'dc_swp_attr_model',
		'dc_swp_ga4_enhanced_conv',
		// Legacy names (pre-1.6.0) -- remove if migration never ran.
		'dampcig_pwa_sw_enabled',
		'dampcig_pwa_footer_credit',
	) as $dc_swp_opt ) {
		delete_option( $dc_swp_opt );
	}

	if ( function_exists( 'wp_cache_flush_group' ) ) {
		wp_cache_flush_group( 'dc_swp' );
	} else {
		wp_cache_delete( 'patterns', 'dc_swp' );
		wp_cache_delete( 'exclude_patterns', 'dc_swp' );
	}
}

dc_swp_uninstall();
