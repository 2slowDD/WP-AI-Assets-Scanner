<?php
// tests/BuildResultErroredPagesTest.php
namespace DrSpeedAIAS\Tests;

use DrSpeedAIAS\Admin\ScannerAjax;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Errored-page copy plan, Task 5 — banner fields (spec §3.2.2 / §3.2.3).
 *
 * A reason-less `status: 'error'` page is ERRORED, not blocked: the FU-NEW-X-A fallback that
 * counted every such page as blocked on both devices with the synthetic reason `scan_errored`
 * is gone. Blocked vs errored is decided by ONE predicate (DRSPEED_AIAS_Scan_Status), and the
 * resulting fields ride on BOTH payload writers (the drspeed_aias_last_result option and the
 * live return) through one fragment.
 *
 * P17: T1–T7 drive the REAL public entry point, `do_build_result()`, with only the Railway HTTP
 * response stubbed (same harness as BuildResultAttributionTest). T4b pins the `all_already` gate
 * on the pure static `ScannerAjax::aggregate_banner_fields()` — the only function
 * do_build_result() calls to produce these fields.
 */
class BuildResultErroredPagesTest extends TestCase {

	/** Captured drspeed_aias_last_result write (key 'option'), reset by each build_payload_for(). */
	private array $last_option = [];

