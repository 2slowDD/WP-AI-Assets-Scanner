<?php
// tests/RailwayClientUndeliveredTest.php
namespace DrSpeedAIAS\Tests;

use DrSpeedAIAS\Api\RailwayClient;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * FU-WORKER-CLOUDFLARE-FRONT (spec §3.3, AC-3) — the never-delivered classifier. Rows are the message
 * strings as WordPress's bundled Requests transports emit them (Curl.php / Fsockopen.php), flattened by
 * WP_Http to WP_Error( 'http_request_failed', $message ). The table is shared verbatim with the SaaS
 * classifier (wpservice-saas CU_Railway_Failover): the two MUST agree.
 */
class RailwayClientUndeliveredTest extends TestCase {

    /** @dataProvider undelivered_cases */
    public function test_is_undelivered_classifier( $response, bool $expected ): void {
        $this->assertSame( $expected, RailwayClient::is_undelivered( $response ) );
    }

    public function undelivered_cases(): array {
        $err  = fn( string $m ) => new WP_Error( 'http_request_failed', $m );
        $http = fn( int $code ) => [ 'response' => [ 'code' => $code ], 'body' => '' ];
        return [
            // cURL transport — strings as Requests/cURL emit them (Requests Curl.php › "throw new Exception($error, 'curlerror'")
            'curl 28 connect'   => [ $err( 'cURL error 28: Connection timed out after 10001 milliseconds' ), true ],
            'curl 28 resolving (DNS-phase)' => [ $err( 'cURL error 28: Resolving timed out after 10000 milliseconds' ), true ],
            'curl 28 read'      => [ $err( 'cURL error 28: Operation timed out after 30000 milliseconds with 0 bytes received' ), false ],
            'curl 7'            => [ $err( 'cURL error 7: Failed to connect to scanner.wpservice.pro port 443 after 21 ms: Couldn\'t connect to server' ), true ],
            'curl 6'            => [ $err( 'cURL error 6: Could not resolve host: scanner.wpservice.pro' ), true ],
            'curl 35'           => [ $err( 'cURL error 35: OpenSSL SSL_connect: SSL_ERROR_SYSCALL in connection to scanner.wpservice.pro:443' ), true ],
            'curl 56 recv'      => [ $err( 'cURL error 56: Recv failure: Connection reset by peer' ), false ],
            // streams transport — Requests Fsockopen.php: connect-phase = captured PHP warning text or $errstr; the ONLY read error is 'fsocket timed out'
            'streams connect'   => [ $err( 'stream_socket_client(): Unable to connect to ssl://scanner.wpservice.pro:443 (Connection timed out)' ), true ],
            'streams dns'       => [ $err( 'php_network_getaddresses: getaddrinfo failed: Name or service not known' ), true ],
            'streams errstr'    => [ $err( 'Connection refused' ), true ],
            'streams read'      => [ $err( 'fsocket timed out' ), false ],
            'empty message'     => [ $err( '' ), true ],
            // captured 2026-11-04 render stack (WordPress 7.0.1, PHP 8.2, Requests Fsockopen forced by disabling the cURL functions,
            // host blackholed to TEST-NET-2): the REAL connect-phase text says "Operation timed out", not "Connection timed out".
            'streams connect (captured)' => [ $err( 'stream_socket_client(): Unable to connect to ssl://scanner.wpservice.pro:443 (Operation timed out)' ), true ],
            // captured 2026-11-04 render stack (cURL, second attempt under its 5 s remainder): the overall timeout fired in the connect phase.
            'curl 28 connect, 5 s budget (captured)' => [ $err( 'cURL error 28: Connection timed out after 5001 milliseconds' ), true ],
            // Cloudflare origin-not-reached vs delivered
            'cf 521' => [ $http( 521 ), true ], 'cf 522' => [ $http( 522 ), true ], 'cf 523' => [ $http( 523 ), true ],
            'cf 525' => [ $http( 525 ), true ], 'cf 526' => [ $http( 526 ), true ], 'cf 530' => [ $http( 530 ), true ],
            'cf 520' => [ $http( 520 ), false ], 'cf 524' => [ $http( 524 ), false ], 'cf 403' => [ $http( 403 ), false ],
            'worker 401' => [ $http( 401 ), false ], 'worker 500' => [ $http( 500 ), false ], 'ok 200' => [ $http( 200 ), false ],
        ];
    }
}
