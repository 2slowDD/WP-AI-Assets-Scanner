<?php
namespace DrSpeedAIAS\Tests;

use DrSpeedAIAS\Admin\ScannerAjax;
use DrSpeedAIAS\Scanner\ScannerAccessCheck;
use DrSpeedAIAS\Settings;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/** FU-SITE-DENIAL spec §3.2 "Entry" + AC-12 — drspeed_aias_check_scanner_access handler. */
class CheckScannerAccessEndpointTest extends TestCase {
    private const FIXTURE_SECRET = 'SECRETzq81x7Kp';
    private const STAGE_USER = 'stageUSERq4';
    private const STAGE_PASS = 'STAGEpassW93v';

    private ?array $sent = null;

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        $this->sent = null;
        $_POST      = [];
        WP_Mock::userFunction( 'wp_send_json' )->andReturnUsing( function ( $data, $status = null ) {
            $this->sent = [ 'data' => $data, 'status' => $status ];
            throw new \Exception( 'sent' );
        } );
        WP_Mock::userFunction( 'wp_send_json_success' )->andReturnUsing( function ( $data ) {
            $this->sent = [ 'data' => [ 'success' => true, 'data' => $data ], 'status' => 200 ];
            throw new \Exception( 'sent' );
        } );
        WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( fn( $v ) => $v );
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( $url, $component = -1 ) {
            return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
        } );
    }

    public function tearDown(): void {
        $_POST = [];
        WP_Mock::tearDown();
        parent::tearDown();
    }

    private function call(): void {
        try {
            ( new ScannerAjax() )->check_scanner_access();
        } catch ( \Exception $e ) {
            $this->assertSame( 'sent', $e->getMessage() );
        }
    }

    /** Authorised request with a stored secret and no staging login; every request answers 200. */
    private function authorised( string $home = 'https://example.com', array &$seen = [] ): void {
        WP_Mock::userFunction( 'check_ajax_referer' )->andReturn( 1 );
        WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
        WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing( fn( $u ) => $u );
        WP_Mock::userFunction( 'home_url' )->andReturn( $home );
        WP_Mock::userFunction( 'get_option' )->andReturnUsing( fn( $k, $d = false ) => 'drspeed_aias_secret' === $k ? self::FIXTURE_SECRET : $d );
        WP_Mock::userFunction( 'wp_safe_remote_get' )->andReturnUsing( function ( $url ) use ( &$seen ) {
            $seen[] = $url;
            return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => '' ];
        } );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( '' );
    }

    public function test_bad_nonce_403(): void {
        WP_Mock::userFunction( 'check_ajax_referer' )->with( 'drspeed_aias_nonce', '_wpnonce', false )->andReturn( false );
        WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
        WP_Mock::userFunction( 'wp_safe_remote_get' )->never();
        $_POST['urls'] = [ 'https://example.net/' ];
        $this->call();
        $this->assertSame( [ [ 'ok' => false, 'error' => 'nonce_invalid' ], 403 ], [ $this->sent['data'], $this->sent['status'] ] );
    }

    public function test_no_capability_403(): void {
        WP_Mock::userFunction( 'check_ajax_referer' )->andReturn( 1 );
        WP_Mock::userFunction( 'current_user_can' )->with( 'manage_options' )->andReturn( false );
        WP_Mock::userFunction( 'wp_safe_remote_get' )->never();
        $_POST['urls'] = [ 'https://example.net/' ];
        $this->call();
        $this->assertSame( [ [ 'ok' => false, 'error' => 'permission_denied' ], 403 ], [ $this->sent['data'], $this->sent['status'] ] );
    }

    public function test_urls_not_array_400(): void {
        WP_Mock::userFunction( 'check_ajax_referer' )->andReturn( 1 );
        WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
        WP_Mock::userFunction( 'wp_safe_remote_get' )->never();
        $_POST['urls'] = 'https://example.net/';
        $this->call();
        $this->assertSame( [ [ 'ok' => false, 'error' => 'urls_must_be_array' ], 400 ], [ $this->sent['data'], $this->sent['status'] ] );
    }

    public function test_non_http_dropped_and_success_envelope(): void {
        $seen = [];
        $this->authorised( 'https://example.com', $seen );
        $urls = [ 'ftp://example.net/x', 'javascript:alert(1)', 'https://example.net/p1', 'https://example.net/p2' ];
        $_POST['urls'] = $urls;
        $this->call();
        $this->assertSame( 200, $this->sent['status'] );
        $this->assertTrue( $this->sent['data']['success'] );
        $this->assertSame( [ 'https://example.net/p1' ], $seen, 'one host, normal site → one request; ftp:/javascript: never requested' );
        $this->assertSame( [ 'example.net' ], array_column( $this->sent['data']['data']['hosts'], 'host' ), 'non-http URLs form no host row' );
        $this->assertSame( [ 'hosts', 'denied' ], array_keys( $this->sent['data']['data'] ) );
        $this->assertFalse( $this->sent['data']['data']['denied'] );
        $this->assertStringNotContainsString( self::FIXTURE_SECRET, (string) json_encode( $this->sent['data'] ) );
    }

    public function test_hostless_empty_and_nested_elements_are_skipped(): void {
        $seen = [];
        $this->authorised( 'https://example.com', $seen );
        $_POST['urls'] = [ '', 'https:/nohost', [ 'https://example.net/nested' ], 'https://example.net/a' ];
        $this->call();
        $this->assertSame( 200, $this->sent['status'] );
        $this->assertSame( [ 'example.net' ], array_column( $this->sent['data']['data']['hosts'], 'host' ) );
        $this->assertSame( [ 'https://example.net/a' ], $seen );
    }

    /** PF7 / AC-8 — at most 20 valid URLs are kept, in order: the 21st URL's host is never checked. */
    public function test_url_cap_20_cuts_the_21st_urls_host(): void {
        $seen = [];
        // Home is a third site, so neither tested host is the own site.
        $this->authorised( 'https://staging.example.com', $seen );
        $urls = [];
        foreach ( range( 1, 20 ) as $i ) {
            $urls[] = "https://example.net/p{$i}";
        }
        $urls[]        = 'https://example.com/x';
        $_POST['urls'] = $urls;
        $this->call();
        $hosts = $this->sent['data']['data']['hosts'];
        $this->assertCount( 1, $hosts, 'the 21st URL (second host) is cut by the 20-URL cap' );
        $this->assertSame( 'example.net', $hosts[0]['host'] );
        $this->assertNotContains( 'https://example.com/x', $seen );
        $this->assertSame( 20, ScannerAccessCheck::MAX_URLS );
    }

    /** Invalid entries (other scheme, hostless) do not use up the cap: only kept URLs count toward 20. */
    public function test_cap_counts_only_kept_urls(): void {
        $seen = [];
        $this->authorised( 'https://staging.example.com', $seen );
        $urls = [];
        foreach ( range( 1, 19 ) as $i ) {
            $urls[] = "https://example.net/p{$i}";
        }
        $urls[]        = 'ftp://example.net/skip';
        $urls[]        = 'https:/hostless';
        $urls[]        = 'https://example.com/x';
        $_POST['urls'] = $urls;
        $this->call();
        $this->assertSame( [ 'example.net', 'example.com' ], array_column( $this->sent['data']['data']['hosts'], 'host' ) );
    }

    /**
     * The Scanner Secret and staging login are sent (B request on an own-site URL) but never
     * appear in the response. Uses a real stored staging-login blob (Settings::set_http_auth).
     */
    public function test_secret_and_staging_login_never_in_response(): void {
        WP_Mock::userFunction( 'wp_salt' )->andReturn( str_repeat( 'x', 64 ) );
        WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( fn( $v ) => json_encode( $v ) );
        $blob = '';
        WP_Mock::userFunction( 'update_option' )->andReturnUsing( function ( $k, $v ) use ( &$blob ) {
            $blob = (string) $v;
            return true;
        } );
        ( new Settings() )->set_http_auth( self::STAGE_USER, self::STAGE_PASS );
        $this->assertNotSame( '', $blob );

        WP_Mock::userFunction( 'check_ajax_referer' )->andReturn( 1 );
        WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
        WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing( fn( $u ) => $u );
        WP_Mock::userFunction( 'home_url' )->andReturn( 'https://example.com' );
        WP_Mock::userFunction( 'get_option' )->andReturnUsing( function ( $k, $d = false ) use ( &$blob ) {
            if ( 'drspeed_aias_secret' === $k ) {
                return self::FIXTURE_SECRET;
            }
            return 'drspeed_aias_http_auth' === $k ? $blob : $d;
        } );
        $headers_sent = [];
        // Own site that refuses A and B by status, but serves the control (C) → denied.
        WP_Mock::userFunction( 'wp_safe_remote_get' )->andReturnUsing( function ( $url, $args ) use ( &$headers_sent ) {
            $headers_sent[] = $args['headers'];
            $code = ScannerAccessCheck::control_user_agent() === $args['headers']['User-Agent'] ? 200 : 403;
            return [ 'response' => [ 'code' => $code ], 'headers' => [], 'body' => 'Blocked. Reference ID: 18ab-77' ];
        } );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturnUsing( fn( $r ) => $r['response']['code'] );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturnUsing( fn( $r ) => $r['body'] );
        WP_Mock::userFunction( 'wp_strip_all_tags' )->andReturnUsing( fn( $s ) => strip_tags( (string) $s ) );
        $_POST['urls'] = [ 'https://example.com/a' ];
        $this->call();

        $this->assertSame( 'denied', $this->sent['data']['data']['hosts'][0]['verdict'] );
        $secret_went = false;
        $auth_went   = '';
        foreach ( $headers_sent as $h ) {
            $secret_went = $secret_went || ( $h['x-cu-scanner'] ?? '' ) === self::FIXTURE_SECRET;
            $auth_went   = $h['Authorization'] ?? $auth_went;
        }
        $this->assertTrue( $secret_went, 'precondition: the secret was in play' );
        $this->assertSame( 'Basic ' . base64_encode( self::STAGE_USER . ':' . self::STAGE_PASS ), $auth_went, 'precondition: the staging login was in play' );

        $json = (string) json_encode( $this->sent['data'] );
        foreach ( [ self::FIXTURE_SECRET, self::STAGE_USER, self::STAGE_PASS, base64_encode( self::STAGE_USER . ':' . self::STAGE_PASS ) ] as $needle ) {
            $this->assertStringNotContainsString( $needle, $json );
        }
    }

    /**
     * AC-12 — a private-IP target maps to "unclear". That no request leaves rests on core
     * wp_safe_remote_get / wp_http_validate_url rejecting the target; WordPress is not loaded
     * in tests, so its WP_Error result is mocked here. This test proves the mapping only.
     */
    public function test_private_ip_wp_error_maps_to_unclear(): void {
        WP_Mock::userFunction( 'check_ajax_referer' )->andReturn( 1 );
        WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
        WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing( fn( $u ) => $u );
        WP_Mock::userFunction( 'home_url' )->andReturn( 'https://example.com' );
        WP_Mock::userFunction( 'get_option' )->andReturnUsing( fn( $k, $d = false ) => 'drspeed_aias_secret' === $k ? self::FIXTURE_SECRET : $d );
        WP_Mock::userFunction( 'wp_safe_remote_get' )->andReturn( new \WP_Error( 'http_request_failed', 'A valid URL was not provided.' ) );
        $_POST['urls'] = [ 'http://127.0.0.1/a', 'http://10.0.0.5/b' ];
        $this->call();
        $verdicts = array_column( $this->sent['data']['data']['hosts'], 'verdict' );
        $this->assertSame( [ 'unclear', 'unclear' ], $verdicts );
    }

    public function test_action_is_registered(): void {
        WP_Mock::expectActionAdded(
            'wp_ajax_drspeed_aias_check_scanner_access',
            [ new \WP_Mock\Matcher\AnyInstance( ScannerAjax::class ), 'check_scanner_access' ]
        );
        ( new ScannerAjax() )->register();
        $this->assertConditionsMet();
    }

    /**
     * Plan E4 — drspeedAias.settingsUrl. Source pin: enqueue_assets() needs a large harness
     * (Outbox, LastPushSyncUndo, banner copy map, CDN detector), so this catches deletion or a
     * changed target only, not a retyped value.
     */
    public function test_settings_url_is_localized_into_drspeedaias(): void {
        $src = (string) file_get_contents( dirname( __DIR__ ) . '/admin/class-admin-pages.php' );
        $this->assertSame( 1, preg_match( "/wp_localize_script\\(\\s*'drspeed-aias-scanner',\\s*'drspeedAias',\\s*\\[(.*?)\\]\\s*\\);/s", $src, $m ) );
        $this->assertMatchesRegularExpression( "/'settingsUrl'\\s*=>\\s*admin_url\\(\\s*'admin\\.php\\?page=drspeed-aias-settings'\\s*\\)/", $m[1] );
    }

    /** Plan E7 — empty notice slot, placed before the target-stack notice. */
    public function test_scanner_page_has_access_notice_slot_before_target_stack_notice(): void {
        $markup = (string) file_get_contents( dirname( __DIR__ ) . '/admin/views/scanner-page.php' );
        $slot   = strpos( $markup, '<div id="cu-access-notice" aria-live="polite"></div>' );
        $stack  = strpos( $markup, 'id="cu-target-stack-notice"' );
        $this->assertNotFalse( $slot );
        $this->assertNotFalse( $stack );
        $this->assertLessThan( $stack, $slot );
    }
}
