<?php
namespace DrSpeedAIAS\Tests;

use DRSPEED_AIAS_Scan_Status;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Stage 2 Part P, spec §5.1 P-R + AC-P: a page the worker did not scan because its document
 * redirected elsewhere is "redirected" for the row label and the error kind, before any
 * blocked entry. Input shape = the worker's foldLandedElsewhere output (v1.02920).
 */
class ScanStatusRedirectedTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )->andReturnArg( 0 );
		// Final-review I1: on a site whose blog_charset is not UTF-8, core's wp_check_invalid_utf8()
		// returns its input unchanged — the display check must not depend on it. Emulate that site.
		WP_Mock::userFunction( 'wp_check_invalid_utf8' )->andReturnArg( 0 );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	private function page( array $o = [] ): array {
		return array_merge( [ 'url' => 'https://site.test/checkout/', 'status' => 'error', 'error_reason' => 'redirected', 'assets' => [] ], $o );
	}

	private function label( array $o = [] ): string {
		return DRSPEED_AIAS_Scan_Status::classify( $this->page( $o ) )['label'];
	}

	public function test_on_site_path(): void {
		$st = DRSPEED_AIAS_Scan_Status::classify( $this->page( [ 'redirected_to' => '/cart/' ] ) );
		$this->assertSame( 'error', $st['class'] );
		$this->assertSame( 'Not scanned: redirected to /cart/', $st['label'] );
		$this->assertSame( 0, $st['credits'] );
		$this->assertSame( 'redirected', DRSPEED_AIAS_Scan_Status::error_kind( $this->page( [ 'redirected_to' => '/cart/' ] ) ) );
	}

	public function test_pr_beats_blocked_entry(): void {
		$o  = [ 'redirected_to' => '/cart/', 'broken_devices' => [ [ 'device' => 'desktop', 'reason' => 'tier1_http_rate_limit' ] ] ];
		$st = DRSPEED_AIAS_Scan_Status::classify( $this->page( $o ) );
		$this->assertSame( 'error', $st['class'] );
		$this->assertSame( 'Not scanned: redirected to /cart/', $st['label'] );
		$this->assertSame( 'redirected', DRSPEED_AIAS_Scan_Status::error_kind( $this->page( $o ) ) );
	}

	public function test_off_site(): void {
		$this->assertSame( 'Not scanned: redirected to another site', $this->label( [ 'redirected_off_site' => 1 ] ) );
		// Only the int 1 counts (Rule 1); true / '1' fall through to "another page".
		$this->assertSame( 'Not scanned: redirected to another page', $this->label( [ 'redirected_off_site' => true ] ) );
		$this->assertSame( 'Not scanned: redirected to another page', $this->label( [ 'redirected_off_site' => '1' ] ) );
	}

	public function test_path_absent_or_invalid_reads_another_page(): void {
		$this->assertSame( 'Not scanned: redirected to another page', $this->label() );
		$this->assertSame( 'Not scanned: redirected to another page', $this->label( [ 'redirected_to' => '/<script>' ] ) );
		$this->assertSame( 'Not scanned: redirected to another page', $this->label( [ 'redirected_to' => 'cart/' ] ) );
		$this->assertSame( 'Not scanned: redirected to another page', $this->label( [ 'redirected_to' => [ '/cart/' ] ] ) );
		$this->assertSame( 'Not scanned: redirected to another page', $this->label( [ 'redirected_to' => '/' . str_repeat( 'a', 200 ) ] ) ); // 201 bytes
		$this->assertSame( 'Not scanned: redirected to /' . str_repeat( 'a', 199 ), $this->label( [ 'redirected_to' => '/' . str_repeat( 'a', 199 ) ] ) ); // 200 bytes
	}

	public function test_display_decodes_valid_utf8_only(): void {
		$this->assertSame( 'Not scanned: redirected to /café/', $this->label( [ 'redirected_to' => '/caf%C3%A9/' ] ) );
		$this->assertSame( 'Not scanned: redirected to /%FF/', $this->label( [ 'redirected_to' => '/%FF/' ] ) );              // invalid UTF-8
		$this->assertSame( 'Not scanned: redirected to /a%0Ab/', $this->label( [ 'redirected_to' => '/a%0Ab/' ] ) );          // control char
		$this->assertSame( 'Not scanned: redirected to /%E2%80%AEx/', $this->label( [ 'redirected_to' => '/%E2%80%AEx/' ] ) ); // bidi override (Cf)
	}

	// Final-review I1 / M1 / M2: the display check fails closed whatever the site charset.
	public function test_display_check_fails_closed(): void {
		$this->assertSame( 'Not scanned: redirected to /caf%E9/', $this->label( [ 'redirected_to' => '/caf%E9/' ] ) );            // Latin-1 byte, non-UTF-8 site
		$this->assertSame( 'Not scanned: redirected to /a%E2%80%A8b/', $this->label( [ 'redirected_to' => '/a%E2%80%A8b/' ] ) );  // U+2028 line separator
		$this->assertSame( 'Not scanned: redirected to another page', $this->label( [ 'redirected_to' => "/cart/\n" ] ) );         // raw trailing newline
		$this->assertSame( 'Not scanned: redirected to /商品/ ok', $this->label( [ 'redirected_to' => '/%E5%95%86%E5%93%81/%20ok' ] ) ); // CJK + space stay decoded
	}

	public function test_et_credit_kept(): void {
		$this->assertSame( 1, DRSPEED_AIAS_Scan_Status::classify( $this->page( [ 'redirected_to' => '/cart/', 'extra_time_charged' => true ] ) )['credits'] );
	}

	public function test_unknown_reason_still_error(): void {
		$this->assertSame( 'Error', $this->label( [ 'error_reason' => 'garbage' ] ) );
		$this->assertSame( 'unknown', DRSPEED_AIAS_Scan_Status::error_kind( $this->page( [ 'error_reason' => 'garbage' ] ) ) );
	}

	public function test_redirected_reason_on_a_done_page_is_ignored(): void {
		$st = DRSPEED_AIAS_Scan_Status::classify( $this->page( [ 'status' => 'done', 'redirected_to' => '/cart/' ] ) );
		$this->assertSame( 'ok', $st['class'] );
		$this->assertSame( '', DRSPEED_AIAS_Scan_Status::error_kind( $this->page( [ 'status' => 'done' ] ) ) );
	}
}
