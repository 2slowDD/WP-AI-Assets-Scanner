<?php

use WP_Mock\Tools\TestCase;

class UninstallTest extends TestCase {
    /**
     * Option names the wpservice.pro service plugin owns, taken from its source.
     * On a site running both plugins these rows are the service's configuration
     * and data; AAS must never delete or overwrite them. cu_scanner_railway_url
     * is the one AAS 1.8.9 got wrong: it was the service's worker URL.
     */
    private const SERVICE_PLUGIN_OPTIONS = [
        'cu_scanner_credit_products',
        'cu_scanner_free_key_next_number',
        'cu_scanner_free_trial_credits',
        'cu_scanner_db_version',
        'cu_scanner_free_key_insert_error',
        'cu_scanner_insert_error',
        'cu_scanner_limit_events',
        'cu_scanner_railway_url',
        'cu_scanner_saas_version',
        'cu_scanner_schema_version',
        'cu_scanner_service_secret',
    ];

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

        // No secret survives uninstall, under its current or its pre-1.9.4 name.
        foreach ( [ 'api_key', 'secret', 'paid_key_claim_token' ] as $secret ) {
            $this->assertContains( 'drspeed_aias_' . $secret, $deleted_options );
            $this->assertContains( 'cu_scanner_' . $secret, $deleted_options );
        }
        // Migration marker must go (both names), or a reinstall skips the ladder.
        $this->assertContains( 'drspeed_aias_db_version', $deleted_options );
        $this->assertContains( 'aias_db_version', $deleted_options );
        // The removed self-updater's cache is cleaned up.
        $this->assertContains( 'cu_scanner_updater_manifest_v1', $deleted_transients );

        foreach ( [ 'free_key_retry', 'outbox_replay', 'r3_rebuild' ] as $hook ) {
            $this->assertContains( 'drspeed_aias_' . $hook, $unscheduled );
            $this->assertContains( 'cu_scanner_' . $hook, $unscheduled );
        }
        foreach ( [ 'event_emitter_flush', 'optimizer_watchdog' ] as $hook ) {
            $this->assertContains( 'drspeed_aias_' . $hook, $unscheduled );
            $this->assertContains( 'aias_' . $hook, $unscheduled );
        }

        // Every name in the shared list, current and old, is removed.
        $names = require dirname( __DIR__ ) . '/includes/names.php';
        foreach ( [ 'options', 'version' ] as $group ) {
            foreach ( $names[ $group ] as $new => $old ) {
                $this->assertContains( $new, $deleted_options );
                $this->assertContains( $old, $deleted_options );
            }
        }

        // The wpservice.pro service plugin shares the cu_scanner_ prefix; its rows must survive.
        $this->assertNotEmpty( $wpdb->queries );
        $prefixes = [];
        foreach ( $wpdb->queries as $sql ) {
            $this->assertSame( 1, preg_match( "/LIKE '([^']*)%'/", $sql, $m ), 'every cleanup query is a LIKE prefix match: ' . $sql );
            $prefixes[] = str_replace( [ '\\_', '\\%' ], [ '_', '%' ], $m[1] );
        }
        foreach ( self::SERVICE_PLUGIN_OPTIONS as $theirs ) {
            $this->assertNotContains( $theirs, $deleted_options, $theirs . ' belongs to the service plugin' );
            $this->assertNotContains( $theirs, $deleted_transients, $theirs . ' belongs to the service plugin' );
            foreach ( $prefixes as $prefix ) {
                $this->assertFalse(
                    str_starts_with( $theirs, $prefix ),
                    "LIKE '{$prefix}%' would delete the service plugin's {$theirs}"
                );
            }
        }
    }
}
