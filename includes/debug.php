<?php
/**
 * AAS debug-log gate. Default OFF: AAS writes diagnostic error_log lines ONLY when
 * the operator opts in via `define( 'DRSPEED_AIAS_DEBUG', true );` in wp-config.php.
 * Real-error (exception-catch) logs stay ungated elsewhere — this gates DIAGNOSTICS only.
 */
defined( 'ABSPATH' ) || exit;

function drspeed_aias_debug_enabled(): bool {
    return defined( 'DRSPEED_AIAS_DEBUG' ) && DRSPEED_AIAS_DEBUG;
}
