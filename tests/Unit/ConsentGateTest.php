<?php

namespace DC_SW_Proxy\Tests\Unit;

/**
 * Tests for dc_swp_has_consent_for() and dc_swp_is_consent_gate_enabled().
 *
 * dc_swp_has_consent_for() has three branches:
 *   1. Gate disabled  → always return true (load script unconditionally).
 *   2. Gate enabled + wp_has_consent() available → delegate to Consent API.
 *   3. Gate enabled + wp_has_consent() NOT available → return false (fail-closed).
 *
 * In unit tests, wp_has_consent() is defined by tests/stubs/wordpress.php so
 * branch 3 is never reachable. Consent state is controlled per-category via
 * TestCase::setConsent(). Branch 3 is covered by the integration environment
 * where the WP Consent API plugin is absent.
 */
class ConsentGateTest extends TestCase {

	// -----------------------------------------------------------------------
	// Gate disabled (default setting)
	// -----------------------------------------------------------------------

	public function test_gate_disabled_always_allows_regardless_of_consent(): void {
		$this->setOption( 'dc_swp_consent_gate', 'no' );
		// Even when the visitor has withheld consent, the gate being off means allow.
		$this->setConsent( 'marketing', false );

		$this->assertTrue( dc_swp_has_consent_for( 'marketing' ) );
	}

	public function test_gate_disabled_is_the_default_when_option_absent(): void {
		// get_option returns false (our stub default) when the key is absent.
		// The function treats anything !== 'yes' as disabled.
		$this->assertTrue( dc_swp_has_consent_for( 'marketing' ) );
	}

	// -----------------------------------------------------------------------
	// Gate enabled — delegates to WP Consent API
	// -----------------------------------------------------------------------

	public function test_gate_enabled_blocks_when_consent_is_denied(): void {
		$this->setOption( 'dc_swp_consent_gate', 'yes' );
		$this->setConsent( 'marketing', false );

		$this->assertFalse( dc_swp_has_consent_for( 'marketing' ) );
	}

	public function test_gate_enabled_allows_when_consent_is_granted(): void {
		$this->setOption( 'dc_swp_consent_gate', 'yes' );
		$this->setConsent( 'marketing', true );

		$this->assertTrue( dc_swp_has_consent_for( 'marketing' ) );
	}

	public function test_gate_checks_each_category_independently(): void {
		$this->setOption( 'dc_swp_consent_gate', 'yes' );
		$this->setConsent( 'marketing',  true  );
		$this->setConsent( 'statistics', false );
		$this->setConsent( 'functional', true  );

		$this->assertTrue(  dc_swp_has_consent_for( 'marketing' ) );
		$this->assertFalse( dc_swp_has_consent_for( 'statistics' ) );
		$this->assertTrue(  dc_swp_has_consent_for( 'functional' ) );
	}

	public function test_gate_covers_all_valid_wp_consent_api_categories(): void {
		$this->setOption( 'dc_swp_consent_gate', 'yes' );

		$categories = [ 'marketing', 'statistics', 'statistics-anonymous', 'functional', 'preferences' ];

		foreach ( $categories as $category ) {
			$this->setConsent( $category, true );
			$this->assertTrue(
				dc_swp_has_consent_for( $category ),
				"Expected consent to be allowed for category: {$category}"
			);

			$this->setConsent( $category, false );
			$this->assertFalse(
				dc_swp_has_consent_for( $category ),
				"Expected consent to be blocked for category: {$category}"
			);
		}
	}

	// -----------------------------------------------------------------------
	// dc_swp_is_consent_gate_enabled() directly
	// -----------------------------------------------------------------------

	public function test_consent_gate_enabled_returns_true_when_option_is_yes(): void {
		$this->setOption( 'dc_swp_consent_gate', 'yes' );
		$this->assertTrue( dc_swp_is_consent_gate_enabled() );
	}

	public function test_consent_gate_enabled_returns_false_when_option_is_no(): void {
		$this->setOption( 'dc_swp_consent_gate', 'no' );
		$this->assertFalse( dc_swp_is_consent_gate_enabled() );
	}

	public function test_consent_gate_enabled_returns_false_when_option_absent(): void {
		// No option set → get_option returns its default of false → not 'yes'.
		$this->assertFalse( dc_swp_is_consent_gate_enabled() );
	}
}
