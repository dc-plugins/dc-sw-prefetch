<?php
/**
 * DC Script Worker Prefetcher — Uninstall
 *
 * Fired when the plugin is deleted via the WordPress admin.
 * Removes all plugin-specific options and transients from the database.
 *
 * @package DC_Service_Worker_Prefetcher
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
function dc_swp_uninstall() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	foreach ( array(
		'dampcig_pwa_sw_enabled',
		'dampcig_pwa_preload_products',
		'dampcig_pwa_product_base',
		'dampcig_pwa_footer_credit',
		'dc_swp_disable_emoji',
		'dc_swp_partytown_scripts',
		'dc_swp_partytown_exclude',
		'dc_swp_inline_scripts',
		'dc_swp_coi_headers',
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
