<?php
namespace DrSpeedAIAS\Tests;

use DRSPEED_AIAS_Scan_Status;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Spec §4.1, AC-4. Input = real worker output captured by the worker's production-path test.
 * tests/js/fixtures/bypass-lost-rows.json is this method's output, so the JS render test
 * (AC-5) starts from it and the chain from worker to render is unbroken.
 * Regenerate: UPDATE_BYPASS_ROWS=1 vendor/bin/phpunit tests/ScanStatusBypassLostTest.php
 */
class ScanStatusBypassLostTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( static fn ( $u, $c = -1 ) => parse_url( $u, $c ) );
        WP_Mock::userFunction( 'home_url' )->andReturn( 'https://www.own.example/' );
        WP_Mock::userFunction( '__' )->andReturnArg( 0 );
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    private function row( array $o ): array {
        $page = array_merge( [ 'url' => 'https://other.example/', 'status' => 'done', 'assets' => [], 'broken_devices' => [] ], $o );
        return DRSPEED_AIAS_Scan_Status::build_pages( [ $page ], [] )[0];
    }

    private function wire(): array {
        return json_decode( (string) file_get_contents( DRSPEED_AIAS_DIR . 'tests/fixtures/bypass-lost-wire.json' ), true );
    }

    public function test_real_output_external_yoast(): void {
        $r = $this->row( $this->wire()['external_yoast'] );
        $this->assertTrue( $r['bypass_lost'] );
        $this->assertSame( [ 'nowprocket' ], $r['bypass_keys_lost'] );
        $this->assertSame( 'yoast', $r['bypass_lost_by'] );
        $this->assertSame( 'other.example', $r['bypass_lost_host'] );
        $this->assertTrue( $r['bypass_lost_external'] );
        $this->assertSame( [ 'nowprocket' ], $r['bypass_fix_keys'] );
    }

    public function test_a_row_with_no_fields_reads_as_no_warning(): void {
        $r = $this->row( [] );
        $this->assertFalse( $r['bypass_lost'] );
        $this->assertSame( [], $r['bypass_keys_lost'] );
        $this->assertSame( '', $r['bypass_lost_by'] );
        $this->assertSame( '', $r['bypass_lost_host'] );
        $this->assertFalse( $r['bypass_lost_external'] );
        $this->assertSame( [], $r['bypass_fix_keys'] );
    }

    /** @dataProvider hostile */
    public function test_hostile_input_is_validated_out( array $o, bool $warns, array $keys ): void {
        $r = $this->row( $o );
        $this->assertSame( $warns, $r['bypass_lost'] );
        $this->assertSame( $keys, $r['bypass_keys_lost'] );
    }

    public function hostile(): array {
        return [
            'markup key'      => [ [ 'bypass_lost' => 1, 'bypass_keys_lost' => [ '<script>x</script>', 'nowprocket' ] ], true, [ 'nowprocket' ] ],
            'unknown key'     => [ [ 'bypass_lost' => 1, 'bypass_keys_lost' => [ 'foo' ] ], false, [] ],
            '500 duplicates'  => [ [ 'bypass_lost' => 1, 'bypass_keys_lost' => array_fill( 0, 500, 'nowprocket' ) ], true, [ 'nowprocket' ] ],
            'not an array'    => [ [ 'bypass_lost' => 1, 'bypass_keys_lost' => 'nowprocket' ], false, [] ],
            'non-string keys' => [ [ 'bypass_lost' => 1, 'bypass_keys_lost' => [ [ 'x' ], 3, 'nowprocket' ] ], true, [ 'nowprocket' ] ],
            'string flag'     => [ [ 'bypass_lost' => '1', 'bypass_keys_lost' => [ 'nowprocket' ] ], false, [] ],
            'no key survives' => [ [ 'bypass_lost' => 1, 'bypass_keys_lost' => [] ], false, [] ],
        ];
    }

    public function test_unknown_issuer_is_dropped(): void {
        $this->assertSame( '', $this->row( [ 'bypass_lost' => 1, 'bypass_keys_lost' => [ 'nowprocket' ], 'bypass_lost_by' => '<b>x</b>' ] )['bypass_lost_by'] );
    }

    public function test_a_blocked_row_never_warns(): void {
        $r = $this->row( [
            'status'           => 'error',
            'broken_devices'   => [
                [ 'device' => 'desktop', 'reason' => 'tier2_cf_challenge' ],
                [ 'device' => 'mobile', 'reason' => 'tier2_cf_challenge' ],
            ],
            'bypass_lost'      => 1,
            'bypass_keys_lost' => [ 'nowprocket' ],
        ] );
        $this->assertSame( 'blocked', $r['status_class'] );
        $this->assertFalse( $r['bypass_lost'] );
    }

    public function test_an_error_row_never_warns(): void {
        $r = $this->row( [ 'status' => 'error', 'bypass_lost' => 1, 'bypass_keys_lost' => [ 'nowprocket' ] ] );
        $this->assertSame( 'error', $r['status_class'] );
        $this->assertFalse( $r['bypass_lost'] );
    }

    public function test_a_partial_row_warns(): void {
        $r = $this->row( [
            'broken_devices'   => [ [ 'device' => 'mobile', 'reason' => 'tier2_cf_challenge' ] ],
            'bypass_lost'      => 1,
            'bypass_keys_lost' => [ 'nowprocket' ],
        ] );
        $this->assertSame( 'partial', $r['status_class'] );
        $this->assertTrue( $r['bypass_lost'] );
    }

    public function test_www_and_case_match_the_workers_is_external(): void {
        $r = $this->row( [ 'url' => 'https://OWN.example/p', 'bypass_lost' => 1, 'bypass_keys_lost' => [ 'cu_scan_token' ] ] );
        $this->assertFalse( $r['bypass_lost_external'] );
        $this->assertSame( 'own.example', $r['bypass_lost_host'] );
    }

    public function test_a_junk_url_yields_an_empty_host_and_no_throw(): void {
        $r = $this->row( [ 'url' => 'http://', 'bypass_lost' => 1, 'bypass_keys_lost' => [ 'nowprocket' ] ] );
        $this->assertSame( '', $r['bypass_lost_host'] );
        $this->assertFalse( $r['bypass_lost_external'] );
    }

    /** M1: the worker never compares nowpcu, so the panel adds what the row URL carries. */
    public function test_own_site_fix_keys_add_known_keys_from_the_url(): void {
        $r = $this->row( [ 'url' => 'https://own.example/?nowpcu&foo=1&a[]=2&&nowprocket', 'bypass_lost' => 1, 'bypass_keys_lost' => [ 'cu_scan_token', 'nowprocket' ] ] );
        $this->assertSame( [ 'cu_scan_token', 'nowprocket' ], $r['bypass_keys_lost'], 'the row note claims only what the worker saw' );
        $this->assertSame( [ 'cu_scan_token', 'nowpcu', 'nowprocket' ], $r['bypass_fix_keys'] );
    }

    public function test_external_fix_keys_equal_the_worker_list(): void {
        $r = $this->row( [ 'url' => 'https://other.example/?nowpcu&nowprocket', 'bypass_lost' => 1, 'bypass_keys_lost' => [ 'nowprocket' ] ] );
        $this->assertSame( [ 'nowprocket' ], $r['bypass_fix_keys'] );
    }

    /** The rows AC-5 renders are exactly what build_pages produces from real worker output. */
    public function test_js_fixture_equals_build_pages_output(): void {
        $wire = $this->wire();
        $rows = [
            'external_yoast'   => $this->row( $wire['external_yoast'] + [ 'url' => 'https://other.example/a' ] ),
            'external_generic' => $this->row( $wire['external_generic'] + [ 'url' => 'https://third.example/b' ] ),
            'own_generic'      => $this->row( $wire['own_generic'] + [ 'url' => 'https://www.own.example/c?nowpcu' ] ),
            'own_token_only'   => $this->row( $wire['own_token_only'] + [ 'url' => 'https://own.example/d' ] ),
            'clean'            => $this->row( [ 'url' => 'https://own.example/e', 'bypass_suffixes' => [ 'nowprocket' ] ] ),
            'own_yoast'        => $this->row( $wire['own_yoast'] + [ 'url' => 'https://own.example/f' ] ),
        ];
        $path = DRSPEED_AIAS_DIR . 'tests/js/fixtures/bypass-lost-rows.json';
        if ( getenv( 'UPDATE_BYPASS_ROWS' ) ) {
            file_put_contents( $path, json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
        }
        $this->assertSame( $rows, json_decode( (string) file_get_contents( $path ), true ), 'stale fixture: see the class docblock' );
    }
}
