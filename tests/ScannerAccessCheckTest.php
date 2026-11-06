<?php
namespace DrSpeedAIAS\Tests;

use DrSpeedAIAS\Scanner\ScannerAccessCheck;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/** FU-SITE-DENIAL Part C — ScannerAccessCheck. Spec Rev 3.1 §3.2 + plan errata E2. */
class ScannerAccessCheckTest extends TestCase {
    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( $url, $component = -1 ) {
            return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
        } );
        WP_Mock::userFunction( 'wp_strip_all_tags' )->andReturnUsing( function ( $s ) {
            return trim( strip_tags( preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $s ) ) );
        } );
    }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    // ---- AC-16 (PHP side) + Review Focus 1 -------------------------------------------
    public function test_is_own_site_ac16_rows(): void {
        $home = 'https://example.com';
        $this->assertTrue( ScannerAccessCheck::is_own_site( 'https://www.example.com/x', $home ) );
        $this->assertFalse( ScannerAccessCheck::is_own_site( 'https://shop.example.com/x', $home ) );
        $this->assertFalse( ScannerAccessCheck::is_own_site( 'https://example.com:8443/x', $home ) );
        $this->assertTrue( ScannerAccessCheck::is_own_site( 'http://example.com/x', $home ), 'E2: default ports of each scheme are the same site' );
        $this->assertFalse( ScannerAccessCheck::is_own_site( 'https://example.net/x', $home ) );
    }

    /** E2: an explicit port equal to the URL's OWN scheme default is omitted (JS `URL.host`); any other port must match. */
    public function test_is_own_site_explicit_default_port_of_own_scheme(): void {
        $home = 'https://example.com';
        $this->assertTrue( ScannerAccessCheck::is_own_site( 'https://example.com:443/x', $home ) );
        $this->assertTrue( ScannerAccessCheck::is_own_site( 'http://example.com:80/x', $home ) );
        $this->assertFalse( ScannerAccessCheck::is_own_site( 'http://example.com:443/x', $home ), ':443 is not the http default' );
        $this->assertTrue( ScannerAccessCheck::is_own_site( 'https://example.com:8443/x', 'https://example.com:8443' ) );
    }

    public function test_is_own_site_case_and_www(): void {
        $this->assertTrue( ScannerAccessCheck::is_own_site( 'https://WWW.Example.COM/', 'https://example.com/' ) );
        $this->assertTrue( ScannerAccessCheck::is_own_site( 'https://example.com/', 'https://www.example.com' ) );
        $this->assertFalse( ScannerAccessCheck::is_own_site( 'https://www.www.example.com/', 'https://example.com' ), 'only ONE www. is stripped' );
    }

    public function test_credentials_allowed_rules(): void {
        $home = 'https://example.com';
        $this->assertTrue( ScannerAccessCheck::credentials_allowed( 'https://example.com/a', $home ) );
        $this->assertTrue( ScannerAccessCheck::credentials_allowed( 'https://www.example.com/a', $home ) );
        $this->assertFalse( ScannerAccessCheck::credentials_allowed( 'http://example.com/a', $home ), 'never over http when home is https' );
        $this->assertFalse( ScannerAccessCheck::credentials_allowed( 'https://shop.example.com/a', $home ) );
        $this->assertTrue( ScannerAccessCheck::credentials_allowed( 'http://example.com/a', 'http://example.com' ), 'http home, http URL: allowed' );
    }

    // ---- AC-7 helpers + Review Focus 2 ------------------------------------------------
    public function test_normalise_page_dedupes_fragment_and_empty_path(): void {
        $this->assertSame(
            ScannerAccessCheck::normalise_page( 'https://Example.com' ),
            ScannerAccessCheck::normalise_page( 'https://example.com/#top' )
        );
        $this->assertNotSame(
            ScannerAccessCheck::normalise_page( 'https://example.com/?p=1' ),
            ScannerAccessCheck::normalise_page( 'https://example.com/' )
        );
    }

    public function test_root_of_keeps_host_as_written_and_port(): void {
        $this->assertSame( 'https://www.example.com/', ScannerAccessCheck::root_of( 'https://www.example.com/shop/item' ) );
        $this->assertSame( 'http://example.com:8080/', ScannerAccessCheck::root_of( 'http://example.com:8080/a' ) );
    }

    // ---- AC-11 (PHP side) + Review Focus 3 --------------------------------------------
    public function test_extract_reference_finds_reference_id(): void {
        $body = '<html><body><h1>Request Blocked</h1><p>Reference ID WAF-425</p><p>If you believe…</p></body></html>';
        $this->assertSame( [ 'label' => 'Reference ID', 'value' => 'WAF-425' ], ScannerAccessCheck::extract_reference( $body ) );
    }

    /** PF1: tag stripping inserts no space between elements; a minified block page must still yield its code. */
    public function test_extract_reference_survives_minified_html_without_whitespace_between_tags(): void {
        $body = '<h1>Request Blocked</h1><p>Reference ID WAF-425</p>';
        $this->assertSame( [ 'label' => 'Reference ID', 'value' => 'WAF-425' ], ScannerAccessCheck::extract_reference( $body ) );
    }

    /** PF11: invalid UTF-8 (a Latin-1 page, or a multibyte char split by the 32 KB cut) must not lose the reference. */
    public function test_extract_reference_survives_invalid_utf8_bytes(): void {
        $latin1 = "<p>Acc\xE8s refus\xE9</p><p>Reference ID WAF-425</p>";
        $this->assertSame( [ 'label' => 'Reference ID', 'value' => 'WAF-425' ], ScannerAccessCheck::extract_reference( $latin1 ) );
        $split = "<p>caf\xC3</p> <p>Reference ID WAF-425</p>";
        $this->assertSame( [ 'label' => 'Reference ID', 'value' => 'WAF-425' ], ScannerAccessCheck::extract_reference( $split ) );
    }

    /** PF18 / E10: a candidate value must contain a digit, so prose "reference" never becomes the code. */
    public function test_extract_reference_skips_prose_reference_before_real_code(): void {
        $r = ScannerAccessCheck::extract_reference( '<p>Please quote this reference when contacting support. Reference ID: WAF-425</p>' );
        $this->assertSame( [ 'label' => 'Reference ID', 'value' => 'WAF-425' ], $r );
    }

    public function test_extract_reference_prose_only_returns_null(): void {
        $this->assertNull( ScannerAccessCheck::extract_reference( '<p>Your reference number is shown below.</p>' ) );
    }

    public function test_extract_reference_plural_ids_prose_returns_null(): void {
        $this->assertNull( ScannerAccessCheck::extract_reference( '<p>Reference IDs are logged.</p>' ) );
    }

    public function test_extract_reference_normalises_label(): void {
        $r = ScannerAccessCheck::extract_reference( '<p>support   id: ABC123.x</p>' );
        $this->assertSame( [ 'label' => 'Support ID', 'value' => 'ABC123.x' ], $r );
    }

    public function test_extract_reference_rejects_hostile_or_long_tokens(): void {
        $this->assertNull( ScannerAccessCheck::extract_reference( '<p>Reference ID "><script>x</script></p>' ) );
        $this->assertNull( ScannerAccessCheck::extract_reference( '<p>Reference ID ' . str_repeat( 'A', 200 ) . '</p>' ) );
        $this->assertNull( ScannerAccessCheck::extract_reference( '<script>var t="Reference ID JS-1";</script>' ) );
    }

    public function test_extract_reference_ignores_text_after_32kb(): void {
        $body = str_repeat( 'x', 40000 ) . ' Reference ID LATE-1';
        $this->assertNull( ScannerAccessCheck::extract_reference( $body ) );
        $this->assertNull( ScannerAccessCheck::extract_reference( "\x00\xff\xfe" . str_repeat( "\x01", 10 ) ) );
    }

    public function test_user_agents(): void {
        $this->assertSame(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36 (compatible; CU-Scanner/1.0; +https://wpservice.pro/bot)',
            ScannerAccessCheck::scanner_user_agent()
        );
        $this->assertSame(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36',
            ScannerAccessCheck::control_user_agent()
        );
        $this->assertStringNotContainsString( 'CU-Scanner', ScannerAccessCheck::control_user_agent() );
    }
}
