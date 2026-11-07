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

    /** Scanner Secret + staging login: used only to build own-site request headers; never returned, logged or echoed. */
    private string $secret;
    private ?array $http_auth;
    private string $home_url;
    /** @var callable */
    private $clock;
    private float $deadline = 0.0;

    public function __construct( string $secret, ?array $http_auth, string $home_url, ?callable $clock = null ) {
        $this->secret    = $secret;
        $this->http_auth = $http_auth;
        $this->home_url  = $home_url;
        $this->clock     = $clock ?? static fn(): float => microtime( true );
    }

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

    /** @param array<string,list<string>> $by_host host key => URLs (ScannerAjax::group_urls_by_host order). */
    public function check_groups( array $by_host ): array {
        $this->deadline = (float) ( $this->clock )() + self::BUDGET_SECONDS;
        $hosts          = [];
        $checked        = 0;
        $budget_out     = false;
        foreach ( $by_host as $urls ) {
            if ( ! is_array( $urls ) || ! isset( $urls[0] ) || ! is_string( $urls[0] ) ) {
                continue;
            }
            $page1 = $urls[0];
            $row   = [
                'host'            => (string) wp_parse_url( $page1, PHP_URL_HOST ),
                'own_site'        => self::is_own_site( $page1, $this->home_url ),
                'verdict'         => 'unchecked',
                'requests'        => 0,
                'status'          => null,
                'reference_label' => null,
                'reference'       => null,
            ];
            if ( $checked >= self::MAX_HOSTS ) {
                $hosts[] = $row;
                continue;
            }
            $checked++;
            if ( $budget_out ) {
                $hosts[] = array_merge( $row, [ 'verdict' => 'unclear' ] );
                continue;
            }
            $page2 = ( isset( $urls[1] ) && is_string( $urls[1] ) ) ? $urls[1] : self::root_of( $page1 );
            if ( self::normalise_page( $page2 ) === self::normalise_page( $page1 ) ) {
                $page2 = null;
            }
            $result = $this->check_host( $page1, $page2 );
            if ( ! empty( $result['budget_out'] ) ) {
                $budget_out = true;
            }
            unset( $result['budget_out'] );
            $hosts[] = array_merge( $row, $result );
        }
        $denied = false;
        foreach ( $hosts as $h ) {
            $denied = $denied || 'denied' === $h['verdict'];
        }
        return [ 'hosts' => $hosts, 'denied' => $denied ];
    }

    private function check_host( string $page1, ?string $page2 ): array {
        $n   = 0;
        $end = static fn( string $verdict, int $n, bool $budget = false ): array => [ 'verdict' => $verdict, 'requests' => $n, 'budget_out' => $budget ];

        $a1 = $this->request( $page1, 'A' );
        if ( 'budget' === $a1['kind'] ) { return $end( 'unclear', $n, true ); }
        $n++;
        if ( 'error' === $a1['kind'] ) { return $end( 'unclear', $n ); }
        if ( ! self::refused( $a1 ) ) { return $end( 'ok', $n ); }

        $b1 = $this->request( $page1, 'B' );
        if ( 'budget' === $b1['kind'] ) { return $end( 'unclear', $n, true ); }
        $n++;
        if ( ! self::refused( $b1 ) ) { return $end( 'ok', $n ); }

        if ( null !== $page2 ) {
            $a2 = $this->request( $page2, 'A' );
            if ( 'budget' === $a2['kind'] ) { return $end( 'unclear', $n, true ); }
            $n++;
            $page2_redirects = 'response' === $a2['kind'] && $a2['status'] >= 300 && $a2['status'] < 400;
            if ( ! $page2_redirects ) {
                if ( ! self::refused( $a2 ) ) { return $end( 'ok', $n ); }
                $b2 = $this->request( $page2, 'B' );
                if ( 'budget' === $b2['kind'] ) { return $end( 'unclear', $n, true ); }
                $n++;
                if ( ! self::refused( $b2 ) ) { return $end( 'ok', $n ); }
            }
        }

        $c = $this->request( $page1, 'C' );
        if ( 'budget' === $c['kind'] ) { return $end( 'unclear', $n, true ); }
        $n++;
        $c_passes = 'response' === $c['kind'] && $c['status'] >= 200 && $c['status'] < 300 && 202 !== $c['status'];
        if ( ! $c_passes ) {
            return $end( 'refused_general', $n );
        }
        $ref = self::extract_reference( $b1['body'] ) ?? self::extract_reference( $a1['body'] );
        return [
            'verdict'         => 'denied',
            'requests'        => $n,
            'budget_out'      => false,
            'status'          => $b1['status'],
            'reference_label' => $ref['label'] ?? null,
            'reference'       => $ref['value'] ?? null,
        ];
    }

    private static function refused( array $r ): bool {
        return 'response' === $r['kind'] && in_array( $r['status'], self::REFUSED_STATUSES, true );
    }

    /** @return array{kind:'response'|'error'|'budget',status?:int,body?:string} */
    private function request( string $url, string $identity ): array {
        $remaining = $this->deadline - (float) ( $this->clock )();
        if ( $remaining <= 0 ) {
            return [ 'kind' => 'budget' ];
        }
        $headers = [
            'User-Agent' => 'A' === $identity ? PluginDetector::PROBE_USER_AGENT
                : ( 'B' === $identity ? self::scanner_user_agent() : self::control_user_agent() ),
            'Accept'     => PluginDetector::PROBE_ACCEPT,
            'Range'      => 'bytes=0-32767',
        ];
        if ( 'A' !== $identity && self::credentials_allowed( $url, $this->home_url ) ) {
            if ( 'B' === $identity && '' !== $this->secret ) {
                $headers['x-cu-scanner'] = $this->secret;
            }
            $basic = $this->basic_auth();
            if ( null !== $basic ) {
                $headers['Authorization'] = $basic;
            }
        }
        $response = wp_safe_remote_get( $url, [
            'timeout'             => min( self::REQUEST_TIMEOUT, $remaining ),
            'redirection'         => 0,
            'limit_response_size' => 32768,
            'sslverify'           => true,
            'headers'             => $headers,
        ] );
        if ( is_wp_error( $response ) ) {
            return [ 'kind' => 'error' ];
        }
        return [
            'kind'   => 'response',
            'status' => (int) wp_remote_retrieve_response_code( $response ),
            'body'   => (string) wp_remote_retrieve_body( $response ),
        ];
    }

    private function basic_auth(): ?string {
        $user = is_array( $this->http_auth ) ? (string) ( $this->http_auth['username'] ?? '' ) : '';
        $pass = is_array( $this->http_auth ) ? (string) ( $this->http_auth['password'] ?? '' ) : '';
        if ( '' === $user || '' === $pass ) {
            return null;
        }
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication (RFC 7617) is defined as base64 of "user:pass"; same credential the scanner worker sends to this site.
        return 'Basic ' . base64_encode( $user . ':' . $pass );
    }
}
