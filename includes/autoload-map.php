<?php
/**
 * The ONE class→file autoload map.
 *
 * Was duplicated as an inline array in dr-speed-ai-assets-scanner.php AND tests/bootstrap.php.
 * Both are hand-maintained, so a new class registered in one and forgotten in the other
 * leaves the whole suite green while a live site fatals on first use — the test map is
 * an injected activation path; this file makes both consumers load the real one (P17).
 *
 * Paths are relative to DRSPEED_AIAS_DIR.
 */

defined( 'ABSPATH' ) || exit;

return [
    'DrSpeedAIAS\\Plugin'           => 'includes/class-plugin.php',
    'DrSpeedAIAS\\Settings'         => 'includes/class-settings.php',
    'DrSpeedAIAS\\DomainNormalizer' => 'includes/class-domain-normalizer.php',
    'DrSpeedAIAS\\FreeKeyBootstrap' => 'includes/class-free-key-bootstrap.php',
    'DrSpeedAIAS\\ScanHistory'      => 'includes/class-scan-history.php',
    'DrSpeedAIAS\\Api\\WpserviceClient' => 'includes/api/class-wpservice-client.php',
    'DrSpeedAIAS\\Api\\RailwayClient'   => 'includes/api/class-railway-client.php',
    'DrSpeedAIAS\\Api\\HttpException'   => 'includes/api/class-http-exception.php',
    'DrSpeedAIAS\\MenuBadge'           => 'includes/class-menu-badge.php',
    'DrSpeedAIAS\\Scanner\\PageDiscovery'   => 'includes/scanner/class-page-discovery.php',
    'DrSpeedAIAS\\Scanner\\PluginDetector'  => 'includes/scanner/class-plugin-detector.php',
    'DrSpeedAIAS\\Scanner\\BypassManager'   => 'includes/scanner/class-bypass-manager.php',
    'DrSpeedAIAS\\Scanner\\OptimizerState'  => 'includes/scanner/class-optimizer-state.php',
    'DrSpeedAIAS\\Scanner\\BypassHandler'   => 'includes/scanner/class-bypass-handler.php',
    'DrSpeedAIAS\\Scanner\\CU_DepGraph_Island' => 'includes/scanner/class-cu-depgraph-island.php',
    'DrSpeedAIAS\\Scanner\\Strategies\\AbstractOptimizerBypass' => 'includes/scanner/strategies/abstract-optimizer-bypass.php',
    'DrSpeedAIAS\\Scanner\\Strategies\\FlyingPressBypass'        => 'includes/scanner/strategies/class-flying-press-bypass.php',
    'DrSpeedAIAS\\Scanner\\Strategies\\SgOptimizerBypass'        => 'includes/scanner/strategies/class-sg-optimizer-bypass.php',
    'DrSpeedAIAS\\Scanner\\Strategies\\HummingbirdBypass'        => 'includes/scanner/strategies/class-hummingbird-bypass.php',
    'DrSpeedAIAS\\Scanner\\OptimizerBypassOrchestrator' => 'includes/scanner/class-optimizer-bypass-orchestrator.php',
    'DrSpeedAIAS\\Scanner\\StrategyFactory'             => 'includes/scanner/class-strategy-factory.php',
    'DrSpeedAIAS\\Scanner\\EventEmitter'    => 'includes/scanner/class-event-emitter.php',
    'DrSpeedAIAS\\Scanner\\CuJsonBuilder'   => 'includes/scanner/class-cu-json-builder.php',
    'DrSpeedAIAS\\Scanner\\RatchetMerger'   => 'includes/scanner/class-ratchet-merger.php',
    'DrSpeedAIAS\\Scanner\\RulePusher'      => 'includes/scanner/class-rule-pusher.php',
    'DrSpeedAIAS\\Scanner\\UrlPattern'      => 'includes/scanner/class-url-pattern.php',
    'DrSpeedAIAS\\Scanner\\LastPushSyncUndo' => 'includes/scanner/class-last-push-sync-undo.php',
    'DrSpeedAIAS\\Scanner\\SnapshotManager' => 'includes/scanner/class-snapshot-manager.php',
    'DrSpeedAIAS\\Scanner\\GroupVersionManager' => 'includes/scanner/class-group-version-manager.php',
    'DrSpeedAIAS\\Scanner\\Outbox'             => 'includes/scanner/class-outbox.php',
    'DrSpeedAIAS\\Admin\\AdminPages'        => 'admin/class-admin-pages.php',
    'DrSpeedAIAS\\Admin\\SettingsAjax'      => 'admin/class-settings-ajax.php',
    'DrSpeedAIAS\\Admin\\ScannerAjax'       => 'admin/class-scanner-ajax.php',
    'DrSpeedAIAS\\Scanner\\RestPreflight'       => 'includes/scanner/class-rest-preflight.php',
    'DrSpeedAIAS\\Admin\\OptimizerStateNotices' => 'includes/admin/class-optimizer-state-notices.php',
    'DRSPEED_AIAS_Broken_Banner'                     => 'includes/class-broken-banner.php',
    'DRSPEED_AIAS_Scan_Status'                       => 'includes/class-scan-status.php',
    'DrSpeedAIAS\\Cdn\\AdapterInterface'       => 'includes/cdn/interface-adapter.php',
    'DrSpeedAIAS\\Cdn\\Registry'               => 'includes/cdn/class-registry.php',
    'DrSpeedAIAS\\Cdn\\CloudflareAdapter'      => 'includes/cdn/class-cloudflare-adapter.php',
    'DrSpeedAIAS\\Cdn\\GenericAdapter'         => 'includes/cdn/class-generic-adapter.php',
    'DrSpeedAIAS\\Cdn\\Detector'              => 'includes/cdn/class-detector.php',
    'DrSpeedAIAS\\Migrations'                  => 'includes/class-migrations.php',
];
