<?php
namespace DrSpeedAIAS\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * FU-SITE-DENIAL Part C — pre-scan check: does the scanned site's firewall refuse our scanner BY NAME?
 *
 * Requests per host (spec Rev 3.1 §3.2), stopping at the first conclusion:
 *   A = probe identity (no credentials); B = worker-like identity + own-site credentials;
 *   C = unnamed control (same as B minus our tag and minus x-cu-scanner), sent only after A and B are refused.
 * `denied` only when A and B are refused on every checked page AND C gets a real page (2xx, not 202).
 * Every request: wp_safe_remote_get, redirection 0 (no header ever follows a redirect to another host).
 */
final class ScannerAccessCheck {

    /** Chromium major version of the scan worker's browser. Bump to the worker's major at each release. */
    public const CHROME_MAJOR     = '153';
    public const REFUSED_STATUSES = [ 403, 406, 418, 425, 451 ];
    public const MAX_URLS         = 20;
    public const MAX_HOSTS        = 5;
    public const BUDGET_SECONDS   = 20.0;
    public const REQUEST_TIMEOUT  = 6.0;

    private const BODY_SCAN_BYTES = 32768;
    private const SCANNER_TAG     = '(compatible; CU-Scanner/1.0; +https://wpservice.pro/bot)';
    private const REFERENCE_RE    = '/\b(Reference(?:\s+ID)?|Support\s+ID|Incident\s+ID|Request\s+ID|Event\s+ID|Ray\s+ID)\s*[:#]?\s*([A-Za-z0-9][A-Za-z0-9._-]{2,63})(?![A-Za-z0-9._-])/i';
    private const REFERENCE_LABELS = [
        'reference'    => 'Reference',
        'reference id' => 'Reference ID',
        'support id'   => 'Support ID',
        'incident id'  => 'Incident ID',
        'request id'   => 'Request ID',
        'event id'     => 'Event ID',
        'ray id'       => 'Ray ID',
    ];

    public static function control_user_agent(): string {
        return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/'
            . self::CHROME_MAJOR . '.0.0.0 Safari/537.36';
    }

    public static function scanner_user_agent(): string {
        return self::control_user_agent() . ' ' . self::SCANNER_TAG;
    }

    /** Host key with JS `URL.host` semantics: lower-case, one leading `www.` removed, explicit non-default port kept (plan E2). */
    private static function site_key( string $url ): ?string {
        $p = wp_parse_url( $url );
        if ( ! is_array( $p ) || empty( $p['host'] ) ) {
            return null;
        }
        $scheme  = strtolower( (string) ( $p['scheme'] ?? 'https' ) );
        $host    = preg_replace( '/^www\./', '', strtolower( (string) $p['host'] ) );
        $default = 'http' === $scheme ? 80 : 443;
        $port    = ( isset( $p['port'] ) && (int) $p['port'] !== $default ) ? ':' . (int) $p['port'] : '';
        return $host . $port;
    }

    public static function is_own_site( string $url, string $home ): bool {
        $a = self::site_key( $url );
        $b = self::site_key( $home );
        return null !== $a && $a === $b;
    }

    /** Credentials go only to own-site URLs, and never over http when the site itself is https. */
    public static function credentials_allowed( string $url, string $home ): bool {
        if ( ! self::is_own_site( $url, $home ) ) {
            return false;
        }
        $url_scheme  = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
        $home_scheme = strtolower( (string) wp_parse_url( $home, PHP_URL_SCHEME ) );
        return ! ( 'https' === $home_scheme && 'https' !== $url_scheme );
    }

    /** Lower-case host, fragment dropped, empty path = "/", query kept. Used only to compare page 1 and page 2. */
    public static function normalise_page( string $url ): string {
        $p = wp_parse_url( $url );
        if ( ! is_array( $p ) ) {
            return $url;
        }
        $port = isset( $p['port'] ) ? ':' . (int) $p['port'] : '';
        $path = ( isset( $p['path'] ) && '' !== $p['path'] ) ? $p['path'] : '/';
        $q    = isset( $p['query'] ) ? '?' . $p['query'] : '';
        return strtolower( (string) ( $p['scheme'] ?? 'https' ) ) . '://' . strtolower( (string) ( $p['host'] ?? '' ) ) . $port . $path . $q;
    }

    /** Root of the page's own host AS WRITTEN (www. and port kept) — not ScannerAjax::root_url_for(), which strips www. */
    public static function root_of( string $url ): string {
        $p    = wp_parse_url( $url );
        $p    = is_array( $p ) ? $p : [];
        $port = isset( $p['port'] ) ? ':' . (int) $p['port'] : '';
        return ( $p['scheme'] ?? 'https' ) . '://' . ( $p['host'] ?? '' ) . $port . '/';
    }

    /**
     * First firewall reference code in the first 32 KB of a block page; label normalised, value allowlisted.
     *
     * Tags are padded with a space before stripping: tag stripping inserts nothing between elements, so a
     * minified page (`…Blocked</h1><p>Reference ID …`) would otherwise glue words together and defeat `\b`.
     * The whitespace collapse deliberately has no `/u` flag: with it, invalid UTF-8 (a Latin-1 page, or a
     * multibyte character split by the 32 KB cut) makes preg_replace return null and the code would be lost.
     */
    public static function extract_reference( string $body ): ?array {
        $text = wp_strip_all_tags( str_replace( '<', ' <', substr( $body, 0, self::BODY_SCAN_BYTES ) ) );
        $text = preg_replace( '/\s+/', ' ', (string) $text );
        if ( ! is_string( $text ) || ! preg_match_all( self::REFERENCE_RE, $text, $all, PREG_SET_ORDER ) ) {
            return null;
        }
        // First candidate whose value carries a digit: prose such as "quote this reference when …" is skipped.
        foreach ( $all as $m ) {
            if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{2,63}$/', $m[2] ) || ! preg_match( '/\d/', $m[2] ) ) {
                continue;
            }
            $key = strtolower( (string) preg_replace( '/\s+/', ' ', $m[1] ) );
            if ( ! isset( self::REFERENCE_LABELS[ $key ] ) ) {
                continue;
            }
            return [ 'label' => self::REFERENCE_LABELS[ $key ], 'value' => $m[2] ];
        }
        return null;
    }
}
