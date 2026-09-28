<?php
namespace CUScanner\Tests;

use CUScanner\Admin\SettingsAjax;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class FreeKeyOptInJsonSent extends \Exception {
    public function __construct( public string $kind, public mixed $payload = null ) {
        parent::__construct( 'json_' . $kind );
    }
}

/**
 * WordPress.org guideline 7: the plugin must not contact wpservice.pro until the
 * administrator opts in. These tests pin the three places that used to call out
 * on their own.
 */
final class FreeKeyOptInTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        WP_Mock::userFunction( 'check_ajax_referer' )->andReturn( 1 );
        WP_Mock::userFunction( 'current_user_can' )->with( 'manage_options' )->andReturn( true );
        WP_Mock::userFunction( '__' )->andReturnUsing( fn( $s ) => $s );
        WP_Mock::userFunction( 'wp_send_json_success' )->andReturnUsing(
            function ( $data = null ) {
                throw new FreeKeyOptInJsonSent( 'success', $data );
            }
        );
        WP_Mock::userFunction( 'wp_send_json_error' )->andReturnUsing(
            function ( $data = null ) {
                throw new FreeKeyOptInJsonSent( 'error', $data );
            }
        );
        foreach ( [ 'wp_remote_post', 'wp_remote_get', 'wp_remote_request', 'wp_safe_remote_post', 'wp_safe_remote_get' ] as $fn ) {
            WP_Mock::userFunction( $fn )->never();
        }
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_bootstrap_file_registers_no_activation_or_admin_init_registration(): void {
        $src = (string) file_get_contents( dirname( __DIR__ ) . '/dr-speed-ai-assets-scanner.php' );
        $this->assertStringNotContainsString( 'register_activation_hook', $src );
        $this->assertDoesNotMatchRegularExpression( "/add_action\(\s*'admin_init'/", $src );
    }

    public function test_balance_refresh_makes_no_request_when_no_key_is_saved(): void {
        WP_Mock::userFunction( 'get_option' )->with( 'cu_scanner_api_key', '' )->andReturn( '' );

        try {
            ( new SettingsAjax() )->fetch_balance();
            $this->fail( 'expected a JSON response' );
        } catch ( FreeKeyOptInJsonSent $sent ) {
            $this->assertSame( 'error', $sent->kind );
        }
    }

    public function test_opt_in_is_refused_when_a_key_is_already_saved(): void {
        WP_Mock::userFunction( 'get_option' )->with( 'cu_scanner_api_key', '' )->andReturn( 'cusk_paid_key' );

        try {
            ( new SettingsAjax() )->request_free_key();
            $this->fail( 'expected a JSON response' );
        } catch ( FreeKeyOptInJsonSent $sent ) {
            $this->assertSame( 'error', $sent->kind );
        }
    }

    public function test_failure_message_names_the_real_cause(): void {
        WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( fn( $s ) => $s );
        $http = fn( string $m, int $c ) => new \CUScanner\Api\HttpException( $m, $c );

        $this->assertStringContainsString(
            'too many free-key requests',
            SettingsAjax::free_key_failure_message( $http( 'HTTP 429: Too many free key registration attempts. Try again later.', 429 ) )
        );
        $this->assertStringContainsString(
            'could not issue a free key: HTTP 500: Could not allocate a free API key. The plugin',
            SettingsAjax::free_key_failure_message( $http( 'HTTP 500: Could not allocate a free API key.', 500 ) )
        );
        $this->assertStringContainsString(
            'Could not reach wpservice.pro (cURL error 28: timed out)',
            SettingsAjax::free_key_failure_message( $http( 'cURL error 28: timed out', 0 ) )
        );
        $this->assertStringContainsString(
            'did not answer',
            SettingsAjax::free_key_failure_message( null )
        );
        $this->assertLessThan(
            400,
            strlen( SettingsAjax::free_key_failure_message( $http( 'HTTP 500: ' . str_repeat( 'x', 5000 ), 500 ) ) ),
            'a huge error body must not be echoed whole'
        );
    }
}
