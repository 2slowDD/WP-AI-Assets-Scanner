<?php
namespace CUScanner\Tests;

use CUScanner\Admin\SettingsAjax;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class ReplaceKeyJsonSent extends \Exception {
    public function __construct( public string $kind, public mixed $payload = null ) {
        parent::__construct( 'json_' . $kind );
    }
}

/**
 * Settings → Replace API key: only a paid key may replace the saved one, and the
 * saved key is never removed unless /auth accepted the replacement as paid.
 */
final class ReplaceKeyTest extends TestCase {

    private const CURRENT_KEY = 'cusk_Freekey_9';
    private const PAID_KEY    = 'cusk_paid_key';
    private const RAILWAY_URL = 'https://cu-scanner-railway-production.up.railway.app';

    /** @var array<int,array{0:string,1:mixed}> */
    private array $writes = [];

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        $this->writes = [];
        $_POST        = [];

        WP_Mock::userFunction( 'check_ajax_referer' )->with( 'cu_scanner_settings_nonce', 'nonce' )->andReturn( 1 );
        WP_Mock::userFunction( 'current_user_can' )->with( 'manage_options' )->andReturn( true );
        WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( fn( $v ) => $v );
        WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( fn( $v ) => $v );
        WP_Mock::userFunction( '__' )->andReturnUsing( fn( $s ) => $s );
        WP_Mock::userFunction( 'get_home_url' )->andReturn( 'https://site.test' );
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $url, $c = -1 ) => parse_url( $url, $c ) );
        WP_Mock::userFunction( 'get_option' )
            ->andReturnUsing( fn( $name, $default = false ) => 'cu_scanner_api_key' === $name ? self::CURRENT_KEY : $default );
        WP_Mock::userFunction( 'update_option' )->andReturnUsing( function ( $name, $value = null ) {
            $this->writes[] = [ $name, $value ];
            return true;
        } );
        WP_Mock::userFunction( 'delete_option' )->andReturn( true );
        WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->andReturn( 0 );
        WP_Mock::userFunction( 'wp_send_json_success' )->andReturnUsing( function ( $data = null ) {
            throw new ReplaceKeyJsonSent( 'success', $data );
        } );
        WP_Mock::userFunction( 'wp_send_json_error' )->andReturnUsing( function ( $data = null ) {
            throw new ReplaceKeyJsonSent( 'error', $data );
        } );
    }

    public function tearDown(): void {
        $_POST = [];
        WP_Mock::tearDown();
        parent::tearDown();
    }

    private function mock_auth( int $code, string $body ): void {
        WP_Mock::userFunction( 'wp_remote_post' )->andReturn( [ 'response' => [ 'code' => $code ] ] );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( $code );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( $body );
    }

    private function no_request_expected(): void {
        WP_Mock::userFunction( 'wp_remote_post' )->never();
        WP_Mock::userFunction( 'wp_remote_get' )->never();
    }

    private function replace(): ReplaceKeyJsonSent {
        try {
            ( new SettingsAjax() )->replace_key();
        } catch ( ReplaceKeyJsonSent $sent ) {
            return $sent;
        }
        $this->fail( 'replace_key() returned without sending a JSON response' );
    }

    /** @return array<int,mixed> */
    private function key_writes(): array {
        return array_values( array_map(
            fn( $w ) => $w[1],
            array_filter( $this->writes, fn( $w ) => 'cu_scanner_api_key' === $w[0] )
        ) );
    }

    public function test_paid_key_replaces_the_current_key_and_reports_its_credits(): void {
        $this->mock_auth( 200, '{"user_id":14,"balance":250,"railway_url":"' . self::RAILWAY_URL . '"}' );
        $_POST['new_api_key'] = self::PAID_KEY;

        $sent = $this->replace();

        $this->assertSame( 'success', $sent->kind );
        $this->assertSame( 250, $sent->payload['credits'], 'the credits that come with the new key are shown' );
        $this->assertSame( [ self::PAID_KEY ], $this->key_writes() );
        $this->assertContains( [ 'aias_railway_url', self::RAILWAY_URL ], $this->writes );
    }

    public function test_free_key_is_refused_before_any_request(): void {
        $this->no_request_expected();
        $_POST['new_api_key'] = 'cusk_Freekey_12';

        $sent = $this->replace();

        $this->assertSame( 'error', $sent->kind );
        $this->assertStringContainsString( 'Only a paid API key', (string) $sent->payload );
        $this->assertSame( [], $this->key_writes() );
    }

    public function test_key_the_service_reports_as_free_is_refused(): void {
        // Defence in depth: a key that does not look free but /auth says is free.
        $this->mock_auth( 200, '{"user_id":0,"free_key_id":3,"balance":5,"key_type":"free","railway_url":"' . self::RAILWAY_URL . '"}' );
        $_POST['new_api_key'] = self::PAID_KEY;

        $sent = $this->replace();

        $this->assertSame( 'error', $sent->kind );
        $this->assertStringContainsString( 'Only a paid API key', (string) $sent->payload );
        $this->assertSame( [], $this->key_writes(), 'a free account replaced the current key' );
    }

    public function test_rejected_key_leaves_the_current_key_in_place(): void {
        $this->mock_auth( 401, '{"message":"Invalid API key"}' );
        $_POST['new_api_key'] = 'cusk_WRONGKEY_999999';

        $sent = $this->replace();

        $this->assertSame( 'error', $sent->kind );
        $this->assertStringContainsString( 'Invalid API key', (string) $sent->payload );
        $this->assertSame( [], $this->key_writes() );
    }

    public function test_empty_and_unchanged_keys_are_refused_without_a_request(): void {
        $this->no_request_expected();

        $_POST['new_api_key'] = '';
        $this->assertSame( 'error', $this->replace()->kind );

        $_POST['new_api_key'] = self::CURRENT_KEY;
        $sent = $this->replace();
        $this->assertSame( 'error', $sent->kind );
        $this->assertSame( [], $this->key_writes() );
    }
}
