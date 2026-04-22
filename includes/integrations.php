<?php
/**
 * DC Script Worker Proxy -- Partytown Integrations
 *
 * Auto-injects HubSpot, Klaviyo, Mixpanel, FullStory, and Intercom snippets
 * as type="text/partytown" scripts when a service ID is configured in the
 * Integrations admin tab. All injected scripts run inside the Partytown web
 * worker; each service's CDN hosts are automatically added to the CORS proxy
 * allowlist via the dc_swp_extra_proxy_hosts filter.
 *
 * @package DC_Service_Worker_Proxy
 * @since   2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

// ============================================================
// INTEGRATIONS -- SHARED HELPERS
// ============================================================

/**
 * Return true if Partytown is enabled and the current context allows injection.
 *
 * @since 2.6.0
 * @return bool
 */
function dc_swp_intg_can_inject(): bool {
	if ( dc_swp_is_bot_request() || is_admin() ) {
		return false;
	}
	if ( 'yes' !== get_option( 'dc_swp_sw_enabled', 'yes' ) ) {
		return false;
	}
	if ( dc_swp_is_excluded_url() ) {
		return false;
	}
	return true;
}

/**
 * Return a ready-to-echo nonce attribute string (space-leading) for inline scripts.
 *
 * @since 2.6.0
 * @return string Empty string or ' nonce="..."'.
 */
function dc_swp_intg_nonce_attr(): string {
	$nonce = dc_swp_get_csp_nonce();
	return '' !== $nonce ? ' nonce="' . esc_attr( $nonce ) . '"' : '';
}

// ============================================================
// HUBSPOT
// ============================================================

/**
 * Inject the HubSpot tracking script as type="text/partytown".
 *
 * Loads js.hs-scripts.com/{portal_id}.js in the Partytown web worker so
 * HubSpot analytics and form tracking run off the main thread.
 *
 * @since 2.6.0
 * @return void
 */
function dc_swp_intg_hubspot(): void {
	$portal_id = sanitize_text_field( get_option( 'dc_swp_hubspot_portal_id', '' ) );
	if ( empty( $portal_id ) || ! dc_swp_intg_can_inject() ) {
		return;
	}
	if ( ! dc_swp_has_consent_for( 'marketing' ) ) {
		return;
	}

	$handle = 'dc-swp-hubspot';
	$src    = esc_url_raw( 'https://js.hs-scripts.com/' . $portal_id . '.js' );
	wp_register_script( $handle, $src, array( 'dc-swp-partytown-config' ), null, array( 'in_footer' => true, 'strategy' => 'async' ) );
	wp_script_add_data( $handle, 'dc_swp_type', 'text/partytown' );
	wp_enqueue_script( $handle );
}
add_action( 'wp_enqueue_scripts', 'dc_swp_intg_hubspot', 8 );

// ============================================================
// KLAVIYO
// ============================================================

/**
 * Inject the Klaviyo onsite JS as type="text/partytown".
 *
 * Loads the Klaviyo client library in the Partytown web worker for off-thread
 * event tracking and form behaviour.
 *
 * @since 2.6.0
 * @return void
 */
function dc_swp_intg_klaviyo(): void {
	$site_id = sanitize_text_field( get_option( 'dc_swp_klaviyo_site_id', '' ) );
	if ( empty( $site_id ) || ! dc_swp_intg_can_inject() ) {
		return;
	}
	if ( ! dc_swp_has_consent_for( 'marketing' ) ) {
		return;
	}

	$handle = 'dc-swp-klaviyo';
	$src    = esc_url_raw( 'https://static.klaviyo.com/onsite/js/klaviyo.js?company_id=' . rawurlencode( $site_id ) );
	wp_register_script( $handle, $src, array( 'dc-swp-partytown-config' ), null, array( 'in_footer' => false, 'strategy' => 'async' ) );
	wp_script_add_data( $handle, 'dc_swp_type', 'text/partytown' );
	wp_enqueue_script( $handle );
}
add_action( 'wp_enqueue_scripts', 'dc_swp_intg_klaviyo', 8 );

// ============================================================
// MIXPANEL
// ============================================================

/**
 * Inject the Mixpanel stub + init snippet as type="text/partytown".
 *
 * Uses the standard Mixpanel snippet which self-loads cdn.mxpnl.com. The
 * snippet creates all stub methods so client code can call mixpanel.track()
 * immediately, even before the library resolves inside the worker.
 *
 * @since 2.6.0
 * @return void
 */
