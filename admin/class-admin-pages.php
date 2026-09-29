<?php
namespace DrSpeedAIAS\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use DrSpeedAIAS\Scanner\LastPushSyncUndo;

class AdminPages {
    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_notices', [ $this, 'maybe_render_history_deleted_notice' ] );
        ( new \DrSpeedAIAS\MenuBadge() )->init();
    }

    public function add_menus(): void {
        $icon = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCIgZmlsbD0ibm9uZSI+PGNpcmNsZSBjeD0iMTAiIGN5PSIxMCIgcj0iOC41IiBzdHJva2U9IiM3MmFlZTYiIHN0cm9rZS13aWR0aD0iMS4yIiBvcGFjaXR5PSIwLjMiLz48Y2lyY2xlIGN4PSIxMCIgY3k9IjEwIiByPSI1LjUiIHN0cm9rZT0iIzcyYWVlNiIgc3Ryb2tlLXdpZHRoPSIxLjIiIG9wYWNpdHk9IjAuNTUiLz48Y2lyY2xlIGN4PSIxMCIgY3k9IjEwIiByPSIyLjgiIHN0cm9rZT0iIzcyYWVlNiIgc3Ryb2tlLXdpZHRoPSIxLjIiIG9wYWNpdHk9IjAuODUiLz48Y2lyY2xlIGN4PSIxMCIgY3k9IjEwIiByPSIxIiBmaWxsPSIjNzJhZWU2Ii8+PGxpbmUgeDE9IjEwIiB5MT0iMTAiIHgyPSIxNi41IiB5Mj0iMy41IiBzdHJva2U9IiM3MmFlZTYiIHN0cm9rZS13aWR0aD0iMS4yIiBzdHJva2UtbGluZWNhcD0icm91bmQiLz48L3N2Zz4=';
        // The menu title also names the admin page hooks (sanitize_title() of it is the
        // "<title>_page_<slug>" prefix used in enqueue_assets and the history notice), so
        // it must stay in step with the plugin slug.
        add_menu_page(
            'Dr. Speed: AI Assets Scanner', 'Dr. Speed: AI Assets Scanner', 'manage_options',
            'drspeed-aias', [ $this, 'render_scanner' ],
            $icon, 80
        );
        add_submenu_page(
            'drspeed-aias', 'Settings', 'Settings', 'manage_options',
            'drspeed-aias-settings', [ $this, 'render_settings' ]
        );
        add_submenu_page(
            'drspeed-aias', 'Scan History', 'Scan History', 'manage_options',
            'drspeed-aias-history', [ $this, 'render_history' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        $pages = [ 'toplevel_page_drspeed-aias', 'dr-speed-ai-assets-scanner_page_drspeed-aias-settings', 'dr-speed-ai-assets-scanner_page_drspeed-aias-history' ];
        if ( ! in_array( $hook, $pages, true ) ) return;
        wp_enqueue_style( 'drspeed-aias-admin', DRSPEED_AIAS_URL . 'admin/css/dr-speed-ai-assets-scanner-admin.css', [], DRSPEED_AIAS_ASSET_VERSION );
        if ( $hook === 'toplevel_page_drspeed-aias' ) {
            wp_enqueue_script( 'drspeed-aias-scanner', DRSPEED_AIAS_URL . 'admin/js/scanner.js', [], DRSPEED_AIAS_ASSET_VERSION, true );
            wp_localize_script( 'drspeed-aias-scanner', 'drspeedAias', [
                'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
                'nonce'            => wp_create_nonce( 'drspeed_aias_nonce' ),
                'siteUrl'          => get_home_url(),
                'outbox'           => \DrSpeedAIAS\Scanner\Outbox::outbox_state_for_user( get_current_user_id() ),
                'lastPushSyncUndo' => ( new LastPushSyncUndo() )->state_for_ui(),
            ] );
            // Subsystem D-4: nonce for AJAX banner-dismiss endpoint.
            wp_localize_script( 'drspeed-aias-scanner', 'drspeedAiasBannerL10n', [
                'nonce' => wp_create_nonce( 'drspeed_aias_dismiss_banner' ),
            ] );

            // FU-ANTIBLOCK-1/2 — single-source copy map (spec §3.1): plain text + separate
            // settings_url; scanner.js DOM-builds anchors. stack_names comes from the
            // CANONICAL map PluginDetector::stack_display_names() (FU-ANTIBLOCK-STACK-NAMES
            // drift-guard — consumer + reserved-row provenance documented there).
            $copy_map                = \DRSPEED_AIAS_Broken_Banner::export_copy_map();
            $copy_map['stack_names'] = \DrSpeedAIAS\Scanner\PluginDetector::stack_display_names();
            wp_localize_script( 'drspeed-aias-scanner', 'drspeedAiasReasonCopy', $copy_map );

            // FU-ANTIBLOCK-2 — same-site pre-scan state (spec §3.4). detect_cached() is
            // zero-HTTP by contract (d-review M2) — never call detect() here.
            wp_localize_script( 'drspeed-aias-scanner', 'drspeedAiasLocalStack', [
                'cdn'              => ( new \DrSpeedAIAS\Cdn\Detector() )->detect_cached(),
                'acknowledged'     => ( new \DrSpeedAIAS\Settings() )->get_acknowledged_cdn(),
                'security_plugins' => \DrSpeedAIAS\Scanner\PluginDetector::active_security_warn_ids(),
            ] );
        }
        if ( $hook === 'dr-speed-ai-assets-scanner_page_drspeed-aias-settings' ) {
            wp_enqueue_script( 'drspeed-aias-settings', DRSPEED_AIAS_URL . 'admin/js/settings.js', [], DRSPEED_AIAS_ASSET_VERSION, true );
            wp_localize_script( 'drspeed-aias-settings', 'drspeedAiasSettings', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'drspeed_aias_settings_nonce' ),
            ] );
        }
        if ( $hook === 'dr-speed-ai-assets-scanner_page_drspeed-aias-history' ) {
            wp_enqueue_script(
                'drspeed-aias-history',
                DRSPEED_AIAS_URL . 'admin/js/history.js',
                [ 'jquery' ],
                DRSPEED_AIAS_ASSET_VERSION,
                true
            );
            wp_localize_script( 'drspeed-aias-history', 'drspeedAiasHistory', [
                'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
                'nonce'         => wp_create_nonce( 'drspeed_aias_nonce' ),
                'deleteWarning' => __(
                    "\xE2\x9A\xA0 This will permanently delete all scan history AND all stored scan JSON snapshots. Re-download links will stop working for old scans.\n\nDid you export a backup first?\n\nClick OK to delete everything, or Cancel to abort.",
                    'dr-speed-ai-assets-scanner'
                ),
            ] );
        }
    }

    public function maybe_render_history_deleted_notice(): void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->id !== 'dr-speed-ai-assets-scanner_page_drspeed-aias-history' ) {
            return;
        }
        $count = get_transient( 'drspeed_aias_history_deleted_notice' );
        if ( $count === false ) {
            return;
        }
        delete_transient( 'drspeed_aias_history_deleted_notice' );
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php
                // translators: %d = number of deleted records.
                printf( esc_html__( 'History deleted (%d records).', 'dr-speed-ai-assets-scanner' ), (int) $count );
            ?></p>
        </div>
        <?php
    }

    public function render_scanner(): void  { require DRSPEED_AIAS_DIR . 'admin/views/scanner-page.php'; }
    public function render_settings(): void { require DRSPEED_AIAS_DIR . 'admin/views/settings-page.php'; }
    public function render_history(): void  { require DRSPEED_AIAS_DIR . 'admin/views/history-page.php'; }

    /**
     * Pure visibility predicate: show the CDN notice when a CDN is detected
     * and the detected CDN differs from the one the operator has acknowledged.
     *
     * @param string|null $detected     CDN slug returned by Detector::detect(), or null.
     * @param string      $acknowledged CDN slug stored in settings ('' = none).
     */
    public static function cdn_notice_should_show( ?string $detected, string $acknowledged ): bool {
        return $detected !== null && $detected !== $acknowledged;
    }
}
