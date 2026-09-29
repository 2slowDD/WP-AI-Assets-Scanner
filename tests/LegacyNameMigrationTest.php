<?php

use WP_Mock\Tools\TestCase;

/** Scripted wpdb: one scripted result per query, last_error reset per query like the real one. */
class LegacyNameWpdbFake {
    public string $options    = 'wp_options';
    public string $last_error = '';
    public array $log         = [];

    public function __construct( private array $script ) {}

    public function esc_like( string $value ): string {
        return addcslashes( $value, '_%\\' );
    }

    public function prepare( string $query, ...$args ): string {
        if ( isset( $args[0] ) && is_array( $args[0] ) ) {
            $args = $args[0];
        }
        return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
    }

    private function step( string $sql ) {
        $this->log[] = $sql;
        $s           = array_shift( $this->script );
        if ( null === $s ) {
            throw new \RuntimeException( 'unscripted query: ' . $sql );
        }
        $this->last_error = $s['error'] ?? '';
        return $s['result'];
    }

    public function get_col( string $sql ): array {
        return (array) $this->step( $sql );
    }

    public function get_var( string $sql ) {
        return $this->step( $sql );
    }

    public function query( string $sql ) {
        return $this->step( $sql );
    }
}

/**
 * 1.9.4 renamed every stored name from cu_scanner_ / aias_ to drspeed_aias_
 * (WordPress.org prefix review). A site updating from 1.9.3 or older must keep
 * its API key, settings and scan history, and the service plugin's own
 * cu_scanner_ options on wpservice.pro must never be touched.
 */
class LegacyNameMigrationTest extends TestCase {

