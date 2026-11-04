<?php
namespace DrSpeedAIAS\Tests;

use DrSpeedAIAS\Scanner\Outbox;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * FU-AAS-OUTBOX-CANNOT-BE-CANCELLED — Outbox::discard().
 *
 * Cancel in the locally-queued state must drop the entry, release the reservation
 * it holds, clear bypass tokens and unschedule the replay cron — and must do none
 * of that when there is nothing pending or a dispatch pass holds the lock.
 */
class OutboxCancelTest extends TestCase {
    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    private function pendingEntry( ?string $token ): array {
        return [
            'status'          => 'pending',
            'attempts'        => 3,
            'created_at'      => time() - 600,
            'next_attempt_at' => time() + 300,
            'job_token'       => $token,
            'last_error'      => 'cURL error 28',
            'intent'          => [ 'user_id' => 7, 'page_count' => 1 ],
        ];
    }

    private function deps( &$log, bool $release_throws = false ): array {
        $log = [ 'release' => [], 'clear_bypass' => 0 ];
        return [
            'release'      => function ( $tok ) use ( &$log, $release_throws ) {
                $log['release'][] = $tok;
                if ( $release_throws ) { throw new \RuntimeException( 'HTTP 409: already released' ); }
            },
            'clear_bypass' => function () use ( &$log ) { $log['clear_bypass']++; },
        ];
    }

    public function test_discard_releases_token_drops_entry_and_unschedules(): void {
        WP_Mock::userFunction( 'get_option' )->with( Outbox::OPTION_KEY )->andReturn( $this->pendingEntry( 'TOK-Q' ) );
        WP_Mock::userFunction( 'add_option' )->andReturn( true );                        // lock acquired
        WP_Mock::userFunction( 'delete_option' )->with( Outbox::OPTION_KEY )->once()->andReturn( true );
        WP_Mock::userFunction( 'delete_option' )->with( Outbox::LOCK_KEY )->once()->andReturn( true );
        WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->with( Outbox::CRON_HOOK )->once()->andReturn( 1 );

        $deps = $this->deps( $log );
        $this->assertTrue( Outbox::discard( $deps ) );
        $this->assertConditionsMet();
        $this->assertSame( [ 'TOK-Q' ], $log['release'], 'the held reservation is released exactly once' );
        $this->assertSame( 1, $log['clear_bypass'] );
    }

    public function test_discard_without_a_token_skips_release(): void {
        WP_Mock::userFunction( 'get_option' )->with( Outbox::OPTION_KEY )->andReturn( $this->pendingEntry( null ) );
        WP_Mock::userFunction( 'add_option' )->andReturn( true );
        WP_Mock::userFunction( 'delete_option' )->andReturn( true );
        WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->once()->andReturn( 1 );

        $deps = $this->deps( $log );
        $this->assertTrue( Outbox::discard( $deps ) );
        $this->assertSame( [], $log['release'] );
        $this->assertSame( 1, $log['clear_bypass'] );
    }

    public function test_discard_still_drops_entry_when_release_fails(): void {
        // A 409 from the SaaS (token already released/finalised) must not leave the
        // queue in place — the user asked for the scan to stop.
        WP_Mock::userFunction( 'get_option' )->with( Outbox::OPTION_KEY )->andReturn( $this->pendingEntry( 'TOK-Q' ) );
        WP_Mock::userFunction( 'add_option' )->andReturn( true );
        WP_Mock::userFunction( 'delete_option' )->with( Outbox::OPTION_KEY )->once()->andReturn( true );
        WP_Mock::userFunction( 'delete_option' )->with( Outbox::LOCK_KEY )->once()->andReturn( true );
        WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->once()->andReturn( 1 );

        $deps = $this->deps( $log, true );
        $this->assertTrue( Outbox::discard( $deps ) );
        $this->assertConditionsMet();
        $this->assertSame( [ 'TOK-Q' ], $log['release'] );
    }

    public function test_discard_returns_false_when_nothing_is_pending(): void {
        WP_Mock::userFunction( 'get_option' )->with( Outbox::OPTION_KEY )->andReturn( false );
        WP_Mock::userFunction( 'delete_option' )->never();
        WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->never();

        $deps = $this->deps( $log );
        $this->assertFalse( Outbox::discard( $deps ) );
        $this->assertConditionsMet();
        $this->assertSame( [], $log['release'] );
    }

    public function test_discard_leaves_a_failed_entry_alone(): void {
        $entry = $this->pendingEntry( 'TOK-Q' );
        $entry['status'] = 'failed';
        WP_Mock::userFunction( 'get_option' )->with( Outbox::OPTION_KEY )->andReturn( $entry );
        WP_Mock::userFunction( 'delete_option' )->never();

        $deps = $this->deps( $log );
        $this->assertFalse( Outbox::discard( $deps ) );
        $this->assertConditionsMet();
        $this->assertSame( [], $log['release'], 'a failed entry already released its token in fail()' );
    }

    public function test_discard_returns_false_while_a_dispatch_holds_the_lock(): void {
        // A cron replay mid-flight owns the entry; deleting underneath it would let its
        // crash-safe checkpoint re-create the entry (with a fresh reservation) after we
        // told the user it was cancelled. Let the user retry a moment later instead.
        WP_Mock::userFunction( 'get_option' )->with( Outbox::OPTION_KEY )->andReturn( $this->pendingEntry( 'TOK-Q' ) );
        WP_Mock::userFunction( 'add_option' )->andReturn( false );                       // lock exists
        WP_Mock::userFunction( 'get_option' )->with( Outbox::LOCK_KEY )->andReturn( time() ); // and is fresh
        WP_Mock::userFunction( 'delete_option' )->never();
        WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->never();

        $deps = $this->deps( $log );
        $this->assertFalse( Outbox::discard( $deps ) );
        $this->assertConditionsMet();
        $this->assertSame( [], $log['release'] );
    }
}
