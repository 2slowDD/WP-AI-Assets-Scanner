<?php
/**
 * Plugin Name:       AI Assets Scanner
 * Plugin URI:        https://github.com/2slowDD/WP-AI-Assets-Scanner
 * Description:       Scans your pages with an AI service and builds per-page rules to unload unused CSS and JavaScript.
 * Version:           1.9.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            WPservice.pro
 * Author URI:        https://wpservice.pro/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-assets-scanner
 */
/*
 * AI Assets Scanner - Copyright (C) 2026 Dalibor Druzinec / WPservice.pro
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation; either version 2 of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
 * PARTICULAR PURPOSE. See the GNU General Public License for more details.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'AIAS_VERSION', '1.9.0' );
define( 'AIAS_ASSET_VERSION', '1.9.0' );
define( 'AIAS_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIAS_URL', plugin_dir_url( __FILE__ ) );
define( 'AIAS_WPSERVICE_BASE', 'https://wpservice.pro' );
define( 'AIAS_WPSERVICE_URL',  AIAS_WPSERVICE_BASE . '/wp-json' );

require_once AIAS_DIR . 'includes/debug.php';

spl_autoload_register( function ( string $class ): void {
    // Single source of truth, shared with tests/bootstrap.php. Two hand-maintained
    // copies used to drift silently: a class registered here but not there (or vice
    // versa) kept the suite green while a live site fatalled on first use.
    $map = require AIAS_DIR . 'includes/autoload-map.php';
    if ( isset( $map[ $class ] ) ) {
        require AIAS_DIR . $map[ $class ];
    }
} );

add_action( 'rest_api_init', [ \CUScanner\Scanner\RestPreflight::class, 'register_routes' ] );

// No request goes to wpservice.pro until an administrator clicks "Get free credits"
// on the Settings screen (SettingsAjax::request_free_key). The retry below only
// exists after that opt-in: FreeKeyBootstrap::run() is its sole scheduler.
add_action( 'cu_scanner_free_key_retry', function (): void {
    ( new \CUScanner\FreeKeyBootstrap() )->run();
} );

add_action( \CUScanner\Scanner\Outbox::CRON_HOOK, [ \CUScanner\Scanner\Outbox::class, 'replay' ] );

add_action( 'plugins_loaded', function (): void {
    \CUScanner\Migrations::maybe_run(); // O(1) alloptions lookup once migrated
    ( new CUScanner\Plugin() )->init();
} );
