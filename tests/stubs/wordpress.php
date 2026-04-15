<?php
/**
 * Minimal WordPress function stubs for unit tests.
 *
 * Only the subset of WordPress functions actually called when loading
 * dc-sw-prefetch.php, admin.php, and includes/integrations.php is defined
 * here. Tests control option return values via the global
 * $_dc_swp_test_options array (set through TestCase::setOption()).
 *
 * NOTA BENE: wp_has_consent() is defined here so function_exists() checks
 * inside the plugin always resolve. Set consent state per-category via
 * TestCase::setConsent() or the $_dc_swp_test_has_consent global.
 */

// -----------------------------------------------------------------------
// Constants
// -----------------------------------------------------------------------

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}
if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

// -----------------------------------------------------------------------
// Shared test state (reset in TestCase::setUp)
// -----------------------------------------------------------------------

$GLOBALS['_dc_swp_test_options']     = [];
$GLOBALS['_dc_swp_test_has_consent'] = [];

// -----------------------------------------------------------------------
// Options API
// -----------------------------------------------------------------------

function get_option( string $option, $default = false ) {
	return $GLOBALS['_dc_swp_test_options'][ $option ] ?? $default;
}
function update_option( string $option, $value, $autoload = null ): bool {
	$GLOBALS['_dc_swp_test_options'][ $option ] = $value;
	return true;
}
function delete_option( string $option ): bool {
	unset( $GLOBALS['_dc_swp_test_options'][ $option ] );
	return true;
}

// -----------------------------------------------------------------------
// Object cache (always miss — prevents cross-test static bleed via cache)
// -----------------------------------------------------------------------

function wp_cache_get( $key, string $group = '', bool $force = false, &$found = null ) {
	$found = false;
	return false;
}
function wp_cache_set( $key, $value, string $group = '', int $expire = 0 ): bool {
	return true;
}
function wp_cache_delete( $key, string $group = '' ): bool {
	return true;
}

// -----------------------------------------------------------------------
// Transients
// -----------------------------------------------------------------------

function get_transient( $transient ) { return false; }
function set_transient( $transient, $value, int $expiration = 0 ): bool { return true; }
function delete_transient( $transient ): bool { return true; }

// -----------------------------------------------------------------------
// Hook API (no-ops — the plugin registers hooks at load time)
// -----------------------------------------------------------------------

function add_action(): bool { return true; }
function add_filter(): bool { return true; }
function remove_action(): bool { return true; }
function remove_filter(): bool { return true; }
function do_action(): void {}
function apply_filters( string $tag, $value, ...$args ) { return $value; }
function has_filter(): bool { return false; }
function did_action(): int { return 0; }
function register_activation_hook(): void {}
function register_deactivation_hook(): void {}

// -----------------------------------------------------------------------
// String / escaping (pass-through — tests supply clean input already)
// -----------------------------------------------------------------------

