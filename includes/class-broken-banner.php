<?php
defined( 'ABSPATH' ) || exit;

/**
 * DRSPEED_AIAS_Broken_Banner — dismissable admin notice for pages blocked during a scan.
 *
 * Render surface: the scan-results (Step 4) JS calls the AJAX dismiss endpoint;
 * the banner HTML itself is built in scanner.js using data from build_result.
 * This class owns: dismissal storage, the AJAX dismiss handler, and the submit-job
 * wipe hook.  The render() method is also provided for unit tests and any future
 * PHP-rendered surface.
 */
class DRSPEED_AIAS_Broken_Banner {

	const OPTION_DISMISSALS = 'drspeed_aias_dismissed_warnings';

	/**
	 * T0-C (2026-08-02) — the worker's `attribution` enum, mirrored from
	 * cu-scanner-railway `classifyThrottleAttribution()`.
	 *
	 * UNTRUSTED INPUT: this value arrives in the Railway status response, i.e. a
	 * third-party API response. It is validated against this allowlist and then used
	 * ONLY to select one of the hardcoded translatable strings below — it is never
	 * interpolated into markup, echoed, or passed to innerHTML. Validate-don't-sanitize
	 * is correct here because the value space is closed.
	 *
	 * Mirrored in admin/js/scanner.js as ATTR_ALLOWED — keep the two in sync.
	 */
	const ATTRIBUTION_ALLOWED = [ 'cloudflare', 'akamai', 'imperva', 'waf', 'host', 'unknown' ];

	/**
	 * Collapse any incoming attribution value to a known member of the enum.
	 *
	 * Anything unrecognised — an absent field on a stale page, a future worker enum
	 * member, or a hostile string — degrades to 'unknown', which is the widest and
	 * most hedged copy branch. No input can produce an empty clause.
	 *
	 * @param mixed $raw Raw value from the scan payload.
	 */
	public static function normalize_attribution( $raw ): string {
		return ( is_string( $raw ) && in_array( $raw, self::ATTRIBUTION_ALLOWED, true ) )
			? $raw
			: 'unknown';
	}

