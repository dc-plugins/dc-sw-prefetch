<?php
/**
 * PHPUnit bootstrap for DC Script Worker Proxy.
 *
 * Load order:
 *   1. WordPress function stubs  — defines all WP functions as no-ops so the
 *      plugin can be required without a running WordPress installation.
 *   2. Composer autoloader       — makes PHPUnit and test classes available.
 *   3. Plugin files              — registers all dc_swp_* functions in the
 *      global namespace so test methods can call them directly.
 */

require_once __DIR__ . '/stubs/wordpress.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Suppress output produced at module scope (e.g. admin.php inline CSS echoed
// via wp_add_inline_style stubs). Test assertions never check this output.
ob_start();
require_once dirname( __DIR__ ) . '/dc-sw-prefetch.php';
ob_end_clean();
