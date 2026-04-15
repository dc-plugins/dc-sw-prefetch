<?php

namespace DC_SW_Proxy\Tests\Unit;

/**
 * Tests for dc_swp_is_safe_page().
 *
 * dc_swp_is_safe_page() returns true when the current page is a WooCommerce
 * transactional page (cart, checkout, account). It guards every WooCommerce
 * function call with function_exists(), so when WooCommerce is absent it
 * safely falls through and returns false.
 *
 * In unit tests WooCommerce is not loaded (is_cart, is_checkout, and
 * is_account_page are deliberately not defined in tests/stubs/wordpress.php),
 * so this test suite covers the "WooCommerce not active" path.
 *
 * NOTE: dc_swp_is_safe_page() memoises its result in a static variable.
 * Because statics persist across test methods within the same PHP process,
 * only one assertion per boolean outcome is needed here — the first call fixes
 * the value for the lifetime of this test run.
 */
class SafePageTest extends TestCase {

	public function test_returns_false_when_woocommerce_is_not_active(): void {
		// Confirm none of the WooCommerce conditionals are defined so this test
		// is meaningful — if they were, the static would be set to true.
		$this->assertFalse( function_exists( 'is_cart' ),        'is_cart() should not be defined in unit tests' );
		$this->assertFalse( function_exists( 'is_checkout' ),    'is_checkout() should not be defined in unit tests' );
		$this->assertFalse( function_exists( 'is_account_page' ),'is_account_page() should not be defined in unit tests' );

		$this->assertFalse( dc_swp_is_safe_page() );
	}

	public function test_returns_bool(): void {
		$this->assertIsBool( dc_swp_is_safe_page() );
	}
}
