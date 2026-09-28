<?php
/**
 * Uninstall handler: removes every option, transient and scheduled event the
 * plugin created.
 *
 * WordPress runs this file when the user deletes the plugin from the Plugins
 * screen. All work happens inside a closure, so no global variables are defined.
 *
 * Names are listed explicitly instead of deleting everything that starts with
 * `cu_scanner_`: the wpservice.pro service plugin uses that prefix for its own
 * options, and a site running both must keep them. Wildcards are used only for
 * this plugin's per-scan keys. tests/UninstallTest.php holds the list of names
 * the service plugin owns and fails if any of them would be deleted; check a
 * new name against it before adding it here.
 *
 * The saved API key is removed too, so no secret stays in the database after
 * the plugin is deleted.
 *
 * @package AIAssetsScanner
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

( static function (): void {
	global $wpdb;

	if ( ! current_user_can( 'delete_plugins' ) ) {
		return;
	}

	foreach ( array(
		'cu_scanner_free_key_retry',
		'cu_scanner_outbox_replay',
		'cu_scanner_r3_rebuild',
		'aias_event_emitter_flush',
		'aias_optimizer_watchdog',
	) as $hook ) {
		wp_unschedule_hook( $hook );
	}

	foreach ( array(
		'cu_scanner_active_tokens',
		'cu_scanner_api_key',
		'cu_scanner_cdn_exemption_ack',
		'cu_scanner_free_key_pending',
		'cu_scanner_history',
		'cu_scanner_http_auth',
		'cu_scanner_omit_cu_bypass',
		'cu_scanner_outbox',
		'cu_scanner_paid_key_claim_token',
		'cu_scanner_ratchet_enabled',
		'cu_scanner_secret',
		'aias_db_version',
		'aias_dismissed_warnings',
		'aias_free_key_unusable',
		'aias_railway_url',
		'aias_last_push_sync_undo',
		'aias_last_result',
		'aias_last_seen_scan_id',
		'aias_optimizer_state',
		'aias_pending_events',
	) as $option ) {
		delete_option( $option );
	}

	foreach ( array(
		'cu_scanner_cdn_detected',
		'cu_scanner_history_deleted_notice',
		'cu_scanner_outbox_lock',
		'cu_scanner_updater_manifest_v1', // Left behind by the pre-1.9.0 self-updater.
		'aias_bypass_misuse_throttle',
		'aias_event_overflow_warned',
		'aias_menu_badge_last_poll',
		'aias_free_key_welcome',
		'aias_claim_checked',
	) as $transient ) {
		delete_transient( $transient );
	}

	// Per-scan keys, stored as options or transients.
	$prefixes = array(
		'cu_scanner_bypass_map_',
		'cu_scanner_et_rescan_',
		'cu_scanner_et_urls_',
		'cu_scanner_job_',
		'cu_scanner_json_',
		'cu_scanner_pending_token_',
		'cu_scanner_r_orig_',
		'cu_scanner_target_stack_v',
		'cu_scanner_url_res_v',
	);
	foreach ( $prefixes as $prefix ) {
		foreach ( array( '', '_transient_', '_transient_timeout_' ) as $kind ) {
			$pattern = $wpdb->esc_like( $kind . $prefix ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time uninstall cleanup; WordPress has no API to delete options by prefix.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) );
		}
	}
} )();
