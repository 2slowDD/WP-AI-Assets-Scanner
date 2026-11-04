<?php
// tests/RailwayClientFailoverTest.php
namespace DrSpeedAIAS\Tests;

use DrSpeedAIAS\Api\HttpException;
use DrSpeedAIAS\Api\RailwayClient;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * FU-WORKER-CLOUDFLARE-FRONT (spec §3.3, AC-3) — one request path: scanner.wpservice.pro first, the direct
 * Railway host as the never-delivered fallback; demotion marks (never exclusion); one total deadline per
 * call split across attempts with a 5 s floor. Drives the REAL public methods; wp_remote_* is stubbed at
 * the WordPress boundary and asserted by URL.
 */
class RailwayClientFailoverTest extends TestCase {

    private const CF_MARK   = 'drspeed_aias_worker_demoted_scanner-wpservice-pro';
    private const CF_FAILS  = 'drspeed_aias_worker_fails_scanner-wpservice-pro';
    private const CONNECT   = 'cURL error 28: Connection timed out after 10001 milliseconds';

    /** @var array<string, mixed> */
    private array $store = [];
    /** @var array<string, int> TTL passed to set_transient, by key */
    private array $ttl = [];

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        $this->store = [];
        $this->ttl   = [];
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $u, $c = -1 ) => parse_url( $u, $c ) );
        WP_Mock::userFunction( 'get_transient' )->andReturnUsing( fn( $k ) => $this->store[ $k ] ?? false );
        WP_Mock::userFunction( 'set_transient' )->andReturnUsing( function ( $k, $v, $ttl = 0 ) { $this->store[ $k ] = $v; $this->ttl[ $k ] = (int) $ttl; return true; } );
        WP_Mock::userFunction( 'delete_transient' )->andReturnUsing( function ( $k ) { unset( $this->store[ $k ] ); return true; } );
        WP_Mock::userFunction( 'is_wp_error' )->andReturnUsing( fn( $r ) => $r instanceof \WP_Error );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturnUsing( fn( $r ) => (int) ( $r['response']['code'] ?? 0 ) );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturnUsing( fn( $r ) => (string) ( $r['body'] ?? '' ) );
        WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( fn( $v ) => json_encode( $v ) );
    }

    public function tearDown(): void {
        RailwayClient::$clock = null;
        WP_Mock::tearDown();
        parent::tearDown();
    }

    private function client(): RailwayClient {
        // The stored URL is the SaaS-advertised Railway address: an allowlist GATE, not the order.
        return new RailwayClient( 'https://cu-scanner-railway-production.up.railway.app', 'k' );
    }

    private function ok( string $body = '{}' ): array {
        return [ 'response' => [ 'code' => 200 ], 'body' => $body ];
    }

    public function test_submit_tries_cloudflare_first_then_direct_with_the_remaining_budget(): void {
        $calls = [];
        WP_Mock::userFunction( 'wp_remote_post' )->andReturnUsing( function ( $url, $args ) use ( &$calls ) {
            $calls[] = [ $url, $args['timeout'] ];
            return count( $calls ) === 1 ? new \WP_Error( 'http_request_failed', self::CONNECT ) : $this->ok( '{"job_id":"J"}' );
        } );
        $r = $this->client()->submit_job( [ 'job_token' => 'T', 'pages' => [] ] );
        $this->assertSame( 'J', $r['job_id'] );
        $this->assertSame( 'https://scanner.wpservice.pro/jobs', $calls[0][0] );
        $this->assertSame( 29, $calls[0][1], 'first attempt gets deadline − 1' );
        $this->assertSame( 'https://cu-scanner-railway-production.up.railway.app/jobs', $calls[1][0] );
        $this->assertGreaterThanOrEqual( 5, $calls[1][1] );
        $this->assertLessThanOrEqual( 30, $calls[1][1] );
    }

    public function test_post_never_resends_on_524_or_read_timeout(): void {
        $cases = [
            '524'          => [ 'response' => [ 'code' => 524 ], 'body' => '' ],
            'read timeout' => new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 30000 milliseconds with 0 bytes received' ),
        ];
        // One stub for the whole test: a second userFunction() for the same name would only ADD an expectation
        // behind the first, unbounded one (Mockery matches in definition order), so the stub reads $first by reference.
        $n     = 0;
        $first = null;
        WP_Mock::userFunction( 'wp_remote_post' )->andReturnUsing( function () use ( &$n, &$first ) { $n++; return $first; } );
        foreach ( $cases as $label => $case ) {
            $this->store = [];
            $n           = 0;
            $first       = $case;
            try {
                $this->client()->submit_job( [ 'job_token' => 'T', 'pages' => [] ] );
                $this->fail( 'expected HttpException on ' . $label );
            } catch ( HttpException $e ) {
                // delivered: the worker's / Cloudflare's answer is surfaced, never retried
            }
            $this->assertSame( 1, $n, 'one submit per click on ' . $label );
            $this->assertArrayNotHasKey( self::CF_FAILS, $this->store, 'a delivered failure never counts toward demotion (' . $label . ')' );
        }
    }

    public function test_worker_401_is_returned_without_a_second_attempt(): void {
        $n = 0;
        WP_Mock::userFunction( 'wp_remote_get' )->andReturnUsing( function () use ( &$n ) { $n++; return [ 'response' => [ 'code' => 401 ], 'body' => '{"error":"bad token"}' ]; } );
        try {
            $this->client()->get_status( 'J', 'T' );
            $this->fail( 'expected HttpException' );
        } catch ( HttpException $e ) {
            $this->assertSame( 401, $e->get_status_code() );
        }
        $this->assertSame( 1, $n );
    }

    public function test_all_hosts_undelivered_throws_status_0_retryable(): void {
        WP_Mock::userFunction( 'wp_remote_get' )->andReturn( new \WP_Error( 'http_request_failed', self::CONNECT ) );
        try {
            $this->client()->get_status( 'J', 'T' );
            $this->fail( 'expected HttpException' );
        } catch ( HttpException $e ) {
            $this->assertSame( 0, $e->get_status_code() );
        }
        $this->assertTrue( \DrSpeedAIAS\Scanner\Outbox::is_retryable( new HttpException( 'x', 0 ) ) );
    }

    public function test_demotion_two_failures_then_direct_first_then_success_clears(): void {
        $urls = [];
        $down = 'scanner.wpservice.pro';                        // which host is unreachable in the current phase
        WP_Mock::userFunction( 'wp_remote_get' )->andReturnUsing( function ( $url ) use ( &$urls, &$down ) {
            $urls[] = $url;
            return str_contains( $url, $down ) ? new \WP_Error( 'http_request_failed', self::CONNECT ) : $this->ok();
        } );
        $this->client()->get_status( 'J', 'T' );              // CF fails (1), direct ok
        $this->assertArrayNotHasKey( self::CF_MARK, $this->store, 'one failure is not a mark' );
        $this->assertSame( 600, $this->ttl[ self::CF_FAILS ] ?? null, 'the failure window is 10 min' );
        $this->client()->get_status( 'J', 'T' );              // CF fails (2) → demoted, direct ok
        $this->assertNotEmpty( $this->store[ self::CF_MARK ] ?? null );
        $this->assertSame( 600, $this->ttl[ self::CF_MARK ], 'a mark lasts 10 min' );
        $this->assertArrayNotHasKey( self::CF_FAILS, $this->store, 'marking resets the counter' );

        $urls = [];
        $this->client()->get_status( 'J', 'T' );              // demoted: direct FIRST, and it answers
        $this->assertStringContainsString( 'up.railway.app', $urls[0] );
        $this->assertCount( 1, $urls );

        // blocked-host shape: direct fails, CF succeeds second → mark cleared on that success
        $this->store = [ self::CF_MARK => time() ];
        $down        = 'up.railway.app';
        $urls        = [];
        $this->client()->get_status( 'J', 'T' );
        $this->assertSame( 2, count( $urls ), 'direct first (demoted CF last), direct fails, CF answers' );
        $this->assertStringContainsString( 'scanner.wpservice.pro', $urls[1] );
        $this->assertArrayNotHasKey( self::CF_MARK, $this->store, 'one success clears the mark' );
        $this->assertArrayNotHasKey( self::CF_FAILS, $this->store, '…and resets the counter' );
    }

    public function test_marks_never_exclude_and_a_failure_while_marked_does_not_start_a_new_counter(): void {
        // Group S review M2: a failure recorded WHILE a host is demoted must not seed a counter that outlives
        // the mark (spec §3.3: the counter resets on expiry; re-marking needs two NEW failures).
        $this->store = [ self::CF_MARK => time() ];
        $urls        = [];
        WP_Mock::userFunction( 'wp_remote_get' )->andReturnUsing( function ( $url ) use ( &$urls ) {
            $urls[] = $url;
            return new \WP_Error( 'http_request_failed', self::CONNECT );   // both hosts down
        } );
        try {
            $this->client()->get_status( 'J', 'T' );
        } catch ( HttpException $e ) {
            $this->assertSame( 0, $e->get_status_code() );
        }
        $this->assertCount( 2, $urls, 'a marked host is still tried, last' );
        $this->assertStringContainsString( 'up.railway.app', $urls[0] );
        $this->assertStringContainsString( 'scanner.wpservice.pro', $urls[1] );
        $this->assertArrayNotHasKey( self::CF_FAILS, $this->store, 'no counter is seeded while the host is marked' );
        $this->assertArrayHasKey( self::CF_MARK, $this->store, 'the mark is left to expire on its own' );
    }

    public function test_no_second_attempt_when_the_remainder_is_under_the_5s_floor(): void {
        // The first attempt consumes the budget: the client reads the clock through a settable seam.
        $t = 1000.0;
        RailwayClient::$clock = function () use ( &$t ) { return $t; };
        $n = 0;
        WP_Mock::userFunction( 'wp_remote_get' )->andReturnUsing( function () use ( &$n, &$t ) { $n++; $t += 12.0; return new \WP_Error( 'http_request_failed', self::CONNECT ); } );
        try {
            $this->client()->get_status( 'J', 'T' );
        } catch ( HttpException $e ) {
            $this->assertSame( 0, $e->get_status_code() );
        }
        $this->assertSame( 1, $n, '15 s deadline − 12 s elapsed = 3 s < floor 5 s → no second attempt' );
    }

    public function test_fallback_gets_the_whole_second_remainder(): void {
        // 30 s submit, Cloudflare answered 522 after ~16 s (its own origin wait): the direct attempt gets 30 − 16 = 14 s.
        $t = 1000.0;
        RailwayClient::$clock = function () use ( &$t ) { return $t; };
        $calls = [];
        WP_Mock::userFunction( 'wp_remote_post' )->andReturnUsing( function ( $url, $args ) use ( &$calls, &$t ) {
            $calls[] = $args['timeout'];
            if ( count( $calls ) === 1 ) { $t += 16.4; return [ 'response' => [ 'code' => 522 ], 'body' => '' ]; }
            return $this->ok( '{"job_id":"J"}' );
        } );
        $this->client()->submit_job( [ 'job_token' => 'T', 'pages' => [] ] );
        $this->assertSame( [ 29, 14 ], $calls, 'elapsed rounds DOWN to whole seconds (16.4 → 16): the fallback gets 30 − 16 = 14 s' );
    }
}
