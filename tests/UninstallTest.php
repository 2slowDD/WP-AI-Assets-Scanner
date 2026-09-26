<?php

use WP_Mock\Tools\TestCase;

class UninstallTest extends TestCase {
    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_uninstall_removes_plugin_data_but_never_the_saas_plugins_options(): void {
        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }

        WP_Mock::userFunction( 'current_user_can' )
            ->with( 'delete_plugins' )
            ->andReturn( true );

        $deleted_options    = [];
        $deleted_transients = [];
        $unscheduled        = [];

        WP_Mock::userFunction( 'wp_unschedule_hook' )->andReturnUsing(
            static function ( $hook ) use ( &$unscheduled ) {
                $unscheduled[] = $hook;
                return 0;
            }
        );
        WP_Mock::userFunction( 'delete_option' )->andReturnUsing(
            static function ( $name ) use ( &$deleted_options ) {
                $deleted_options[] = $name;
                return true;
            }
        );
        WP_Mock::userFunction( 'delete_transient' )->andReturnUsing(
            static function ( $name ) use ( &$deleted_transients ) {
                $deleted_transients[] = $name;
                return true;
            }
        );

        $wpdb = new class {
            public string $options = 'wp_options';
            public array $queries  = [];

            public function esc_like( string $value ): string {
                return addcslashes( $value, '_%\\' );
            }

            public function prepare( string $query, string ...$args ): string {
                return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
            }

            public function query( string $query ): int {
                $this->queries[] = $query;
                return 0;
            }
        };
        $GLOBALS['wpdb'] = $wpdb;

        require dirname( __DIR__ ) . '/uninstall.php';

        // No secret survives uninstall.
        $this->assertContains( 'cu_scanner_api_key', $deleted_options );
        $this->assertContains( 'cu_scanner_secret', $deleted_options );
        $this->assertContains( 'cu_scanner_paid_key_claim_token', $deleted_options );
        // Migration marker must go, or a reinstall skips m1.
        $this->assertContains( 'aias_db_version', $deleted_options );
        // The removed self-updater's cache is cleaned up.
        $this->assertContains( 'cu_scanner_updater_manifest_v1', $deleted_transients );

        foreach ( [ 'cu_scanner_free_key_retry', 'cu_scanner_outbox_replay', 'cu_scanner_r3_rebuild', 'aias_event_emitter_flush', 'aias_optimizer_watchdog' ] as $hook ) {
            $this->assertContains( $hook, $unscheduled, $hook . ' must be unscheduled' );
        }

        // The wpservice.pro SaaS plugin shares the cu_scanner_ prefix; its options must survive.
        $this->assertNotContains( 'cu_scanner_db_version', $deleted_options );
        $this->assertNotEmpty( $wpdb->queries );
        foreach ( $wpdb->queries as $sql ) {
            $this->assertStringNotContainsString(
                "LIKE 'cu\\_scanner\\_%'",
                $sql,
                'a bare cu_scanner_% wildcard would delete the SaaS plugin options'
            );
        }
    }
}
