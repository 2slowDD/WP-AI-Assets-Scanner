<?php
namespace DrSpeedAIAS\Tests;

use DrSpeedAIAS\FreeKeyBootstrap;
use DrSpeedAIAS\Settings;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * The Settings page dims around "Validate your key" only while that is the next step.
 * Operator 2026-11-02: it must not show once a key is in place, validated or bought.
 */
class FirstRunGuideTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    private function guide_for( string $key, string $unusable = '' ): bool {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'drspeed_aias_api_key', '' )
            ->andReturn( $key );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'drspeed_aias_free_key_unusable', '' )
            ->andReturn( $unusable );

        return FreeKeyBootstrap::needs_first_run_guide( new Settings() );
    }

    public function test_guides_a_new_install_with_no_key(): void {
        $this->assertTrue( $this->guide_for( '' ) );
    }

    public function test_guides_while_a_free_key_request_is_still_pending(): void {
        $this->assertTrue( $this->guide_for( 'cusk_Freekey_?' ) );
    }

    public function test_never_guides_once_a_validated_free_key_is_in_place(): void {
        $this->assertFalse( $this->guide_for( 'cusk_Freekey_12' ) );
    }

    public function test_never_guides_once_a_paid_key_is_in_place(): void {
        $this->assertFalse( $this->guide_for( 'cusk_' . str_repeat( 'a', 40 ) ) );
    }

    /**
     * Validate cannot help here: the page tells them to enter a paid key instead,
     * so spotlighting the button would point them the wrong way.
     */
    public function test_does_not_guide_when_the_free_key_is_converted_or_revoked(): void {
        $this->assertFalse( $this->guide_for( '', 'converted' ) );
    }

    public function test_does_not_guide_when_the_free_key_is_revoked_with_a_pending_placeholder(): void {
        $this->assertFalse( $this->guide_for( 'cusk_Freekey_?', 'revoked' ) );
    }

    public function test_the_settings_view_sets_the_class_from_this_decision(): void {
        $markup = file_get_contents( dirname( __DIR__ ) . '/admin/views/settings-page.php' );

        $this->assertIsString( $markup );
        $this->assertStringContainsString(
            "needs_first_run_guide( new \\DrSpeedAIAS\\Settings() ) ? ' cu-first-run' : ''",
            $markup
        );
    }

    /**
     * Clicks must reach the page: a paid-key customer has to be able to use the field under the dim.
     */
    public function test_the_dim_never_blocks_the_page_and_the_segment_sits_above_it(): void {
        $css = file_get_contents( dirname( __DIR__ ) . '/admin/css/dr-speed-ai-assets-scanner-admin.css' );

        $this->assertIsString( $css );
        $this->assertMatchesRegularExpression( '/#drspeed-aias-settings\.cu-first-run::before \{[^}]*pointer-events: none;/', $css );
        $this->assertMatchesRegularExpression( '/#drspeed-aias-settings\.cu-first-run::before \{[^}]*z-index: 50;/', $css );
        $this->assertMatchesRegularExpression( '/#drspeed-aias-settings\.cu-first-run #cu-free-key-optin \{[^}]*z-index: 51;/', $css );
    }
}
