<?php
/**
 * "Verified by Dr. Speed Lab" badge, shared by the scanner, settings and history
 * headers so the three cannot drift apart. Plain text with no link for now.
 * Meaning of the claim: BRANDING.md.
 *
 * @package DrSpeedAIAS
 */

defined( 'ABSPATH' ) || exit;
?>
<span class="cu-header-verified">
    <span class="cu-header-verified-text"><?php
        /* translators: %s: the name "Dr. Speed Lab", which stays in English. */
        printf( esc_html__( 'Verified by %s', 'dr-speed-ai-assets-scanner' ), '<span class="cu-header-verified-name">Dr. Speed Lab</span>' );
    ?></span>
    <svg class="cu-header-verified-icon" viewBox="4 4 32 44" width="28" height="39" aria-hidden="true" focusable="false" fill="none" stroke="#22c55e" stroke-linecap="round" stroke-linejoin="round">
        <path d="M25.24 13.02A14 14 0 0 1 25.24 38.98M14.76 38.98A14 14 0 0 1 14.76 13.02" stroke-width="2"/>
        <path transform="translate(13 19) scale(.7)" d="M7 1.5h6v5.5h5.5v6H13v5.5H7V13H1.5V7H7z" fill="#22c55e" stroke="none"/>
        <g stroke-width="1.3">
            <rect x="16.1" y="5.2" width="7.8" height="5.1" rx=".8"/>
            <path d="M20 10.3v1.4M18.2 11.7h3.6"/>
            <rect x="17.84" y="39.1" width="4.32" height="7.8" rx=".9"/>
            <path d="M19.6 45.1h.8"/>
        </g>
    </svg>
</span>