function dc_swp_intg_mixpanel(): void {
	$token = sanitize_text_field( get_option( 'dc_swp_mixpanel_token', '' ) );
	if ( empty( $token ) || ! dc_swp_intg_can_inject() ) {
		return;
	}
	if ( ! dc_swp_has_consent_for( 'statistics' ) ) {
		return;
	}

	$nonce_attr = dc_swp_intg_nonce_attr();
	$safe_token = esc_js( $token );

	// Standard Mixpanel stub snippet. Loads cdn.mxpnl.com/libs/mixpanel-2-latest.min.js.
	$js = '(function(f,b){if(!b.__SV){var e,g,i,h;window.mixpanel=b;b._i=[];' .
		'b.init=function(e,f,c){function g(a,d){var b=d.split(".");2==b.length&&(a=a[b[0]],d=b[1]);' .
		'a[d]=function(){a.push([d].concat(Array.prototype.slice.call(arguments,0)))}}' .
		'var a=b;"undefined"!==typeof c?a=b[c]=[]:c="mixpanel";' .
		'a.people=a.people||[];a.toString=function(a){var d="mixpanel";"mixpanel"!==c&&(d+="."+c);' .
		'a||(d+=" (stub)");return d};a.people.toString=function(){return a.toString(1)+".people (stub)"};' .
		'i="disable time_event track track_pageview track_links track_forms track_with_groups add_group set_group ' .
		'remove_group register register_once alias unregister identify name_tag set_config reset ' .
		'opt_in_tracking opt_out_tracking has_opted_in_tracking has_opted_out_tracking ' .
		'clear_opt_in_out_tracking start_batch_senders ' .
		'people.set people.set_once people.unset people.increment people.append people.union ' .
		'people.track_charge people.clear_charges people.delete_user people.remove".split(" ");' .
		'for(h=0;h<i.length;h++)g(a,i[h]);' .
		'var j="set set_once union unset remove delete".split(" ");' .
		'a.get_group=function(){function b(a){f[a]=function(){f.push([a].concat(Array.prototype.slice.call(arguments,0)))}}' .
		'for(var f=new(Array),a=0;a<j.length;a++)b(j[a]);return f};b._i.push([e,f,c])};' .
		'b.__SV=1.2;e=f.createElement("script");e.type="text/javascript";e.async=!0;' .
		'e.src="https://cdn.mxpnl.com/libs/mixpanel-2-latest.min.js";' .
		'g=f.getElementsByTagName("script")[0];g.parentNode.insertBefore(e,g)}})(document,window.mixpanel||[]);' .
		"mixpanel.init('" . $safe_token . "');";

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $js contains static strings + esc_js token; nonce is pre-escaped.
	echo '<script type="text/partytown"' . $nonce_attr . '>' . $js . "</script>\n";
}
add_action( 'wp_head', 'dc_swp_intg_mixpanel', 8 );

// ============================================================
// FULLSTORY
// ============================================================

/**
 * Inject the FullStory snippet as type="text/partytown".
 *
 * Follows FullStory's standard snippet. Sets window._fs_org and loads
 * edge.fullstory.com/s/fs.js through the Partytown CORS proxy.
 * strictProxyHas is auto-enabled (via dc_swp_has_fullstory_via_integrations())
 * to prevent false namespace-conflict detection.
 *
 * @since 2.6.0
 * @return void
 */
function dc_swp_intg_fullstory(): void {
	$org_id = sanitize_text_field( get_option( 'dc_swp_fullstory_org_id', '' ) );
	if ( empty( $org_id ) || ! dc_swp_intg_can_inject() ) {
		return;
	}
	if ( ! dc_swp_has_consent_for( 'statistics' ) ) {
		return;
	}

	$safe_org = esc_js( $org_id );
	$handle   = 'dc-swp-fullstory';

	// Set FullStory config variables on the main thread before the library loads
	// in the Partytown worker. fs.js reads these from window when it initialises.
	$config = "window['_fs_host']='fullstory.com';" .
		"window['_fs_script']='edge.fullstory.com/s/fs.js';" .
		"window['_fs_org']='" . $safe_org . "';" .
		"window['_fs_namespace']='FS';";

	wp_register_script( $handle, 'https://edge.fullstory.com/s/fs.js', array( 'dc-swp-partytown-config' ), null, array( 'in_footer' => false ) );
	wp_script_add_data( $handle, 'dc_swp_type', 'text/partytown' );
	wp_add_inline_script( $handle, $config, 'before' );
	wp_enqueue_script( $handle );
}
add_action( 'wp_enqueue_scripts', 'dc_swp_intg_fullstory', 8 );

// ============================================================
// INTERCOM
// ============================================================

/**
 * Inject the Intercom snippet as type="text/partytown".
 *
 * Uses the standard Intercom loader snippet. The boot call uses only app_id
 * so that user data is not hard-coded into the page HTML.
 *
 * @since 2.6.0
 * @return void
 */
function dc_swp_intg_intercom(): void {
	$app_id = sanitize_text_field( get_option( 'dc_swp_intercom_app_id', '' ) );
	if ( empty( $app_id ) || ! dc_swp_intg_can_inject() ) {
		return;
	}
	if ( ! dc_swp_has_consent_for( 'functional' ) ) {
		return;
	}

	$nonce_attr = dc_swp_intg_nonce_attr();
	$safe_id    = esc_js( $app_id );

	$js = '(function(){var w=window;var ic=w.Intercom;' .
		'if(typeof ic==="function"){ic("reattach_activator");ic("update",w.intercomSettings);}' .
		'else{var d=document;var i=function(){i.c(arguments)};i.q=[];i.c=function(args){i.q.push(args)};' .
		'w.Intercom=i;var l=function(){var s=d.createElement("script");' .
		's.type="text/javascript";s.async=true;' .
		"s.src='https://widget.intercom.io/widget/" . $safe_id . "';" .
		'var x=d.getElementsByTagName("script")[0];x.parentNode.insertBefore(s,x);};' .
		'if(document.readyState==="complete"){l();}' .
		'else if(w.attachEvent){w.attachEvent("onload",l);}' .
		'else{w.addEventListener("load",l,false);}}' .
		"})();window.Intercom('boot',{app_id:'" . $safe_id . "'});";

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $js contains static strings + esc_js app ID; nonce is pre-escaped.
	echo '<script type="text/partytown"' . $nonce_attr . '>' . $js . "</script>\n";
}
add_action( 'wp_head', 'dc_swp_intg_intercom', 8 );

