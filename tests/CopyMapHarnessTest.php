<?php
namespace DrSpeedAIAS\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Runs the standalone harness tests/broken-banner-copy-map-test.php (the frozen-copy
 * pins on export_copy_map()) inside `composer test`. It defines its own WP stubs, so it
 * runs in a child PHP process, never in this one. Exit 0 alone is not a pass: an early
 * exit (e.g. a guard tripping inside a required file) also ends with 0, so the harness's
 * final success line must be on stdout too.
 */
class CopyMapHarnessTest extends TestCase {

	public function test_standalone_copy_map_harness_passes(): void {
		$cmd  = [ PHP_BINARY, DRSPEED_AIAS_DIR . 'tests/broken-banner-copy-map-test.php' ];
		$proc = proc_open( $cmd, [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
		$this->assertIsResource( $proc, 'harness process started' );

		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code = proc_close( $proc );

		$this->assertSame( 0, $code, "harness exit code\nstdout: $stdout\nstderr: $stderr" );
		$this->assertStringContainsString( 'broken-banner-copy-map-test ... ok', $stdout, "harness success line\nstderr: $stderr" );
	}
}
