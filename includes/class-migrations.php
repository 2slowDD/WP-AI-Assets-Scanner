<?php
namespace DrSpeedAIAS;

defined( 'ABSPATH' ) || exit;

/**
 * One-time data migrations, integer-ladder versioned via the autoloaded
 * VERSION_OPTION (~10 bytes; read every request, so autoloading it is
 * correct). Runs on `plugins_loaded`: WordPress runs no plugin code after
 * an update, so migrations must self-run at runtime.
 *
 * CONSTRAINT: every mN MUST be idempotent — a later failing step re-runs
 * all earlier steps on the next request.
 */
class Migrations {
    private const DB_VERSION     = 2;
    // NOT `cu_scanner_db_version` — that option is owned by the co-installed
    // wpservice-saas plugin (its own schema marker, rewritten from its own
    // plugins_loaded hook on every divergence). AAS briefly squatted on it in
    // 1.7.84b/1.7.85b, causing a per-request write-war that re-ran the SaaS
    // plugin's dbDelta migrations. AAS must never read, write, or delete that
    // name again. Verified free in AAS + wpservice-saas + Code Unloader + live DB.
    private const VERSION_OPTION = 'drspeed_aias_db_version';
    /** VERSION_OPTION's name before 1.9.4. Read only while the new one is absent. */
    private const LEGACY_VERSION_OPTION = 'aias_db_version';

    public static function maybe_run(): void {
        if ( wp_installing() ) {
            return; // plugins_loaded also fires during core install/upgrade
        }
        // Defensive parse: only a value the ladder verifiably writes (a bare non-negative
        // integer) counts as a completed migration. A 1.2.x-era build stored the plugin
        // version string ('1.2.41') under the marker; (int)-casting that relic read as
        // 1 >= DB_VERSION and silently skipped every migration, forever (field-found on a
        // live install 2026-08-01). Foreign value => 0 => the ladder runs, and the
        // successful stamp below overwrites the relic.
        $raw = get_option( self::VERSION_OPTION, 0 );
        if ( 0 === $raw ) {
            $raw = get_option( self::LEGACY_VERSION_OPTION, 0 ); // a 1.9.3-or-older install
        }
        $at  = ( is_int( $raw ) || ( is_string( $raw ) && ctype_digit( $raw ) ) ) ? (int) $raw : 0;
        if ( $at >= self::DB_VERSION ) {
            return; // O(1): autoloaded option — alloptions array lookup, no query
        }
        // m2 runs BEFORE m1: m1 works on the new names, so a site that never ran m1
        // (1.7.x or older) must have its rows moved first.
        if ( $at < 2 && ! self::m2_move_legacy_names() ) {
            return; // failed — version NOT recorded; next request retries
        }
        if ( $at < 1 && ! self::m1_scan_history_autoload_off() ) {
            return;
        }
        update_option( self::VERSION_OPTION, self::DB_VERSION ); // autoloaded (default) — intentional
        delete_option( self::LEGACY_VERSION_OPTION );
    }

