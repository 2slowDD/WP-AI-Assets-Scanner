<?php
namespace DrSpeedAIAS\Tests;

use PHPUnit\Framework\TestCase;

/**
 * WordPress.org review (1.9.3): on a front-end request with a valid scan token,
 * BypassHandler -> PluginDetector::detect_typed() called is_plugin_active(), which
 * exists only after wp-admin/includes/plugin.php is loaded, so the page fataled.
 *
 * Every function that calls one of these admin-only helpers must first call
 * PluginDetector::load_plugin_api(). Checked on the source, per function, so a new
 * caller added later cannot slip through.
 */
final class AdminOnlyFunctionGuardTest extends TestCase {

    private const ADMIN_ONLY = [ 'is_plugin_active', 'is_plugin_active_for_network', 'get_plugin_data', 'get_plugins' ];

    public function test_every_caller_loads_the_plugin_api_first(): void {
        $root  = dirname( __DIR__ );
        $files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/includes' ) );
        $paths = [ $root . '/dr-speed-ai-assets-scanner.php' ];
        foreach ( $files as $f ) {
            if ( str_ends_with( (string) $f, '.php' ) ) {
                $paths[] = (string) $f;
            }
        }
        foreach ( glob( $root . '/admin/*.php' ) as $f ) {
            $paths[] = $f;
        }

        $checked = 0;
        foreach ( $paths as $path ) {
            $src = (string) file_get_contents( $path );
            // Split into function bodies; the chunk before the first "function" is file scope.
            $chunks = preg_split( '/\bfunction\s+(?=[A-Za-z_])/', $src );
            foreach ( $chunks as $i => $chunk ) {
                $code = preg_replace( '#//[^\n]*|/\*.*?\*/#s', '', $chunk );
                foreach ( self::ADMIN_ONLY as $fn ) {
                    $at = preg_match( '/(?<![\w>:$])' . $fn . '\s*\(/', $code, $m, PREG_OFFSET_CAPTURE ) ? $m[0][1] : false;
                    if ( false === $at ) {
                        continue;
                    }
                    ++$checked;
                    $name  = 0 === $i ? '(file scope)' : strtok( $chunk, '(' );
                    $guard = strpos( $code, 'load_plugin_api()' );
                    $this->assertTrue(
                        false !== $guard && $guard < $at,
                        basename( $path ) . ' ' . $name . '() calls ' . $fn . '() without PluginDetector::load_plugin_api() first'
                    );
                }
            }
        }
        $this->assertGreaterThanOrEqual( 5, $checked, 'the scan found too few callers; the guard went blind' );
    }

    public function test_the_loader_requires_the_core_file_only_when_needed(): void {
        $src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/scanner/class-plugin-detector.php' );
        $this->assertMatchesRegularExpression(
            "#if \( ! function_exists\( 'is_plugin_active' \) \) \{\s*require_once ABSPATH \. 'wp-admin/includes/plugin\.php';#",
            $src
        );
    }
}
