<?php
namespace CUScanner\Tests;

use CUScanner\Admin\ScannerAjax;
use CUScanner\Api\HttpException;
use CUScanner\Settings;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Deleting the plugin removes the saved key (WordPress.org requires it), and the
 * plugin may not contact wpservice.pro on its own afterwards. A scan started in
 * that state used to fail with a bare "HTTP 401: Invalid API key". These tests pin
 * the guidance that replaced it: the scan stops before any request and points
 * to Get free credits in Settings.
 */
final class NoApiKeyGuidanceTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        WP_Mock::userFunction( '__' )->andReturnUsing( fn( $s ) => $s );
        WP_Mock::userFunction( 'admin_url' )->andReturnUsing( fn( $p = '' ) => 'https://example.test/wp-admin/' . $p );
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    private function settings_with_key( string $key ): Settings {
        WP_Mock::userFunction( 'get_option' )->with( 'cu_scanner_api_key', '' )->andReturn( $key );
        return new Settings();
    }

    public function test_no_saved_key_stops_the_scan_and_links_to_get_free_credits(): void {
        $error = ScannerAjax::missing_key_error( $this->settings_with_key( '' ) );

        $this->assertIsArray( $error );
        $this->assertSame( 'no_api_key', $error['error'] );
        $this->assertFalse( $error['retryable'], 'must not be queued for retry: nothing changes until the admin acts' );
        $this->assertStringContainsString( 'Get free credits', $error['message'] );
        $this->assertStringEndsWith( 'page=cu-scanner-settings#cu-free-key-optin', $error['settings_url'] );
    }

    public function test_pending_free_key_also_stops_the_scan(): void {
        $error = ScannerAjax::missing_key_error( $this->settings_with_key( 'cusk_Freekey_?' ) );

        $this->assertIsArray( $error );
        $this->assertSame( 'no_api_key', $error['error'] );
    }

    public function test_a_saved_key_lets_the_scan_start(): void {
        $this->assertNull( ScannerAjax::missing_key_error( $this->settings_with_key( 'cusk_Freekey_9' ) ) );
    }

    public function test_rejected_key_points_to_settings_and_keeps_the_service_reason(): void {
        foreach ( [ 401 => 'HTTP 401: Invalid API key', 403 => 'HTTP 403: This free API key has been revoked.' ] as $code => $msg ) {
            $error = ScannerAjax::rejected_key_error( new HttpException( $msg, $code ) );
            $this->assertIsArray( $error, "HTTP {$code} must map to key guidance" );
            $this->assertSame( 'invalid_api_key', $error['error'] );
            $this->assertStringContainsString( $msg, $error['message'] );
            $this->assertStringContainsString( 'page=cu-scanner-settings', $error['settings_url'] );
        }
    }

    public function test_other_failures_keep_their_existing_handling(): void {
        $this->assertNull( ScannerAjax::rejected_key_error( new HttpException( 'HTTP 500: Unknown error', 500 ) ) );
        $this->assertNull( ScannerAjax::rejected_key_error( new HttpException( 'Insufficient credits', 402 ) ) );
        $this->assertNull( ScannerAjax::rejected_key_error( new \RuntimeException( 'offline' ) ) );
    }

    public function test_reserve_checks_for_a_key_before_any_request(): void {
        $src = (string) file_get_contents( dirname( __DIR__ ) . '/admin/class-scanner-ajax.php' );
        $start = strpos( $src, 'public function reserve_job(): void' );
        $body  = substr( $src, $start, 1500 );
        $this->assertLessThan(
            strpos( $body, 'new WpserviceClient' ),
            strpos( $body, 'missing_key_error' ),
            'the key check must run before the wpservice.pro client is created'
        );
    }

    public function test_scanner_page_and_settings_page_show_the_way_to_a_key(): void {
        $root    = dirname( __DIR__ );
        $scanner = (string) file_get_contents( $root . '/admin/views/scanner-page.php' );
        $page    = (string) file_get_contents( $root . '/admin/views/settings-page.php' );
        $js      = (string) file_get_contents( $root . '/admin/js/scanner.js' );

        $this->assertStringContainsString( 'id="cu-no-api-key-notice"', $scanner );
        $this->assertStringContainsString( 'page=cu-scanner-settings#cu-free-key-optin', $scanner );
        $this->assertStringContainsString( 'id="cu-free-key-optin"', $page, 'the link target must exist' );
        $this->assertStringContainsString(
            'FreeKeyBootstrap::can_request( $settings )',
            $page,
            'Get free credits must also be offered while an earlier request is pending'
        );
        $this->assertStringContainsString( 'data.settings_url', $js );
    }
}
