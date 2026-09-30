<?php
namespace DrSpeedAIAS\Tests;

use PHPUnit\Framework\TestCase;

/**
 * 1.9.5: support and reviews go through WordPress.org. Both scanner sidebars end with
 * the ratings and support boxes, and no screen links to the old wpservice.pro contact page.
 */
final class SupportLinksTest extends TestCase {

    private const REVIEWS = 'https://wordpress.org/support/plugin/dr-speed-ai-assets-scanner/reviews/#new-post';
    private const FORUM   = 'https://wordpress.org/support/plugin/dr-speed-ai-assets-scanner/';

    private function partial(): string {
        return (string) file_get_contents( dirname( __DIR__ ) . '/admin/views/partials/support-links.php' );
    }

    public function test_the_partial_links_to_the_wordpress_org_reviews_and_forum(): void {
        $src = $this->partial();
        $this->assertStringContainsString( 'href="' . self::REVIEWS . '"', $src );
        $this->assertStringContainsString( 'href="' . self::FORUM . '"', $src );
        $this->assertSame( 2, substr_count( $src, 'rel="noopener noreferrer"' ) );
        $this->assertStringContainsString( "str_repeat( \$drspeed_aias_star, 5 )", $src, 'five stars' );
        $this->assertStringContainsString( 'aria-label=', $src, 'the star row is announced, not read as five icons' );
    }

    public function test_the_step4_guidance_sidebar_ends_with_the_support_boxes(): void {
        $page = (string) file_get_contents( dirname( __DIR__ ) . '/admin/views/scanner-page.php' );
        // Once: the step 1 sidebar (.cu-admin-sidebar) has been display:none since the 1.9.1
        // redesign, so an include there would never render.
        $this->assertSame( 1, substr_count( $page, "include __DIR__ . '/partials/support-links.php';" ) );
        // After the Undo card, before the guidance aside closes.
        $this->assertMatchesRegularExpression( '#cu-guidance-card--undo.*?support-links\.php.*?</aside>#s', $page );
    }

    public function test_no_screen_links_to_the_old_contact_page(): void {
        foreach ( glob( dirname( __DIR__ ) . '/admin/views/*.php' ) as $view ) {
            $this->assertStringNotContainsString( 'wpservice.pro/contact', (string) file_get_contents( $view ), basename( $view ) );
            $this->assertStringNotContainsString( 'Found a bug', (string) file_get_contents( $view ), basename( $view ) );
        }
        $js = (string) file_get_contents( dirname( __DIR__ ) . '/admin/js/scanner.js' );
        $this->assertStringNotContainsString( 'wpservice.pro/contact', $js );
    }
}
