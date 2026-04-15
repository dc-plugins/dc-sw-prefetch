<?php

namespace DC_SW_Proxy\Tests\Unit;

/**
 * Tests for dc_swp_is_bot_request().
 *
 * The function reads $_SERVER['HTTP_USER_AGENT'], lower-cases it, and checks
 * for ~40 bot/crawler/speed-tester substrings. No WordPress functions are
 * involved beyond the sanitize_text_field / wp_unslash pass-throughs.
 */
class BotDetectionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Ensure no leftover UA from a previous test.
		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	// -----------------------------------------------------------------------
	// Missing / empty user agent
	// -----------------------------------------------------------------------

	public function test_missing_user_agent_is_not_a_bot(): void {
		$this->assertFalse( dc_swp_is_bot_request() );
	}

	public function test_empty_user_agent_is_not_a_bot(): void {
		$_SERVER['HTTP_USER_AGENT'] = '';
		$this->assertFalse( dc_swp_is_bot_request() );
	}

	// -----------------------------------------------------------------------
	// Real browser user agents (should NOT be flagged as bots)
	// -----------------------------------------------------------------------

	/** @dataProvider human_user_agents */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'human_user_agents' )]
	public function test_human_user_agents_are_not_bots( string $ua ): void {
		$_SERVER['HTTP_USER_AGENT'] = $ua;
		$this->assertFalse( dc_swp_is_bot_request() );
	}

	public static function human_user_agents(): array {
		return [
			'Chrome on Windows' => [
				'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
			],
			'Firefox on macOS' => [
				'Mozilla/5.0 (Macintosh; Intel Mac OS X 14.4; rv:125.0) Gecko/20100101 Firefox/125.0',
			],
			'Safari on iPhone' => [
				'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
			],
			'Edge on Windows' => [
				'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.0.0',
			],
		];
	}

	// -----------------------------------------------------------------------
	// Known crawlers and speed testers (should be flagged as bots)
	// -----------------------------------------------------------------------

	/** @dataProvider bot_user_agents */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'bot_user_agents' )]
	public function test_bot_user_agents_are_detected( string $ua ): void {
		$_SERVER['HTTP_USER_AGENT'] = $ua;
		$this->assertTrue( dc_swp_is_bot_request() );
	}

	public static function bot_user_agents(): array {
		return [
			'Googlebot' => [
				'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
			],
			'Bingbot' => [
				'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
			],
			'ClaudeBot (Anthropic)' => [
				'Mozilla/5.0 (compatible; ClaudeBot/1.0; +https://www.anthropic.com/claudebot)',
			],
			'GPTBot (OpenAI)' => [
				'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.1; +https://openai.com/gptbot)',
			],
			'AhrefsBot' => [
				'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)',
			],
			'GTmetrix' => [
				'Mozilla/5.0 (Windows NT 6.3; Win64; x64) GTmetrix/1.0 (gtmetrix.com)',
			],
			'Pingdom' => [
				'Pingdom.com_bot_version_1.4_(http://www.pingdom.com/)',
			],
			'HeadlessChrome' => [
				'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/124.0.0.0 Safari/537.36',
			],
		];
	}

	// -----------------------------------------------------------------------
	// Case insensitivity
	// -----------------------------------------------------------------------

	public function test_detection_is_case_insensitive(): void {
		// The function lowercases the UA before matching.
		$_SERVER['HTTP_USER_AGENT'] = 'GOOGLEBOT/2.1 (+http://www.google.com/bot.html)';
		$this->assertTrue( dc_swp_is_bot_request() );
	}

	public function test_mixed_case_speed_tester_is_detected(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'GTMetrix Speed Test Agent';
		$this->assertTrue( dc_swp_is_bot_request() );
	}
}
