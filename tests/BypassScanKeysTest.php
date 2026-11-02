<?php
namespace DrSpeedAIAS\Tests;

use DrSpeedAIAS\Scanner\BypassHandler;
use DrSpeedAIAS\Scanner\PluginDetector;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class BypassScanKeysTest extends TestCase {

    /** @var array<int, mixed> Every value handed to sanitize_text_field during a test. */
    private array $sanitized = [];

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        $_GET            = [];
        $this->sanitized = [];
        WP_Mock::userFunction( 'wp_unslash' )->andReturnArg( 0 );
        WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing(
            function ( $v ) {
                $this->sanitized[] = $v;
                return is_string( $v ) ? $v : '';
            }
        );
    }

    public function tearDown(): void {
        $_GET = [];
        BypassHandler::for_testing_set_token_validator( null );
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_vocabulary_holds_every_key_as_a_bare_name(): void {
        $keys = PluginDetector::scan_query_keys();
        foreach ( [ 'cu_scan_token', 'cu_dep_graph', 'nowpcu', 'nowprocket', 'perfmattersoff', 'ao_noptimize', 'nonitro', 'wpacu_no_load', 'LSCWP_CTRL', 'swis_disable', 'no_optimize' ] as $key ) {
            $this->assertContains( $key, $keys );
        }
        $this->assertSame( array_values( array_unique( $keys ) ), $keys, 'no duplicates' );
        foreach ( $keys as $key ) {
            $this->assertStringNotContainsString( '=', $key );
        }
    }

    /** Rule 38: this runs on front-end requests, so it must read consts only. Other tests can define is_plugin_active, so check the source. */
    public function test_vocabulary_calls_no_admin_function(): void {
        $method = new \ReflectionMethod( PluginDetector::class, 'scan_query_keys' );
        $lines  = file( (string) $method->getFileName() );
        $body   = implode( '', array_slice( $lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );
        foreach ( [ 'is_plugin_active', 'get_plugin_data', 'get_plugins', 'get_option' ] as $call ) {
            $this->assertStringNotContainsString( $call, $body );
        }
    }

    public function test_a_valid_token_widens_the_list(): void {
        $_GET['cu_scan_token'] = 'good';
        BypassHandler::for_testing_set_token_validator( static fn ( $t ) => 'good' === $t );
        $out = BypassHandler::allow_scan_keys( [ 'utm_source' ] );
        $this->assertContains( 'utm_source', $out );
        $this->assertContains( 'nowprocket', $out );
        $this->assertContains( 'cu_scan_token', $out );
    }

    public function test_no_token_returns_the_input_untouched(): void {
        $this->assertSame( [ 'utm_source' ], BypassHandler::allow_scan_keys( [ 'utm_source' ] ) );
    }

    public function test_a_bad_token_returns_the_input_untouched(): void {
        $_GET['cu_scan_token'] = 'bad';
        BypassHandler::for_testing_set_token_validator( static fn ( $t ) => false );
        $this->assertSame( [ 'utm_source' ], BypassHandler::allow_scan_keys( [ 'utm_source' ] ) );
    }

    public function test_an_array_token_cannot_widen_or_fatal(): void {
        $_GET['cu_scan_token'] = [ 'x' ];
        BypassHandler::for_testing_set_token_validator( static fn ( $t ) => true );
        $this->assertSame( [ 'utm_source' ], BypassHandler::allow_scan_keys( [ 'utm_source' ] ) );
        // The sanitizer also turns an array into '', which would hide a missing is_string() check above.
        $this->assertSame( [], $this->sanitized, 'an array token stops at the is_string check' );
    }

    /** Rule 29: a hook callback gets whatever the caller holds. */
    public function test_non_array_input_returns_untouched(): void {
        $_GET['cu_scan_token'] = 'good';
        BypassHandler::for_testing_set_token_validator( static fn ( $t ) => true );
        $this->assertNull( BypassHandler::allow_scan_keys( null ) );
        $this->assertSame( 'x', BypassHandler::allow_scan_keys( 'x' ) );
    }

    /** P17: the logic above is unreachable unless init() really hooks it onto Yoast's filter. */
    public function test_init_registers_the_yoast_allowlist_filter(): void {
        WP_Mock::expectFilterAdded( 'Yoast\WP\SEO\allowlist_permalink_vars', [ BypassHandler::class, 'allow_scan_keys' ] );
        BypassHandler::init();
        $this->assertConditionsMet();
    }
}
