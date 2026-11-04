<?php
namespace DrSpeedAIAS\Api;

defined( 'ABSPATH' ) || exit;

use DrSpeedAIAS\Settings;

class RailwayClient {
    /**
     * @throws \RuntimeException if $railway_url fails the host/scheme allowlist.
     */
    public function __construct(
        private string $railway_url,
        private string $api_key
    ) {
        if ( ! Settings::is_safe_railway_url( $railway_url ) ) {
            throw new \RuntimeException( 'Railway URL rejected: not on the host allowlist. Re-save Settings to refresh.' );
        }
    }

    public function submit_job( array $payload ): array {
        $job_token = isset( $payload['job_token'] ) ? (string) $payload['job_token'] : '';
        if ( $job_token === '' ) {
            throw new \RuntimeException( 'job_token required for Railway submit' );
        }
        // FU-AAS-VERSION-IN-SCAN-LOG (1.8.5): tell the worker which plugin build created the scan
        // so the Railway log can attribute it. Same expression the SaaS client sends on the free-key
        // routes; set HERE (not in build_submit_payload) so the interactive submit AND the outbox
        // replay both carry it, and a replay reports the build that actually replayed. The worker
        // treats a missing or malformed value as absent — never a rejection.
        $payload['plugin_version'] = defined( 'DRSPEED_AIAS_VERSION' ) ? DRSPEED_AIAS_VERSION : '';
        return $this->request( 'POST', '/jobs', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $job_token,
            ],
            'body'    => wp_json_encode( $payload ),
        ], 30 );
    }

    public function get_status( string $job_id, string $job_token, int $from = 0 ): array {
        return $this->request( 'GET', "/jobs/{$job_id}/status?from={$from}", [
            'headers' => [ 'Authorization' => 'Bearer ' . $job_token ],
        ], 15, true );
    }

    public function cancel_job( string $job_id, string $job_token ): array {
        // Railway returns { ok: true, pages_completed: N } — callers use pages_completed
        // to record the actual billable count on the local scan history (user_cancel
        // is a charging source; charge amount = pages_completed at cancel-click time).
        return $this->request( 'POST', "/jobs/{$job_id}/cancel", [
            'headers' => [ 'Authorization' => 'Bearer ' . $job_token ],
        ], 15 );
    }

    /** @var null|callable Test seam for the monotonic clock (seconds); production uses microtime( true ). */
    public static $clock = null;

    private const MARK_TTL    = 600; // seconds a demotion lasts
    private const MARK_WINDOW = 600; // seconds within which two never-delivered failures must occur
    private const FLOOR_S     = 5;   // no fallback attempt starts with less than this (spec §3.3)

    private static function now(): float {
        return self::$clock ? (float) ( self::$clock )() : microtime( true );
    }

    /** Transient-safe slug of a worker host; hosts come from Settings::RAILWAY_HOST_ORDER (code), never input. */
    private static function host_slug( string $host ): string {
        return strtolower( (string) preg_replace( '/[^a-z0-9-]/i', '-', $host ) );
    }

    private static function mark_key( string $host ): string {
        return 'drspeed_aias_worker_demoted_' . self::host_slug( $host );
    }

    private static function fails_key( string $host ): string {
        return 'drspeed_aias_worker_fails_' . self::host_slug( $host );
    }

    /**
     * Code order with demoted hosts moved to the END — never removed (spec §3.3: marks never exclude).
     *
     * @return string[]
     */
    private static function ordered_hosts(): array {
        $live    = [];
        $demoted = [];
        foreach ( Settings::railway_host_order() as $h ) {
            if ( get_transient( self::mark_key( $h ) ) ) {
                $demoted[] = $h;
            } else {
                $live[] = $h;
            }
        }
        return array_merge( $live, $demoted );
    }

    private static function record_failure( string $host ): void {
        if ( get_transient( self::mark_key( $host ) ) ) {
            return; // already demoted: a failure neither extends the mark nor seeds a counter that could outlive it
        }
        $fails = (int) get_transient( self::fails_key( $host ) ) + 1;
        if ( $fails >= 2 ) {
            set_transient( self::mark_key( $host ), time(), self::MARK_TTL );
            delete_transient( self::fails_key( $host ) ); // re-marking after expiry needs two NEW failures
            return;
        }
        set_transient( self::fails_key( $host ), $fails, self::MARK_WINDOW );
    }

    private static function record_success( string $host ): void {
        delete_transient( self::mark_key( $host ) );
        delete_transient( self::fails_key( $host ) );
    }

    /**
     * FU-WORKER-CLOUDFLARE-FRONT (spec §3.3) — the one request path for every worker call: walk the
     * ordered hosts (scanner.wpservice.pro first, the direct Railway address as the permanent fallback),
     * fail over ONLY on never-delivered errors (is_undelivered), split one total deadline across the
     * attempts (first attempt deadline − 1 s, the fallback gets the whole-second remainder, never under
     * FLOOR_S). The stored railway_url is the constructor's allowlist gate; it no longer picks the host.
     *
     * @param  array $args wp_remote_* args WITHOUT 'timeout' (set here per attempt).
     * @throws HttpException status 0 when every host was undelivered (→ Outbox::is_retryable), otherwise
     *                       the delivered answer's status as parse() throws it today.
     */
    private function request( string $method, string $path, array $args, int $deadline_s, bool $allow_410 = false ): array {
        $hosts   = self::ordered_hosts();
        $n       = count( $hosts );
        $start   = self::now();
        $last    = null;
        $timeout = max( self::FLOOR_S, $deadline_s - 1 );
        foreach ( $hosts as $i => $host ) {
            $a            = $args;
            $a['timeout'] = $timeout;
            $url          = 'https://' . $host . $path;
            $res          = ( 'POST' === $method ) ? wp_remote_post( $url, $a ) : wp_remote_get( $url, $a );
            if ( ! self::is_undelivered( $res ) ) {
                self::record_success( $host );
                return $this->parse( $res, $allow_410 ); // delivered: the worker's answer, good or bad
            }
            self::record_failure( $host );
            $last = $res;
            if ( $i >= $n - 1 ) {
                break;
            }
            $reason  = is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . (int) wp_remote_retrieve_response_code( $res );
            $timeout = $deadline_s - (int) floor( self::now() - $start ); // whole seconds used, rounded DOWN
            if ( $timeout < self::FLOOR_S ) {
                error_log( '[AI Assets Scanner] worker host ' . $host . ' undelivered (' . $reason . '); no fallback to ' . $hosts[ $i + 1 ] . ': ' . $timeout . ' s left of ' . $deadline_s . ', floor ' . self::FLOOR_S ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional production logging: the only visibility of a skipped failover (spec §3.3); host names + transport message, no tokens.
                break;
            }
            error_log( '[AI Assets Scanner] worker host failover: ' . $host . ' -> ' . $hosts[ $i + 1 ] . ': ' . $reason ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional production logging: the only visibility of a failover (spec §3.3); host names + transport message, no tokens.
        }
        if ( null === $last ) {
            throw new HttpException( 'No worker host configured', 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught by AJAX handler; passed to wp_send_json_error(), not rendered as HTML.
        }
        if ( is_wp_error( $last ) ) {
            throw new HttpException( $last->get_error_message(), 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught by AJAX handler; passed to wp_send_json_error(), not rendered as HTML.
        }
        return $this->parse( $last, $allow_410 ); // a Cloudflare 52x on the last host: parse() throws HttpException( code )
    }

    /** Cloudflare "origin not reached" statuses — the worker never saw the request (spec §3.3). */
    private const CF_UNDELIVERED = array( 521, 522, 523, 525, 526, 530 );
    /** cURL connect-phase codes: DNS, connect, TLS. 28 is split by message (connect/resolving vs read). */
    private const CURL_UNDELIVERED = array( 6, 7, 35, 51, 53, 54, 58, 59, 60 );

    /**
     * FU-WORKER-CLOUDFLARE-FRONT (spec §3.3): true when the attempt could not have reached the worker, so
     * a second host may be tried without risking a duplicate delivery. Pure; shared verbatim with the SaaS.
     *
     * - cURL transport ("cURL error N: …"): N in CURL_UNDELIVERED, or 28 whose message says the CONNECT or
     *   RESOLVE phase timed out. 28 "Operation timed out … with N bytes received" is a read timeout: delivered.
     * - streams (fsockopen) transport: Requests raises every connect-phase failure as PHP warning text or
     *   $errstr and exactly one read-side error, 'fsocket timed out' ⇒ undelivered ⇔ message ≠ that string.
     * - HTTP response: undelivered ⇔ a Cloudflare origin-not-reached status. 520/524, worker statuses and a
     *   Cloudflare 403 are delivered (or must fail loudly).
     *
     * @param \WP_Error|array $response A wp_remote_* result.
     */
    public static function is_undelivered( $response ): bool {
        if ( $response instanceof \WP_Error ) {
            $m = (string) $response->get_error_message();
            if ( preg_match( '/^cURL error (\d+):/', $m, $mm ) ) {
                $n = (int) $mm[1];
                if ( in_array( $n, self::CURL_UNDELIVERED, true ) ) {
                    return true;
                }
                if ( 28 === $n ) {
                    return str_contains( $m, 'Connection timed out' ) || str_contains( $m, 'Resolving timed out' );
                }
                return false;
            }
            return 'fsocket timed out' !== $m;
        }
        $code = is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0;
        return in_array( $code, self::CF_UNDELIVERED, true );
    }

    private function parse( mixed $response, bool $allow_410 = false ): array {
        if ( is_wp_error( $response ) ) {
            throw new HttpException( $response->get_error_message(), 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught by AJAX handler; passed to wp_send_json_error(), not rendered as HTML.
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
        if ( $allow_410 && $code === 410 ) {
            throw new HttpException( 'Job data expired. Re-download from Scan History or run a new scan.', 410 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught by AJAX handler; passed to wp_send_json_error(), not rendered as HTML.
        }
        if ( $code < 200 || $code >= 300 ) {
            throw new HttpException( "Railway HTTP {$code}: " . ( $body['message'] ?? $body['error'] ?? 'error' ), (int) $code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught by AJAX handler; passed to wp_send_json_error(), not rendered as HTML.
        }
        return $body;
    }
}
