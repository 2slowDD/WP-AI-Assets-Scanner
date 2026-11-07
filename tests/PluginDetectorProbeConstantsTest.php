<?php
namespace DrSpeedAIAS\Tests;

use DrSpeedAIAS\Scanner\PluginDetector;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/** FU-SITE-DENIAL spec AC-13 — the probe still sends the identical UA/Accept, now via public constants. */
class PluginDetectorProbeConstantsTest extends TestCase {
    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    public function test_constants_hold_the_exact_strings(): void {
        $this->assertSame( 'CU-Scanner-Probe/1.0 (target-stack-detection)', PluginDetector::PROBE_USER_AGENT );
        $this->assertSame( 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', PluginDetector::PROBE_ACCEPT );
    }

    public function test_probe_request_sends_the_constants(): void {
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( $url, $component = -1 ) {
            return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
        } );
        $captured = null;
        WP_Mock::userFunction( 'wp_remote_get' )->andReturnUsing( function ( $url, $args ) use ( &$captured ) {
            $captured = $args;
            return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => '<html></html>' ];
        } );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
        WP_Mock::userFunction( 'wp_remote_retrieve_headers' )->andReturn( [] );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( '<html></html>' );

        PluginDetector::__test_single_probe_attempt( 'https://example.com/', 12, true );

        $this->assertSame( PluginDetector::PROBE_USER_AGENT, $captured['headers']['User-Agent'] );
        $this->assertSame( PluginDetector::PROBE_ACCEPT, $captured['headers']['Accept'] );
        $this->assertSame( 3, $captured['redirection'], 'the probe itself keeps redirection 3 (unchanged)' );
    }
}
