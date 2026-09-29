<?php
namespace DrSpeedAIAS;

if ( ! defined( 'ABSPATH' ) ) exit;

use DrSpeedAIAS\Scanner\BypassManager;
use DrSpeedAIAS\Scanner\BypassHandler;
use DrSpeedAIAS\Admin\AdminPages;
use DrSpeedAIAS\Admin\SettingsAjax;
use DrSpeedAIAS\Admin\ScannerAjax;

class Plugin {
    public function init(): void {
        if ( is_admin() ) {
            ( new AdminPages() )->register();
            ( new SettingsAjax() )->register();
            ( new ScannerAjax() )->register();
        }
        // Frontend: bypass token hook (runs on every request, not just admin)
        $bypass = new BypassManager();
        add_action( 'wp_loaded', [ $bypass, 'handle_wp_loaded' ] );

        // Class A optimizer hook-removal (priority 0 — before BypassManager's default priority).
        BypassHandler::init();

        // Class C orchestrator: stale-state self-heal + watchdog hooks.
        \DrSpeedAIAS\Scanner\OptimizerBypassOrchestrator::init();

        // Admin banner + force-restore handler.
        \DrSpeedAIAS\Admin\OptimizerStateNotices::init();

        // Class C scan-complete restore: fires when build_result() writes 'complete' to scan history.
        add_action( 'drspeed_aias_scan_complete', static function ( $scan_id ) {
            $state = \DrSpeedAIAS\Scanner\OptimizerState::load();
            if ( ! $state || ( $state['scan_id'] ?? '' ) !== (string) $scan_id ) return;
            \DrSpeedAIAS\Scanner\OptimizerBypassOrchestrator::build_default_orchestrator()
                ->complete( 'normal' );
        }, 10, 1 );

        // R3 Stage C (Tier C) — the rebuild cron fires in wp-cron context (front-end
        // traffic), where the admin-gated MenuBadge::init() is NOT loaded. Register the
        // handler here (un-gated) so the scheduled drspeed_aias_r3_rebuild event always has a
        // callback (O-7a). O-8: the run_r3_rebuild → do_build_result → drspeed_aias_scan_complete
        // → OptimizerBypassOrchestrator::complete() chain is site-global (no per-user/session
        // state in the optimizer classes), and run_r3_rebuild calls wp_set_current_user() first.
        add_action( 'drspeed_aias_r3_rebuild', [ new \DrSpeedAIAS\MenuBadge(), 'run_r3_rebuild' ], 10, 1 );
    }
}
