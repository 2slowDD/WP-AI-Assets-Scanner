<?php
/**
 * Dr. Speed brand lockup + tagline, shared by the scanner, settings and history
 * screens so the three headers cannot drift apart. Brand rules: BRANDING.md.
 *
 * The mark (cross + "Dr. Speed") and the product line (name + version) are each
 * kept on one line, so narrow screens break between them, never inside them.
 *
 * @package DrSpeedAIAS
 */

defined( 'ABSPATH' ) || exit;
?>
<h2 class="cu-brand-title">
    <span class="cu-brand-mark"><svg class="cu-brand-cross" viewBox="0 0 20 20" width="20" height="20" aria-hidden="true" focusable="false"><path d="M7 1.5h6v5.5h5.5v6H13v5.5H7V13H1.5V7H7z" fill="currentColor"/></svg><span class="cu-brand-name">Dr. Speed</span></span><span class="cu-brand-sep" aria-hidden="true"></span><span class="screen-reader-text">: </span><span class="cu-brand-line"><span class="cu-brand-product">AI Assets Scanner</span><small class="cu-header-version">v<?php echo esc_html( DRSPEED_AIAS_VERSION ); ?></small></span>
</h2>
<p class="cu-header-tagline"><?php esc_html_e( 'Safely debloat your pages with one push of a button.', 'dr-speed-ai-assets-scanner' ); ?></p>
