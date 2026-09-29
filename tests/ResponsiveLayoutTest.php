<?php
namespace DrSpeedAIAS\Tests;

use PHPUnit\Framework\TestCase;

/**
 * 1.9.4: no sideways scrollbar at any window width. These pin the causes found by
 * tools/responsive-audit.js; the audit itself needs a running WordPress.
 */
final class ResponsiveLayoutTest extends TestCase {

    private function css(): string {
        return (string) file_get_contents( dirname( __DIR__ ) . '/admin/css/dr-speed-ai-assets-scanner-admin.css' );
    }

    public function test_the_results_table_has_no_fixed_minimum_width(): void {
        // A fixed min-width forced a scrollbar whenever the results card was narrower.
        $this->assertDoesNotMatchRegularExpression( '/\.cu-url-table\s*\{[^}]*min-width:\s*[1-9]\d*px/', $this->css() );
    }

    public function test_narrow_cards_switch_tables_to_labelled_rows(): void {
        $css = $this->css();
        $this->assertStringContainsString( 'container: drspeed-aias-results / inline-size', $css );
        $this->assertMatchesRegularExpression( '/@container drspeed-aias-results \(max-width: \d+px\)/', $css );
        $this->assertStringContainsString( 'content: attr(data-label)', $css );
    }

    public function test_every_results_and_history_cell_is_labelled(): void {
        $root = dirname( __DIR__ );
        $js   = (string) file_get_contents( $root . '/admin/js/scanner.js' );
        preg_match( "/return '<tr class=\"cu-row-'.*?'<\\/td><\\/tr>';/s", $js, $row );
        $this->assertNotEmpty( $row, 'results row template not found' );
        $this->assertSame( 7, substr_count( $row[0], '<td' ), 'seven result columns' );
        $this->assertSame( 7, substr_count( $row[0], 'data-label="' ), 'every result cell carries a label' );

        $history = (string) file_get_contents( $root . '/admin/views/history-page.php' );
        $this->assertSame( 8, substr_count( $history, 'data-label="' ), 'every history cell carries a label' );
    }

    public function test_step4_action_buttons_wrap_instead_of_clipping(): void {
        $this->assertMatchesRegularExpression( '/\.cu-step4-action-row \.button \{[^}]*white-space: normal/', $this->css() );
    }

    public function test_results_headers_wrap_and_keep_the_help_marker_inline(): void {
        // Around 1100-1440px the "?" marker sat on the next column's text and "Extra Time ?" was clipped.
        $css = $this->css();
        $this->assertMatchesRegularExpression( '/\.cu-url-table thead th \{ height: auto; white-space: normal; \}/', $css );
        $this->assertMatchesRegularExpression( '/thead th \.cu-th-inner \.cu-help \{\s*position: static;/', $css );
    }

    public function test_narrow_cards_use_a_compact_grid_not_one_value_per_row(): void {
        $css = $this->css();
        $this->assertMatchesRegularExpression( '/\.cu-url-table tr \{\s*display: flex;\s*flex-wrap: wrap;/', $css );
        $this->assertStringContainsString( 'grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));', $css );
    }

    public function test_the_scan_summary_is_not_nudged_under_the_scan_id(): void {
        // `left: 10%` slid the summary under the Scan ID on narrower cards (operator report, 1226px).
        $this->assertDoesNotMatchRegularExpression( '/\.cu-completion-heading p \{[^}]*left:\s*\d+%/', $this->css() );
    }

    public function test_the_table_stays_a_table_down_to_its_measured_minimum(): void {
        // A 760px threshold made the layout flip table/cards several times as the window narrowed.
        $css = $this->css();
        $this->assertStringContainsString( '@container drspeed-aias-results (max-width: 600px) {', $css );
        $this->assertStringNotContainsString( '@container drspeed-aias-results (max-width: 760px)', $css );
        $this->assertStringContainsString( '@container drspeed-aias-results (min-width: 601px) and (max-width: 900px) {', $css );
    }

    public function test_the_results_sidebar_moves_below_by_available_width(): void {
        // Keyed to the window (1180px), the sidebar squeezed the table under its minimum
        // between 1181 and 1210px. It now depends on the width actually available.
        $css = $this->css();
        $this->assertStringContainsString( '#drspeed-aias-app #step-4 { container: drspeed-aias-step4 / inline-size; }', $css );
        $this->assertMatchesRegularExpression( '/@container drspeed-aias-step4 \(max-width: 887px\) \{\s*#drspeed-aias-app \.cu-results-shell \{ grid-template-columns: minmax\(0, 1fr\); \}/', $css );
    }

    public function test_the_balance_row_wraps_instead_of_overlapping(): void {
        $this->assertStringContainsString( '.cu-admin-page .cu-balance-widget { display: flex; flex-wrap: wrap;', $this->css() );
    }
}