	/** Railway status body the wp_remote_retrieve_body stub returns for the current call. */
	private string $status_body = '';

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * Stubs every WP function do_build_result() reaches, then returns the real
	 * payload it produces for the given worker pages. Also captures the
	 * drspeed_aias_last_result option write into $this->last_option['option'].
	 */
	private function build_payload_for( array $pages ): array {
		WP_Mock::userFunction( 'get_home_url' )->andReturn( 'https://site.test' );
		WP_Mock::userFunction( 'wp_parse_url' )
			->andReturnUsing( fn( $url, $component = -1 ) => parse_url( (string) $url, $component ) );
		WP_Mock::userFunction( '__' )->andReturnUsing( fn( $t, $d = null ) => $t );

		// RailwayClient::get_status() — the only external boundary.
		WP_Mock::userFunction( 'wp_remote_get' )->andReturn( [] );
		WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		// Several tests call build_payload_for() more than once. WP_Mock keeps the FIRST
		// registered expectation for a function, so a fixed andReturn() would replay the first
		// call's pages; the stubs read per-call state off $this instead.
		$this->status_body = json_encode( [
			'status'    => 'complete',
			'total'     => count( $pages ),
			'completed' => count( $pages ),
			'pages'     => $pages,
			'flags'     => [],
		] );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturnUsing( fn() => $this->status_body );

		// The Settings-backed options RailwayClient's host allowlist requires; every
		// other option falls through to its default.
		WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			function ( $k, $default = false ) {
				if ( 'drspeed_aias_railway_url' === $k ) {
					return 'https://cu-scanner-railway-production.up.railway.app';
				}
				if ( 'drspeed_aias_api_key' === $k ) {
					return 'api-key-123';
				}
				return $default;
			}
		);
		$this->last_option = [];
		WP_Mock::userFunction( 'update_option' )->andReturnUsing( function ( $k, $v ) { if ( 'drspeed_aias_last_result' === $k ) { $this->last_option['option'] = $v; } return true; } );
		WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		WP_Mock::userFunction( 'set_transient' )->andReturn( true );
		WP_Mock::userFunction( 'delete_transient' )->andReturn( true );
		WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( fn( $d, $f = 0 ) => json_encode( $d, $f ) );
		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( fn( $tag, $value = null ) => $value );
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );
		WP_Mock::userFunction( 'do_action' )->andReturn( null );
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );

		return ( new ScannerAjax() )->do_build_result( 'job-xyz', 'tok-abc' );
	}

	private function page( array $extra ): array { return array_merge( [ 'url' => 'https://site.test/p' . md5( json_encode( $extra ) ) . '/', 'status' => 'error', 'assets' => [] ], $extra ); }
	private function done_page_with_rules( string $url, array $broken ): array {
		return [ 'url' => $url, 'status' => 'done', 'broken_devices' => $broken, 'assets' => [ [ 'handle' => 'h1', 'type' => 'script', 'desktop' => [ 'loaded' => true, 'coverage' => 0.0 ], 'mobile' => [ 'loaded' => true, 'coverage' => 0.0 ] ] ] ];
	}
	public function test_t1_reasonless_error_is_errored_not_blocked(): void {
		$p = $this->build_payload_for( [ $this->page( [] ) ] );
		$this->assertSame( [ 'desktop' => 0, 'mobile' => 0 ], $p['pages_blocked'] );
		$this->assertSame( [], $p['blocked_reasons'] );
		$this->assertSame( [ 'count' => 1, 'reasons' => [ 'unknown' => 1 ] ], $p['pages_errored'] );
		$this->assertSame( 0, $p['pages_one_device'] );
		$this->assertStringNotContainsString( 'scan_errored', json_encode( $p ) );
	}
	public function test_t2_error_reason_kinds(): void {
		$this->assertSame( [ 'page_time_limit' => 1 ], $this->build_payload_for( [ $this->page( [ 'error_reason' => 'page_time_limit' ] ) ] )['pages_errored']['reasons'] );
		$this->assertSame( [ 'analysis_failed' => 1 ], $this->build_payload_for( [ $this->page( [ 'error_reason' => 'analysis_failed' ] ) ] )['pages_errored']['reasons'] );
		$this->assertSame( [ 'unknown' => 1 ],         $this->build_payload_for( [ $this->page( [ 'error_reason' => 'garbage' ] ) ] )['pages_errored']['reasons'] );
	}
	public function test_t3_site_lock_and_et_lost(): void {
		$this->assertSame( [ 'off_site' => 1 ],        $this->build_payload_for( [ $this->page( [ 'site_lock' => 'free_off_site', 'error_reason' => 'page_time_limit' ] ) ] )['pages_errored']['reasons'] );
		$this->assertSame( [ 'extra_time_lost' => 1 ], $this->build_payload_for( [ $this->page( [ 'extra_time_lost' => true ] ) ] )['pages_errored']['reasons'] );
	}
	public function test_t4_mixed_one_device_block_plus_errored_page(): void {
		$p = $this->build_payload_for( [
			$this->done_page_with_rules( 'https://site.test/a/', [ [ 'device' => 'desktop', 'reason' => 'tier2_cf_challenge' ] ] ),
			$this->page( [ 'error_reason' => 'page_time_limit' ] ),
		] );
		$this->assertSame( [ 'desktop' => 1, 'mobile' => 0 ], $p['pages_blocked'] );
		$this->assertSame( 1, $p['pages_one_device'] );
		$this->assertSame( 1, $p['pages_one_device_with_rules'] );   // the asset is aggressive on both devices → rules > 0
		$this->assertSame( 1, $p['pages_errored']['count'] );
	}
	public function test_t5_both_blocked_is_blocked_not_errored(): void {
		$p = $this->build_payload_for( [ $this->page( [ 'broken_devices' => [ [ 'device' => 'desktop', 'reason' => 'tier2_cf_challenge' ], [ 'device' => 'mobile', 'reason' => 'tier2_cf_challenge' ] ] ] ) ] );
		$this->assertSame( [ 'desktop' => 1, 'mobile' => 1 ], $p['pages_blocked'] );
		$this->assertSame( 0, $p['pages_one_device'] );
		$this->assertSame( 0, $p['pages_errored']['count'] );
	}
	public function test_t6_blocked_stacks_counted_per_entry_and_allowlisted(): void {
		$p = $this->build_payload_for( [ $this->page( [ 'broken_devices' => [ [ 'device' => 'desktop', 'reason' => 'tier1_zero_bytes', 'stack' => 'siteground' ], [ 'device' => 'mobile', 'reason' => 'tier1_zero_bytes', 'stack' => 'garbage' ] ] ] ) ] );
		$this->assertSame( [ 'siteground' => 1 ], $p['blocked_stacks'] );
		$this->assertSame( [ 'tier1_zero_bytes' => 2 ], $p['blocked_reasons'] );
	}
	public function test_t7_option_carries_every_banner_field(): void {
		$p = $this->build_payload_for( [ $this->page( [ 'error_reason' => 'page_time_limit' ] ) ] );
		foreach ( [ 'pages_blocked', 'blocked_reasons', 'blocked_stacks', 'pages_errored', 'pages_one_device', 'pages_one_device_with_rules', 'rate_limit_attribution' ] as $k ) {
			$this->assertArrayHasKey( $k, $this->last_option['option'], $k );
			$this->assertSame( $p[ $k ], $this->last_option['option'][ $k ], $k );
		}
	}

	/**
	 * T4b — the `all_already` gate. A one-device survivor whose every rule Code Unloader
	 * already holds delivered nothing new, so it must not count toward
	 * pages_one_device_with_rules. Pinned on the pure static that do_build_result() calls; the
	 * control half (same page, all_already false) proves the rule tally alone would count it.
	 */
	public function test_t4b_all_already_survivor_has_no_rules(): void {
		$pages   = [ $this->done_page_with_rules( 'https://site.test/a/', [ [ 'device' => 'desktop', 'reason' => 'tier2_cf_challenge' ] ] ) ];
		$by_page = [ 0 => [ 'safe' => 0, 'aggressive' => 2, 'needed' => 0 ] ];

		$gated = ScannerAjax::aggregate_banner_fields( $pages, $by_page, [ 0 => true ] );
		$this->assertSame( 1, $gated['pages_one_device'] );
		$this->assertSame( 0, $gated['pages_one_device_with_rules'] );

		$control = ScannerAjax::aggregate_banner_fields( $pages, $by_page, [ 0 => false ] );
		$this->assertSame( 1, $control['pages_one_device'] );
		$this->assertSame( 1, $control['pages_one_device_with_rules'] );
	}

	/**
	 * Final-review M1 — the survivor count uses the SAME status test as
	 * DRSPEED_AIAS_Scan_Status::classify(): any non-'error' page with one blocked entry is a
	 * partial row, so it counts as one-device (not only status 'done'); an 'error' page with one
	 * blocked entry is an error/blocked row and does not.
	 */
	public function test_m1_one_device_count_matches_classify_status_test(): void {
		$broken  = [ [ 'device' => 'desktop', 'reason' => 'tier2_cf_challenge' ] ];
		$by_page = [ 0 => [ 'safe' => 1, 'aggressive' => 0, 'needed' => 0 ] ];

		$page = $this->done_page_with_rules( 'https://site.test/a/', $broken );
		$page['status'] = 'partial';
		$f = ScannerAjax::aggregate_banner_fields( [ $page ], $by_page, [] );
		$this->assertSame( 1, $f['pages_one_device'] );
		$this->assertSame( 1, $f['pages_one_device_with_rules'] );

		$page['status'] = 'error';
		$f = ScannerAjax::aggregate_banner_fields( [ $page ], $by_page, [] );
		$this->assertSame( 0, $f['pages_one_device'] );
		$this->assertSame( 0, $f['pages_one_device_with_rules'] );
	}

	/**
	 * Final-review M2 — the partial row label's device word goes through __() (the same
	 * 'Desktop'/'Mobile' literals as export_copy_map()['device']), not ucfirst() of the English key.
	 */
	public function test_m2_partial_label_device_word_is_translated(): void {
		WP_Mock::userFunction( '__' )->andReturnUsing( function ( $t ) { return 'Ü' . $t; } );
		$r = \DRSPEED_AIAS_Scan_Status::classify( [ 'status' => 'done', 'broken_devices' => [ [ 'device' => 'mobile', 'reason' => 'tier2_cf_challenge' ] ] ] );
		$this->assertStringStartsWith( 'Ü' . 'Ü' . 'Mobile not scanned: ', $r['label'] );
	}
}
