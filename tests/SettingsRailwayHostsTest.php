<?php
// tests/SettingsRailwayHostsTest.php
namespace DrSpeedAIAS\Tests;

use DrSpeedAIAS\Settings;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * FU-WORKER-CLOUDFLARE-FRONT (spec §3.3, AC-6) — the worker is reachable on two hosts; both are
 * allowlisted (exact match, HTTPS, port 443/none) and the Cloudflare-fronted one is preferred.
 */
class SettingsRailwayHostsTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $u, $c = -1 ) => parse_url( $u, $c ) );
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_host_order_is_cloudflare_then_direct_and_both_are_allowed(): void {
        $this->assertSame( [ 'scanner.wpservice.pro', 'cu-scanner-railway-production.up.railway.app' ], Settings::railway_host_order() );
        foreach ( Settings::railway_host_order() as $h ) {
            $this->assertTrue( Settings::is_safe_railway_url( 'https://' . $h ), $h );
        }
    }

    public function test_allowlist_has_exactly_the_two_hosts_and_stays_exact(): void {
        $this->assertFalse( Settings::is_safe_railway_url( 'https://evil.scanner.wpservice.pro' ) );
        $this->assertFalse( Settings::is_safe_railway_url( 'https://scanner.wpservice.pro:8443' ) );
        $this->assertFalse( Settings::is_safe_railway_url( 'http://scanner.wpservice.pro' ) );
        $this->assertFalse( Settings::is_safe_railway_url( 'https://other.up.railway.app' ) );
        $this->assertFalse( Settings::is_safe_railway_url( 'https://user:pw@scanner.wpservice.pro' ) );
    }
}
