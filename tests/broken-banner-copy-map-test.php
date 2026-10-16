<?php
/**
 * Standalone: php tests/broken-banner-copy-map-test.php
 * Covers spec §3.1/§6.1 + AC-1: 12-key completeness, plain-text payload
 * (no HTML), rate-clause delegation, unknown-key fallback.
 */
define( 'ABSPATH', __DIR__ . '/' );
// WP stubs — i18n passthrough, escapers identity-ish, admin_url deterministic.
function __( $s, $d = null ) { return $s; }
// _n records every call so the errored-pair drift guard below can prove that the
// count-1 and count-2 calls of each kind carry the SAME literal pair.
function _n( $s, $p, $n, $d ) { $GLOBALS['drspeed_aias_n_calls'][] = [ $s, $p, $n, $d ]; return 1 === $n ? $s : $p; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function wp_kses_post( $s ) { return (string) $s; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function get_option( $k, $d = false ) { return $d; }
// class-broken-banner.php registers its AJAX hook unconditionally at file scope
// (add_action() at the bottom of the file) — stub it so require() below can
// load the real class without a WP runtime (brief's stub list omitted this).
function add_action( $tag, $cb, $priority = 10, $accepted_args = 1 ) { return true; }

require __DIR__ . '/../includes/class-broken-banner.php';
require __DIR__ . '/../includes/class-scan-status.php'; // entry_phrase() → Scan_Status::stack_of()

function drspeed_aias_assert( $cond, $msg ) {
    if ( ! $cond ) { throw new RuntimeException( 'FAIL: ' . $msg ); }
}

$keys = [
    'tier2_cf_challenge', 'tier2_akamai_challenge', 'tier2_imperva_challenge',
    'tier2_waf_challenge', 'tier2_unknown_challenge', 'tier2_rocket_loader_stub',
    'tier2_small_body', 'tier1_zero_bytes', 'tier1_http_4xx', 'tier1_http_5xx',
    'tier1_http_rate_limit', 'tier1_transport_error',
];

// AC-1: every key yields a non-empty remediation with no embedded HTML.
foreach ( $keys as $k ) {
    $r = DRSPEED_AIAS_Broken_Banner::reason_remediation( $k );
    drspeed_aias_assert( is_string( $r ) && $r !== '', "remediation non-empty for $k" );
    drspeed_aias_assert( strpos( $r, '<' ) === false, "remediation plain-text (no HTML) for $k" );
}
// Rate delegation: substantive, mentions rate-limiting behavior.
drspeed_aias_assert( stripos( DRSPEED_AIAS_Broken_Banner::reason_remediation( 'tier1_http_rate_limit' ), 'rate' ) !== false, 'rate clause delegated' );
// Unknown key falls back to its category clause (bot default), still non-empty plain text.
$unk = DRSPEED_AIAS_Broken_Banner::reason_remediation( 'tier9_never_seen' );
drspeed_aias_assert( $unk !== '' && strpos( $unk, '<' ) === false, 'unknown-key fallback plain text' );

// export_copy_map shape (spec §3.1).
$map = DRSPEED_AIAS_Broken_Banner::export_copy_map();
foreach ( [ 'phrases', 'remediation', 'categories' ] as $sect ) {
    drspeed_aias_assert( count( $map[ $sect ] ) === 12, "$sect has 12 keys" );
    foreach ( $keys as $k ) {
        drspeed_aias_assert( array_key_exists( $k, $map[ $sect ] ), "$sect covers $k" );
        drspeed_aias_assert( strpos( (string) $map[ $sect ][ $k ], '<' ) === false, "$sect/$k no HTML" );
    }
}
drspeed_aias_assert( strpos( $map['settings_url'], 'cu-cloudflare-waf-bypass' ) !== false, 'settings_url anchors exemption section' );
$expected_link_keys = [ 'tier2_cf_challenge', 'tier2_rocket_loader_stub', 'tier1_http_rate_limit', 'tier2_waf_challenge', 'tier2_unknown_challenge' ];
drspeed_aias_assert( $map['settings_link_keys'] === $expected_link_keys, 'settings_link_keys exact' );
// Phrases must be UNescaped source text (M1): the CF phrase contains no &quot;/&amp; artifacts.
drspeed_aias_assert( strpos( $map['phrases']['tier2_cf_challenge'], '&' ) === false, 'phrases unescaped plain text' );

// ---------------------------------------------------------------------------
// Errored-page copy spec §3.3 / §3.3.4 — the map is the single source of EVERY
// banner sentence. Approved copy, pinned verbatim (operator "apply" 2026-10-16).
// ---------------------------------------------------------------------------
$GLOBALS['drspeed_aias_n_calls'] = [];
$map = DRSPEED_AIAS_Broken_Banner::export_copy_map();

$expected = [
    'heading'       => 'Some pages couldn\'t be fully scanned',
    'dismiss'       => 'Got it — don\'t show again for this scan',
    'device'        => [ 'Desktop' => 'Desktop', 'Mobile' => 'Mobile', 'desktop' => 'desktop', 'mobile' => 'mobile' ],
    'blocked'       => [
        'desktop' => 'Desktop scanner blocked on %1$d of %2$d pages.',
        'mobile'  => 'Mobile scanner blocked on %1$d of %2$d pages.',
    ],
    'action'        => [
        'bot_one'           => 'Your bot protection denied the %1$s scanner. For %1$s rules too, temporarily disable bot protection during scans and re-scan.',
        'bot_both'          => 'Your bot protection denied the scanner. For full coverage, temporarily disable bot protection during scans and re-scan.',
        'bot_link'          => 'Behind Cloudflare or another CDN? Set up the scanner exemption so future scans aren\'t blocked — open AI Assets Scanner settings.',
        'bot_link_rate'     => 'Behind Cloudflare or another CDN? Set up the scanner rate-limit exemption so future scans aren\'t throttled — open AI Assets Scanner settings.',
        'error'             => 'Your server returned an error or didn\'t respond. Try again later, or check site health.',
        'rate_cf'           => 'Cloudflare rate-limited the scan. Whoever manages your Cloudflare — you, your host, or your agency — needs to allowlist the scanner.',
        'rate_cf_link'      => 'If you manage the Cloudflare account yourself, set up the one-time scanner exemption — open AI Assets Scanner settings.',
        'rate_host'         => "Your host's server rate-limited the scan. A CDN or WAF exemption will not help here. Wait a few minutes between scans, or ask your host to raise the rate limit during scans.",
        'rate_unknown'      => 'The scan was rate-limited. Wait a few minutes between scans.',
        'rate_unknown_link' => 'If a CDN or WAF sits in front of your site, ask whoever manages it to allowlist the scanner; otherwise check your own server\'s rate limits — open AI Assets Scanner settings.',
        'siteground'        => 'SiteGround\'s bot protection denied the scanner. Turn off the anti-bot protection in SiteGround Site Tools while the scan runs, or ask SiteGround support to allow the scanner, then re-scan.',
    ],
    'tail'          => [
        'push'     => 'Rules from the device that got through are ready to Push or Sync.',
        'download' => 'Rules from the device that got through are ready to download.',
    ],
    'errored'       => [
        'page_time_limit' => [ '%d page hit the scanner\'s time limit and wasn\'t finished. Re-scan it later.', '%d pages hit the scanner\'s time limit and weren\'t finished. Re-scan them later.' ],
        'analysis_failed' => [ '%d page wasn\'t finished (scanner error). Re-scan it later.', '%d pages weren\'t finished (scanner error). Re-scan them later.' ],
        'off_site'        => [ '%d page wasn\'t scanned: free keys cover your own site only.', '%d pages weren\'t scanned: free keys cover your own site only.' ],
        'extra_time_lost' => [ 'Extra Time for %d page couldn\'t be completed and wasn\'t charged. Re-scan it later.', 'Extra Time for %d pages couldn\'t be completed and wasn\'t charged. Re-scan them later.' ],
        'unknown'         => [ '%d page couldn\'t be finished. Re-scan it later.', '%d pages couldn\'t be finished. Re-scan them later.' ],
    ],
    'partial_row'   => [
        'push'     => '✓ %s rules ready to Push/Sync',
        'download' => '✓ %s rules ready to download',
        'none'     => '✓ %s: no unloads found',
    ],
    'stack_phrases' => [ 'siteground' => 'SiteGround bot protection' ],
];

// Key completeness + verbatim copy (exact key sets — no extra, no missing).
foreach ( $expected as $sect => $want ) {
    drspeed_aias_assert( array_key_exists( $sect, $map ), "map has '$sect'" );
    drspeed_aias_assert( $map[ $sect ] === $want, "'$sect' verbatim:\n  got  " . var_export( $map[ $sect ], true ) . "\n  want " . var_export( $want, true ) );
}

// Leaf shape: every leaf a string with zero HTML; errored.* are 2-element string LISTS.
foreach ( $expected as $sect => $want ) {
    $leaves = is_array( $map[ $sect ] ) ? $map[ $sect ] : [ $map[ $sect ] ];
    foreach ( $leaves as $k => $leaf ) {
        if ( 'errored' === $sect ) {
            drspeed_aias_assert( is_array( $leaf ) && array_keys( $leaf ) === [ 0, 1 ] && is_string( $leaf[0] ) && is_string( $leaf[1] ), "errored/$k is a [singular, plural] list" );
            $strings = $leaf;
        } else {
            drspeed_aias_assert( is_string( $leaf ), "$sect/$k is a string" );
            $strings = [ $leaf ];
        }
        foreach ( $strings as $s ) {
            drspeed_aias_assert( strpos( $s, '<' ) === false && strpos( $s, '>' ) === false, "$sect/$k no HTML" );
        }
    }
}
// JSON serialisation: errored[kind] → array, every other sub-map → object.
$json = json_decode( json_encode( $map ) );
drspeed_aias_assert( is_array( $json->errored->page_time_limit ), 'errored kind serialises as a JSON array' );
drspeed_aias_assert( is_object( $json->action ) && is_object( $json->errored ), 'action / errored serialise as JSON objects' );

// Placeholders: one ordered argument used twice; ordered %1$d / %2$d.
drspeed_aias_assert( substr_count( $map['action']['bot_one'], '%1$s' ) === 2, 'bot_one uses %1$s twice' );
drspeed_aias_assert( strpos( $map['action']['bot_one'], '%s' ) === false, 'bot_one has no bare %s' );
foreach ( [ 'desktop', 'mobile' ] as $d ) {
    drspeed_aias_assert( strpos( $map['blocked'][ $d ], '%1$d' ) !== false && strpos( $map['blocked'][ $d ], '%2$d' ) !== false, "blocked/$d ordered placeholders" );
}

// Plural mechanism (spec §3.3.2 (a)): each kind = _n( s, p, 1 ) then _n( s, p, 2 ) with the
// SAME literal pair and the plugin text domain (drift guard on the duplicated literals).
$calls = $GLOBALS['drspeed_aias_n_calls'];
drspeed_aias_assert( count( $calls ) === 2 * count( $expected['errored'] ), '_n called twice per errored kind' );
$i = 0;
foreach ( $expected['errored'] as $kind => $pair ) {
    $one = $calls[ $i++ ];
    $two = $calls[ $i++ ];
    drspeed_aias_assert( [ $one[0], $one[1], $one[2] ] === [ $pair[0], $pair[1], 1 ], "errored/$kind count-1 call carries the pair" );
    drspeed_aias_assert( [ $two[0], $two[1], $two[2] ] === [ $pair[0], $pair[1], 2 ], "errored/$kind count-2 call carries the same pair" );
    drspeed_aias_assert( 'dr-speed-ai-assets-scanner' === $one[3] && 'dr-speed-ai-assets-scanner' === $two[3], "errored/$kind text domain" );
}

// §3.3.1 row 4 — rate remediation drops the "unblocked device" sentence.
drspeed_aias_assert(
    DRSPEED_AIAS_Broken_Banner::reason_remediation( 'tier1_http_rate_limit' ) === 'Your server rate-limited the scanner. Wait a few minutes between scans, or temporarily raise rate limits during scans. Behind Cloudflare or another CDN: set up the scanner exemption in AI Assets Scanner settings.',
    'tier1_http_rate_limit remediation = §3.3.1 row-4 text'
);
foreach ( $map['remediation'] as $k => $r ) {
    drspeed_aias_assert( stripos( $r, 'unblocked device' ) === false, "remediation/$k no 'unblocked device'" );
}

// entry_phrase(): stack phrase wins for an allowlisted stack that has one, else the reason phrase.
drspeed_aias_assert( DRSPEED_AIAS_Broken_Banner::entry_phrase( [ 'reason' => 'tier1_zero_bytes', 'stack' => 'siteground' ] ) === 'SiteGround bot protection', 'entry_phrase siteground' );
drspeed_aias_assert( DRSPEED_AIAS_Broken_Banner::entry_phrase( [ 'reason' => 'tier1_zero_bytes' ] ) === 'empty response', 'entry_phrase no stack → reason phrase' );
drspeed_aias_assert( DRSPEED_AIAS_Broken_Banner::entry_phrase( [ 'reason' => 'tier1_zero_bytes', 'stack' => 'cloudflare' ] ) === 'empty response', 'entry_phrase stack without a phrase → reason phrase' );
drspeed_aias_assert( DRSPEED_AIAS_Broken_Banner::entry_phrase( [ 'reason' => 'tier1_zero_bytes', 'stack' => 'SiteGround' ] ) === 'empty response', 'entry_phrase non-allowlisted stack → reason phrase' );
drspeed_aias_assert( DRSPEED_AIAS_Broken_Banner::entry_phrase( [ 'reason' => '<b>x</b>' ] ) === '&lt;b&gt;x&lt;/b&gt;', 'entry_phrase unknown reason escaped (untrusted input)' );
drspeed_aias_assert( DRSPEED_AIAS_Broken_Banner::entry_phrase( [ 'reason' => [ 'x' ] ] ) === '', 'entry_phrase non-string reason → empty' );

echo "broken-banner-copy-map-test ... ok\n";
