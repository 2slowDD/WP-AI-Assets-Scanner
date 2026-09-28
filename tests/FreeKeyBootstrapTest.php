<?php
namespace CUScanner\Tests;

use CUScanner\FreeKeyBootstrap;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class FreeKeyBootstrapTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_bootstrap_keeps_existing_paid_key(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_api_key', '' )
            ->andReturn( 'cusk_paid_random' );
        WP_Mock::userFunction( 'update_option' )->never();

        $bootstrap = new FreeKeyBootstrap( null, function (): object {
            throw new \RuntimeException( 'Client should not be created' );
        } );

        $bootstrap->run();
        $this->assertTrue( true );
    }

    public function test_bootstrap_stores_returned_free_key_for_empty_install(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_api_key', '' )
            ->andReturn( '' );
        WP_Mock::userFunction( 'update_option' )
            ->with( 'cu_scanner_api_key', 'cusk_Freekey_10' )
            ->once();
        WP_Mock::userFunction( 'delete_option' )
            ->with( 'cu_scanner_free_key_pending' )
            ->once();
        WP_Mock::userFunction( 'delete_option' )
            ->with( 'aias_free_key_unusable' )
            ->once();

        $bootstrap = new FreeKeyBootstrap( null, function (): object {
            return new class {
                public function register_free_key( string $current ): array {
                    return [ 'api_key' => 'cusk_Freekey_10', 'balance' => 3, 'status' => 'active' ];
                }
            };
        } );

        $bootstrap->run();
        $this->assertTrue( true );
    }

    public function test_bootstrap_caches_railway_url_after_free_key_activation(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_api_key', '' )
            ->andReturn( '' );
        WP_Mock::userFunction( 'update_option' )
            ->with( 'cu_scanner_api_key', 'cusk_Freekey_10' )
            ->once();
        WP_Mock::userFunction( 'delete_option' )
            ->with( 'cu_scanner_free_key_pending' )
            ->once();
        WP_Mock::userFunction( 'delete_option' )
            ->with( 'aias_free_key_unusable' )
            ->once();
        WP_Mock::userFunction( 'wp_parse_url' )
            ->andReturnUsing( function ( string $url, ?int $component = null ) {
                $parts = parse_url( $url );
                if ( null === $component ) {
                    return $parts;
                }
                $map = [
                    PHP_URL_SCHEME => 'scheme',
                    PHP_URL_HOST   => 'host',
                    PHP_URL_PORT   => 'port',
                    PHP_URL_USER   => 'user',
                    PHP_URL_PASS   => 'pass',
                ];
                return $parts[ $map[ $component ] ?? '' ] ?? null;
            } );
        WP_Mock::userFunction( 'update_option' )
            ->with( 'aias_railway_url', 'https://cu-scanner-railway-production.up.railway.app' )
            ->once();

        $bootstrap = new FreeKeyBootstrap( null, function ( string $current_key ): object {
            if ( 'cusk_Freekey_10' === $current_key ) {
                return new class {
                    public function authenticate(): array {
                        return [ 'railway_url' => 'https://cu-scanner-railway-production.up.railway.app' ];
                    }
                };
            }

            return new class {
                public function register_free_key( string $current ): array {
                    return [ 'api_key' => 'cusk_Freekey_10', 'balance' => 3, 'status' => 'active' ];
                }
            };
        } );

        $bootstrap->run();
        $this->assertTrue( true );
    }

    public function test_bootstrap_sets_pending_placeholder_when_saas_unreachable(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_api_key', '' )
            ->andReturn( '' );
        WP_Mock::userFunction( 'update_option' )
            ->with( 'cu_scanner_api_key', 'cusk_Freekey_?' )
            ->once();
        WP_Mock::userFunction( 'update_option' )
            ->with( 'cu_scanner_free_key_pending', '1', false )
            ->once();

        $bootstrap = new FreeKeyBootstrap( null, function (): object {
            return new class {
                public function register_free_key( string $current ): array {
                    throw new \RuntimeException( 'offline' );
                }
            };
        } );

        $bootstrap->run();
        $this->assertTrue( true );
    }

    public function test_free_key_opt_in_is_offered_for_empty_or_pending_key_only(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_api_key', '' )
            ->andReturn( '', 'cusk_Freekey_?', 'cusk_Freekey_10', 'cusk_paid_key' );

        $this->assertTrue( FreeKeyBootstrap::can_request( new \CUScanner\Settings() ) );
        $this->assertTrue( FreeKeyBootstrap::can_request( new \CUScanner\Settings() ) );
        $this->assertFalse( FreeKeyBootstrap::can_request( new \CUScanner\Settings() ) );
        $this->assertFalse( FreeKeyBootstrap::can_request( new \CUScanner\Settings() ) );
    }

    public function test_converted_key_is_not_stored_and_stops_the_retry_loop(): void {
        // The service returns the domain's existing key; this one was upgraded to paid.
        WP_Mock::userFunction( 'get_option' )->with( 'cu_scanner_api_key', '' )->andReturn( '' );
        WP_Mock::userFunction( 'update_option' )->with( 'cu_scanner_api_key', \Mockery::any() )->never();
        WP_Mock::userFunction( 'update_option' )->with( 'aias_free_key_unusable', 'converted', false )->once();
        WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->with( 'cu_scanner_free_key_retry' )->once();
        WP_Mock::userFunction( 'wp_schedule_single_event' )->never();

        $bootstrap = new FreeKeyBootstrap( null, function (): object {
            return new class {
                public function register_free_key( string $current ): array {
                    return [ 'api_key' => 'cusk_Freekey_9', 'balance' => 5, 'status' => 'converted' ];
                }
            };
        } );

        $this->assertSame( FreeKeyBootstrap::OUTCOME_UNUSABLE, $bootstrap->run() );
    }

    public function test_revoked_key_clears_a_pending_placeholder_instead_of_storing_the_key(): void {
        WP_Mock::userFunction( 'get_option' )->with( 'cu_scanner_api_key', '' )->andReturn( 'cusk_Freekey_?' );
        WP_Mock::userFunction( 'update_option' )->with( 'cu_scanner_api_key', '' )->once();
        WP_Mock::userFunction( 'delete_option' )->with( 'cu_scanner_free_key_pending' )->once();
        WP_Mock::userFunction( 'update_option' )->with( 'aias_free_key_unusable', 'revoked', false )->once();
        WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->with( 'cu_scanner_free_key_retry' )->once();

        $bootstrap = new FreeKeyBootstrap( null, function (): object {
            return new class {
                public function register_free_key( string $current ): array {
                    return [ 'api_key' => 'cusk_Freekey_9', 'balance' => 0, 'status' => 'revoked' ];
                }
            };
        } );

        $this->assertSame( FreeKeyBootstrap::OUTCOME_UNUSABLE, $bootstrap->run() );
    }

    public function test_site_already_holding_a_dead_key_keeps_it_but_stops_retrying(): void {
        // A 1.9.1 site that stored its converted key: the next hourly retry lands here.
        WP_Mock::userFunction( 'get_option' )->with( 'cu_scanner_api_key', '' )->andReturn( 'cusk_Freekey_9' );
        WP_Mock::userFunction( 'update_option' )->with( 'cu_scanner_api_key', \Mockery::any() )->never();
        WP_Mock::userFunction( 'update_option' )->with( 'aias_free_key_unusable', 'converted', false )->once();
        WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->with( 'cu_scanner_free_key_retry' )->once();
        WP_Mock::userFunction( 'wp_schedule_single_event' )->never();

        $bootstrap = new FreeKeyBootstrap( null, function (): object {
            return new class {
                public function register_free_key( string $current ): array {
                    return [ 'api_key' => 'cusk_Freekey_9', 'balance' => 5, 'status' => 'converted' ];
                }
            };
        } );

        $this->assertSame( FreeKeyBootstrap::OUTCOME_UNUSABLE, $bootstrap->run() );
    }


    public function test_the_failure_behind_a_pending_outcome_is_kept_for_the_settings_screen(): void {
        WP_Mock::userFunction( 'get_option' )->with( 'cu_scanner_api_key', '' )->andReturn( '' );
        WP_Mock::userFunction( 'update_option' );
        WP_Mock::userFunction( 'wp_next_scheduled' )->andReturn( false );
        WP_Mock::userFunction( 'wp_schedule_single_event' );

        $bootstrap = new FreeKeyBootstrap( null, function (): object {
            return new class {
                public function register_free_key( string $current ): array {
                    throw new \CUScanner\Api\HttpException( 'HTTP 429: Too many free key registration attempts. Try again later.', 429 );
                }
            };
        } );

        $this->assertSame( 'pending', $bootstrap->run() );
        $error = $bootstrap->last_error();
        $this->assertInstanceOf( \CUScanner\Api\HttpException::class, $error );
        $this->assertSame( 429, $error->get_status_code() );
    }

    /** @dataProvider welcome_cases */
    public function test_stored_key_leaves_a_one_shot_welcome_for_settings( array $reply, bool $restored ): void {
        WP_Mock::userFunction( 'get_option' )->with( 'cu_scanner_api_key', '' )->andReturn( '' );
        WP_Mock::userFunction( 'update_option' );
        WP_Mock::userFunction( 'delete_option' );
        WP_Mock::userFunction( 'set_transient' )
            ->with( FreeKeyBootstrap::WELCOME_TRANSIENT, [ 'restored' => $restored, 'key' => 'cusk_Freekey_12', 'balance' => 4 ], 600 )
            ->once();

        $bootstrap = new FreeKeyBootstrap( null, function () use ( $reply ): object {
            return new class( $reply ) {
                public function __construct( private array $reply ) {}
                public function register_free_key( string $current ): array {
                    return $this->reply;
                }
            };
        } );

        $this->assertSame( 'stored', $bootstrap->run() );
    }

    public static function welcome_cases(): array {
        $base = [ 'api_key' => 'cusk_Freekey_12', 'balance' => 4, 'status' => 'active' ];
        return [
            'domain had a key: welcome back' => [ $base + [ 'restored' => true ], true ],
            'new key'                        => [ $base + [ 'restored' => false ], false ],
            'SaaS older than 1.2.47'         => [ $base, false ],
        ];
    }
}