function sanitize_text_field( $str ): string { return (string) $str; }
function sanitize_key( string $key ): string {
	return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', $key ) );
}
function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}
function wp_slash( $value ) { return $value; }
function wp_kses_post( $data ): string { return (string) $data; }
function esc_url( $url ): string { return (string) $url; }
function esc_url_raw( $url ): string { return (string) $url; }
function esc_attr( $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_html( $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_js( $text ): string { return (string) $text; }
function esc_textarea( $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function absint( $maybeint ): int { return abs( (int) $maybeint ); }
function wp_json_encode( $data, int $flags = 0, int $depth = 512 ) {
	return json_encode( $data, $flags, $depth );
}
function wp_parse_args( $args, $defaults = [] ): array {
	return array_merge( (array) $defaults, (array) $args );
}
function wp_normalize_path( string $path ): string {
	return str_replace( '\\', '/', $path );
}

// -----------------------------------------------------------------------
// Translations (return string as-is)
// -----------------------------------------------------------------------

function __( $text, $domain = 'default' ): string { return (string) $text; }
function _e( $text, $domain = 'default' ): void { echo $text; }
function esc_html__( $text, $domain = 'default' ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_attr__( $text, $domain = 'default' ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_html_e( $text, $domain = 'default' ): void {
	echo htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_attr_e( $text, $domain = 'default' ): void {
	echo htmlspecialchars( (string) $text, ENT_QUOTES );
}
function load_plugin_textdomain(): bool { return true; }
function number_format_i18n( $number, int $decimals = 0 ): string {
	return number_format( (float) $number, $decimals );
}

// -----------------------------------------------------------------------
// URL / permalink helpers
// -----------------------------------------------------------------------

function wp_parse_url( $url, int $component = -1 ) { return parse_url( $url, $component ); }
function home_url( string $path = '', $scheme = null ): string {
	return 'https://example.com' . $path;
}
function trailingslashit( $string ): string { return rtrim( (string) $string, '/\\' ) . '/'; }
function untrailingslashit( $string ): string { return rtrim( (string) $string, '/\\' ); }
function admin_url( string $path = '', string $scheme = 'admin' ): string {
	return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
}

// -----------------------------------------------------------------------
// Plugin helpers
// -----------------------------------------------------------------------

function plugin_dir_path( string $file ): string { return trailingslashit( dirname( $file ) ); }
function plugin_dir_url( string $file ): string {
	return 'https://example.com/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
}
function plugin_basename( string $file ): string {
	return ltrim( str_replace( ABSPATH, '', $file ), '/' );
}
function plugins_url( string $path = '', string $plugin = '' ): string {
	return 'https://example.com/wp-content/plugins/' . ltrim( $path, '/' );
}

// -----------------------------------------------------------------------
// Script / style enqueueing (no-ops)
// -----------------------------------------------------------------------

function wp_enqueue_script(): void {}
function wp_register_script(): void {}
function wp_deregister_script(): void {}
function wp_localize_script(): bool { return true; }
function wp_add_inline_script(): bool { return true; }
function wp_script_is(): bool { return false; }
function wp_enqueue_style(): void {}
function wp_register_style(): void {}
function wp_add_inline_style(): bool { return true; }
function wp_script_attributes( array $attributes ): string { return ''; }

// -----------------------------------------------------------------------
// User / auth context
// -----------------------------------------------------------------------

function is_user_logged_in(): bool { return false; }
function is_admin(): bool { return false; }
function current_user_can(): bool { return false; }
function check_ajax_referer(): int { return 1; }
function wp_verify_nonce(): int { return 1; }
function wp_create_nonce(): string { return 'test-nonce'; }
function wp_die( $message = '' ): never {
	throw new \RuntimeException( 'wp_die: ' . (string) $message );
}

// -----------------------------------------------------------------------
// Admin UI (no-ops — only invoked inside is_admin() guards)
// -----------------------------------------------------------------------

function add_menu_page(): ?string { return null; }
function add_submenu_page(): ?string { return null; }
function add_management_page(): ?string { return null; }
function add_options_page(): ?string { return null; }
function settings_fields(): void {}
function do_settings_sections(): void {}
function register_setting(): void {}
function add_settings_section(): void {}
function add_settings_field(): void {}
function settings_errors(): void {}
function wp_nonce_field(): int { return 1; }
function submit_button(): void {}
function wp_send_json_success( $data = null, ?int $status_code = null ): never {
	echo json_encode( [ 'success' => true, 'data' => $data ] );
	exit( 0 );
}
function wp_send_json_error( $data = null, ?int $status_code = null ): never {
	echo json_encode( [ 'success' => false, 'data' => $data ] );
	exit( 0 );
}
function get_current_screen(): ?object { return null; }

// -----------------------------------------------------------------------
// Filesystem
// -----------------------------------------------------------------------

function WP_Filesystem(): bool { return true; }

// -----------------------------------------------------------------------
// Miscellaneous
// -----------------------------------------------------------------------

function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; }
function wp_parse_str( $string, &$array ): void { parse_str( $string, $array ); }

/** Minimal WP_Error stub. */
class WP_Error {
	public function __construct( $code = '', $message = '', $data = '' ) {}
	public function get_error_message( $code = '' ): string { return ''; }
	public function get_error_code(): string { return ''; }
}

// -----------------------------------------------------------------------
// WP Consent API
//
// Defined here so function_exists( 'wp_has_consent' ) always returns true
// in tests. Consent state is controlled per-category via
// TestCase::setConsent() or by writing to $_dc_swp_test_has_consent directly.
// -----------------------------------------------------------------------

function wp_has_consent( string $category ): bool {
	return (bool) ( $GLOBALS['_dc_swp_test_has_consent'][ $category ] ?? false );
}

// -----------------------------------------------------------------------
// WooCommerce conditionals
//
// NOT defined here intentionally. dc_swp_is_safe_page() guards every call
// with function_exists(), so omitting these functions means the safe-page
// check correctly returns false (= WooCommerce not active) in unit tests.
// -----------------------------------------------------------------------
