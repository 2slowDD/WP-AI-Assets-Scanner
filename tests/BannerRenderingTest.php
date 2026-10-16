<?php
namespace DrSpeedAIAS\Tests;

use DRSPEED_AIAS_Broken_Banner;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Tests for DRSPEED_AIAS_Broken_Banner rendering + dismissal logic.
 */
class BannerRenderingTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// render() — no blocked pages → empty string
	// -------------------------------------------------------------------------

	public function test_no_banner_when_no_blocked_pages(): void {
		$html = DRSPEED_AIAS_Broken_Banner::render( [
			'scan_id'         => 'abc',
			'pages_blocked'   => [ 'desktop' => 0, 'mobile' => 0 ],
			'blocked_reasons' => [],
		] );

		$this->assertSame( '', $html );
	}

	// -------------------------------------------------------------------------
	// render() — desktop blocked with cf reason → banner with key strings
	// -------------------------------------------------------------------------

	public function test_desktop_blocked_banner_with_cf_reason(): void {
		// T0-C fix: this test inlined a PARTIAL mock set (no admin_url/esc_url/__), so it
		// died with "Call to undefined function admin_url()" the moment action_clause()
		// built the settings anchor — a pre-existing ERROR on origin/main (1.7.86b).
		// stub_render_helpers() already existed with the complete set; this test just
		// predated it. Test-harness gap only, no production behaviour involved.
		$this->stub_render_helpers();

		$html = DRSPEED_AIAS_Broken_Banner::render( [
			'scan_id'         => 'abc',
			'pages_blocked'   => [ 'desktop' => 5, 'mobile' => 0 ],
			'blocked_reasons' => [ 'tier2_cf_challenge' => 5 ],
			'total_pages'     => 10,
		] );

		$this->assertStringContainsString( 'Desktop scanner blocked on 5 of', $html );
		$this->assertStringContainsString( 'Cloudflare', $html );
		$this->assertStringContainsString( 'temporarily disable bot protection', $html );
	}

	/** @param mixed $attribution */
	private function rate_payload( $attribution ): array {
		return [
			'scan_id'                => 'rate-scan',
			'pages_blocked'          => [ 'desktop' => 1, 'mobile' => 0 ],
			'blocked_reasons'        => [ 'tier1_http_rate_limit' => 1 ],
			'total_pages'            => 3,
			'rate_limit_attribution' => $attribution,
		];
	}

	// -------------------------------------------------------------------------
	// T0-C — normalize_attribution(): allowlist, not sanitizer
	// -------------------------------------------------------------------------

	public function test_normalize_attribution_allowlists_known_values(): void {
		foreach ( DRSPEED_AIAS_Broken_Banner::ATTRIBUTION_ALLOWED as $member ) {
			$this->assertSame( $member, DRSPEED_AIAS_Broken_Banner::normalize_attribution( $member ) );
		}
	}

	public function test_normalize_attribution_rejects_everything_else(): void {
		foreach ( [ '', null, 'garbage', '<script>alert(1)</script>', 'HOST', 0, [], true ] as $bad ) {
			$this->assertSame( 'unknown', DRSPEED_AIAS_Broken_Banner::normalize_attribution( $bad ) );
		}
	}

	// -------------------------------------------------------------------------
	// T0-C — the rate branch names the party that actually throttled the scan
	// -------------------------------------------------------------------------

	public function test_rate_banner_names_cloudflare_and_keeps_link(): void {
		$this->stub_render_helpers();

		$html = DRSPEED_AIAS_Broken_Banner::render( $this->rate_payload( 'cloudflare' ) );

		$this->assertStringContainsString( 'Desktop scanner blocked on 1 of 3 pages. (rate limit (429)) Cloudflare rate-limited the scan', $html );
		$this->assertStringContainsString( 'cu-cloudflare-waf-bypass', $html );
		$this->assertStringNotContainsString( 'Your server rate-limited', $html );
		$this->assertStringNotContainsString( 'ready to', $html ); // pages_one_device_with_rules absent → no tail.
	}

	public function test_rate_banner_names_host_and_drops_the_cdn_link(): void {
		$this->stub_render_helpers();

		$html = DRSPEED_AIAS_Broken_Banner::render( $this->rate_payload( 'host' ) );

		$this->assertStringContainsString( "Desktop scanner blocked on 1 of 3 pages. (rate limit (429)) Your host's server rate-limited the scan", $html );
		$this->assertStringContainsString( 'will not help here', $html );
		$this->assertStringNotContainsString( 'cu-cloudflare-waf-bypass', $html );
		$this->assertStringNotContainsString( 'ready to', $html ); // pages_one_device_with_rules absent → no tail.
	}

	public function test_rate_banner_unknown_variant_is_the_default_for_everything_else(): void {
		// Stub once, outside the loop — re-registering the same WP_Mock userFunction
		// expectations on each iteration errors out.
		$this->stub_render_helpers();

		foreach ( [ 'akamai', 'imperva', 'waf', 'unknown', 'garbage', null ] as $attr ) {
			$label = null === $attr ? 'null' : (string) $attr;
			$html  = DRSPEED_AIAS_Broken_Banner::render( $this->rate_payload( $attr ) );

			$this->assertStringContainsString( 'Desktop scanner blocked on 1 of 3 pages. (rate limit (429)) The scan was rate-limited', $html, "attr={$label}" );
			$this->assertStringContainsString( 'cu-cloudflare-waf-bypass', $html, "attr={$label}" );
			$this->assertStringNotContainsString( 'Cloudflare rate-limited the scan', $html, "attr={$label}" );
			$this->assertStringNotContainsString( 'ready to', $html, "attr={$label}" ); // no tail without rules.
		}
	}

	public function test_non_rate_branch_is_identical_with_and_without_the_field(): void {
		$this->stub_render_helpers();

		$base = [
			'scan_id'         => 'err-scan',
			'pages_blocked'   => [ 'desktop' => 1, 'mobile' => 0 ],
			'blocked_reasons' => [ 'tier1_http_5xx' => 1 ],
			'total_pages'     => 3,
		];

		$without = DRSPEED_AIAS_Broken_Banner::render( $base );
		$with    = DRSPEED_AIAS_Broken_Banner::render( $base + [ 'rate_limit_attribution' => 'cloudflare' ] );

		$this->assertSame( $without, $with );
		$this->assertStringContainsString( 'returned an error', $without );
	}

	// -------------------------------------------------------------------------
	// render() — dismissed scan → empty string
	// -------------------------------------------------------------------------

	public function test_dismissed_banner_returns_empty_html(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( DRSPEED_AIAS_Broken_Banner::OPTION_DISMISSALS, [] )
			->andReturn( [ 'abc' => true ] );

		$html = DRSPEED_AIAS_Broken_Banner::render( [
			'scan_id'         => 'abc',
			'pages_blocked'   => [ 'desktop' => 5, 'mobile' => 0 ],
			'blocked_reasons' => [ 'tier2_cf_challenge' => 5 ],
		] );

		$this->assertSame( '', $html );
	}

	// -------------------------------------------------------------------------
	// on_submit_job() — wipes all dismissals
	// -------------------------------------------------------------------------

	public function test_submit_job_wipes_all_dismissals(): void {
		$called = false;
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( DRSPEED_AIAS_Broken_Banner::OPTION_DISMISSALS, [], false )
			->andReturnUsing( function () use ( &$called ) { $called = true; return true; } );

		DRSPEED_AIAS_Broken_Banner::on_submit_job();

		$this->assertTrue( $called, 'update_option must be called to wipe dismissals' );
	}

	// -------------------------------------------------------------------------
	// reason_copy() per-reason action clause — rate-limit gets cadence guidance,
	// not bot-protection guidance (the original copy was misleading for 429s).
	// -------------------------------------------------------------------------

	private function stub_render_helpers(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( DRSPEED_AIAS_Broken_Banner::OPTION_DISMISSALS, [] )
			->andReturn( [] );
		WP_Mock::userFunction( 'esc_attr' )->andReturnArg( 0 );
		WP_Mock::userFunction( 'esc_html__' )->andReturnArg( 0 );
		WP_Mock::userFunction( 'esc_html_e' )->andReturnUsing( function ( $t ) { echo $t; } );
		WP_Mock::userFunction( 'esc_html' )->andReturnArg( 0 );
		WP_Mock::userFunction( 'wp_kses_post' )->andReturnArg( 0 );
		WP_Mock::userFunction( '__' )->andReturnArg( 0 );
		// export_copy_map() builds each errored [singular, plural] pair through _n().
		WP_Mock::userFunction( '_n' )->andReturnUsing( function ( $s, $p, $n, $d ) { return 1 === $n ? $s : $p; } );
		WP_Mock::userFunction( 'esc_url' )->andReturnArg( 0 );
		WP_Mock::userFunction( 'admin_url' )->andReturnArg( 0 );
	}

	public function test_rate_limit_alone_uses_cadence_action_clause(): void {
		$this->stub_render_helpers();

		$html = DRSPEED_AIAS_Broken_Banner::render( [
			'scan_id'         => 'abc',
			'pages_blocked'   => [ 'desktop' => 2, 'mobile' => 0 ],
			'blocked_reasons' => [ 'tier1_http_rate_limit' => 2 ],
			'total_pages'     => 5,
		] );

		$this->assertStringContainsString( 'rate-limited', $html );
		$this->assertStringContainsString( 'between scans', $html );
		$this->assertStringNotContainsString( 'bot protection denied', $html );
		// CDN-exemption solution + settings deep-link must appear on 429 banners.
		// T0-C: this payload carries no `rate_limit_attribution`, so it takes the UNKNOWN
		// variant. The old assertion pinned "rate-limit exemption" — copy that promised a
		// CDN exemption would fix a limit we cannot attribute. It now hedges instead.
		$this->assertStringContainsString( 'allowlist the scanner', $html );
		$this->assertStringContainsString( 'cu-cloudflare-waf-bypass', $html );
		$this->assertStringNotContainsString( 'Your server rate-limited', $html );
	}

	public function test_server_error_alone_uses_retry_action_clause(): void {
		$this->stub_render_helpers();

		$html = DRSPEED_AIAS_Broken_Banner::render( [
			'scan_id'         => 'abc',
			'pages_blocked'   => [ 'desktop' => 1, 'mobile' => 1 ],
			'blocked_reasons' => [ 'tier1_http_5xx' => 2 ],
			'total_pages'     => 5,
		] );

		$this->assertStringContainsString( 'didn\'t respond', $html );
		$this->assertStringNotContainsString( 'bot protection denied', $html );
		// Errored-page copy §3.3: blocked sentences lead; no survivor tail without
		// pages_one_device_with_rules, and the old "unblocked device" sentence is gone.
		$this->assertStringContainsString( 'Desktop scanner blocked on 1 of 5 pages. Mobile scanner blocked on 1 of 5 pages.', $html );
		$this->assertStringNotContainsString( 'ready to', $html );
		$this->assertStringNotContainsString( 'unblocked device', $html );
	}

	public function test_mixed_reasons_falls_back_to_bot_protection_clause(): void {
		$this->stub_render_helpers();

		$html = DRSPEED_AIAS_Broken_Banner::render( [
			'scan_id'         => 'abc',
			'pages_blocked'   => [ 'desktop' => 3, 'mobile' => 0 ],
			'blocked_reasons' => [
				'tier1_http_rate_limit' => 1,
				'tier2_cf_challenge'    => 2,
			],
			'total_pages'     => 5,
		] );

		// Mixed-category reasons fall back to the generic bot-protection clause
		// to avoid misleading single-cause guidance.
		$this->assertStringContainsString( 'bot protection denied', $html );
		// CDN-exemption solution also appears when rate is one of the reasons (mixed scan).
		$this->assertStringContainsString( 'rate-limit exemption', $html );
		$this->assertStringContainsString( 'cu-cloudflare-waf-bypass', $html );
	}

	// -------------------------------------------------------------------------
	// tier2_waf_challenge → 'firewall/WAF' phrase, bot category
	// -------------------------------------------------------------------------

	public function test_waf_challenge_shows_firewall_phrase_and_bot_action(): void {
		$this->stub_render_helpers();

		$html = DRSPEED_AIAS_Broken_Banner::render( [
			'scan_id'         => 'waf-test',
			'pages_blocked'   => [ 'desktop' => 1, 'mobile' => 0 ],
			'blocked_reasons' => [ 'tier2_waf_challenge' => 1 ],
			'total_pages'     => 1,
		] );

		$this->assertStringContainsString( 'firewall/WAF', $html );
		$this->assertStringContainsString( 'temporarily disable bot protection', $html );
		$this->assertStringNotContainsString( 'rate-limited', $html );
	}

	// -------------------------------------------------------------------------
	// tier2_unknown_challenge → 'unidentified' phrase, bot category
	// -------------------------------------------------------------------------

	public function test_unknown_challenge_shows_unidentified_phrase_and_bot_action(): void {
		$this->stub_render_helpers();

		$html = DRSPEED_AIAS_Broken_Banner::render( [
			'scan_id'         => 'unk-test',
			'pages_blocked'   => [ 'desktop' => 1, 'mobile' => 0 ],
			'blocked_reasons' => [ 'tier2_unknown_challenge' => 1 ],
			'total_pages'     => 1,
		] );

		$this->assertStringContainsString( 'unidentified', $html );
		$this->assertStringContainsString( 'temporarily disable bot protection', $html );
		$this->assertStringNotContainsString( 'rate-limited', $html );
	}

	// -------------------------------------------------------------------------
	// Errored-page copy spec §3.3 — the body is composed from export_copy_map():
	// blocked sentence(s) → action clause → survivor tail → errored sentence(s).
	// Each test stubs once (re-registering WP_Mock userFunctions in one test errors).
	// -------------------------------------------------------------------------

	private function base( array $over ): array {
		return array_merge( [ 'scan_id' => 's', 'total_pages' => 3, 'pages_blocked' => [ 'desktop' => 0, 'mobile' => 0 ], 'blocked_reasons' => [], 'blocked_stacks' => [],
			'pages_errored' => [ 'count' => 0, 'reasons' => [] ], 'pages_one_device' => 0, 'pages_one_device_with_rules' => 0, 'rate_limit_attribution' => null, 'push_ready' => true ], $over );
	}

	public function test_errored_only_renders_time_limit_sentence_and_nothing_false(): void {   // G2 + Review Focus 4
		$this->stub_render_helpers();
		$html = DRSPEED_AIAS_Broken_Banner::render( $this->base( [ 'pages_errored' => [ 'count' => 1, 'reasons' => [ 'page_time_limit' => 1 ] ] ] ) );
		$this->assertStringContainsString( '1 page hit the scanner', $html );
		$this->assertStringContainsString( 'time limit', $html );
		$this->assertStringNotContainsString( 'blocked', strtolower( strip_tags( $html ) ) );
		$this->assertStringNotContainsString( 'server', strtolower( $html ) );
		$this->assertStringNotContainsString( 'ready to', $html );
	}

	public function test_plural_picks_second_form_at_two(): void {
		$this->stub_render_helpers();
		$html = DRSPEED_AIAS_Broken_Banner::render( $this->base( [ 'pages_errored' => [ 'count' => 2, 'reasons' => [ 'analysis_failed' => 2 ] ] ] ) );
		$this->assertStringContainsString( '2 pages weren', $html );
	}

	public function test_both_blocked_has_no_tail_and_uses_bot_both(): void {
		$this->stub_render_helpers();
		$html = DRSPEED_AIAS_Broken_Banner::render( $this->base( [ 'pages_blocked' => [ 'desktop' => 1, 'mobile' => 1 ], 'blocked_reasons' => [ 'tier2_cf_challenge' => 2 ] ] ) );
		$this->assertStringContainsString( 'For full coverage', $html );
		$this->assertStringNotContainsString( 'ready to', $html );
		$this->assertStringNotContainsString( 'unblocked device', $html );
	}

	public function test_one_device_with_rules_has_device_named_clause_and_push_tail(): void {
		$this->stub_render_helpers();
		$html = DRSPEED_AIAS_Broken_Banner::render( $this->base( [ 'pages_blocked' => [ 'desktop' => 1, 'mobile' => 0 ], 'blocked_reasons' => [ 'tier2_cf_challenge' => 1 ], 'pages_one_device' => 1, 'pages_one_device_with_rules' => 1 ] ) );
		$this->assertStringContainsString( 'denied the desktop scanner. For desktop rules too', $html );
		$this->assertStringContainsString( 'ready to Push or Sync', $html );
		$dl = DRSPEED_AIAS_Broken_Banner::render( $this->base( [ 'pages_blocked' => [ 'desktop' => 1, 'mobile' => 0 ], 'blocked_reasons' => [ 'tier2_cf_challenge' => 1 ], 'pages_one_device' => 1, 'pages_one_device_with_rules' => 1, 'push_ready' => false ] ) );
		$this->assertStringContainsString( 'ready to download', $dl );
	}

	public function test_one_device_without_rules_has_no_tail(): void {
		$this->stub_render_helpers();
		$html = DRSPEED_AIAS_Broken_Banner::render( $this->base( [ 'pages_blocked' => [ 'desktop' => 1, 'mobile' => 0 ], 'blocked_reasons' => [ 'tier2_cf_challenge' => 1 ], 'pages_one_device' => 1, 'pages_one_device_with_rules' => 0 ] ) );
		$this->assertStringNotContainsString( 'ready to', $html );
	}

	public function test_siteground_only_replaces_phrase_and_uses_siteground_clause(): void {
		$this->stub_render_helpers();
		$html = DRSPEED_AIAS_Broken_Banner::render( $this->base( [ 'pages_blocked' => [ 'desktop' => 1, 'mobile' => 1 ], 'blocked_reasons' => [ 'tier1_zero_bytes' => 2 ], 'blocked_stacks' => [ 'siteground' => 2 ] ] ) );
		$this->assertStringContainsString( '(SiteGround bot protection)', $html );
		$this->assertStringNotContainsString( 'empty response', $html );
		$this->assertStringContainsString( 'SiteGround Site Tools', $html );
		$this->assertStringNotContainsString( 'cu-cloudflare-waf-bypass', $html );
	}

	public function test_siteground_mixed_adds_phrase_and_keeps_generic_clause(): void {
		$this->stub_render_helpers();
		$html = DRSPEED_AIAS_Broken_Banner::render( $this->base( [ 'pages_blocked' => [ 'desktop' => 2, 'mobile' => 0 ], 'blocked_reasons' => [ 'tier1_zero_bytes' => 2 ], 'blocked_stacks' => [ 'siteground' => 1 ] ] ) );
		$this->assertStringContainsString( 'SiteGround bot protection', $html );
		$this->assertStringContainsString( 'empty response', $html );
		$this->assertStringNotContainsString( 'Site Tools', $html );
	}

	public function test_order_is_blocked_action_tail_errored(): void {
		$this->stub_render_helpers();
		$html = strip_tags( DRSPEED_AIAS_Broken_Banner::render( $this->base( [ 'pages_blocked' => [ 'desktop' => 1, 'mobile' => 0 ], 'blocked_reasons' => [ 'tier2_cf_challenge' => 1 ], 'pages_one_device' => 1, 'pages_one_device_with_rules' => 1, 'pages_errored' => [ 'count' => 1, 'reasons' => [ 'unknown' => 1 ] ] ] ) ) );
		// strrpos for "couldn": the heading ("Some pages couldn't be fully scanned") also
		// contains it and precedes the body; the LAST hit is the errored sentence.
		$this->assertTrue( strpos( $html, 'blocked on' ) < strpos( $html, 'denied the' ) && strpos( $html, 'denied the' ) < strpos( $html, 'ready to' ) && strpos( $html, 'ready to' ) < strrpos( $html, "couldn" ) );
	}

	public function test_no_fixture_renders_the_old_sentence(): void {
		$this->stub_render_helpers();
		foreach ( [ [ 'tier2_cf_challenge' => 1 ], [ 'tier1_http_5xx' => 1 ], [ 'tier1_http_rate_limit' => 1 ] ] as $reasons ) {
			$html = DRSPEED_AIAS_Broken_Banner::render( $this->base( [ 'pages_blocked' => [ 'desktop' => 1, 'mobile' => 0 ], 'blocked_reasons' => $reasons ] ) );
			$this->assertStringNotContainsString( 'unblocked device', $html );
		}
	}

	// compose_body() is the pure core Task 8's JS is diffed against: pin whole bodies.
	public function test_compose_body_exact_text_for_parity_fixtures(): void {
		$this->stub_render_helpers();
		$map  = DRSPEED_AIAS_Broken_Banner::export_copy_map();
		$link = '<a href="admin.php?page=drspeed-aias-settings#cu-cloudflare-waf-bypass">open AI Assets Scanner settings</a>';
		$this->assertSame(
			'1 page hit the scanner\'s time limit and wasn\'t finished. Re-scan it later.',
			DRSPEED_AIAS_Broken_Banner::compose_body( $this->base( [ 'pages_errored' => [ 'count' => 1, 'reasons' => [ 'page_time_limit' => 1 ] ] ] ), $map )
		);
		$this->assertSame(
			'Desktop scanner blocked on 1 of 3 pages. (Cloudflare challenge) Your bot protection denied the desktop scanner. For desktop rules too, temporarily disable bot protection during scans and re-scan. Behind Cloudflare or another CDN? Set up the scanner exemption so future scans aren\'t blocked — ' . $link . '. Rules from the device that got through are ready to Push or Sync.',
			DRSPEED_AIAS_Broken_Banner::compose_body( $this->base( [ 'pages_blocked' => [ 'desktop' => 1, 'mobile' => 0 ], 'blocked_reasons' => [ 'tier2_cf_challenge' => 1 ], 'pages_one_device' => 1, 'pages_one_device_with_rules' => 1 ] ), $map )
		);
		$this->assertSame(
			'Desktop scanner blocked on 1 of 3 pages. Mobile scanner blocked on 1 of 3 pages. (Cloudflare challenge) Your bot protection denied the scanner. For full coverage, temporarily disable bot protection during scans and re-scan. Behind Cloudflare or another CDN? Set up the scanner exemption so future scans aren\'t blocked — ' . $link . '.',
			DRSPEED_AIAS_Broken_Banner::compose_body( $this->base( [ 'pages_blocked' => [ 'desktop' => 1, 'mobile' => 1 ], 'blocked_reasons' => [ 'tier2_cf_challenge' => 2 ] ] ), $map )
		);
		$this->assertSame(
			'Desktop scanner blocked on 1 of 3 pages. Mobile scanner blocked on 1 of 3 pages. (SiteGround bot protection) SiteGround\'s bot protection denied the scanner. Turn off the anti-bot protection in SiteGround Site Tools while the scan runs, or ask SiteGround support to allow the scanner, then re-scan.',
			DRSPEED_AIAS_Broken_Banner::compose_body( $this->base( [ 'pages_blocked' => [ 'desktop' => 1, 'mobile' => 1 ], 'blocked_reasons' => [ 'tier1_zero_bytes' => 2 ], 'blocked_stacks' => [ 'siteground' => 2 ] ] ), $map )
		);
	}

	// Final-review M5: whole bodies for every remaining action branch. The SAME payloads and
	// literals are in tests/js/banner-errored.test.js › PARITY, so JS ≡ PHP on each branch.
	public function test_compose_body_exact_text_for_remaining_branches(): void {
		$this->stub_render_helpers();
		$map   = DRSPEED_AIAS_Broken_Banner::export_copy_map();
		$link  = '<a href="admin.php?page=drspeed-aias-settings#cu-cloudflare-waf-bypass">open AI Assets Scanner settings</a>';
		$cases = [
			'rate_cf'      => [
				[ 'pages_blocked' => [ 'desktop' => 1, 'mobile' => 0 ], 'blocked_reasons' => [ 'tier1_http_rate_limit' => 1 ], 'rate_limit_attribution' => 'cloudflare' ],
				'Desktop scanner blocked on 1 of 3 pages. (rate limit (429)) Cloudflare rate-limited the scan. Whoever manages your Cloudflare — you, your host, or your agency — needs to allowlist the scanner. If you manage the Cloudflare account yourself, set up the one-time scanner exemption — ' . $link . '.',
			],
			'rate_host'    => [
				[ 'pages_blocked' => [ 'desktop' => 1, 'mobile' => 0 ], 'blocked_reasons' => [ 'tier1_http_rate_limit' => 1 ], 'rate_limit_attribution' => 'host' ],
				'Desktop scanner blocked on 1 of 3 pages. (rate limit (429)) Your host\'s server rate-limited the scan. A CDN or WAF exemption will not help here. Wait a few minutes between scans, or ask your host to raise the rate limit during scans.',
			],
			'rate_unknown' => [
				[ 'pages_blocked' => [ 'desktop' => 0, 'mobile' => 1 ], 'blocked_reasons' => [ 'tier1_http_rate_limit' => 1 ] ],
				'Mobile scanner blocked on 1 of 3 pages. (rate limit (429)) The scan was rate-limited. Wait a few minutes between scans. If a CDN or WAF sits in front of your site, ask whoever manages it to allowlist the scanner; otherwise check your own server\'s rate limits — ' . $link . '.',
			],
			'error'        => [
				[ 'pages_blocked' => [ 'desktop' => 1, 'mobile' => 0 ], 'blocked_reasons' => [ 'tier1_http_5xx' => 1 ] ],
				'Desktop scanner blocked on 1 of 3 pages. (site error (5xx)) Your server returned an error or didn\'t respond. Try again later, or check site health.',
			],
			'mixed_rate_bot' => [
				[ 'pages_blocked' => [ 'desktop' => 1, 'mobile' => 1 ], 'blocked_reasons' => [ 'tier1_http_rate_limit' => 1, 'tier2_cf_challenge' => 1 ] ],
				'Desktop scanner blocked on 1 of 3 pages. Mobile scanner blocked on 1 of 3 pages. (rate limit (429), Cloudflare challenge) Your bot protection denied the scanner. For full coverage, temporarily disable bot protection during scans and re-scan. Behind Cloudflare or another CDN? Set up the scanner rate-limit exemption so future scans aren\'t throttled — ' . $link . '.',
			],
			'siteground_add' => [
				[ 'pages_blocked' => [ 'desktop' => 2, 'mobile' => 0 ], 'blocked_reasons' => [ 'tier1_zero_bytes' => 2 ], 'blocked_stacks' => [ 'siteground' => 1 ] ],
				'Desktop scanner blocked on 2 of 3 pages. (empty response, SiteGround bot protection) Your bot protection denied the desktop scanner. For desktop rules too, temporarily disable bot protection during scans and re-scan. Behind Cloudflare or another CDN? Set up the scanner exemption so future scans aren\'t blocked — ' . $link . '.',
			],
			'two_errored_kinds' => [
				[ 'pages_errored' => [ 'count' => 3, 'reasons' => [ 'page_time_limit' => 1, 'analysis_failed' => 2 ] ] ],
				'1 page hit the scanner\'s time limit and wasn\'t finished. Re-scan it later. 2 pages weren\'t finished (scanner error). Re-scan them later.',
			],
		];
		foreach ( $cases as $name => [ $payload, $expected ] ) {
			$this->assertSame( $expected, DRSPEED_AIAS_Broken_Banner::compose_body( $this->base( $payload ), $map ), $name );
		}
	}

	// Final-review M4: no SiteGround entry (0 === 0 reason entries) must NOT pick the SiteGround
	// clause — the `$siteground > 0` guard on the action condition is what prevents it.
	public function test_blocked_without_reasons_or_stacks_uses_bot_clause_not_siteground(): void {
		$this->stub_render_helpers();
		$body = DRSPEED_AIAS_Broken_Banner::compose_body(
			$this->base( [ 'pages_blocked' => [ 'desktop' => 1, 'mobile' => 0 ], 'blocked_reasons' => [], 'blocked_stacks' => [], 'pages_one_device' => 0 ] ),
			DRSPEED_AIAS_Broken_Banner::export_copy_map()
		);
		$this->assertStringContainsString( 'Your bot protection denied the desktop scanner', $body );
		$this->assertStringNotContainsString( 'SiteGround', $body );
	}

	// Ruling 6/7: worker values are untrusted — non-numeric counts read as 0, list-shaped
	// ([]) or scalar sub-maps are tolerated, and stale reasons without blocked pages say nothing.
	public function test_malformed_payload_shapes_degrade_quietly(): void {
		$this->stub_render_helpers();
		$map = DRSPEED_AIAS_Broken_Banner::export_copy_map();
		$this->assertSame( '', DRSPEED_AIAS_Broken_Banner::compose_body( $this->base( [ 'pages_blocked' => 'x', 'blocked_reasons' => [ 'tier1_http_5xx' => 1 ], 'blocked_stacks' => 'x', 'pages_errored' => 'x' ] ), $map ) );
		$body = DRSPEED_AIAS_Broken_Banner::compose_body( $this->base( [ 'pages_blocked' => [ 'desktop' => 1, 'mobile' => 0 ], 'blocked_reasons' => [ 'tier2_cf_challenge' => 1 ], 'pages_one_device_with_rules' => 'yes' ] ), $map );
		$this->assertStringNotContainsString( 'ready to', $body );
		$this->assertSame( '', DRSPEED_AIAS_Broken_Banner::render( $this->base( [ 'pages_errored' => [ 'count' => 'many', 'reasons' => [] ] ] ) ) );
		// An unrecognised errored kind (future worker enum member) reads as "unknown".
		$this->assertSame( '1 page couldn\'t be finished. Re-scan it later.', DRSPEED_AIAS_Broken_Banner::compose_body( $this->base( [ 'pages_errored' => [ 'count' => 1, 'reasons' => [ 'kind_from_the_future' => 1 ] ] ] ), $map ) );
	}
}
