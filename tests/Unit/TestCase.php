<?php

namespace DC_SW_Proxy\Tests\Unit;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Base test case for DC Script Worker Proxy unit tests.
 *
 * Resets all shared test globals before every test so that option values,
 * consent state, and $_SERVER entries do not bleed between methods.
 */
abstract class TestCase extends PHPUnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Clear the option store used by the get_option() stub.
		$GLOBALS['_dc_swp_test_options'] = [];

		// Clear the consent map used by the wp_has_consent() stub.
		$GLOBALS['_dc_swp_test_has_consent'] = [];

		// Remove server variables that some functions read directly.
		unset( $_SERVER['HTTP_USER_AGENT'], $_SERVER['REQUEST_URI'] );
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Seed the get_option() stub for a single option key.
	 *
	 * @param string $key   Option name (e.g. 'dc_swp_consent_gate').
	 * @param mixed  $value Value that get_option() should return.
	 */
	protected function setOption( string $key, $value ): void {
		$GLOBALS['_dc_swp_test_options'][ $key ] = $value;
	}

	/**
	 * Set the consent state for a WP Consent API category.
	 *
	 * Controls what wp_has_consent( $category ) returns.
	 *
	 * @param string $category  Category slug (e.g. 'marketing', 'statistics').
	 * @param bool   $consented Whether the visitor has given consent.
	 */
	protected function setConsent( string $category, bool $consented ): void {
		$GLOBALS['_dc_swp_test_has_consent'][ $category ] = $consented;
	}
}
