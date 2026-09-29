<?php
/**
 * Every name this plugin stores in the database, mapped to the name it had
 * before 1.9.4. Up to 1.9.3 the plugin used the `cu_scanner_` and `aias_`
 * prefixes; WordPress.org asked for one distinct prefix, so 1.9.4 renamed
 * everything to `drspeed_aias_`.
 *
 * Read by Migrations (moves old data to the new names once) and by uninstall.php
 * (deletes both). Keeping one list means a name cannot be migrated but left
 * behind on delete, or the other way round.
 *
 * The old names are listed one by one on purpose. The wpservice.pro service
 * plugin still uses the `cu_scanner_` prefix for its own options, and a site can
 * run both plugins, so nothing here may match a name the service plugin owns.
 * tests/UninstallTest.php holds that plugin's names and checks this list.
 *
 * @package DrSpeedAIAS
 */

defined( 'ABSPATH' ) || exit;

return array(
	// Scheduled events: new hook => old hook.
	'cron'       => array(
		'drspeed_aias_free_key_retry'       => 'cu_scanner_free_key_retry',
		'drspeed_aias_outbox_replay'        => 'cu_scanner_outbox_replay',
		'drspeed_aias_r3_rebuild'           => 'cu_scanner_r3_rebuild',
		'drspeed_aias_event_emitter_flush'  => 'aias_event_emitter_flush',
		'drspeed_aias_optimizer_watchdog'   => 'aias_optimizer_watchdog',
	),
	// Options: new name => old name.
	'options'    => array(
		'drspeed_aias_active_tokens'        => 'cu_scanner_active_tokens',
		'drspeed_aias_api_key'              => 'cu_scanner_api_key',
		'drspeed_aias_cdn_exemption_ack'    => 'cu_scanner_cdn_exemption_ack',
		'drspeed_aias_free_key_pending'     => 'cu_scanner_free_key_pending',
		'drspeed_aias_history'              => 'cu_scanner_history',
		'drspeed_aias_http_auth'            => 'cu_scanner_http_auth',
		'drspeed_aias_omit_cu_bypass'       => 'cu_scanner_omit_cu_bypass',
		'drspeed_aias_outbox'               => 'cu_scanner_outbox',
		'drspeed_aias_paid_key_claim_token' => 'cu_scanner_paid_key_claim_token',
		'drspeed_aias_ratchet_enabled'      => 'cu_scanner_ratchet_enabled',
		'drspeed_aias_secret'               => 'cu_scanner_secret',
		'drspeed_aias_dismissed_warnings'   => 'aias_dismissed_warnings',
		'drspeed_aias_free_key_unusable'    => 'aias_free_key_unusable',
		'drspeed_aias_railway_url'          => 'aias_railway_url',
		'drspeed_aias_last_push_sync_undo'  => 'aias_last_push_sync_undo',
		'drspeed_aias_last_result'          => 'aias_last_result',
		'drspeed_aias_last_seen_scan_id'    => 'aias_last_seen_scan_id',
		'drspeed_aias_optimizer_state'      => 'aias_optimizer_state',
		'drspeed_aias_pending_events'       => 'aias_pending_events',
	),
	// The migration ladder's own marker. Not moved: Migrations reads the old one once.
	'version'    => array( 'drspeed_aias_db_version' => 'aias_db_version' ),
	// Transients: new name => old name. Short-lived, so they are not moved; uninstall deletes both.
	'transients' => array(
		'drspeed_aias_cdn_detected'           => 'cu_scanner_cdn_detected',
		'drspeed_aias_history_deleted_notice' => 'cu_scanner_history_deleted_notice',
		'drspeed_aias_outbox_lock'            => 'cu_scanner_outbox_lock',
		'drspeed_aias_bypass_misuse_throttle' => 'aias_bypass_misuse_throttle',
		'drspeed_aias_event_overflow_warned'  => 'aias_event_overflow_warned',
		'drspeed_aias_menu_badge_last_poll'   => 'aias_menu_badge_last_poll',
		'drspeed_aias_free_key_welcome'       => 'aias_free_key_welcome',
		'drspeed_aias_claim_checked'          => 'aias_claim_checked',
		// Left behind by the pre-1.9.0 self-updater; it never had a new name.
		'cu_scanner_updater_manifest_v1'      => 'cu_scanner_updater_manifest_v1',
	),
	// Per-scan keys, stored as options or transients: new prefix => old prefix.
	'prefixes'   => array(
		'drspeed_aias_bypass_map_'    => 'cu_scanner_bypass_map_',
		'drspeed_aias_et_rescan_'     => 'cu_scanner_et_rescan_',
		'drspeed_aias_et_urls_'       => 'cu_scanner_et_urls_',
		'drspeed_aias_job_'           => 'cu_scanner_job_',
		'drspeed_aias_json_'          => 'cu_scanner_json_',
		'drspeed_aias_pending_token_' => 'cu_scanner_pending_token_',
		'drspeed_aias_r_orig_'        => 'cu_scanner_r_orig_',
		'drspeed_aias_target_stack_v' => 'cu_scanner_target_stack_v',
		'drspeed_aias_url_res_v'      => 'cu_scanner_url_res_v',
	),
);
