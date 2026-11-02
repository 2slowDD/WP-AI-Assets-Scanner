<?php
/**
 * Uninstall handler: removes every option, transient and scheduled event the
 * plugin created.
 *
 * WordPress runs this file when the user deletes the plugin from the Plugins
 * screen. All work happens inside a closure, so no global variables are defined.
 *
 * Names are listed explicitly instead of deleting everything that starts with
 * `drspeed_aias_`: the wpservice.pro service plugin uses that prefix for its own
 * options, and a site running both must keep them. Wildcards are used only for
 * this plugin's per-scan keys. tests/UninstallTest.php holds the list of names
 * the service plugin owns and fails if any of them would be deleted; check a
 * new name against it before adding it here.
 *
 * A saved paid API key stays, by the operator's decision: the service can only
 * bring back a free key, so deleting a paid one would leave its customer on a new
 * free key after a reinstall. A free key is removed. Every other secret is removed.
 *
 * @package AIAssetsScanner
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

( static function (): void {
	global $wpdb;

	if ( ! current_user_can( 'delete_plugins' ) ) {
		return;
	}

	// Every stored name, current and pre-1.9.4, from one list shared with Migrations.
	$names = require __DIR__ . '/includes/names.php';

	foreach ( $names['cron'] as $hook => $legacy_hook ) {
		wp_unschedule_hook( $hook );
		wp_unschedule_hook( $legacy_hook );
	}

	foreach ( array( 'options', 'version' ) as $group ) {
		foreach ( $names[ $group ] as $option => $legacy_option ) {
			foreach ( array( $option, $legacy_option ) as $name ) {
				if ( 'drspeed_aias_api_key' === $option ) {
					$key = get_option( $name, '' );
					if ( is_string( $key ) && '' !== $key && ! str_starts_with( $key, 'cusk_Freekey_' ) ) {
						continue; // A paid key: see the note at the top of this file.
					}
				}
				delete_option( $name );
			}
		}
	}

	foreach ( $names['transients'] as $transient => $legacy_transient ) {
		delete_transient( $transient );
		delete_transient( $legacy_transient );
	}

	// Per-scan keys, stored as options or transients.
	foreach ( $names['prefixes'] as $prefix => $legacy_prefix ) {
		foreach ( array( $prefix, $legacy_prefix ) as $name_prefix ) {
			foreach ( array( '', '_transient_', '_transient_timeout_' ) as $kind ) {
				$pattern = $wpdb->esc_like( $kind . $name_prefix ) . '%';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time uninstall cleanup; WordPress has no API to delete options by prefix.
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) );
			}
		}
	}
} )();
