<?php
/**
 * Ratings and support boxes, at the bottom of the scanner sidebars. Both link to
 * the plugin's WordPress.org pages: reviews and the support forum. The caller sets
 * $drspeed_aias_support_card_class to the sidebar's own card class so the boxes
 * take that sidebar's spacing and borders.
 *
 * @package DrSpeedAIAS
 */

defined( 'ABSPATH' ) || exit;

$drspeed_aias_support_card_class = isset( $drspeed_aias_support_card_class ) ? (string) $drspeed_aias_support_card_class : '';
$drspeed_aias_star               = '<svg class="cu-support-star" viewBox="0 0 20 20" width="18" height="18" aria-hidden="true" focusable="false"><path d="M10 1.6l2.6 5.4 5.9.8-4.3 4.1 1.1 5.9L10 15l-5.3 2.8 1.1-5.9L1.5 7.8l5.9-.8z" fill="currentColor"/></svg>';
?>
<div class="<?php echo esc_attr( trim( $drspeed_aias_support_card_class . ' cu-support-card cu-support-card--rating' ) ); ?>">
    <h3 class="cu-support-heading"><?php esc_html_e( 'Ratings &amp; Reviews', 'dr-speed-ai-assets-scanner' ); ?></h3>
    <p class="cu-support-text">
        <?php esc_html_e( 'If you like AI Assets Scanner, please consider leaving a', 'dr-speed-ai-assets-scanner' ); ?>
        <span class="cu-support-stars" role="img" aria-label="<?php esc_attr_e( '5 star', 'dr-speed-ai-assets-scanner' ); ?>"><?php
            // Static markup, five copies; nothing here comes from the request.
            echo str_repeat( $drspeed_aias_star, 5 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        ?></span>
        <?php esc_html_e( 'rating.', 'dr-speed-ai-assets-scanner' ); ?>
    </p>
    <a href="https://wordpress.org/support/plugin/dr-speed-ai-assets-scanner/reviews/#new-post" target="_blank" rel="noopener noreferrer" class="button button-primary cu-support-btn"><?php esc_html_e( 'Leave a rating', 'dr-speed-ai-assets-scanner' ); ?></a>
</div>
<div class="<?php echo esc_attr( trim( $drspeed_aias_support_card_class . ' cu-support-card cu-support-card--issues' ) ); ?>">
    <h3 class="cu-support-heading"><?php esc_html_e( 'Having issues?', 'dr-speed-ai-assets-scanner' ); ?></h3>
    <p class="cu-support-text"><?php esc_html_e( 'I\'m always happy to help out! Support is handled exclusively through WordPress.org.', 'dr-speed-ai-assets-scanner' ); ?></p>
    <a href="https://wordpress.org/support/plugin/dr-speed-ai-assets-scanner/" target="_blank" rel="noopener noreferrer" class="button button-primary cu-support-btn"><?php esc_html_e( 'Get Support', 'dr-speed-ai-assets-scanner' ); ?></a>
</div>
