<?php
/**
 * DC Service Worker Prefetcher — Uninstall
 *
 * Fired when the plugin is deleted via the WordPress admin.
 * Removes all plugin-specific options and transients from the database.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

/**
 * Remove all plugin data on uninstall.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function dc_swp_uninstall() {
	foreach ( [
		'dampcig_pwa_sw_enabled',
		'dampcig_pwa_offline_page',
		'dampcig_pwa_preload_products',
		'dampcig_pwa_footer_credit',
	] as $dc_swp_opt ) {
		delete_option( $dc_swp_opt );
	}

	delete_transient( 'dc_swp_footer_strategy' );

	if ( function_exists( 'wp_cache_flush_group' ) ) {
		wp_cache_flush_group( 'dc_swp' );
	} else {
		wp_cache_delete( 'dc_swp_footer_strategy', 'dc_swp' );
	}
}

dc_swp_uninstall();

// Remove transient and object cache entry
delete_transient( 'dc_swp_footer_strategy' );

if ( function_exists( 'wp_cache_flush_group' ) ) {
	wp_cache_flush_group( 'dc_swp' );
} else {
	wp_cache_delete( 'dc_swp_footer_strategy', 'dc_swp' );
}