    /** Options the wpservice.pro service plugin owns (it can run on the same site). */
    private const SERVICE_PLUGIN_NAMES = [
        'cu_scanner_railway_url', 'cu_scanner_db_version', 'cu_scanner_service_secret',
        'cu_scanner_saas_version', 'cu_scanner_schema_version', 'cu_scanner_credit_products',
        'cu_scanner_insert_error', 'cu_scanner_free_key_insert_error', 'cu_scanner_limit_events',
        'cu_scanner_free_key_next_number', 'cu_scanner_free_trial_credits',
    ];

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        WP_Mock::userFunction( 'wp_installing' )->andReturn( false );
        WP_Mock::userFunction( 'wp_cache_delete' )->andReturn( true );
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        unset( $GLOBALS['wpdb'] );
        parent::tearDown();
    }

    private function legacy_193_site(): void {
        WP_Mock::userFunction( 'get_option' )->with( 'drspeed_aias_db_version', 0 )->andReturn( 0 );
        WP_Mock::userFunction( 'get_option' )->with( 'aias_db_version', 0 )->andReturn( '1' );
    }

    public function test_a_193_site_keeps_its_key_and_history_under_the_new_names(): void {
        $this->legacy_193_site();
        WP_Mock::userFunction( '_get_cron_array' )->andReturn( [] );
        WP_Mock::userFunction( 'update_option' )->with( 'drspeed_aias_db_version', 2 )->once();
        WP_Mock::userFunction( 'delete_option' )->with( 'aias_db_version' )->once();

        $GLOBALS['wpdb'] = new LegacyNameWpdbFake( [
            // The fixed SELECT also returns rows this plugin never owned; they must be skipped.
            [ 'result' => [ 'cu_scanner_api_key', 'cu_scanner_railway_url', 'cu_scanner_json_job7', 'cu_scanner_db_version', 'aias_railway_url', '_transient_cu_scanner_job_x' ] ],
            [ 'result' => '0' ], [ 'result' => 1 ], // api_key: new name free -> rename
            [ 'result' => '0' ], [ 'result' => 1 ], // json_job7 (per-scan prefix) -> rename
            [ 'result' => '1' ], [ 'result' => 1 ], // railway_url: new name exists -> drop the old row
        ] );

        \DrSpeedAIAS\Migrations::maybe_run();

        $log = $GLOBALS['wpdb']->log;
        $this->assertCount( 7, $log, 'm1 must not run again on a site already at version 1' );
        $this->assertStringContainsString( "SET option_name = 'drspeed_aias_api_key' WHERE option_name = 'cu_scanner_api_key'", $log[2] );
        $this->assertStringContainsString( "SET option_name = 'drspeed_aias_json_job7' WHERE option_name = 'cu_scanner_json_job7'", $log[4] );
        $this->assertStringContainsString( "DELETE FROM wp_options WHERE option_name = 'aias_railway_url'", $log[6] );
        foreach ( array_slice( $log, 1 ) as $sql ) {
            $this->assertStringNotContainsString( "'cu_scanner_railway_url'", $sql, 'the service plugin owns that option' );
            $this->assertStringNotContainsString( "'cu_scanner_db_version'", $sql, 'the service plugin owns that option' );
        }
    }

    public function test_the_enumeration_never_matches_a_service_plugin_name(): void {
        $names = require dirname( __DIR__ ) . '/includes/names.php';
        $old   = array_merge( ...array_map( 'array_values', array_values( $names ) ) );
        foreach ( self::SERVICE_PLUGIN_NAMES as $theirs ) {
            $this->assertNotContains( $theirs, array_values( $names['options'] ), $theirs . ' belongs to the service plugin' );
            foreach ( $names['prefixes'] as $old_prefix ) {
                $this->assertFalse( str_starts_with( $theirs, $old_prefix ), "{$old_prefix}* would move the service plugin's {$theirs}" );
            }
        }
        foreach ( $old as $name ) {
            $this->assertMatchesRegularExpression( '/^(cu_scanner_|aias_)/', $name, 'old names only' );
        }
        foreach ( array_keys( $names['options'] + $names['prefixes'] + $names['cron'] ) as $new ) {
            $this->assertStringStartsWith( 'drspeed_aias_', $new );
        }
    }

    public function test_queued_events_move_to_the_new_hooks(): void {
        $this->legacy_193_site();
        WP_Mock::userFunction( 'update_option' );
        WP_Mock::userFunction( 'delete_option' );
        WP_Mock::userFunction( '_get_cron_array' )->andReturn( [
            1790000000 => [
                'aias_optimizer_watchdog'   => [ 'k1' => [ 'schedule' => false, 'args' => [ 'job9' ] ] ],
                'cu_scanner_outbox_replay'  => [ 'k2' => [ 'schedule' => 'hourly', 'args' => [], 'interval' => 3600 ] ],
                'some_other_plugin_hook'    => [ 'k3' => [ 'schedule' => false, 'args' => [] ] ],
            ],
        ] );
        WP_Mock::userFunction( 'wp_schedule_single_event' )->with( 1790000000, 'drspeed_aias_optimizer_watchdog', [ 'job9' ] )->once();
        WP_Mock::userFunction( 'wp_schedule_event' )->with( 1790000000, 'hourly', 'drspeed_aias_outbox_replay', [] )->once();
        WP_Mock::userFunction( 'wp_unschedule_event' )->with( 1790000000, 'aias_optimizer_watchdog', [ 'job9' ] )->once();
        WP_Mock::userFunction( 'wp_unschedule_event' )->with( 1790000000, 'cu_scanner_outbox_replay', [] )->once();

        $GLOBALS['wpdb'] = new LegacyNameWpdbFake( [ [ 'result' => [] ] ] );

        \DrSpeedAIAS\Migrations::maybe_run();
        $this->assertCount( 1, $GLOBALS['wpdb']->log );
    }

    public function test_a_failed_rename_does_not_stamp_the_version(): void {
        $this->legacy_193_site();
        WP_Mock::userFunction( '_get_cron_array' )->never();
        WP_Mock::userFunction( 'update_option' )->never();
        WP_Mock::userFunction( 'delete_option' )->never();

        $GLOBALS['wpdb'] = new LegacyNameWpdbFake( [
            [ 'result' => [ 'cu_scanner_api_key' ] ],
            [ 'result' => '0' ],
            [ 'result' => false, 'error' => 'Deadlock found' ],
        ] );

        \DrSpeedAIAS\Migrations::maybe_run();
        $this->assertCount( 3, $GLOBALS['wpdb']->log );
    }

    public function test_a_site_already_on_the_new_names_does_nothing(): void {
        WP_Mock::userFunction( 'get_option' )->with( 'drspeed_aias_db_version', 0 )->andReturn( '2' );
        WP_Mock::userFunction( 'get_option' )->with( 'aias_db_version', 0 )->never();
        WP_Mock::userFunction( 'update_option' )->never();

        $GLOBALS['wpdb'] = new LegacyNameWpdbFake( [] );

        \DrSpeedAIAS\Migrations::maybe_run();
        $this->assertSame( [], $GLOBALS['wpdb']->log );
    }
}
