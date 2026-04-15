<?php

namespace DC_SW_Proxy\Tests\Unit;

/**
 * Tests for dc_swp_is_excluded_url().
 *
 * Key design notes:
 *
 *  - Always pass an explicit non-empty $request_uri to the function. When
 *    $request_uri is non-empty the function skips its internal static cache
 *    and returns the match result directly, letting each test set its own
 *    option values without process-level isolation.
 *
 *  - dc_swp_get_exclusion_patterns() uses wp_cache_get() (stub: always miss)
 *    then get_option(), which reads from our test globals. This means every
 *    call re-reads the option, so setOption() between tests works correctly.
 *
 *  - Patterns are stored as a newline-separated string in the option
 *    'dc_swp_exclusion_patterns'. Wildcards use * (converted to .* regex).
 */
class UrlExclusionTest extends TestCase {

	// -----------------------------------------------------------------------
	// No patterns configured
	// -----------------------------------------------------------------------

	public function test_no_patterns_never_excludes(): void {
		$this->setOption( 'dc_swp_exclusion_patterns', '' );

		$this->assertFalse( dc_swp_is_excluded_url( '/shop' ) );
		$this->assertFalse( dc_swp_is_excluded_url( '/checkout' ) );
		$this->assertFalse( dc_swp_is_excluded_url( '/' ) );
	}

	// -----------------------------------------------------------------------
	// Literal (non-wildcard) pattern matching
	// -----------------------------------------------------------------------

	public function test_exact_literal_match_excludes_url(): void {
		$this->setOption( 'dc_swp_exclusion_patterns', '/checkout' );

		$this->assertTrue( dc_swp_is_excluded_url( '/checkout' ) );
	}

	public function test_literal_pattern_matches_as_substring(): void {
		// The pattern '/checkout' should match any URL that CONTAINS it.
		$this->setOption( 'dc_swp_exclusion_patterns', '/checkout' );

		$this->assertTrue( dc_swp_is_excluded_url( '/checkout/order-received/123/' ) );
	}

	public function test_literal_pattern_does_not_match_unrelated_url(): void {
		$this->setOption( 'dc_swp_exclusion_patterns', '/checkout' );

		$this->assertFalse( dc_swp_is_excluded_url( '/shop' ) );
		$this->assertFalse( dc_swp_is_excluded_url( '/' ) );
	}

	// -----------------------------------------------------------------------
	// Wildcard (*) pattern matching
	// -----------------------------------------------------------------------

	public function test_wildcard_pattern_matches_dynamic_segment(): void {
		$this->setOption( 'dc_swp_exclusion_patterns', '/my-account/*' );

		$this->assertTrue( dc_swp_is_excluded_url( '/my-account/orders/' ) );
		$this->assertTrue( dc_swp_is_excluded_url( '/my-account/edit-address/billing/' ) );
	}

	public function test_wildcard_pattern_does_not_match_unrelated_url(): void {
		$this->setOption( 'dc_swp_exclusion_patterns', '/my-account/*' );

		$this->assertFalse( dc_swp_is_excluded_url( '/shop' ) );
	}

	public function test_leading_wildcard_matches_any_prefix(): void {
		$this->setOption( 'dc_swp_exclusion_patterns', '*/thank-you/*' );

		$this->assertTrue( dc_swp_is_excluded_url( '/checkout/order-received/123/key/thank-you/' ) );
	}

	// -----------------------------------------------------------------------
	// Multiple patterns (newline-separated)
	// -----------------------------------------------------------------------

	public function test_first_of_multiple_patterns_triggers_exclusion(): void {
		$this->setOption( 'dc_swp_exclusion_patterns', "/checkout\n/my-account/*\n/landing-page" );

		$this->assertTrue( dc_swp_is_excluded_url( '/checkout' ) );
	}

	public function test_second_of_multiple_patterns_triggers_exclusion(): void {
		$this->setOption( 'dc_swp_exclusion_patterns', "/checkout\n/my-account/*\n/landing-page" );

		$this->assertTrue( dc_swp_is_excluded_url( '/my-account/orders/' ) );
	}

	public function test_url_matching_no_pattern_in_list_is_not_excluded(): void {
		$this->setOption( 'dc_swp_exclusion_patterns', "/checkout\n/my-account/*\n/landing-page" );

		$this->assertFalse( dc_swp_is_excluded_url( '/shop/product/blue-widget/' ) );
	}

	// -----------------------------------------------------------------------
	// Edge cases
	// -----------------------------------------------------------------------

	public function test_blank_lines_in_option_are_ignored(): void {
		// Extra blank lines between patterns must not cause false positives.
		$this->setOption( 'dc_swp_exclusion_patterns', "\n\n/checkout\n\n" );

		$this->assertTrue( dc_swp_is_excluded_url( '/checkout' ) );
		$this->assertFalse( dc_swp_is_excluded_url( '/shop' ) );
	}
}
