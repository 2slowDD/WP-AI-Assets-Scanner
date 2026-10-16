<?php
use PHPUnit\Framework\TestCase;

final class ScanStatusTest extends TestCase {
    private function page( array $o ): array {
        return array_merge( [ 'url' => 'https://x/', 'status' => 'done', 'assets' => [], 'broken_devices' => [] ], $o );
    }
    public function test_ok_clean_done_page(): void {
        $r = DRSPEED_AIAS_Scan_Status::classify( $this->page([]) );
        $this->assertSame( 'ok', $r['class'] );
        $this->assertSame( 'OK', $r['label'] );
        $this->assertSame( 1, $r['credits'] );
    }
    public function test_partial_one_device_rate_fail(): void {
        $r = DRSPEED_AIAS_Scan_Status::classify( $this->page([ 'broken_devices' => [ [ 'device' => 'mobile', 'reason' => 'tier1_http_rate_limit' ] ] ]) );
        $this->assertSame( 'partial', $r['class'] );
        $this->assertSame( 1, $r['credits'] );
        $this->assertStringContainsString( 'Mobile not scanned', $r['label'] );
        $this->assertStringContainsString( 'rate limit (429)', $r['label'] );
    }
    public function test_blocked_bot_protection(): void {
        // Spec §3.2.4: a bot block on ONE device of a done page is a partial row (the other device got through).
        $r = DRSPEED_AIAS_Scan_Status::classify( $this->page([ 'broken_devices' => [ [ 'device' => 'desktop', 'reason' => 'tier2_cf_challenge' ] ] ]) );
        $this->assertSame( 'partial', $r['class'] );
        $this->assertStringContainsString( 'Cloudflare challenge', $r['label'] );
    }
    public function test_error_zero_credit(): void {
        $r = DRSPEED_AIAS_Scan_Status::classify( $this->page([ 'status' => 'error', 'broken_devices' => [ [ 'device' => 'desktop', 'reason' => 'tier1_http_5xx' ] ] ]) );
        $this->assertSame( 'error', $r['class'] );
        $this->assertSame( 0, $r['credits'] );
        $this->assertStringContainsString( '5xx', $r['label'] );
    }
    public function test_bot_block_precedence_over_error_status(): void {
        $r = DRSPEED_AIAS_Scan_Status::classify( $this->page([ 'status' => 'error', 'broken_devices' => [ [ 'device' => 'desktop', 'reason' => 'tier2_imperva_challenge' ] ] ]) );
        $this->assertSame( 'blocked', $r['class'] );
    }
    public function test_is_broken_without_reason_is_not_affected(): void {
        $r = DRSPEED_AIAS_Scan_Status::classify( $this->page([ 'broken_devices' => [ [ 'device' => 'mobile', 'is_broken' => true, 'reason' => '' ] ] ]) );
        $this->assertSame( 'ok', $r['class'] );
    }
    public function test_origin_unavailable_is_skipped_not_ok_and_zero_credits(): void {
        $out = DRSPEED_AIAS_Scan_Status::classify( [ 'url' => 'https://x/', 'status' => 'origin_unavailable' ] );
        $this->assertSame( 'skipped', $out['class'] );
        $this->assertSame( 0, $out['credits'] );
        $this->assertStringContainsString( 'Origin unavailable', $out['label'] );
    }
    public function test_build_pages_merges_status_and_tallies_by_index(): void {
        $pages_raw = [
            [ 'url' => 'https://x/a', 'status' => 'done',  'broken_devices' => [] ],
            [ 'url' => 'https://x/b', 'status' => 'error', 'broken_devices' => [] ],
        ];
        $by_page = [ 0 => [ 'safe' => 5, 'aggressive' => 2, 'needed' => 18 ] ]; // index 1 (error) absent
        $rows = DRSPEED_AIAS_Scan_Status::build_pages( $pages_raw, $by_page );
        $this->assertCount( 2, $rows );
        $this->assertSame( 1, $rows[0]['n'] );
        $this->assertSame( 'https://x/a', $rows[0]['url'] );
        $this->assertSame( 'ok', $rows[0]['status_class'] );
        $this->assertSame( [ 5, 2, 18 ], [ $rows[0]['safe'], $rows[0]['aggressive'], $rows[0]['needed'] ] );
        $this->assertSame( 2, $rows[1]['n'] );
        $this->assertSame( 'error', $rows[1]['status_class'] );
        $this->assertSame( 0, $rows[1]['credits'] );
        $this->assertSame( [ 0, 0, 0 ], [ $rows[1]['safe'], $rows[1]['aggressive'], $rows[1]['needed'] ] ); // error → 0 (render as —)
    }
    public function test_et_candidate_ok_with_bail_flags_true(): void {
        $rows = DRSPEED_AIAS_Scan_Status::build_pages(
            [ [ 'url' => 'https://x/', 'status' => 'done', 'broken_devices' => [], 'deadline_bail_count' => 2 ] ],
            []
        );
        $this->assertTrue( $rows[0]['et_candidate'] ); // ok + count>0 → yes (AC-ET-5)
    }
    public function test_et_candidate_ok_no_bail_is_false(): void {
        $rows = DRSPEED_AIAS_Scan_Status::build_pages(
            [ [ 'url' => 'https://x/', 'status' => 'done', 'broken_devices' => [], 'deadline_bail_count' => 0 ] ],
            []
        );
        $this->assertFalse( $rows[0]['et_candidate'] ); // ok + count 0 → — (AC-ET-10)
    }
    public function test_et_candidate_partial_excluded_even_with_bail(): void {
        $rows = DRSPEED_AIAS_Scan_Status::build_pages(
            [ [ 'url' => 'https://x/', 'status' => 'done',
                'broken_devices' => [ [ 'device' => 'mobile', 'reason' => 'tier1_http_rate_limit' ] ],
                'deadline_bail_count' => 3 ] ],
            []
        );
        $this->assertFalse( $rows[0]['et_candidate'] ); // partial → — (ok-only; AC-ET-6)
    }
    public function test_et_candidate_blocked_excluded_even_with_bail(): void {
        $rows = DRSPEED_AIAS_Scan_Status::build_pages(
            [ [ 'url' => 'https://x/', 'status' => 'done',
                'broken_devices' => [ [ 'device' => 'desktop', 'reason' => 'tier2_cf_challenge' ] ],
                'deadline_bail_count' => 5 ] ],
            []
        );
        $this->assertFalse( $rows[0]['et_candidate'] ); // blocked (WAF do-NOT) → — even with count>0 (AC-ET-8)
    }
    public function test_et_candidate_error_excluded(): void {
        $rows = DRSPEED_AIAS_Scan_Status::build_pages(
            [ [ 'url' => 'https://x/', 'status' => 'error',
                'broken_devices' => [ [ 'device' => 'desktop', 'reason' => 'tier1_http_5xx' ] ],
                'deadline_bail_count' => 4 ] ],
            []
        );
        $this->assertFalse( $rows[0]['et_candidate'] ); // error → — (AC-ET-7)
    }
    public function test_et_candidate_skipped_excluded(): void {
        $rows = DRSPEED_AIAS_Scan_Status::build_pages(
            [ [ 'url' => 'https://x/', 'status' => 'origin_unavailable' ] ],
            []
        );
        $this->assertFalse( $rows[0]['et_candidate'] ); // skipped, no field → — (AC-ET-9)
    }
    public function test_et_candidate_missing_field_is_false_backfill_safe(): void {
        $rows = DRSPEED_AIAS_Scan_Status::build_pages(
            [ [ 'url' => 'https://x/', 'status' => 'done', 'broken_devices' => [] ] ], // no deadline_bail_count
            []
        );
        $this->assertFalse( $rows[0]['et_candidate'] ); // historical scan → — (AC-ET-11), no PHP warning
    }

