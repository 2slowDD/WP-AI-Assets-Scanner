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
	 * The words of each action.*_link* map sentence that become the settings anchor
	 * (errored-page copy spec §3.3.4). A match key, not copy: it is never shown on
	 * its own. Mirrored in admin/js/scanner.js — keep the two in sync.
	 */
	const SETTINGS_ANCHOR = 'open AI Assets Scanner settings';

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
	 * Test-only reference twin of scanner.js › renderBrokenBanner(): no production
	 * caller (the live banner is JS). Shows when any page was blocked OR errored
	 * (errored-page copy spec §3.3); every word comes from export_copy_map() and the
	 * body from compose_body().
	 *
	 * @param array $payload build_result banner fields (pages_blocked, blocked_reasons,
	 *                       blocked_stacks, pages_errored, pages_one_device_with_rules,
	 *                       rate_limit_attribution) plus scan_id, total_pages, push_ready.
	 */
	public static function render( array $payload ): string {
		$scan_id = is_scalar( $payload['scan_id'] ?? null ) ? (string) $payload['scan_id'] : '';
		$blocked = self::sub_map( $payload, 'pages_blocked' );
		$errored = self::sub_map( $payload, 'pages_errored' );

		if ( self::count_of( $blocked['desktop'] ?? 0 ) + self::count_of( $blocked['mobile'] ?? 0 ) + self::count_of( $errored['count'] ?? 0 ) === 0 ) {
			return '';
		}

		$dismissed = get_option( self::OPTION_DISMISSALS, [] );
		if ( is_array( $dismissed ) && ! empty( $dismissed[ $scan_id ] ) ) {
			return '';
		}

		$map  = self::export_copy_map();
		$copy = self::compose_body( $payload, $map );

		ob_start();
		?>
		<div class="notice notice-warning drspeed-aias-broken-banner" data-scan-id="<?php echo esc_attr( $scan_id ); ?>">
			<p><strong>&#9888; <?php echo esc_html( $map['heading'] ); ?></strong></p>
			<p><?php echo wp_kses_post( $copy ); ?></p>
			<p>
				<button type="button" class="button drspeed-aias-dismiss-banner">
					<?php echo esc_html( $map['dismiss'] ); ?>
				</button>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Errored-page copy spec §3.3 — the banner body, composed ONLY from $map
	 * (= export_copy_map()). Order: blocked sentence(s) with the reason clause on
	 * the last one → action clause → survivor tail → errored sentence(s).
	 * scanner.js › renderBrokenBanner() must produce the same words for the same
	 * inputs; its parity tests diff against this method.
	 *
	 * UNTRUSTED INPUT: $payload values come from the worker via build_result.
	 * Counts pass count_of() (non-numeric → 0), sub-maps pass sub_map() (anything
	 * but an array → [], so an empty map serialised as [] reads the same), the
	 * attribution passes normalize_attribution(). Keys only select map strings; an
	 * unrecognised reason key is shown as its own (escaped) text, an unrecognised
	 * errored kind reads as 'unknown'. Every map string is esc_html()'d before
	 * concatenation (translations are third-party text); linkify() adds the only
	 * markup. Plurals pick [0] iff n === 1 — never _n() at render time, so this twin
	 * and the JS agree in every locale.
	 *
	 * @param array $payload Banner fields (see render()).
	 * @param array $map     export_copy_map().
	 * @return string HTML: escaped text plus settings anchors.
	 */
	public static function compose_body( array $payload, array $map ): string {
		$blocked    = self::sub_map( $payload, 'pages_blocked' );
		$errored    = self::sub_map( $payload, 'pages_errored' );
		$total      = self::count_of( $payload['total_pages'] ?? 0 );
		$siteground = self::count_of( self::sub_map( $payload, 'blocked_stacks' )['siteground'] ?? 0 );
		$per_device = [
			'desktop' => self::count_of( $blocked['desktop'] ?? 0 ),
			'mobile'  => self::count_of( $blocked['mobile'] ?? 0 ),
		];
		$reason_counts = [];
		foreach ( self::sub_map( $payload, 'blocked_reasons' ) as $key => $n ) {
			if ( self::count_of( $n ) > 0 ) {
				$reason_counts[ (string) $key ] = self::count_of( $n );
			}
		}
		$parts = [];

		// 1. Blocked sentences + reason clause, then 2. the action clause. No blocked
		// page → neither, so an errored-only scan never mentions blocking or the server.
		$sentences = [];
		foreach ( $per_device as $device => $n ) {
			if ( $n > 0 ) {
				$sentences[] = esc_html( sprintf( $map['blocked'][ $device ], $n, $total ) );
			}
		}
		if ( [] !== $sentences ) {
			$phrases = [];
			foreach ( array_keys( $reason_counts ) as $key ) {
				$phrases[ $key ] = $map['phrases'][ $key ] ?? $key;
			}
			// Spec §3.3.3: every empty response came from SiteGround → its phrase REPLACES
			// "empty response"; some other entry is SiteGround's → ADD the phrase.
			if ( $siteground > 0 && isset( $map['stack_phrases']['siteground'] ) ) {
				if ( $siteground === ( $reason_counts['tier1_zero_bytes'] ?? 0 ) ) {
					$phrases['tier1_zero_bytes'] = $map['stack_phrases']['siteground'];
				} else {
					$phrases[] = $map['stack_phrases']['siteground'];
				}
			}
			$reason_clause = $phrases ? esc_html( ' (' . implode( ', ', array_unique( $phrases ) ) . ')' ) : '';

			$parts[] = implode( ' ', $sentences ) . $reason_clause;
			$parts[] = self::action_sentence( $per_device, $reason_counts, $siteground, $payload['rate_limit_attribution'] ?? null, $map );
		}

		// 3. Survivor tail: only when a one-device page left rules to apply.
		if ( self::count_of( $payload['pages_one_device_with_rules'] ?? 0 ) > 0 ) {
			// Absent push_ready reads as true, as scanner.js does until it knows the state.
			$push_ready = array_key_exists( 'push_ready', $payload ) ? (bool) $payload['push_ready'] : true;
			$parts[]    = esc_html( $push_ready ? $map['tail']['push'] : $map['tail']['download'] );
		}

		// 4. Errored sentences, in the map's kind order (independent of worker page order).
		$by_kind = [];
		foreach ( self::sub_map( $errored, 'reasons' ) as $kind => $n ) {
			$kind             = isset( $map['errored'][ $kind ] ) ? (string) $kind : 'unknown';
			$by_kind[ $kind ] = ( $by_kind[ $kind ] ?? 0 ) + self::count_of( $n );
		}
		foreach ( $map['errored'] as $kind => $forms ) {
			$n = $by_kind[ $kind ] ?? 0;
			if ( $n > 0 ) {
				$parts[] = esc_html( sprintf( 1 === $n ? $forms[0] : $forms[1], $n ) );
			}
		}

		return trim( implode( ' ', array_filter( $parts, static fn( $s ) => '' !== $s ) ) );
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
	 * The action clause of compose_body() (spec §3.3.3), from the map only.
	 *
	 * SiteGround on every blocked entry → the SiteGround clause. Otherwise one
	 * remediation category → that category's clause; mixed categories fall back to
	 * the bot clause (naming the device when exactly one device was blocked) to
	 * avoid misleading single-cause guidance.
	 *
	 * @param array<string,int> $per_device    { desktop: n, mobile: n } blocked pages.
	 * @param array<string,int> $reason_counts Positive blocked-entry counts per reason key.
	 * @param int               $siteground    Blocked entries whose stack is SiteGround.
	 * @param mixed             $attribution   Raw rate_limit_attribution (allowlisted here).
	 * @param array             $map           export_copy_map().
	 */
	private static function action_sentence( array $per_device, array $reason_counts, int $siteground, $attribution, array $map ): string {
		$action = $map['action'];
		$url    = $map['settings_url'];

		if ( $siteground > 0 && array_sum( $reason_counts ) === $siteground ) {
			return esc_html( $action['siteground'] );
		}

		$categories = [];
		foreach ( array_keys( $reason_counts ) as $key ) {
			$categories[] = $map['categories'][ $key ] ?? 'bot';
		}
		$categories = array_values( array_unique( $categories ) );

		if ( [ 'rate' ] === $categories ) {
			// T0-C: name the party that actually rate-limited the scan; the allowlisted
			// value only picks a branch. The Cloudflare copy deliberately does NOT say
			// "not your server" (a mixed scan resolves to cloudflare); the host copy has
			// no settings link by design.
			switch ( self::normalize_attribution( $attribution ) ) {
				case 'cloudflare':
					return esc_html( $action['rate_cf'] ) . ' ' . self::linkify( $action['rate_cf_link'], $url );
				case 'host':
					return esc_html( $action['rate_host'] );
				default:
					return esc_html( $action['rate_unknown'] ) . ' ' . self::linkify( $action['rate_unknown_link'], $url );
			}
		}
		if ( [ 'error' ] === $categories ) {
			return esc_html( $action['error'] );
		}

		$desktop = $per_device['desktop'] > 0;
		$lead    = ( $desktop !== ( $per_device['mobile'] > 0 ) )
			? sprintf( $action['bot_one'], $map['device'][ $desktop ? 'desktop' : 'mobile' ] )
			: $action['bot_both'];
		// Mixed-with-rate: the rate-limit exemption sentence is the single settings link.
		$link = in_array( 'rate', $categories, true ) ? $action['bot_link_rate'] : $action['bot_link'];
		return esc_html( $lead ) . ' ' . self::linkify( $link, $url );
	}

	/**
	 * Escapes a map sentence and wraps its anchor text — the trailing words
	 * self::SETTINGS_ANCHOR (export_copy_map() docblock) — in a settings link. A
	 * translation without that phrase stays plain escaped text.
	 */
	private static function linkify( string $sentence, string $url ): string {
		$html   = esc_html( $sentence );
		$anchor = esc_html( self::SETTINGS_ANCHOR );
		$at     = strrpos( $html, $anchor );
		if ( false === $at ) {
			return $html;
		}
		return substr_replace( $html, '<a href="' . esc_url( $url ) . '">' . $anchor . '</a>', $at, strlen( $anchor ) );
	}

	/**
	 * A worker-supplied count: numeric → non-negative int, anything else → 0.
	 *
	 * @param mixed $v
	 */
	private static function count_of( $v ): int {
		return is_numeric( $v ) ? max( 0, (int) $v ) : 0;
	}

	/**
	 * $a[ $key ] when it is an array, else []. Empty maps can arrive JSON-encoded as
	 * [] rather than {} — never assume object-ness.
	 */
	private static function sub_map( array $a, string $key ): array {
		return ( isset( $a[ $key ] ) && is_array( $a[ $key ] ) ) ? $a[ $key ] : [];
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