// ============================================================
// TIKTOK PIXEL
// ============================================================

/**
 * Inject the TikTok Pixel base code as type="text/partytown".
 *
 * @since 3.0.1
 * @return void
 */
function dc_swp_intg_tiktok(): void {
	$pixel_id = sanitize_text_field( get_option( 'dc_swp_tt_pixel_id', '' ) );
	if ( empty( $pixel_id ) || ! dc_swp_intg_can_inject() ) {
		return;
	}
	// TikTok Pixel is GCMv2-aware: always load when GCM consent mode is active
	// so TikTok can read ad_storage/ad_user_data signals and self-restrict.
	// When GCM mode is off, gate on explicit marketing consent like any other pixel.
	if ( 'yes' !== get_option( 'dc_swp_consent_mode', 'no' ) && ! dc_swp_has_consent_for( 'marketing' ) ) {
		return;
	}

	$nonce_attr = dc_swp_intg_nonce_attr();
	$safe_id    = esc_js( $pixel_id );

	$js = '!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];' .
		'ttq.methods=["page","track","trackSku","trackWithDeduplication","identify",' .
		'"instances","debug","on","off","once","ready","alias","group",' .
		'"enableCookie","disableCookie","holdConsent","revokeConsent","grantConsent"],' .
		'ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};' .
		'for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);' .
		'ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e},' .
		'ttq.load=function(e,n){var r="https://analytics.tiktok.com/i18n/pixel/events.js";' .
		'ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=r,ttq._t=ttq._t||{},ttq._t[e]=+new Date,' .
		'ttq._o=ttq._o||{},ttq._o[e]=n||{};' .
		'n=document.createElement("script");n.type="text/javascript",n.async=!0,' .
		'n.src=r+"?sdkid="+e+"&lib="+t;' .
		'var a=document.getElementsByTagName("script")[0];a.parentNode.insertBefore(n,a)};' .
		"ttq.load('" . $safe_id . "');ttq.page()}" .
		"(window,document,'ttq');";

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $js is a static string; pixel ID is esc_js-escaped; nonce is pre-escaped via esc_attr.
	echo '<script type="text/partytown"' . $nonce_attr . '>' . $js . "</script>\n";
}
add_action( 'wp_head', 'dc_swp_intg_tiktok', 8 );

// ============================================================
// PROXY HOST REGISTRATION
// ============================================================

/**
 * Register CDN hosts for all configured Integrations tab services.
 *
 * Called via the dc_swp_extra_proxy_hosts filter so that
 * dc_swp_get_proxy_allowed_hosts() includes these hosts without requiring the
 * site owner to manually add each CDN to the Partytown Script List.
 *
 * @since 2.6.0
 * @param string[] $hosts Current allowed proxy host list.
 * @return string[]
 */
function dc_swp_intg_extra_proxy_hosts( array $hosts ): array {
	if ( '' !== get_option( 'dc_swp_hubspot_portal_id', '' ) ) {
		$hosts[] = 'js.hs-scripts.com';
		$hosts[] = 'js.hsforms.net';
		$hosts[] = 'js.hsleadflows.net';
		$hosts[] = 'js.hscollectedforms.net';
	}

	if ( '' !== get_option( 'dc_swp_klaviyo_site_id', '' ) ) {
		$hosts[] = 'static.klaviyo.com';
		$hosts[] = 'static-tracking.klaviyo.com';
	}

	if ( '' !== get_option( 'dc_swp_mixpanel_token', '' ) ) {
		$hosts[] = 'cdn.mxpnl.com';
		$hosts[] = 'cdn4.mxpnl.com';
		$hosts[] = 'api.mixpanel.com';
	}

	if ( '' !== get_option( 'dc_swp_fullstory_org_id', '' ) ) {
		$hosts[] = 'edge.fullstory.com';
		$hosts[] = 'rs.fullstory.com';
	}

	if ( '' !== get_option( 'dc_swp_intercom_app_id', '' ) ) {
		$hosts[] = 'widget.intercom.io';
		$hosts[] = 'js.intercomcdn.com';
		$hosts[] = 'api-iam.intercom.io';
	}

	if ( '' !== get_option( 'dc_swp_tt_pixel_id', '' ) ) {
		$hosts[] = 'analytics.tiktok.com';
	}

	return $hosts;
}
add_filter( 'dc_swp_extra_proxy_hosts', 'dc_swp_intg_extra_proxy_hosts' );