	/**
	 * Returns HTML for the admin notice, or '' if nothing to show.
	 *
	 * @param array{
	 *   scan_id: string,
	 *   pages_blocked: array{desktop: int, mobile: int},
	 *   blocked_reasons: array<string, int>,
	 *   total_pages?: int
	 * } $payload
	 */
	public static function render( array $payload ): string {
		$scan_id     = $payload['scan_id'] ?? '';
		$blocked_d   = (int) ( $payload['pages_blocked']['desktop'] ?? 0 );
		$blocked_m   = (int) ( $payload['pages_blocked']['mobile']  ?? 0 );
		$reasons     = $payload['blocked_reasons'] ?? [];
		$total_pages = (int) ( $payload['total_pages'] ?? 0 );

		if ( $blocked_d + $blocked_m === 0 ) {
			return '';
		}

		$dismissed = get_option( self::OPTION_DISMISSALS, [] );
		if ( is_array( $dismissed ) && ! empty( $dismissed[ $scan_id ] ) ) {
			return '';
		}

		// T0-C: who rate-limited the scan (worker-supplied, allowlisted). Selects copy only.
		$attribution = self::normalize_attribution( $payload['rate_limit_attribution'] ?? null );

		$copy = self::reason_copy( $reasons, $blocked_d, $blocked_m, $total_pages, $attribution );

		ob_start();
		?>
		<div class="notice notice-warning drspeed-aias-broken-banner" data-scan-id="<?php echo esc_attr( $scan_id ); ?>">
			<p><strong>&#9888; <?php echo esc_html__( 'Some pages couldn\'t be fully scanned', 'dr-speed-ai-assets-scanner' ); ?></strong></p>
			<p><?php echo wp_kses_post( $copy ); ?></p>
			<p>
				<button type="button" class="button drspeed-aias-dismiss-banner">
					<?php esc_html_e( 'Got it — don\'t show again for this scan', 'dr-speed-ai-assets-scanner' ); ?>
				</button>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Builds the human-readable copy for the banner body.
	 *
	 * @param array<string, int> $reasons
	 */
	private static function reason_copy(
		array $reasons,
		int $blocked_d,
		int $blocked_m,
		int $total_pages,
		string $attribution = 'unknown'
	): string {
		$bits = [];
		if ( $blocked_d > 0 ) {
			$bits[] = sprintf(
				/* translators: 1: blocked page count, 2: total page count */
				esc_html__( 'Desktop scanner blocked on %1$d of %2$d pages.', 'dr-speed-ai-assets-scanner' ),
				$blocked_d,
				$total_pages
			);
		}
		if ( $blocked_m > 0 ) {
			$bits[] = sprintf(
				/* translators: 1: blocked page count, 2: total page count */
				esc_html__( 'Mobile scanner blocked on %1$d of %2$d pages.', 'dr-speed-ai-assets-scanner' ),
				$blocked_m,
				$total_pages
			);
		}

		$reason_phrases = [];
		foreach ( $reasons as $key => $count ) {
			$reason_phrases[] = self::reason_phrase( (string) $key );
		}
		$reason_clause = $reason_phrases
			? sprintf( ' (%s)', implode( ', ', array_unique( $reason_phrases ) ) )
			: '';

		$action_clause = self::action_clause( $reasons, $attribution );

		return implode( ' ', $bits ) . $reason_clause . ' ' . $action_clause;
	}

	/**
	 * Maps a blocked-reason key to a remediation category.
	 * 'rate'  → operator should space scans / raise rate limits.
	 * 'error' → server-side issue, retry later / check site health.
	 * 'bot'   → bot-protection or asymmetric stub; default for tier2_* + tier1_zero_bytes + unknown.
	 */
	public static function reason_category( string $reason ): string {
		switch ( $reason ) {
			case 'tier1_http_rate_limit':
				return 'rate';
			case 'tier1_http_4xx':
			case 'tier1_http_5xx':
			case 'tier1_transport_error':
				return 'error';
			default:
				return 'bot';
		}
	}

	/**
	 * Builds the per-reason action clause for the banner body.
	 *
	 * If all reasons map to the same remediation category, returns that
	 * category's clause. Mixed-category reasons fall back to the generic
	 * bot-protection clause to avoid misleading single-cause guidance.
	 *
	 * @param array<string, int> $reasons
	 */
	private static function action_clause( array $reasons, string $attribution = 'unknown' ): string {
		$categories = [];
		foreach ( $reasons as $key => $count ) {
			$categories[] = self::reason_category( (string) $key );
		}
		$categories = array_values( array_unique( $categories ) );

		if ( count( $categories ) === 1 ) {
			if ( $categories[0] === 'rate' ) {
				// T0-C: name the party that actually rate-limited the scan. $attribution is
				// already allowlisted by normalize_attribution() — it only picks a branch here.
				$settings_url = esc_url( admin_url( 'admin.php?page=drspeed-aias-settings#cu-cloudflare-waf-bypass' ) );
				$tail         = esc_html__( 'The rules from the unblocked device (if any) are complete and safe to apply.', 'dr-speed-ai-assets-scanner' );

				if ( 'cloudflare' === $attribution ) {
					// Deliberately does NOT say "not your server": aggregate_rate_limit_attribution()
					// ranks cloudflare above host, so a mixed scan resolves here — denying the origin
					// would be positively false for the origin-limited pages.
					return esc_html__( 'Cloudflare rate-limited the scan. Whoever manages your Cloudflare — you, your host, or your agency — needs to allowlist the scanner.', 'dr-speed-ai-assets-scanner' )
						. ' ' . wp_kses_post( sprintf(
							/* translators: %s: URL to AI Assets Scanner settings WAF bypass section */
							__( 'If you manage the Cloudflare account yourself, set up the one-time scanner exemption — <a href="%s">open AI Assets Scanner settings</a>.', 'dr-speed-ai-assets-scanner' ),
							$settings_url
						) ) . ' ' . $tail;
				}

				if ( 'host' === $attribution ) {
					// No settings link by design. The "will not help here" sentence is LOAD-BEARING:
					// without it a user who already has a working CDN exemption concludes it is
					// broken and re-does it. Do not trim it for length.
					return esc_html__( "Your host's server rate-limited the scan. A CDN or WAF exemption will not help here. Wait a few minutes between scans, or ask your host to raise the rate limit during scans.", 'dr-speed-ai-assets-scanner' )
						. ' ' . $tail;
				}

				return esc_html__( 'The scan was rate-limited. Wait a few minutes between scans.', 'dr-speed-ai-assets-scanner' )
					. ' ' . wp_kses_post( sprintf(
						/* translators: %s: URL to AI Assets Scanner settings WAF bypass section */
						__( 'If a CDN or WAF sits in front of your site, ask whoever manages it to allowlist the scanner; otherwise check your own server\'s rate limits — <a href="%s">open AI Assets Scanner settings</a>.', 'dr-speed-ai-assets-scanner' ),
						$settings_url
					) ) . ' ' . $tail;
			}
			if ( $categories[0] === 'error' ) {
				return esc_html__(
					'Your server returned an error or didn\'t respond. The rules from the unblocked device (if any) are complete and safe to apply. Try again later, or check site health.',
					'dr-speed-ai-assets-scanner'
				);
			}
		}

		$base = esc_html__(
			'Your bot protection denied the scanner. The rules from the unblocked device are complete and safe to apply. For full coverage, temporarily disable bot protection during scans.',
			'dr-speed-ai-assets-scanner'
		);
		$settings_url = esc_url( admin_url( 'admin.php?page=drspeed-aias-settings#cu-cloudflare-waf-bypass' ) );
		if ( in_array( 'rate', $categories, true ) ) {
			// Mixed-with-rate: the rate-specific sentence is the single settings
			// link — the generic bot one is gated off to avoid duplicate copy.
			$base .= ' ' . wp_kses_post( sprintf(
				/* translators: %s: URL to AI Assets Scanner settings WAF bypass section */
				__( 'Behind Cloudflare or another CDN? Set up the scanner rate-limit exemption so future scans aren\'t throttled — <a href="%s">open AI Assets Scanner settings</a>.', 'dr-speed-ai-assets-scanner' ),
				$settings_url
			) );
		} else {
			$base .= ' ' . wp_kses_post( sprintf(
				/* translators: %s: URL to AI Assets Scanner settings WAF bypass section */
				__( 'Behind Cloudflare or another CDN? Set up the scanner exemption so future scans aren\'t blocked — <a href="%s">open AI Assets Scanner settings</a>.', 'dr-speed-ai-assets-scanner' ),
				$settings_url
			) );
		}
		return $base;
	}

	/**
	 * Returns a human-readable phrase for a known blocked-reason key.
	 * Unknown keys fall through to esc_html() of the raw key.
	 *
	 * Mirrored by wpservice-saas/admin/class-admin-jobs.php reason_label() — keep the 12-key copy in sync when the enum grows.
	 */
	public static function reason_phrase( string $reason ): string {
		switch ( $reason ) {
			case 'tier2_cf_challenge':
				return esc_html__( 'Cloudflare challenge', 'dr-speed-ai-assets-scanner' );
			case 'tier2_akamai_challenge':
				return esc_html__( 'Akamai Bot Manager', 'dr-speed-ai-assets-scanner' );
			case 'tier2_imperva_challenge':
				return esc_html__( 'Imperva WAF', 'dr-speed-ai-assets-scanner' );
			case 'tier2_waf_challenge':
				return esc_html__( 'firewall/WAF', 'dr-speed-ai-assets-scanner' );
			case 'tier2_unknown_challenge':
				return esc_html__( 'bot/firewall protection (unidentified)', 'dr-speed-ai-assets-scanner' );
			case 'tier2_rocket_loader_stub':
				return esc_html__( 'Cloudflare Rocket-Loader stub', 'dr-speed-ai-assets-scanner' );
			case 'tier2_small_body':
				return esc_html__( 'asymmetric stub response', 'dr-speed-ai-assets-scanner' );
			case 'tier1_zero_bytes':
				return esc_html__( 'empty response', 'dr-speed-ai-assets-scanner' );
			case 'tier1_http_4xx':
				return esc_html__( 'site denial (4xx)', 'dr-speed-ai-assets-scanner' );
			case 'tier1_http_5xx':
				return esc_html__( 'site error (5xx)', 'dr-speed-ai-assets-scanner' );
			case 'tier1_http_rate_limit':
				return esc_html__( 'rate limit (429)', 'dr-speed-ai-assets-scanner' );
			case 'tier1_transport_error':
				return esc_html__( 'unreachable', 'dr-speed-ai-assets-scanner' );
			default:
				return esc_html( $reason );
		}
	}

	/**
	 * Phrases for security stacks the worker names on a blocked entry (spec §3.3.3).
	 * Keys are members of DRSPEED_AIAS_Scan_Status::STACKS; a stack with no phrase
	 * here keeps its entry's reason phrase. Plain __() text — exported verbatim by
	 * export_copy_map()['stack_phrases'] and escaped by entry_phrase().
	 *
	 * @return array<string,string>
	 */
	private static function stack_phrases(): array {
		return [
			'siteground' => __( 'SiteGround bot protection', 'dr-speed-ai-assets-scanner' ),
		];
	}

	/**
	 * Human-readable phrase for ONE blocked entry: the stack's phrase when the
	 * (allowlisted) stack has one, else the reason phrase. Same contract as
	 * reason_phrase(): the return value is HTML-escaped.
	 *
	 * UNTRUSTED INPUT: $entry is a broken_devices entry from the Railway response.
	 * `stack` passes the Scan_Status::stack_of() allowlist and only selects a
	 * hardcoded string; `reason` goes through reason_phrase(), which escapes
	 * unknown keys. A non-string reason degrades to ''.
	 *
	 * @param array $entry One broken_devices entry: { device, reason, stack? }.
	 */
	public static function entry_phrase( array $entry ): string {
		$stack_phrases = self::stack_phrases();
		$stack         = DRSPEED_AIAS_Scan_Status::stack_of( $entry );
		if ( isset( $stack_phrases[ $stack ] ) ) {
			return esc_html( $stack_phrases[ $stack ] );
		}
		$reason = $entry['reason'] ?? '';
		return self::reason_phrase( is_string( $reason ) ? $reason : '' );
	}

	/**
	 * FU-ANTIBLOCK-1 — one actionable remediation line per blocked-reason key.
	 * PLAIN TEXT ONLY (spec Rev 2 §3.1 / d-review M1): no HTML here — settings
	 * links travel separately via export_copy_map()['settings_url'] and are
	 * DOM-built by scanner.js / escaped by PHP render sites.
	 * Unknown keys fall back to the reason's category clause (plain-text form).
	 */
	public static function reason_remediation( string $reason ): string {
		switch ( $reason ) {
			case 'tier2_cf_challenge':
				return __( 'Cloudflare bot protection challenged the scanner. Set up the one-time WAF exemption in AI Assets Scanner settings. Free Cloudflare plan: also turn Bot Fight Mode off during scans (a Skip rule cannot bypass it). Alternative rule key for admins: skip on query strings containing cu_scan_token.', 'dr-speed-ai-assets-scanner' );
			case 'tier2_rocket_loader_stub':
				return __( 'Cloudflare Rocket Loader served a stub page to the scanner. The WAF exemption rule in AI Assets Scanner settings also skips this.', 'dr-speed-ai-assets-scanner' );
			case 'tier1_http_rate_limit':
				return __( 'Your server rate-limited the scanner. Wait a few minutes between scans, or temporarily raise rate limits during scans. Behind Cloudflare or another CDN: set up the scanner exemption in AI Assets Scanner settings.', 'dr-speed-ai-assets-scanner' );
			case 'tier2_akamai_challenge':
				return __( 'Akamai Bot Manager blocked the scan. Ask your CDN admin to allowlist requests carrying the x-cu-scanner header (value: your Scanner Secret, shown in AI Assets Scanner settings).', 'dr-speed-ai-assets-scanner' );
			case 'tier2_imperva_challenge':
				return __( 'Imperva/Incapsula blocked the scan. Ask your security admin to allowlist requests carrying the x-cu-scanner header (value: your Scanner Secret, shown in AI Assets Scanner settings).', 'dr-speed-ai-assets-scanner' );
			case 'tier2_waf_challenge':
				return __( 'A firewall/WAF challenged the scanner. Wordfence: enable Learning Mode during scans, or allowlist the scanner. Behind a CDN: set up the exemption in AI Assets Scanner settings.', 'dr-speed-ai-assets-scanner' );
			case 'tier2_unknown_challenge':
				return __( 'Unidentified bot protection denied the scanner. Try the CDN exemption in AI Assets Scanner settings, or temporarily disable bot protection during scans.', 'dr-speed-ai-assets-scanner' );
			case 'tier2_small_body':
				return __( 'The site served a reduced page to the scanner (likely bot protection). Set up the CDN exemption in AI Assets Scanner settings, or temporarily disable bot protection during scans.', 'dr-speed-ai-assets-scanner' );
			case 'tier1_zero_bytes':
				return __( 'Empty response. Check your host/WAF security logs for blocked requests, then re-scan.', 'dr-speed-ai-assets-scanner' );
			case 'tier1_http_4xx':
				return __( 'The site denied the request. Check security-plugin blocklists or IP bans — allowlist by the x-cu-scanner header, not by IP (scanner IPs change).', 'dr-speed-ai-assets-scanner' );
			case 'tier1_http_5xx':
				return __( 'The server errored. Re-scan later; if persistent, check site health and host status.', 'dr-speed-ai-assets-scanner' );
			case 'tier1_transport_error':
				return __( 'The site was unreachable (DNS/TLS/timeout). Verify the URL loads from outside your network, then re-scan.', 'dr-speed-ai-assets-scanner' );
			default:
				// Category-clause fallback, plain-text (mirrors action_clause() semantics without HTML).
				$cat = self::reason_category( $reason );
				if ( 'rate' === $cat ) {
					return __( 'Your server rate-limited the scanner. Wait a few minutes between scans, or temporarily raise rate limits during scans.', 'dr-speed-ai-assets-scanner' );
				}
				if ( 'error' === $cat ) {
					return __( 'Your server returned an error or did not respond. Try again later, or check site health.', 'dr-speed-ai-assets-scanner' );
				}
				return __( 'Your bot protection denied the scanner. For full coverage, set up the CDN exemption in AI Assets Scanner settings or temporarily disable bot protection during scans.', 'dr-speed-ai-assets-scanner' );
		}
	}

	/**
	 * FU-ANTIBLOCK-1 — single-source copy map for wp_localize_script (spec §3.1).
	 * Everything here is plugin-authored plain text (unescaped __()), zero HTML,
	 * zero secrets. scanner.js DOM-builds the settings anchor from settings_url
	 * for the keys in settings_link_keys.
	 *
	 * Errored-page copy spec §3.3.4: this map is the ONE source of every banner
	 * sentence — heading, dismiss, device words, blocked sentences, action
	 * clauses, survivor tail, errored sentences, partial-row line 1, stack
	 * phrases. scanner.js › renderBrokenBanner() and the PHP render() twin
	 * assemble from it. The link sentences (action.*_link*) carry NO markup: the
	 * trailing words "open AI Assets Scanner settings" are the anchor text, and
	 * each consumer wraps that phrase in an <a href=settings_url> it builds itself.
	 * Translations are third-party text: consumers escape every value before an
	 * HTML sink.
	 *
	 * Serialised shape: errored[kind] is a 2-element LIST [singular, plural]
	 * (a JSON array); settings_link_keys is a list of reason keys (a JSON array);
	 * every other sub-map is an associative array (a JSON object).
	 *
	 * Plural mechanism (spec §3.3.2 (a)): each errored kind exports
	 * _n( s, p, 1 ) and _n( s, p, 2 ); consumers pick [0] iff count === 1. The
	 * literal pair is written inline at both calls so gettext extraction
	 * (wp i18n make-pot) and the WPCS I18n sniff see literals — a wrapper taking
	 * the strings as variables would leave them out of the POT. Known limitation:
	 * locales with three or more plural forms get this two-form approximation.
	 */
	public static function export_copy_map(): array {
		$keys = [
			'tier2_cf_challenge', 'tier2_akamai_challenge', 'tier2_imperva_challenge',
			'tier2_waf_challenge', 'tier2_unknown_challenge', 'tier2_rocket_loader_stub',
			'tier2_small_body', 'tier1_zero_bytes', 'tier1_http_4xx', 'tier1_http_5xx',
			'tier1_http_rate_limit', 'tier1_transport_error',
		];
		$phrases = [
			'tier2_cf_challenge'      => __( 'Cloudflare challenge', 'dr-speed-ai-assets-scanner' ),
			'tier2_akamai_challenge'  => __( 'Akamai Bot Manager', 'dr-speed-ai-assets-scanner' ),
			'tier2_imperva_challenge' => __( 'Imperva WAF', 'dr-speed-ai-assets-scanner' ),
			'tier2_waf_challenge'     => __( 'firewall/WAF', 'dr-speed-ai-assets-scanner' ),
			'tier2_unknown_challenge' => __( 'bot/firewall protection (unidentified)', 'dr-speed-ai-assets-scanner' ),
			'tier2_rocket_loader_stub' => __( 'Cloudflare Rocket-Loader stub', 'dr-speed-ai-assets-scanner' ),
			'tier2_small_body'        => __( 'asymmetric stub response', 'dr-speed-ai-assets-scanner' ),
			'tier1_zero_bytes'        => __( 'empty response', 'dr-speed-ai-assets-scanner' ),
			'tier1_http_4xx'          => __( 'site denial (4xx)', 'dr-speed-ai-assets-scanner' ),
			'tier1_http_5xx'          => __( 'site error (5xx)', 'dr-speed-ai-assets-scanner' ),
			'tier1_http_rate_limit'   => __( 'rate limit (429)', 'dr-speed-ai-assets-scanner' ),
			'tier1_transport_error'   => __( 'unreachable', 'dr-speed-ai-assets-scanner' ),
		];
		$remediation = [];
		$categories  = [];
		foreach ( $keys as $k ) {
			$remediation[ $k ] = self::reason_remediation( $k );
			$categories[ $k ]  = self::reason_category( $k );
		}
		return [
			'phrases'            => $phrases,
			'remediation'        => $remediation,
			'categories'         => $categories,
			'settings_url'       => esc_url( admin_url( 'admin.php?page=drspeed-aias-settings#cu-cloudflare-waf-bypass' ) ),
			'settings_link_keys' => [ 'tier2_cf_challenge', 'tier2_rocket_loader_stub', 'tier1_http_rate_limit', 'tier2_waf_challenge', 'tier2_unknown_challenge' ],
			'heading'            => __( 'Some pages couldn\'t be fully scanned', 'dr-speed-ai-assets-scanner' ),
			'dismiss'            => __( 'Got it — don\'t show again for this scan', 'dr-speed-ai-assets-scanner' ),
			'device'             => [
				'Desktop' => __( 'Desktop', 'dr-speed-ai-assets-scanner' ),
				'Mobile'  => __( 'Mobile', 'dr-speed-ai-assets-scanner' ),
				'desktop' => __( 'desktop', 'dr-speed-ai-assets-scanner' ),
				'mobile'  => __( 'mobile', 'dr-speed-ai-assets-scanner' ),
			],
			'blocked'            => [
				/* translators: 1: blocked page count, 2: total page count */
				'desktop' => __( 'Desktop scanner blocked on %1$d of %2$d pages.', 'dr-speed-ai-assets-scanner' ),
				/* translators: 1: blocked page count, 2: total page count */
				'mobile'  => __( 'Mobile scanner blocked on %1$d of %2$d pages.', 'dr-speed-ai-assets-scanner' ),
			],
			'action'             => [
				/* translators: %1$s: device word (desktop/mobile), used twice */
				'bot_one'           => __( 'Your bot protection denied the %1$s scanner. For %1$s rules too, temporarily disable bot protection during scans and re-scan.', 'dr-speed-ai-assets-scanner' ),
				'bot_both'          => __( 'Your bot protection denied the scanner. For full coverage, temporarily disable bot protection during scans and re-scan.', 'dr-speed-ai-assets-scanner' ),
				'bot_link'          => __( 'Behind Cloudflare or another CDN? Set up the scanner exemption so future scans aren\'t blocked — open AI Assets Scanner settings.', 'dr-speed-ai-assets-scanner' ),
				'bot_link_rate'     => __( 'Behind Cloudflare or another CDN? Set up the scanner rate-limit exemption so future scans aren\'t throttled — open AI Assets Scanner settings.', 'dr-speed-ai-assets-scanner' ),
				'error'             => __( 'Your server returned an error or didn\'t respond. Try again later, or check site health.', 'dr-speed-ai-assets-scanner' ),
				'rate_cf'           => __( 'Cloudflare rate-limited the scan. Whoever manages your Cloudflare — you, your host, or your agency — needs to allowlist the scanner.', 'dr-speed-ai-assets-scanner' ),
				'rate_cf_link'      => __( 'If you manage the Cloudflare account yourself, set up the one-time scanner exemption — open AI Assets Scanner settings.', 'dr-speed-ai-assets-scanner' ),
				'rate_host'         => __( "Your host's server rate-limited the scan. A CDN or WAF exemption will not help here. Wait a few minutes between scans, or ask your host to raise the rate limit during scans.", 'dr-speed-ai-assets-scanner' ),
				'rate_unknown'      => __( 'The scan was rate-limited. Wait a few minutes between scans.', 'dr-speed-ai-assets-scanner' ),
				'rate_unknown_link' => __( 'If a CDN or WAF sits in front of your site, ask whoever manages it to allowlist the scanner; otherwise check your own server\'s rate limits — open AI Assets Scanner settings.', 'dr-speed-ai-assets-scanner' ),
				'siteground'        => __( 'SiteGround\'s bot protection denied the scanner. Turn off the anti-bot protection in SiteGround Site Tools while the scan runs, or ask SiteGround support to allow the scanner, then re-scan.', 'dr-speed-ai-assets-scanner' ),
			],
			'tail'               => [
				'push'     => __( 'Rules from the device that got through are ready to Push or Sync.', 'dr-speed-ai-assets-scanner' ),
				'download' => __( 'Rules from the device that got through are ready to download.', 'dr-speed-ai-assets-scanner' ),
			],
			// Each kind: [ singular, plural ] — see "Plural mechanism" above.
			'errored'            => [
				'page_time_limit' => [
					/* translators: %d: page count */
					_n( '%d page hit the scanner\'s time limit and wasn\'t finished. Re-scan it later.', '%d pages hit the scanner\'s time limit and weren\'t finished. Re-scan them later.', 1, 'dr-speed-ai-assets-scanner' ),
					/* translators: %d: page count */
					_n( '%d page hit the scanner\'s time limit and wasn\'t finished. Re-scan it later.', '%d pages hit the scanner\'s time limit and weren\'t finished. Re-scan them later.', 2, 'dr-speed-ai-assets-scanner' ),
				],
				'analysis_failed' => [
					/* translators: %d: page count */
					_n( '%d page wasn\'t finished (scanner error). Re-scan it later.', '%d pages weren\'t finished (scanner error). Re-scan them later.', 1, 'dr-speed-ai-assets-scanner' ),
					/* translators: %d: page count */
					_n( '%d page wasn\'t finished (scanner error). Re-scan it later.', '%d pages weren\'t finished (scanner error). Re-scan them later.', 2, 'dr-speed-ai-assets-scanner' ),
				],
				'off_site'        => [
					/* translators: %d: page count */
					_n( '%d page wasn\'t scanned: free keys cover your own site only.', '%d pages weren\'t scanned: free keys cover your own site only.', 1, 'dr-speed-ai-assets-scanner' ),
					/* translators: %d: page count */
					_n( '%d page wasn\'t scanned: free keys cover your own site only.', '%d pages weren\'t scanned: free keys cover your own site only.', 2, 'dr-speed-ai-assets-scanner' ),
				],
				'extra_time_lost' => [
					/* translators: %d: page count */
					_n( 'Extra Time for %d page couldn\'t be completed and wasn\'t charged. Re-scan it later.', 'Extra Time for %d pages couldn\'t be completed and wasn\'t charged. Re-scan them later.', 1, 'dr-speed-ai-assets-scanner' ),
					/* translators: %d: page count */
					_n( 'Extra Time for %d page couldn\'t be completed and wasn\'t charged. Re-scan it later.', 'Extra Time for %d pages couldn\'t be completed and wasn\'t charged. Re-scan them later.', 2, 'dr-speed-ai-assets-scanner' ),
				],
				'unknown'         => [
					/* translators: %d: page count */
					_n( '%d page couldn\'t be finished. Re-scan it later.', '%d pages couldn\'t be finished. Re-scan them later.', 1, 'dr-speed-ai-assets-scanner' ),
					/* translators: %d: page count */
					_n( '%d page couldn\'t be finished. Re-scan it later.', '%d pages couldn\'t be finished. Re-scan them later.', 2, 'dr-speed-ai-assets-scanner' ),
				],
			],
			'partial_row'        => [
				/* translators: %s: device word (Desktop/Mobile) */
				'push'     => __( '✓ %s rules ready to Push/Sync', 'dr-speed-ai-assets-scanner' ),
				/* translators: %s: device word (Desktop/Mobile) */
				'download' => __( '✓ %s rules ready to download', 'dr-speed-ai-assets-scanner' ),
				/* translators: %s: device word (Desktop/Mobile) */
				'none'     => __( '✓ %s: no unloads found', 'dr-speed-ai-assets-scanner' ),
			],
			'stack_phrases'      => self::stack_phrases(),
		];
	}

	/**
	 * Wipe all dismissals when a new scan starts.
	 * Call at the top of ScannerAjax::submit_job().
	 */
	public static function on_submit_job(): void {
		update_option( self::OPTION_DISMISSALS, [], false /* autoload off */ );
	}

	/**
	 * AJAX handler: mark a scan's banner as dismissed.
	 * Nonce: drspeed_aias_dismiss_banner
	 * Capability: manage_options (same as every other scanner AJAX endpoint)
	 */
	public static function ajax_dismiss(): void {
		check_ajax_referer( 'drspeed_aias_dismiss_banner' );

		// Rule 4 + 11: nonce alone is NOT authorization — capability check is mandatory.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}

		// Rule 24: wp_unslash before sanitize_text_field.
		$scan_id = sanitize_text_field( wp_unslash( $_POST['scan_id'] ?? '' ) );
		if ( $scan_id === '' ) {
			wp_send_json_error( 'missing_scan_id' );
		}

		$dismissed = get_option( self::OPTION_DISMISSALS, [] );
		if ( ! is_array( $dismissed ) ) {
			$dismissed = [];
		}
		$dismissed[ $scan_id ] = true;
		update_option( self::OPTION_DISMISSALS, $dismissed, false );
		wp_send_json_success();
	}
}

add_action( 'wp_ajax_drspeed_aias_dismiss_banner', [ 'DRSPEED_AIAS_Broken_Banner', 'ajax_dismiss' ] );
