// FU-AAS-BYPASS-STRIPPED-AWARENESS (spec §5, AC-5) — the Step-4 row note and the one warning
// panel per scan, when a site stripped the scanner's query keys. Runs the REAL
// admin/js/scanner.js. P17: rows come from the fixture chain (real worker output -> real
// build_pages, tests/js/fixtures/bypass-lost-rows.json), and the live and restored arms drive
// the production paths into restoreStep4.
//
// S() HTML-escapes map strings, so in innerHTML ' is &#39; and " is &quot;. Assertions on a
// string holding either compare against the escaped form, on purpose.
const assert = require('assert');
const { createHarness } = require('./r3-stage-c-harness');
const ROWS = require('./fixtures/bypass-lost-rows.json');   // build_pages output from real worker output (AC-4 -> AC-5)
const MAP  = require('./fixtures/copy-map.json');

const FIX_TAIL = 'Then scan again before applying these rules.';
const base = (pages) => ({ jobId: 'j', safeCount: 1, aggCount: 0, canPush: true, externalOnly: false, hasInternalRules: true,
                           bannerData: {}, urlsScanned: pages.length, pages, scanId: 's', hasActiveCuRules: false });
function render(pages, opts) { const h = createHarness(opts); h.sandbox.window.__cuTest.restoreStep4(base(pages)); return h; }
const panel = (h) => h.els['cu-bypass-lost-panel'].innerHTML;
const table = (h) => h.els['cu-result-url-list']._html || '';
function flush() { return new Promise(r => setImmediate(r)); }

// Every arm runs even when an earlier one fails, so a mutation names all the arms it turns red.
const failed = [];
async function arm(name, fn) {
  try { await fn(); console.log('OK ' + name); }
  catch (e) { failed.push(name); console.error('RED ' + name + ': ' + e.message); }
}

