<?php
/**
 * Dev-only (never shipped: tools/ is outside bin/build-zip.sh's allowlist and
 * export-ignored). Dumps DRSPEED_AIAS_Broken_Banner::export_copy_map() as JSON so the
 * JS banner tests run on the same map the PHP twin is tested on:
 *
 *   php tools/dump-copy-map.php > tests/js/fixtures/copy-map.json
 *
 * tests/CopyMapFixtureFreshTest.php fails when the committed fixture drifts from a
 * fresh dump — re-run the command above after any copy-map change.
 */

// Command line only. ABSPATH is defined here, standing in for WordPress, BEFORE the
// class file loads, so the class keeps its plain ABSPATH guard (wp-compliance Rule 21).
if ( 'cli' !== PHP_SAPI ) {
	exit;
}
define( 'ABSPATH', dirname( __DIR__ ) . '/' );

// Minimal WP stubs: identity i18n (English source text), _n by count, the settings
// URL left admin-relative exactly as the PHP tests stub it.
function __( $text, $domain = null ) {
	return $text;
}
function _n( $single, $plural, $number, $domain = null ) {
	return 1 === $number ? $single : $plural;
}
function esc_url( $url ) {
	return (string) $url;
}
function admin_url( $path = '' ) {
	return (string) $path;
}
// class-broken-banner.php registers its AJAX hook at file scope.
function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	return true;
}

require dirname( __DIR__ ) . '/includes/class-broken-banner.php';

echo json_encode( DRSPEED_AIAS_Broken_Banner::export_copy_map(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ), "\n";