    // FU-AAS-ET-CREDIT-DISPLAY (2026-06-02) — per-URL Credits column must reflect the +1
    // Extra-Time charge. Railway stamps `extra_time_charged` on pages that ran a billed ET
    // continuation (1:1 with the scan-level et_ran SaaS charges); classify() adds the +1.
    public function test_extra_time_charged_done_adds_one_credit(): void {
        $r = DRSPEED_AIAS_Scan_Status::classify( $this->page([ 'extra_time_charged' => true ]) );
        $this->assertSame( 'ok', $r['class'] );
        $this->assertSame( 2, $r['credits'] ); // base 1 + ET 1 = the amount SaaS billed
    }
    public function test_no_extra_time_charged_done_is_one_credit(): void {
        $r = DRSPEED_AIAS_Scan_Status::classify( $this->page([ 'extra_time_charged' => false ]) );
        $this->assertSame( 1, $r['credits'] );
    }
    public function test_extra_time_charged_partial_adds_one_credit(): void {
        $r = DRSPEED_AIAS_Scan_Status::classify( $this->page([
            'broken_devices'     => [ [ 'device' => 'mobile', 'reason' => 'tier1_http_rate_limit' ] ],
            'extra_time_charged' => true,
        ]) );
        $this->assertSame( 'partial', $r['class'] );
        $this->assertSame( 2, $r['credits'] );
    }
    public function test_extra_time_charged_blocked_adds_one_credit(): void {
        $r = DRSPEED_AIAS_Scan_Status::classify( $this->page([
            'broken_devices'     => [ [ 'device' => 'desktop', 'reason' => 'tier2_cf_challenge' ] ],
            'extra_time_charged' => true,
        ]) );
        $this->assertSame( 'partial', $r['class'] ); // spec §3.2.4: one-device bot block on a done page is partial
        $this->assertSame( 2, $r['credits'] ); // base 1 (done) + ET 1
    }
    public function test_extra_time_charged_error_shows_et_credit_only(): void {
        // An ET continuation that ran but finished 'error': SaaS billed the +1 (et_ran), base 0.
        $r = DRSPEED_AIAS_Scan_Status::classify( $this->page([
            'status'             => 'error',
            'broken_devices'     => [ [ 'device' => 'desktop', 'reason' => 'tier1_http_5xx' ] ],
            'extra_time_charged' => true,
        ]) );
        $this->assertSame( 'error', $r['class'] );
        $this->assertSame( 1, $r['credits'] ); // base 0 + ET 1
    }
    public function test_origin_unavailable_ignores_extra_time_charged(): void {
        $r = DRSPEED_AIAS_Scan_Status::classify( [ 'url' => 'https://x/', 'status' => 'origin_unavailable', 'extra_time_charged' => true ] );
        $this->assertSame( 'skipped', $r['class'] );
        $this->assertSame( 0, $r['credits'] ); // origin never runs a continuation → never ET-charged
    }
    public function test_build_pages_threads_extra_time_charged_to_credits(): void {
        $rows = DRSPEED_AIAS_Scan_Status::build_pages(
            [ [ 'url' => 'https://x/', 'status' => 'done', 'broken_devices' => [], 'extra_time_charged' => true ] ],
            []
        );
        $this->assertSame( 2, $rows[0]['credits'] );
    }
    public function test_missing_extra_time_charged_is_backfill_safe(): void {
        // Historical scan / older Railway worker: no field → base credit only, no PHP warning.
        $r = DRSPEED_AIAS_Scan_Status::classify( $this->page([]) );
        $this->assertSame( 1, $r['credits'] );
    }
    public function test_blocked_entries_keeps_first_reasoned_entry_per_device_and_skips_malformed(): void {
        $page = [ 'status' => 'done', 'broken_devices' => [
            [ 'device' => 'desktop', 'reason' => '' ],                 // empty reason → skipped
            'garbage',                                                 // non-array → skipped
            [ 'device' => 'mobile',  'reason' => 'tier2_cf_challenge' ],
            [ 'device' => 'mobile',  'reason' => 'tier1_http_5xx' ],   // second mobile → ignored
            [ 'device' => 'tablet',  'reason' => 'tier1_http_5xx' ],   // unknown device → skipped
        ] ];
        $e = DRSPEED_AIAS_Scan_Status::blocked_entries( $page );
        $this->assertSame( [ 'mobile' ], array_keys( $e ) );
        $this->assertSame( 'tier2_cf_challenge', $e['mobile']['reason'] );
    }
    public function test_error_kind_is_total(): void {
        $this->assertSame( '', DRSPEED_AIAS_Scan_Status::error_kind( [ 'status' => 'done' ] ) );
        $this->assertSame( '', DRSPEED_AIAS_Scan_Status::error_kind( [ 'status' => 'error', 'broken_devices' => [ [ 'device' => 'desktop', 'reason' => 'tier1_zero_bytes' ] ] ] ) );
        $this->assertSame( 'unknown', DRSPEED_AIAS_Scan_Status::error_kind( [ 'status' => 'error' ] ) );
    }
    public function test_error_kind_precedence_and_allowlist(): void {
        $this->assertSame( 'page_time_limit', DRSPEED_AIAS_Scan_Status::error_kind( [ 'status' => 'error', 'error_reason' => 'page_time_limit' ] ) );
        $this->assertSame( 'analysis_failed', DRSPEED_AIAS_Scan_Status::error_kind( [ 'status' => 'error', 'error_reason' => 'analysis_failed' ] ) );
        $this->assertSame( 'unknown', DRSPEED_AIAS_Scan_Status::error_kind( [ 'status' => 'error', 'error_reason' => 'garbage' ] ) );
        $this->assertSame( 'unknown', DRSPEED_AIAS_Scan_Status::error_kind( [ 'status' => 'error', 'error_reason' => [ 'x' ] ] ) );   // Review Focus 1
        $this->assertSame( 'unknown', DRSPEED_AIAS_Scan_Status::error_kind( [ 'status' => 'error', 'error_reason' => 42 ] ) );
        $this->assertSame( 'off_site', DRSPEED_AIAS_Scan_Status::error_kind( [ 'status' => 'error', 'site_lock' => 'free_off_site', 'extra_time_lost' => true, 'error_reason' => 'page_time_limit' ] ) );
        $this->assertSame( 'extra_time_lost', DRSPEED_AIAS_Scan_Status::error_kind( [ 'status' => 'error', 'extra_time_lost' => true, 'error_reason' => 'page_time_limit' ] ) );
    }
    public function test_stack_of_allowlists(): void {
        $this->assertSame( 'siteground', DRSPEED_AIAS_Scan_Status::stack_of( [ 'stack' => 'siteground' ] ) );
        $this->assertSame( '', DRSPEED_AIAS_Scan_Status::stack_of( [ 'stack' => 'garbage' ] ) );
        $this->assertSame( '', DRSPEED_AIAS_Scan_Status::stack_of( [ 'stack' => [ 'siteground' ] ] ) );   // Review Focus 2
        $this->assertSame( '', DRSPEED_AIAS_Scan_Status::stack_of( [] ) );
    }
    public function test_one_device_bot_block_is_partial_mobile_first(): void {
        $r = DRSPEED_AIAS_Scan_Status::classify( [ 'status' => 'done', 'broken_devices' => [ [ 'device' => 'desktop', 'reason' => 'tier2_cf_challenge' ] ] ] );
        $this->assertSame( 'partial', $r['class'] );
        $this->assertSame( 'Desktop not scanned: Cloudflare challenge', $r['label'] );
        $this->assertSame( 'mobile', $r['survivor_device'] );
        $this->assertSame( 1, $r['credits'] );
    }
    public function test_both_blocked_bot_stays_blocked_zero_credit(): void {
        $r = DRSPEED_AIAS_Scan_Status::classify( [ 'status' => 'error', 'broken_devices' => [ [ 'device' => 'desktop', 'reason' => 'tier2_cf_challenge' ], [ 'device' => 'mobile', 'reason' => 'tier2_cf_challenge' ] ] ] );
        $this->assertSame( [ 'blocked', 'Blocked: Cloudflare challenge', 0, '' ], [ $r['class'], $r['label'], $r['credits'], $r['survivor_device'] ] );
    }
    public function test_both_blocked_non_bot_stays_error_label(): void {   // r1 M5 pin
        $r = DRSPEED_AIAS_Scan_Status::classify( [ 'status' => 'error', 'broken_devices' => [ [ 'device' => 'desktop', 'reason' => 'tier1_http_5xx' ], [ 'device' => 'mobile', 'reason' => 'tier1_http_5xx' ] ] ] );
        $this->assertSame( [ 'error', 'Error: site error (5xx)', 0 ], [ $r['class'], $r['label'], $r['credits'] ] );
    }
    public function test_siteground_both_blocked_label_names_the_stack(): void {   // spec §3.2.4 / §3.3.3
        $r = DRSPEED_AIAS_Scan_Status::classify( [ 'status' => 'error', 'broken_devices' => [ [ 'device' => 'desktop', 'reason' => 'tier1_zero_bytes', 'stack' => 'siteground' ], [ 'device' => 'mobile', 'reason' => 'tier1_zero_bytes', 'stack' => 'siteground' ] ] ] );
        $this->assertSame( [ 'blocked', 'Blocked: SiteGround bot protection' ], [ $r['class'], $r['label'] ] );
    }
    public function test_siteground_one_device_partial_label_names_the_stack(): void {   // spec §3.2.4 / §3.3.3
        $r = DRSPEED_AIAS_Scan_Status::classify( [ 'status' => 'done', 'broken_devices' => [ [ 'device' => 'desktop', 'reason' => 'tier1_zero_bytes', 'stack' => 'siteground' ] ] ] );
        $this->assertSame( [ 'partial', 'Desktop not scanned: SiteGround bot protection', 'mobile' ], [ $r['class'], $r['label'], $r['survivor_device'] ] );
    }
    public function test_error_status_with_one_bot_entry_takes_blocked_branch(): void {   // r2 m7 total table
        $r = DRSPEED_AIAS_Scan_Status::classify( [ 'status' => 'error', 'broken_devices' => [ [ 'device' => 'mobile', 'reason' => 'tier2_waf_challenge' ] ] ] );
        $this->assertSame( 'blocked', $r['class'] );
    }
    /** @dataProvider error_kind_labels */
    public function test_error_kind_rows( array $page, string $label ): void {
        $r = DRSPEED_AIAS_Scan_Status::classify( $page );
        $this->assertSame( [ 'error', $label, 0 ], [ $r['class'], $r['label'], $r['credits'] ] );
    }
    public function error_kind_labels(): array {
        return [
            [ [ 'status' => 'error', 'error_reason' => 'page_time_limit' ], 'Not finished: time limit' ],
            [ [ 'status' => 'error', 'error_reason' => 'analysis_failed' ], 'Not finished: scanner error' ],
            [ [ 'status' => 'error', 'site_lock' => 'free_off_site' ],      'Not scanned: off-site (free key)' ],
            [ [ 'status' => 'error', 'extra_time_lost' => true ],           'Not finished: Extra Time lost' ],
            [ [ 'status' => 'error' ],                                       'Error' ],
        ];
    }
    public function test_error_kind_row_keeps_et_credit(): void {
        $r = DRSPEED_AIAS_Scan_Status::classify( [ 'status' => 'error', 'error_reason' => 'page_time_limit', 'extra_time_charged' => true ] );
        $this->assertSame( 1, $r['credits'] );
    }
    public function test_build_pages_threads_survivor_device(): void {
        $rows = DRSPEED_AIAS_Scan_Status::build_pages( [
            [ 'url' => 'https://x/a', 'status' => 'done', 'broken_devices' => [ [ 'device' => 'mobile', 'reason' => 'tier2_cf_challenge' ] ] ],
            [ 'url' => 'https://x/b', 'status' => 'done', 'broken_devices' => [] ],
        ], [] );
        $this->assertSame( [ 'desktop', '' ], [ $rows[0]['survivor_device'], $rows[1]['survivor_device'] ] );
    }
}