    /**
     * m2 (1.9.4): WordPress.org asked for one distinct prefix, so every stored name
     * moved from `cu_scanner_` / `aias_` to `drspeed_aias_`. Moves this plugin's
     * options (fixed names and per-scan prefixes, never transients, which expire on
     * their own) and its scheduled events. The old names come one by one from
     * includes/names.php: the service plugin on wpservice.pro still uses
     * `cu_scanner_`, so nothing here is a blanket wildcard.
     *
     * A row is renamed in place (value and autoload kept). If the new name already
     * exists, the new data wins and the old row is deleted. Idempotent: a second run
     * finds no old rows.
     */
    private static function m2_move_legacy_names(): bool {
        global $wpdb;
        $names = require __DIR__ . '/names.php';

        $exact = array_flip( $names['options'] ); // old => new
        // Fixed query over both old prefixes; the exact list below decides what moves.
        // A row this plugin never owned (the service plugin's cu_scanner_* options)
        // comes back here and is skipped.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration.
        $old_rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'cu_scanner_' ) . '%', $wpdb->esc_like( 'aias_' ) . '%' ) );
        if ( '' !== $wpdb->last_error ) {
            self::debug_log( 'm2 enumeration SELECT failed: ' . $wpdb->last_error );
            return false;
        }

        foreach ( $old_rows as $old ) {
            $new = $exact[ $old ] ?? null;
            if ( null === $new ) {
                foreach ( $names['prefixes'] as $new_prefix => $old_prefix ) {
                    if ( str_starts_with( $old, $old_prefix ) ) {
                        $new = $new_prefix . substr( $old, strlen( $old_prefix ) );
                        break;
                    }
                }
            }
            if ( null === $new ) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration.
            $taken = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $new ) );
            if ( null === $taken || '' !== $wpdb->last_error ) {
                self::debug_log( 'm2 lookup failed for ' . $new . ': ' . $wpdb->last_error );
                return false;
            }
            if ( (int) $taken > 0 ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration.
                $done = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $old ) );
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration; a rename keeps the value and autoload flag as they are.
                $done = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_name = %s WHERE option_name = %s", $new, $old ) );
            }
            if ( false === $done ) {
                self::debug_log( 'm2 move failed for ' . $old . ': ' . $wpdb->last_error );
                return false;
            }
            wp_cache_delete( $old, 'options' );
            wp_cache_delete( $new, 'options' );
        }
        if ( $old_rows ) {
            wp_cache_delete( 'alloptions', 'options' );
            wp_cache_delete( 'notoptions', 'options' );
        }

        self::move_legacy_cron_events( $names['cron'] );
        return true;
    }

    /**
     * Re-schedules pending events under their new hook names. Without it an event
     * queued before the update (a free-key retry, the optimizer watchdog that
     * restores plugins paused for a scan) would fire into a hook nothing listens to.
     *
     * @param array<string,string> $hooks New hook => old hook.
     */
    private static function move_legacy_cron_events( array $hooks ): void {
        if ( ! function_exists( '_get_cron_array' ) ) {
            return;
        }
        foreach ( (array) _get_cron_array() as $timestamp => $events ) {
            foreach ( $hooks as $new_hook => $old_hook ) {
                foreach ( (array) ( $events[ $old_hook ] ?? array() ) as $event ) {
                    $args = (array) ( $event['args'] ?? array() );
                    if ( ! empty( $event['schedule'] ) ) {
                        wp_schedule_event( (int) $timestamp, (string) $event['schedule'], $new_hook, $args );
                    } else {
                        wp_schedule_single_event( (int) $timestamp, $new_hook, $args );
                    }
                    wp_unschedule_event( (int) $timestamp, $old_hook, $args );
                }
            }
        }
    }

    private static function m1_scan_history_autoload_off(): bool {
        global $wpdb;

        $like = $wpdb->esc_like( 'drspeed_aias_json_' ) . '%';

        // Enumerate ACTUAL rows (prefix-LIKE uses the option_name index — no full-table
        // scan; catches orphaned JSON rows with no history record).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-shot migration over wildcard option keys; cache layer not relevant; table name from $wpdb->options is internal.
        $names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
        // Checked IMMEDIATELY after the query: wpdb::last_error is per-query — any
        // subsequent query wipes it (wpdb::query() -> flush()).
        if ( '' !== $wpdb->last_error ) {
            self::debug_log( 'm1 enumeration SELECT failed: ' . $wpdb->last_error );
            return false;
        }
        $names[] = 'drspeed_aias_history';

        $placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
        // Stored 'no' is non-autoload on every supported version (6.2 loader:
        // autoload='yes'; 6.6+ loader: IN ('yes','on','auto-on','auto')).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one-shot migration; placeholder list is built from count(), values passed as array; table name internal.
        $updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET autoload = 'no' WHERE option_name IN ($placeholders)", $names ) );
        // STRICT false check is load-bearing: an idempotent re-run affects 0 rows and
        // query() returns 0 — `! $updated` would misread that success as failure.
        if ( false === $updated ) {
            self::debug_log( 'm1 UPDATE failed: ' . $wpdb->last_error );
            return false;
        }
        wp_cache_delete( 'alloptions', 'options' );

        // POSITIVE verification — success is asserted by re-reading the DB (the same
        // invariant as the release AC), never inferred from the absence of errors.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-shot migration; table name internal.
        $remaining = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE ( option_name LIKE %s OR option_name = %s ) AND autoload IN ( 'yes', 'on', 'auto-on', 'auto' )", $like, 'drspeed_aias_history' ) );
        if ( null === $remaining || '' !== $wpdb->last_error ) {
            self::debug_log( 'm1 post-verify query failed: ' . $wpdb->last_error );
            return false; // a failed COUNT must not read as 0
        }
        if ( 0 !== (int) $remaining ) {
            self::debug_log( 'm1 post-verify failed: ' . (int) $remaining . ' rows still autoloading' );
            return false;
        }
        return true;
    }

    private static function debug_log( string $msg ): void {
        if ( drspeed_aias_debug_enabled() ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional diagnostic; gated by drspeed_aias_debug_enabled() (default OFF).
            error_log( '[AI Assets Scanner] Migrations: ' . $msg );
        }
    }
}
