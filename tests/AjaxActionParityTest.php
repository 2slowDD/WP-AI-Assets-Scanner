<?php
namespace DrSpeedAIAS\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every AJAX action the admin JavaScript sends must have a wp_ajax_ handler with
 * exactly that name. A mismatch fails silently: admin-ajax.php answers "0" and the
 * button just does nothing. The 1.9.4 prefix rename briefly left the banner
 * dismissal registered under its old name, which is what this test now catches.
 */
final class AjaxActionParityTest extends TestCase {

    public function test_every_action_sent_by_js_has_a_handler(): void {
        $root = dirname( __DIR__ );

        $sent = [];
        foreach ( glob( $root . '/admin/js/*.js' ) as $js ) {
            $src = (string) file_get_contents( $js );
            preg_match_all( "/(?:post\\(\\s*|append\\(\\s*'action'\\s*,\\s*|action\\s*:\\s*)['\"]([a-z0-9_]+)['\"]/", $src, $m );
            foreach ( $m[1] as $action ) {
                $sent[ $action ] = basename( $js );
            }
        }

        $handled = [];
        $php     = array_merge( glob( $root . '/admin/*.php' ), glob( $root . '/includes/*.php' ), glob( $root . '/includes/*/*.php' ) );
        foreach ( $php as $file ) {
            $src = (string) file_get_contents( $file );
            preg_match_all( "/'wp_ajax_([a-z0-9_]+)'/", $src, $m );
            $handled = array_merge( $handled, $m[1] );
        }
        // ScannerAjax registers its actions from a list: 'wp_ajax_' . $action.
        $scanner = (string) file_get_contents( $root . '/admin/class-scanner-ajax.php' );
        preg_match( '/function register\(\): void \{\s*\$actions = \[(.*?)\];/s', $scanner, $list );
        $this->assertNotEmpty( $list, 'ScannerAjax::register() action list not found' );
        preg_match_all( "/'([a-z0-9_]+)'/", $list[1], $m );
        $handled = array_merge( $handled, $m[1] );

        $this->assertGreaterThan( 20, count( $sent ), 'too few JS actions found; the scan went blind' );
        foreach ( $sent as $action => $file ) {
            $this->assertContains( $action, $handled, "{$file} sends '{$action}' but no wp_ajax_{$action} handler is registered" );
            $this->assertStringStartsWith( 'drspeed_aias_', $action, 'AJAX actions carry the plugin prefix' );
        }
    }
}
