<?php
namespace DrSpeedAIAS\Tests;

use DrSpeedAIAS\Scanner\ScannerAccessCheck;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/** FU-SITE-DENIAL Part C — ScannerAccessCheck. Spec Rev 3.1 §3.2 + plan errata E2. */
class ScannerAccessCheckTest extends TestCase {
    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( $url, $component = -1 ) {
            return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
        } );
        WP_Mock::userFunction( 'wp_strip_all_tags' )->andReturnUsing( function ( $s ) {
            return trim( strip_tags( preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $s ) ) );
        } );
    }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    // ---- AC-16 (PHP side) + Review Focus 1 -------------------------------------------
    public function test_is_own_site_ac16_rows(): void {
        $home = 'https://example.com';
        $this->assertTrue( ScannerAccessCheck::is_own_site( 'https://www.example.com/x', $home ) );
        $this->assertFalse( ScannerAccessCheck::is_own_site( 'https://shop.example.com/x', $home ) );
        $this->assertFalse( ScannerAccessCheck::is_own_site( 'https://example.com:8443/x', $home ) );
        $this->assertTrue( ScannerAccessCheck::is_own_site( 'http://example.com/x', $home ), 'E2: default ports of each scheme are the same site' );
        $this->assertFalse( ScannerAccessCheck::is_own_site( 'https://example.net/x', $home ) );
    }

    /** E2: an explicit port equal to the URL's OWN scheme default is omitted (JS `URL.host`); any other port must match. */
    public function test_is_own_site_explicit_default_port_of_own_scheme(): void {
        $home = 'https://example.com';
        $this->assertTrue( ScannerAccessCheck::is_own_site( 'https://example.com:443/x', $home ) );
        $this->assertTrue( ScannerAccessCheck::is_own_site( 'http://example.com:80/x', $home ) );
        $this->assertFalse( ScannerAccessCheck::is_own_site( 'http://example.com:443/x', $home ), ':443 is not the http default' );
        $this->assertTrue( ScannerAccessCheck::is_own_site( 'https://example.com:8443/x', 'https://example.com:8443' ) );
    }

    public function test_is_own_site_case_and_www(): void {
        $this->assertTrue( ScannerAccessCheck::is_own_site( 'https://WWW.Example.COM/', 'https://example.com/' ) );
        $this->assertTrue( ScannerAccessCheck::is_own_site( 'https://example.com/', 'https://www.example.com' ) );
        $this->assertFalse( ScannerAccessCheck::is_own_site( 'https://www.www.example.com/', 'https://example.com' ), 'only ONE www. is stripped' );
    }

    public function test_credentials_allowed_rules(): void {
        $home = 'https://example.com';
        $this->assertTrue( ScannerAccessCheck::credentials_allowed( 'https://example.com/a', $home ) );
        $this->assertTrue( ScannerAccessCheck::credentials_allowed( 'https://www.example.com/a', $home ) );
        $this->assertFalse( ScannerAccessCheck::credentials_allowed( 'http://example.com/a', $home ), 'never over http when home is https' );
        $this->assertFalse( ScannerAccessCheck::credentials_allowed( 'https://shop.example.com/a', $home ) );
        $this->assertTrue( ScannerAccessCheck::credentials_allowed( 'http://example.com/a', 'http://example.com' ), 'http home, http URL: allowed' );
    }

    // ---- AC-7 helpers + Review Focus 2 ------------------------------------------------
    public function test_normalise_page_dedupes_fragment_and_empty_path(): void {
        $this->assertSame(
            ScannerAccessCheck::normalise_page( 'https://Example.com' ),
            ScannerAccessCheck::normalise_page( 'https://example.com/#top' )
        );
        $this->assertNotSame(
            ScannerAccessCheck::normalise_page( 'https://example.com/?p=1' ),
            ScannerAccessCheck::normalise_page( 'https://example.com/' )
        );
    }

    public function test_root_of_keeps_host_as_written_and_port(): void {
        $this->assertSame( 'https://www.example.com/', ScannerAccessCheck::root_of( 'https://www.example.com/shop/item' ) );
        $this->assertSame( 'http://example.com:8080/', ScannerAccessCheck::root_of( 'http://example.com:8080/a' ) );
    }

    // ---- AC-11 (PHP side) + Review Focus 3 --------------------------------------------
    public function test_extract_reference_finds_reference_id(): void {
        $body = '<html><body><h1>Request Blocked</h1><p>Reference ID WAF-425</p><p>If you believe…</p></body></html>';
        $this->assertSame( [ 'label' => 'Reference ID', 'value' => 'WAF-425' ], ScannerAccessCheck::extract_reference( $body ) );
    }

    /** PF1: tag stripping inserts no space between elements; a minified block page must still yield its code. */
    public function test_extract_reference_survives_minified_html_without_whitespace_between_tags(): void {
        $body = '<h1>Request Blocked</h1><p>Reference ID WAF-425</p>';
        $this->assertSame( [ 'label' => 'Reference ID', 'value' => 'WAF-425' ], ScannerAccessCheck::extract_reference( $body ) );
    }

    /** PF11: invalid UTF-8 (a Latin-1 page, or a multibyte char split by the 32 KB cut) must not lose the reference. */
    public function test_extract_reference_survives_invalid_utf8_bytes(): void {
        $latin1 = "<p>Acc\xE8s refus\xE9</p><p>Reference ID WAF-425</p>";
        $this->assertSame( [ 'label' => 'Reference ID', 'value' => 'WAF-425' ], ScannerAccessCheck::extract_reference( $latin1 ) );
        $split = "<p>caf\xC3</p> <p>Reference ID WAF-425</p>";
        $this->assertSame( [ 'label' => 'Reference ID', 'value' => 'WAF-425' ], ScannerAccessCheck::extract_reference( $split ) );
    }

    /** PF18 / E10: a candidate value must contain a digit, so prose "reference" never becomes the code. */
    public function test_extract_reference_skips_prose_reference_before_real_code(): void {
        $r = ScannerAccessCheck::extract_reference( '<p>Please quote this reference when contacting support. Reference ID: WAF-425</p>' );
        $this->assertSame( [ 'label' => 'Reference ID', 'value' => 'WAF-425' ], $r );
    }

    public function test_extract_reference_prose_only_returns_null(): void {
        $this->assertNull( ScannerAccessCheck::extract_reference( '<p>Your reference number is shown below.</p>' ) );
    }

    public function test_extract_reference_plural_ids_prose_returns_null(): void {
        $this->assertNull( ScannerAccessCheck::extract_reference( '<p>Reference IDs are logged.</p>' ) );
    }

    public function test_extract_reference_normalises_label(): void {
        $r = ScannerAccessCheck::extract_reference( '<p>support   id: ABC123.x</p>' );
        $this->assertSame( [ 'label' => 'Support ID', 'value' => 'ABC123.x' ], $r );
    }

    public function test_extract_reference_rejects_hostile_or_long_tokens(): void {
        $this->assertNull( ScannerAccessCheck::extract_reference( '<p>Reference ID "><script>x</script></p>' ) );
        $this->assertNull( ScannerAccessCheck::extract_reference( '<p>Reference ID ' . str_repeat( 'A', 200 ) . '</p>' ) );
        $this->assertNull( ScannerAccessCheck::extract_reference( '<script>var t="Reference ID JS-1";</script>' ) );
    }

    public function test_extract_reference_ignores_text_after_32kb(): void {
        $body = str_repeat( 'x', 40000 ) . ' Reference ID LATE-1';
        $this->assertNull( ScannerAccessCheck::extract_reference( $body ) );
        $this->assertNull( ScannerAccessCheck::extract_reference( "\x00\xff\xfe" . str_repeat( "\x01", 10 ) ) );
    }

    public function test_user_agents(): void {
        $this->assertSame(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36 (compatible; CU-Scanner/1.0; +https://wpservice.pro/bot)',
            ScannerAccessCheck::scanner_user_agent()
        );
        $this->assertSame(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36',
            ScannerAccessCheck::control_user_agent()
        );
        $this->assertStringNotContainsString( 'CU-Scanner', ScannerAccessCheck::control_user_agent() );
    }

    // ---- request-sequence harness ------------------------------------------------------
    /** @var list<array{url:string,args:array}> */
    private array $calls = [];

    /**
     * Install a scripted wp_safe_remote_get. $script(url, args, n) returns:
     *   int status | ['status'=>int,'body'=>string] | 'error' (WP_Error).
     */
    private function http( callable $script ): void {
        $this->calls = [];
        WP_Mock::userFunction( 'wp_safe_remote_get' )->andReturnUsing( function ( $url, $args ) use ( $script ) {
            $this->calls[] = [ 'url' => $url, 'args' => $args ];
            $r = $script( $url, $args, count( $this->calls ) );
            if ( 'error' === $r ) {
                return new \WP_Error( 'http_request_failed', 'timeout' );
            }
            $r = is_int( $r ) ? [ 'status' => $r, 'body' => '' ] : $r;
            return [ 'response' => [ 'code' => $r['status'] ], 'headers' => [], 'body' => $r['body'] ];
        } );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturnUsing( fn( $r ) => $r['response']['code'] ?? '' );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturnUsing( fn( $r ) => $r['body'] ?? '' );
    }

    private function ua( int $i ): string { return $this->calls[ $i ]['args']['headers']['User-Agent']; }
    private function is_a( array $a ): bool { return 'CU-Scanner-Probe/1.0 (target-stack-detection)' === $a['headers']['User-Agent']; }
    private function is_b( array $a ): bool { return str_contains( $a['headers']['User-Agent'], 'CU-Scanner/1.0' ); }
    private function is_c( array $a ): bool { return ! str_contains( $a['headers']['User-Agent'], 'CU-Scanner' ); }

    private function check( array $by_host, string $home = 'https://example.com', string $secret = 's3cret', ?array $auth = null, ?callable $clock = null ): array {
        return ( new ScannerAccessCheck( $secret, $auth, $home, $clock ) )->check_groups( $by_host );
    }

    private const BLOCK = [ 'status' => 425, 'body' => '<h1>Request Blocked</h1><p>Reference ID WAF-425</p>' ];

    // AC-1
    public function test_ac1_name_block_proven_by_control_is_denied(): void {
        $this->http( fn( $u, $a ) => $this->is_c( $a ) ? 200 : self::BLOCK );
        $r = $this->check( [ 'example.net' => [ 'https://example.net/a', 'https://example.net/b' ] ] );
        $h = $r['hosts'][0];
        $this->assertTrue( $r['denied'] );
        $this->assertSame( 'denied', $h['verdict'] );
        $this->assertSame( 5, $h['requests'] );
        $this->assertSame( 425, $h['status'] );
        $this->assertSame( 'Reference ID', $h['reference_label'] );
        $this->assertSame( 'WAF-425', $h['reference'] );
        $this->assertSame( 'https://example.net/a', $this->calls[4]['url'], 'C runs on page 1' );
        $this->assertTrue( $this->is_c( $this->calls[4]['args'] ) );
    }

    public function test_ac1_control_206_also_passes(): void {
        $this->http( fn( $u, $a ) => $this->is_c( $a ) ? 206 : self::BLOCK );
        $this->assertSame( 'denied', $this->check( [ 'example.net' => [ 'https://example.net/a' ] ] )['hosts'][0]['verdict'] );
    }

    // AC-2
    /** @dataProvider control_not_passing */
    public function test_ac2_control_not_passing_is_refused_general( $c ): void {
        $this->http( fn( $u, $a ) => $this->is_c( $a ) ? $c : self::BLOCK );
        $r = $this->check( [ 'example.net' => [ 'https://example.net/a', 'https://example.net/b' ] ] );
        $this->assertFalse( $r['denied'] );
        $this->assertSame( 'refused_general', $r['hosts'][0]['verdict'] );
        $this->assertNull( $r['hosts'][0]['status'] );
    }
    public function control_not_passing(): array {
        return [ [ 403 ], [ 429 ], [ 503 ], [ 401 ], [ 404 ], [ 202 ], [ 301 ], [ 'error' ] ];
    }

    // AC-3 — header allowlist and staging login make B pass (own site)
    public function test_ac3_secret_header_rule_makes_b_pass(): void {
        $this->http( fn( $u, $a ) => ( ( $a['headers']['x-cu-scanner'] ?? '' ) === 's3cret' ) ? 200 : 403 );
        $r = $this->check( [ 'example.com' => [ 'https://example.com/a' ] ] );
        $this->assertSame( 'ok', $r['hosts'][0]['verdict'] );
        $this->assertSame( 2, $r['hosts'][0]['requests'] );
    }
    public function test_ac3_staging_login_makes_b_pass(): void {
        $auth = [ 'username' => 'u', 'password' => 'p' ];
        $this->http( fn( $u, $a ) => ( ( $a['headers']['Authorization'] ?? '' ) === 'Basic ' . base64_encode( 'u:p' ) && $this->is_b( $a ) ) ? 200 : 403 );
        $this->assertSame( 'ok', $this->check( [ 'example.com' => [ 'https://example.com/a' ] ], 'https://example.com', 's3cret', $auth )['hosts'][0]['verdict'] );
    }

    // AC-4 — which identity carries which header
    public function test_ac4_headers_own_site(): void {
        $auth = [ 'username' => 'u', 'password' => 'p' ];
        $this->http( fn( $u, $a ) => $this->is_c( $a ) ? 200 : 403 );
        $this->check( [ 'example.com' => [ 'https://example.com/a' ] ], 'https://example.com', 's3cret', $auth );
        foreach ( $this->calls as $c ) {
            $h = $c['args']['headers'];
            if ( $this->is_a( $c['args'] ) ) { $this->assertArrayNotHasKey( 'x-cu-scanner', $h ); $this->assertArrayNotHasKey( 'Authorization', $h ); }
            if ( $this->is_b( $c['args'] ) ) { $this->assertSame( 's3cret', $h['x-cu-scanner'] ); $this->assertSame( 'Basic ' . base64_encode( 'u:p' ), $h['Authorization'] ); }
            if ( $this->is_c( $c['args'] ) ) { $this->assertArrayNotHasKey( 'x-cu-scanner', $h ); $this->assertSame( 'Basic ' . base64_encode( 'u:p' ), $h['Authorization'] ); }
        }
    }
    public function test_ac4_no_credentials_to_external_or_http_downgrade(): void {
        $auth = [ 'username' => 'u', 'password' => 'p' ];
        $this->http( fn( $u, $a ) => $this->is_c( $a ) ? 200 : 403 );
        $this->check( [ 'example.net' => [ 'https://example.net/a' ], 'example.com' => [ 'http://example.com/a' ] ], 'https://example.com', 's3cret', $auth );
        foreach ( $this->calls as $c ) {
            $this->assertArrayNotHasKey( 'x-cu-scanner', $c['args']['headers'], $c['url'] );
            $this->assertArrayNotHasKey( 'Authorization', $c['args']['headers'], $c['url'] );
        }
    }

    // Review Focus 4
    public function test_b_omits_empty_secret_and_incomplete_auth(): void {
        $this->http( fn( $u, $a ) => $this->is_c( $a ) ? 200 : 403 );
        $this->check( [ 'example.com' => [ 'https://example.com/a' ] ], 'https://example.com', '', [ 'username' => 'u', 'password' => '' ] );
        foreach ( $this->calls as $c ) {
            $this->assertArrayNotHasKey( 'x-cu-scanner', $c['args']['headers'] );
            $this->assertArrayNotHasKey( 'Authorization', $c['args']['headers'] );
        }
    }

    // AC-5
    public function test_ac5_normal_site_costs_one_request(): void {
        $this->http( fn() => 200 );
        $r = $this->check( [ 'example.net' => [ 'https://example.net/a', 'https://example.net/b' ] ] );
        $this->assertSame( 'ok', $r['hosts'][0]['verdict'] );
        $this->assertCount( 1, $this->calls );
    }
    public function test_ac5_a_refused_b_ok_costs_two(): void {
        $this->http( fn( $u, $a ) => $this->is_a( $a ) ? 403 : 200 );
        $r = $this->check( [ 'example.net' => [ 'https://example.net/a' ] ] );
        $this->assertSame( [ 'ok', 2 ], [ $r['hosts'][0]['verdict'], $r['hosts'][0]['requests'] ] );
    }
    public function test_ac5_page2_a_ok_is_ok(): void {
        $this->http( fn( $u ) => str_ends_with( $u, '/b' ) ? 200 : 403 );
        $r = $this->check( [ 'example.net' => [ 'https://example.net/a', 'https://example.net/b' ] ] );
        $this->assertSame( [ 'ok', 3 ], [ $r['hosts'][0]['verdict'], $r['hosts'][0]['requests'] ] );
    }

    // AC-6
    /** @dataProvider refused_codes */
    public function test_ac6_each_refused_code_denies( int $code ): void {
        $this->http( fn( $u, $a ) => $this->is_c( $a ) ? 200 : $code );
        $this->assertSame( 'denied', $this->check( [ 'example.net' => [ 'https://example.net/a', 'https://example.net/b' ] ] )['hosts'][0]['verdict'] );
    }
    public function refused_codes(): array { return [ [ 403 ], [ 406 ], [ 418 ], [ 425 ], [ 451 ] ]; }

    /** @dataProvider not_refused_codes */
    public function test_ac6_not_refused_codes_are_ok( int $code ): void {
        $this->http( fn() => $code );
        $this->assertSame( 'ok', $this->check( [ 'example.net' => [ 'https://example.net/a' ] ] )['hosts'][0]['verdict'] );
    }
    public function not_refused_codes(): array { return [ [ 429 ], [ 500 ], [ 503 ], [ 401 ], [ 404 ] ]; }

    public function test_ac6_page1_a_error_is_unclear(): void {
        $this->http( fn() => 'error' );
        $r = $this->check( [ 'example.net' => [ 'https://example.net/a' ] ] );
        $this->assertSame( [ 'unclear', 1 ], [ $r['hosts'][0]['verdict'], $r['hosts'][0]['requests'] ] );
    }

    // AC-7
    public function test_ac7_page2_equal_to_page1_is_skipped(): void {
        $this->http( fn( $u, $a ) => $this->is_c( $a ) ? 200 : 403 );
        $r = $this->check( [ 'example.net' => [ 'https://example.net/' ] ] );
        $this->assertSame( [ 'denied', 3 ], [ $r['hosts'][0]['verdict'], $r['hosts'][0]['requests'] ], 'A, B on page 1, then C; the root fallback equals page 1' );
    }
    public function test_ac7_root_fallback_keeps_www_and_port(): void {
        $this->http( fn( $u, $a ) => $this->is_c( $a ) ? 200 : 403 );
        $this->check( [ 'example.net' => [ 'https://www.example.net:8443/a' ] ], 'https://example.com' );
        $this->assertSame( 'https://www.example.net:8443/', $this->calls[2]['url'] );
    }
    public function test_ac7_page2_redirect_is_treated_as_skipped(): void {
        $this->http( function ( $u, $a ) {
            if ( $this->is_c( $a ) ) { return 200; }
            return str_ends_with( $u, '.net/' ) ? 301 : 403;
        } );
        $r = $this->check( [ 'example.net' => [ 'https://example.net/a' ] ] );
        $this->assertSame( 'denied', $r['hosts'][0]['verdict'], 'a redirecting root must not hide page 1’s block' );
    }

    // AC-8
    public function test_ac8_budget_exhaustion_marks_current_and_remaining_unclear(): void {
        $t = 0.0;
        $clock = function () use ( &$t ) { return $t; };
        $this->http( function () use ( &$t ) { $t += 8.0; return 403; } );
        $r = $this->check( [ 'example.net' => [ 'https://example.net/a' ], 'example.org' => [ 'https://example.org/a' ] ], 'https://example.com', 's3cret', null, $clock );
        $this->assertSame( 'unclear', $r['hosts'][0]['verdict'] );
        $this->assertSame( 'unclear', $r['hosts'][1]['verdict'] );
        $this->assertSame( 0, $r['hosts'][1]['requests'] );
        $this->assertCount( 3, $this->calls, 'sent at t=0, 8, 16 (4 s left); at t=24 the budget is gone, so the 4th is never sent' );
        foreach ( $this->calls as $c ) {
            $this->assertLessThanOrEqual( 6.0, $c['args']['timeout'] );
        }
    }
    public function test_ac8_more_than_five_hosts_are_unchecked(): void {
        $this->http( fn() => 200 );
        $by_host = [];
        foreach ( range( 1, 7 ) as $i ) { $by_host[ "h{$i}.example.net" ] = [ "https://h{$i}.example.net/" ]; }
        $r = $this->check( $by_host );
        $this->assertSame( [ 'ok', 'ok', 'ok', 'ok', 'ok', 'unchecked', 'unchecked' ], array_column( $r['hosts'], 'verdict' ) );
        $this->assertCount( 5, $this->calls );
    }

    // AC-15
    public function test_ac15_no_redirect_is_followed(): void {
        $this->http( fn() => [ 'status' => 301, 'body' => '' ] );
        $r = $this->check( [ 'example.com' => [ 'https://example.com/moved' ] ] );
        $this->assertSame( 'ok', $r['hosts'][0]['verdict'] );
        foreach ( $this->calls as $c ) {
            $this->assertSame( 0, $c['args']['redirection'] );
            $this->assertSame( 32768, $c['args']['limit_response_size'] );
            $this->assertStringStartsWith( 'https://example.com/', $c['url'] );
        }
    }

    public function test_request_style(): void {
        $this->http( fn() => 200 );
        $this->check( [ 'example.net' => [ 'https://example.net/a' ] ] );
        $a = $this->calls[0]['args'];
        $this->assertSame( 'bytes=0-32767', $a['headers']['Range'] );
        $this->assertSame( 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', $a['headers']['Accept'] );
        $this->assertTrue( $a['sslverify'] );
    }

    /** PF12 + house rule: redirection 0 and a 32 KB response cap on EVERY request, A, B and C alike. */
    public function test_every_request_has_redirection_0_and_size_cap(): void {
        $this->http( fn( $u, $a ) => $this->is_c( $a ) ? 200 : self::BLOCK );
        $this->check( [ 'example.com' => [ 'https://example.com/a' ] ], 'https://example.com', 's3cret', [ 'username' => 'u', 'password' => 'p' ] );
        $this->assertCount( 5, $this->calls );
        $seen = [];
        foreach ( $this->calls as $c ) {
            $this->assertSame( 0, $c['args']['redirection'], $c['url'] );
            $this->assertSame( 32768, $c['args']['limit_response_size'], $c['url'] );
            $seen[] = $this->is_a( $c['args'] ) ? 'A' : ( $this->is_b( $c['args'] ) ? 'B' : 'C' );
        }
        $this->assertSame( [ 'A', 'B', 'A', 'B', 'C' ], $seen );
    }

    /** Rule 10: the Scanner Secret and the staging login never appear in the returned array. */
    public function test_secrets_never_returned(): void {
        $this->http( fn( $u, $a ) => $this->is_c( $a ) ? 200 : [ 'status' => 403, 'body' => 'Reference ID REF-1 s3cret' ] );
        $r    = $this->check( [ 'example.com' => [ 'https://example.com/a' ] ], 'https://example.com', 's3cret', [ 'username' => 'stageuser', 'password' => 'stagepass' ] );
        $json = (string) json_encode( $r );
        $this->assertSame( 'denied', $r['hosts'][0]['verdict'] );
        foreach ( [ 's3cret', 'stageuser', 'stagepass', base64_encode( 'stageuser:stagepass' ) ] as $needle ) {
            $this->assertStringNotContainsString( $needle, $json );
        }
    }

    /** A hostless / empty page 1 (never sent by Task 4, which validates http(s)) must not fatal. */
    public function test_hostless_page1_does_not_fatal(): void {
        $this->http( fn() => 'error' );
        $r = $this->check( [ 'x' => [ 'https:///' ], 'y' => [ '' ], 'z' => [], 'w' => 'not-a-list' ] );
        $this->assertSame( [ 'unclear', 'unclear' ], array_column( $r['hosts'], 'verdict' ) );
        $this->assertFalse( $r['denied'] );
    }

    /** AC-8: per-request timeout = min( 6 s, remaining budget ) — the page-2 A at t=16 gets 4 s. */
    public function test_ac8_timeout_capped_by_remaining_budget(): void {
        $t     = 0.0;
        $clock = function () use ( &$t ) { return $t; };
        $this->http( function () use ( &$t ) { $t += 8.0; return 403; } );
        $this->check( [ 'example.net' => [ 'https://example.net/a' ] ], 'https://example.com', 's3cret', null, $clock );
        $this->assertSame( [ 6.0, 6.0, 4.0 ], array_map( fn( $c ) => (float) $c['args']['timeout'], $this->calls ) );
    }
}
