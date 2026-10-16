<?php
namespace DrSpeedAIAS\Tests;

use DRSPEED_AIAS_Broken_Banner;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * tests/js/fixtures/copy-map.json is the map the JS banner tests render with. It must
 * equal a fresh export_copy_map() (stubbed exactly as tools/dump-copy-map.php stubs it),
 * so the PHP twin and scanner.js are always tested on the same sentences.
 * On failure: php tools/dump-copy-map.php > tests/js/fixtures/copy-map.json
 */
class CopyMapFixtureFreshTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_committed_fixture_equals_a_fresh_dump(): void {
		WP_Mock::userFunction( '__' )->andReturnArg( 0 );
		WP_Mock::userFunction( '_n' )->andReturnUsing( function ( $s, $p, $n, $d ) { return 1 === $n ? $s : $p; } );
		WP_Mock::userFunction( 'esc_url' )->andReturnArg( 0 );
		WP_Mock::userFunction( 'admin_url' )->andReturnArg( 0 );

		$fixture = json_decode( (string) file_get_contents( DRSPEED_AIAS_DIR . 'tests/js/fixtures/copy-map.json' ), true );

		$this->assertIsArray( $fixture, 'fixture is valid JSON' );
		$this->assertSame( DRSPEED_AIAS_Broken_Banner::export_copy_map(), $fixture, 'stale fixture: php tools/dump-copy-map.php > tests/js/fixtures/copy-map.json' );
	}
}