async function run() {
  await arm('one optimizer row: heading, body, Yoast line, no host label, comma list', () => {
    const out = panel(render([ROWS.external_yoast]));
    assert.ok(out.includes('Some pages were scanned with the optimizer still on'));
    assert.ok(out.includes('This site removes the scanner&#39;s URL parameters'));
    assert.ok(out.includes('at least some of these pages'));
    assert.ok(/If you run this site: this looks like Yoast SEO/.test(out));
    assert.ok(out.includes('<code>nowprocket</code>'));
    assert.ok(out.includes(FIX_TAIL));
    assert.ok(!out.includes('cu-bypass-lost-host'), 'one host: no label');
  });

  await arm('M2 token-only alone: nothing claims an optimizer', () => {
    const out = panel(render([ROWS.own_token_only]));
    assert.ok(out.includes('Some pages may not have been measured correctly'));
    assert.ok(out.includes('This site removes the scanner&#39;s URL parameters, so these pages may not have been measured correctly'));
    assert.ok(!/optimizer|optimization/i.test(out), 'no optimizer claim');
    assert.ok(/A plugin or server rule on this site is removing them/.test(out));
    assert.ok(out.includes(FIX_TAIL));
  });

  await arm('M2 mixed: the optimizer heading + "at least some"', () => {
    const out = panel(render([ROWS.own_token_only, ROWS.external_generic]));
    assert.ok(out.includes('Some pages were scanned with the optimizer still on'));
    assert.ok(out.includes('These sites remove'));
    assert.ok(out.includes('at least some of these pages'));
  });

  await arm('m7 two hosts, one Yoast: separate labels, each with its own keys and wording', () => {
    const out = panel(render([ROWS.external_yoast, ROWS.external_generic]));
    const a = out.indexOf('other.example'), b = out.indexOf('third.example');
    assert.ok(a !== -1 && b !== -1, 'both host labels');
    assert.ok(out.includes('cu-bypass-lost-host'), 'labels carry their class');
    const lineA = out.slice(a, b), lineB = out.slice(b);
    assert.ok(lineA.includes('Yoast SEO') && !lineB.includes('Yoast SEO'), 'wording stays per host');
    assert.ok(lineB.includes('If you run this site: a plugin or server rule on it is removing them'), 'external generic line');
    assert.ok(lineB.includes(FIX_TAIL));
  });

  await arm('fix_own_yoast: own-site wording, ends with the scan-again sentence', () => {
    const out = panel(render([ROWS.own_yoast]));
    assert.ok(out.includes('This looks like Yoast SEO'));
    assert.ok(!out.includes('If you run this site'), 'own site is not addressed as someone else\'s');
    assert.ok(out.includes('&quot;Additional URL parameters to allow&quot;: <code>cu_dep_graph, cu_scan_token, nowprocket</code>'), 'keys land at the placeholder');
    assert.ok(out.endsWith(FIX_TAIL + '</p></div>'), 'the fix line ends with the scan-again sentence');
  });

  await arm('body_plain_many: two token-only hosts, no optimizer claim', () => {
    // The worker cannot produce this today (an external token-only page is flag 0, and own-site
    // is one host); the string ships, so it is tested.
    const second = Object.assign({}, ROWS.own_token_only, { bypass_lost_host: 'second.example' });
    const out = panel(render([ROWS.own_token_only, second]));
    assert.ok(out.includes('These sites remove the scanner'));
    assert.ok(out.includes('may not have been measured correctly'));
    assert.ok(!/optimizer|optimization/i.test(out), 'no optimizer claim');
  });

  await arm('one host across rows is one group, "This site"; M1 URL-carried key listed', () => {
    const out = panel(render([ROWS.own_generic, ROWS.own_token_only]));
    assert.ok(out.includes('This site removes'));
    assert.ok(out.includes('nowpcu'), 'M1: the URL-carried key is listed');
    assert.ok(!out.includes('cu-bypass-lost-host'));
    assert.strictEqual((out.match(/<code>/g) || []).length, 1, 'one fix line for one host');
  });

  await arm('m4 comma separated', () => {
    const out = panel(render([ROWS.own_generic]));
    assert.ok(/<code>[^<]*cu_scan_token, [^<]*<\/code>/.test(out), 'keys joined by comma + space inside one code element');
  });

  await arm('M3 the banner writers cannot touch the panel', () => {
    const h = render([ROWS.external_yoast]); const T = h.sandbox.window.__cuTest;
    assert.strictEqual(h.els['cu-banner-area'].innerHTML, '', 'nothing written into the banner area');
    T.renderPartialBanner({ status: 'user_cancel', pages: [], total_pages: 1 });
    assert.ok(panel(h).includes('optimizer still on'), 'a live partial banner leaves the panel');
  });

  await arm('M3 blocked banner + panel together', () => {
    const h = createHarness(); const p = base([ROWS.external_yoast]);
    p.bannerData = { scan_id: 's', pages_blocked: { desktop: 1, mobile: 0 }, blocked_reasons: { tier2_cf_challenge: 1 } };
    h.sandbox.window.__cuTest.restoreStep4(p);
    assert.ok(h.els['cu-banner-area'].innerHTML.includes('drspeed-aias-broken-banner'));
    assert.ok(panel(h).includes('optimizer still on'));
  });

  await arm('M3 a flagged row only on table page 2 still produces the panel', () => {
    const clean = Array.from({ length: 30 }, (_, i) => Object.assign({}, ROWS.clean, { n: i + 1 }));
    const h = render([...clean, ROWS.external_yoast]);
    assert.ok(!table(h).includes('other.example'), 'precondition: the flagged row is off the rendered table page');
    assert.ok(panel(h).includes('optimizer still on'));
  });

  await arm('m5 a new scan empties it; restoreStep4 with nothing flagged empties it', () => {
    const h = render([ROWS.external_yoast]); const T = h.sandbox.window.__cuTest;
    T.beginScanPolling();
    assert.strictEqual(panel(h), '', 'a same-page restart clears the old warning');
    T.restoreStep4(base([ROWS.external_yoast])); assert.notStrictEqual(panel(h), '');
    T.restoreStep4(base([ROWS.clean])); assert.strictEqual(panel(h), '');
  });

  await arm('malformed stored rows do not stop the render', () => {
    const out = panel(render([{ url: 'https://x.example/', status_class: 'ok', bypass_lost: true }]));
    assert.ok(out.includes('may not have been measured correctly'));
    render([{ url: 'https://x.example/', status_class: 'ok', bypass_lost: true, bypass_fix_keys: 'nope', bypass_keys_lost: 7, bypass_lost_host: 3 }]);
  });

  await arm('legacy restored row with no keys: nothing', () => {
    const h = render([{ url: 'https://x.example/', status_class: 'ok', bypass_suffixes: ['nowprocket'] }]);
    assert.strictEqual(panel(h), '');
  });

  await arm('escaping: a hostile host or key cannot inject markup', () => {
    const out = panel(render([Object.assign({}, ROWS.external_yoast, { bypass_lost_host: '<img src=x>', bypass_fix_keys: ['<b>k</b>'] }), ROWS.external_generic]));
    assert.ok(!/<img|<b>k/.test(out)); assert.ok(out.includes('&lt;img'));
  });

  await arm('a markup host never reaches the panel unescaped', () => {
    // build_pages lets such a host through by design; this render is its only escape.
    const out = panel(render([Object.assign({}, ROWS.external_generic, { bypass_lost_host: '<script>x' }), ROWS.external_yoast]));
    assert.ok(!out.includes('<script'), 'no raw script tag');
    assert.ok(out.includes('<strong class="cu-bypass-lost-host">&lt;script&gt;x</strong>'), 'shown as text in its label');
  });

  await arm('a map without the new keys falls back to English', () => {
    const h = createHarness(); const old = JSON.parse(JSON.stringify(MAP)); delete old.bypass_lost;
    h.sandbox.drspeedAiasReasonCopy = old; h.sandbox.window.__cuTest.restoreStep4(base([ROWS.external_yoast]));
    assert.ok(panel(h).includes('optimizer still on'), 'English default');
  });

  await arm('the map is what renders, and a hostile translation is escaped', () => {
    const h = createHarness(); const hostile = JSON.parse(JSON.stringify(MAP));
    hostile.bypass_lost.heading_optimizer = '<u>h</u>';
    hostile.bypass_lost.fix_external_yoast = '<i>%s</i>';
    hostile.bypass_lost.note_optimizer = '<b>n</b> %s';
    h.sandbox.drspeedAiasReasonCopy = hostile; h.sandbox.window.__cuTest.restoreStep4(base([ROWS.external_yoast]));
    assert.ok(panel(h).includes('&lt;u&gt;h&lt;/u&gt;'), 'translated heading, escaped');
    assert.ok(panel(h).includes('&lt;i&gt;<code>nowprocket</code>&lt;/i&gt;'), 'translated fix line, escaped, keys still in code');
    assert.ok(table(h).includes('&lt;b&gt;n&lt;/b&gt; nowprocket'), 'translated row note, escaped');
  });

  // Row notes, read off the rendered table as tests/js/kept-protection-chip.test.js does.
  await arm('row note: optimizer key lost', () => {
    const out = table(render([ROWS.external_yoast]));
    assert.ok(out.includes('cu-bypass-note--lost'));
    assert.ok(out.includes('Optimizer stayed on: this site removed ?nowprocket'));
    assert.ok(!out.includes('scanned with ?'));
  });

  await arm('row note: token-only is the plain note', () => {
    const out = table(render([ROWS.own_token_only]));
    assert.ok(out.includes('cu-bypass-note--lost'));
    assert.ok(out.includes('This site removed the scanner&#39;s URL parameters'), 'escaped form, see the file header');
    assert.ok(!out.includes('Optimizer'));
  });

  await arm('row note: own-site row names only the optimizer key, never the scanner\'s own', () => {
    const out = table(render([ROWS.own_generic]));
    assert.ok(out.includes('this site removed ?nowprocket</span>'));
  });

  await arm('row note: several optimizer keys join with an escaped ampersand', () => {
    const out = table(render([Object.assign({}, ROWS.external_yoast, { bypass_keys_lost: ['nowprocket', 'nowpcu'] })]));
    assert.ok(out.includes('this site removed ?nowprocket&amp;nowpcu'));
  });

  await arm('row note: a flagged row with suffixes still says the key was lost', () => {
    const out = table(render([Object.assign({}, ROWS.external_yoast, { bypass_suffixes: ['nowprocket'] })]));
    assert.ok(out.includes('cu-bypass-note--lost'));
    assert.ok(!out.includes('scanned with ?'), 'the opposite claim is gone');
  });

  await arm('row note: a clean row keeps today\'s note', () => {
    const out = table(render([ROWS.clean]));
    assert.ok(out.includes('optimizer detected — scanned with ?nowprocket'));
    assert.ok(!out.includes('--lost'));
  });

  // P17 / AC-5: the live path. handleStatusUpdate('complete') -> buildResult() -> restoreStep4(),
  // with only the build_result AJAX body supplied, following tests/js/row-partial.test.js.
  await arm('live render: real path from the complete status to the panel', async () => {
    const d = { scan_id: 's1', total_pages: 1, safe_count: 1, aggressive_count: 0, can_push: true, has_internal_rules: true,
      pages: [ROWS.external_yoast], pages_blocked: { desktop: 0, mobile: 0 }, blocked_reasons: {}, blocked_stacks: {},
      pages_errored: { count: 0, reasons: {} }, pages_one_device: 0, pages_one_device_with_rules: 0 };
    const h = createHarness({ fetch: (u, o) => { const a = o && o.body ? o.body.get('action') : null;
      return Promise.resolve({ ok: true, json: () => Promise.resolve(a === 'drspeed_aias_build_result' ? { success: true, data: d } : { success: true, data: {} }) }); } });
    h.sandbox.drspeedAiasReasonCopy = MAP;
    h.sandbox.window.__cuTest.handleStatusUpdate({ status: 'complete', total: 1, completed: 1, pages: [] });
    await flush();
    assert.ok(panel(h).includes('Some pages were scanned with the optimizer still on'), 'panel on the live render');
    assert.ok(table(h).includes('cu-bypass-note--lost'), 'row note on the live render');
  });

  // AC-5: the restored path. The result as it was stored in localStorage, read by the load-time
  // restore in scanner.js; no call into restoreStep4 from the test.
  await arm('restored render: the panel comes back from the stored result on page load', () => {
    const stored = JSON.stringify({ job_id: 'j', safe_count: 1, agg_count: 0, can_push: true, external_only: false,
      total_pages: 1, pages: [ROWS.external_yoast], scan_id: 's' });
    const h = createHarness({ localStorage: { drspeed_aias_result: stored } });
    assert.ok(panel(h).includes('Some pages were scanned with the optimizer still on'), 'panel on the restored render');
    assert.ok(table(h).includes('cu-bypass-note--lost'), 'row note on the restored render');
  });

  if (failed.length) {
    console.error('bypass-lost-panel: ' + failed.length + ' arm(s) red: ' + failed.join(' | '));
    process.exit(1);
  }
  console.log('bypass-lost-panel: all assertions passed');
}

run().catch((e) => { console.error(e); process.exit(1); });
