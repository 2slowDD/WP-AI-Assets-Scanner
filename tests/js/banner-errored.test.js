const assert = require('assert');
const { createHarness } = require('./r3-stage-c-harness');
const MAP = require('./fixtures/copy-map.json');

// Errored-page copy — the live banner is assembled from drspeedAiasReasonCopy
// (= export_copy_map(), dumped to fixtures/copy-map.json and kept fresh by
// tests/CopyMapFixtureFreshTest.php) and must say exactly what the PHP twin
// DRSPEED_AIAS_Broken_Banner::compose_body() says for the same payload.
//
// P17: drives the real path — handleStatusUpdate('complete') -> buildResult() ->
// the bannerData projection -> restoreStep4() -> renderBrokenBanner(). Only the
// build_result AJAX body is supplied, so a key the projection drops never arrives.

function flush() { return new Promise(r => setImmediate(r)); }
function render(data, map = MAP) {
  const d = Object.assign({ scan_id: 's1', total_pages: 3, safe_count: 1, aggressive_count: 0, can_push: true, has_internal_rules: true, pages: [], pages_blocked: { desktop: 0, mobile: 0 },
    blocked_reasons: {}, blocked_stacks: {}, pages_errored: { count: 0, reasons: {} }, pages_one_device: 0, pages_one_device_with_rules: 0 }, data);
  const h = createHarness({ fetch: (u, o) => { const a = o && o.body ? o.body.get('action') : null;
    return Promise.resolve({ ok: true, json: () => Promise.resolve(a === 'drspeed_aias_build_result' ? { success: true, data: d } : { success: true, data: {} }) }); } });
  if (map !== null) h.sandbox.drspeedAiasReasonCopy = map;
  h.sandbox.window.__cuTest.handleStatusUpdate({ status: 'complete', total: 3, completed: 3, pages: [] });
  return flush().then(() => h.els['cu-banner-area'].innerHTML || '');
}
const text = html => html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ');
// The body paragraph (between the heading and the dismiss button), apostrophes decoded:
// JS esc() writes &#39; where the identity-escaped PHP pins below carry a bare '.
function body(html) {
  const m = /<\/strong><\/p><p>([\s\S]*?)<\/p><p><button/.exec(html);
  return m ? m[1].replace(/&#39;/g, '\'') : null;
}

// Verbatim from tests/BannerRenderingTest.php › test_compose_body_exact_text_for_parity_fixtures
// (compose_body() output for the same payloads, push_ready true).
const LINK = '<a href="admin.php?page=drspeed-aias-settings#cu-cloudflare-waf-bypass">open AI Assets Scanner settings</a>';
const PARITY = [
  ['T1', { pages_errored: { count: 1, reasons: { page_time_limit: 1 } } },
    '1 page hit the scanner\'s time limit and wasn\'t finished. Re-scan it later.'],
  ['T4', { pages_blocked: { desktop: 1, mobile: 0 }, blocked_reasons: { tier2_cf_challenge: 1 }, pages_one_device: 1, pages_one_device_with_rules: 1 },
    'Desktop scanner blocked on 1 of 3 pages. (Cloudflare challenge) Your bot protection denied the desktop scanner. For desktop rules too, temporarily disable bot protection during scans and re-scan. Behind Cloudflare or another CDN? Set up the scanner exemption so future scans aren\'t blocked — ' + LINK + '. Rules from the device that got through are ready to Push or Sync.'],
  ['T5', { pages_blocked: { desktop: 1, mobile: 1 }, blocked_reasons: { tier2_cf_challenge: 2 } },
    'Desktop scanner blocked on 1 of 3 pages. Mobile scanner blocked on 1 of 3 pages. (Cloudflare challenge) Your bot protection denied the scanner. For full coverage, temporarily disable bot protection during scans and re-scan. Behind Cloudflare or another CDN? Set up the scanner exemption so future scans aren\'t blocked — ' + LINK + '.'],
  ['T6', { pages_blocked: { desktop: 1, mobile: 1 }, blocked_reasons: { tier1_zero_bytes: 2 }, blocked_stacks: { siteground: 2 } },
    'Desktop scanner blocked on 1 of 3 pages. Mobile scanner blocked on 1 of 3 pages. (SiteGround bot protection) SiteGround\'s bot protection denied the scanner. Turn off the anti-bot protection in SiteGround Site Tools while the scan runs, or ask SiteGround support to allow the scanner, then re-scan.'],
];

async function run() {
  let out = await render({ pages_errored: { count: 1, reasons: { page_time_limit: 1 } } });            // T1 / Review Focus 4
  assert.ok(/1 page hit the scanner/.test(text(out)) && !/blocked/i.test(text(out)) && !/server/i.test(text(out)) && !/ready to/.test(out), 'errored-only');
  out = await render({ pages_blocked: { desktop: 1, mobile: 0 }, blocked_reasons: { tier2_cf_challenge: 1 }, pages_one_device: 1, pages_one_device_with_rules: 1 });   // T4
  assert.ok(/denied the desktop scanner\. For desktop rules too/.test(text(out)) && /ready to Push or Sync/.test(out), 'one-device push tail');
  out = await render({ pages_blocked: { desktop: 1, mobile: 0 }, blocked_reasons: { tier2_cf_challenge: 1 }, pages_one_device: 1, pages_one_device_with_rules: 0 });   // T4b
  assert.ok(!/ready to/.test(out), 'no tail without survivor rules');
  out = await render({ pages_blocked: { desktop: 1, mobile: 1 }, blocked_reasons: { tier2_cf_challenge: 2 } });   // T5
  assert.ok(/For full coverage/.test(out) && !/unblocked device/.test(out) && !/ready to/.test(out), 'both blocked');
  out = await render({ pages_blocked: { desktop: 1, mobile: 1 }, blocked_reasons: { tier1_zero_bytes: 2 }, blocked_stacks: { siteground: 2 } });   // T6
  assert.ok(/\(SiteGround bot protection\)/.test(text(out)) && /Site Tools/.test(out) && !/empty response/.test(out) && !/cu-cloudflare-waf-bypass/.test(out), 'siteground');
  out = await render({ pages_errored: { count: 2, reasons: { unknown: 2 } } }, null);                   // no map → English defaults
  assert.ok(/2 pages couldn/.test(text(out)), 'english defaults');
  out = await render({ pages_errored: { count: 1, reasons: { analysis_failed: 1 } } }, Object.assign({}, MAP, { errored: {} }));   // Review Focus 3: missing key → default for that key
  assert.ok(/scanner error/.test(text(out)) && !/undefined/.test(out), 'missing key falls back per key');
  out = await render({ pages_blocked: { desktop: 1, mobile: 0 }, blocked_reasons: { tier2_cf_challenge: 1 }, pages_one_device: 1, pages_one_device_with_rules: 'yes' });   // Review Focus 5
  assert.ok(!/ready to/.test(out), 'non-numeric survivor count treated as 0');
  const hostile = JSON.parse(JSON.stringify(MAP)); hostile.tail.push = '<img src=x onerror=alert(1)>';           // r2 m8
  out = await render({ pages_blocked: { desktop: 1, mobile: 0 }, blocked_reasons: { tier2_cf_challenge: 1 }, pages_one_device: 1, pages_one_device_with_rules: 1 }, hostile);
  assert.ok(/&lt;img/.test(out) && !/<img/.test(out), 'translations are escaped');

  // Parity with the PHP twin, word for word (anchor markup included).
  for (const [name, payload, php] of PARITY) {
    out = await render(payload);
    assert.strictEqual(body(out), php, name + ': JS banner body equals compose_body()');
    assert.strictEqual(text(body(out)).trim(), text(php).trim(), name + ': text content equals compose_body()');
  }

  // Review Focus 3, one level deeper: a map missing ONE sub-key keeps every other
  // translation and falls back for that key alone — never "undefined".
  const partial = JSON.parse(JSON.stringify(MAP));
  delete partial.tail.push; partial.errored.unknown = ['%d X-ONE.', '%d X-MANY.'];
  out = await render({ pages_blocked: { desktop: 1, mobile: 0 }, blocked_reasons: { tier2_cf_challenge: 1 }, pages_one_device: 1, pages_one_device_with_rules: 1,
    pages_errored: { count: 2, reasons: { unknown: 2 } } }, partial);
  assert.ok(/ready to Push or Sync/.test(out) && /2 X-MANY\./.test(out) && !/undefined/.test(out), 'single missing key → that key\'s default only');

  // Empty PHP maps arrive as JSON [] — read them as empty maps, not rejected.
  out = await render({ pages_blocked: { desktop: 0, mobile: 1 }, blocked_reasons: [], blocked_stacks: [], pages_errored: { count: 1, reasons: [] } });
  assert.ok(/Mobile scanner blocked on 1 of 3 pages\. Your bot protection denied the mobile scanner\./.test(text(out).replace(/&#39;/g, '\'')), '[] maps read as empty');

  // Hostile map strings everywhere else (device word, reason phrase, heading) and a
  // hostile reason key from the worker are escaped too; the settings anchor survives.
  const hostile2 = JSON.parse(JSON.stringify(MAP));
  hostile2.device.desktop = '<b>d</b>'; hostile2.phrases.tier2_cf_challenge = '<i>cf</i>'; hostile2.heading = '<u>h</u>';
  out = await render({ pages_blocked: { desktop: 1, mobile: 0 }, blocked_reasons: { tier2_cf_challenge: 1, '<script>': 1 } }, hostile2);
  assert.ok(!/<(b|i|u|script)>/.test(out) && /&lt;b&gt;d&lt;\/b&gt;/.test(out) && /&lt;script&gt;/.test(out) && /cu-cloudflare-waf-bypass">open AI Assets Scanner settings<\/a>/.test(out), 'every map string and reason key escaped');

  // English defaults = export_copy_map() verbatim (the banner never reads remediation /
  // settings_link_keys, so those stay out of the defaults).
  const h0 = createHarness({ fetch: () => Promise.resolve({ ok: true, json: () => Promise.resolve({ success: true, data: {} }) }) });
  const DEF = JSON.parse(JSON.stringify(h0.sandbox.window.__cuTest.bannerCopyDefaults));
  const want = JSON.parse(JSON.stringify(MAP)); delete want.remediation; delete want.settings_link_keys;
  assert.deepStrictEqual(DEF, want, 'BANNER_COPY_DEFAULTS equals export_copy_map() string for string');

  // ...and the defaults are what renders: no map vs the real map, every action branch.
  const BRANCHES = [
    { pages_blocked: { desktop: 2, mobile: 0 }, blocked_reasons: { tier1_http_rate_limit: 2 }, rate_limit_attribution: 'cloudflare' },
    { pages_blocked: { desktop: 2, mobile: 0 }, blocked_reasons: { tier1_http_rate_limit: 2 }, rate_limit_attribution: 'host' },
    { pages_blocked: { desktop: 0, mobile: 2 }, blocked_reasons: { tier1_http_rate_limit: 2 } },
    { pages_blocked: { desktop: 1, mobile: 1 }, blocked_reasons: { tier1_http_5xx: 1, tier1_transport_error: 1 } },
    { pages_blocked: { desktop: 0, mobile: 2 }, blocked_reasons: { tier1_http_rate_limit: 1, tier2_cf_challenge: 1 } },
    { pages_blocked: { desktop: 2, mobile: 0 }, blocked_reasons: { tier1_zero_bytes: 2 }, blocked_stacks: { siteground: 1 }, pages_one_device_with_rules: 1 },
    { pages_errored: { count: 10, reasons: { page_time_limit: 1, analysis_failed: 2, off_site: 1, extra_time_lost: 2, unknown: 1, kind_from_the_future: 3 } } },
  ];
  for (const p of BRANCHES) assert.strictEqual(await render(p, null), await render(p), 'defaults render like the map: ' + JSON.stringify(p));

  console.log('OK banner-errored');
}
run().catch(e => { console.error(e); process.exit(1); });
