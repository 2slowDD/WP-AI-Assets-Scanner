const assert = require('assert');
const { createHarness } = require('./r3-stage-c-harness');
const MAP = require('./fixtures/copy-map.json');

// Errored-page copy — Step-4 row line 1 for `partial` rows (one device blocked, the other
// done). The status cell leads with the SURVIVING device's outcome, then the existing
// status_label on line 2; a partial row counts towards "N of M successful".
//
// P17: drives the real path — handleStatusUpdate('complete') -> buildResult() ->
// restoreStep4() (which sets cuUrlListState.pushReady from can_push + has_internal_rules)
// -> renderResultUrlList() -> renderResultUrlListPage(). Only the build_result AJAX body is
// supplied. Expected strings are independent copies (not read out of scanner.js).

function flush() { return new Promise(r => setImmediate(r)); }
function render(data, map = MAP) {
  const d = Object.assign({ scan_id: 's1', total_pages: 2, safe_count: 4, aggressive_count: 0, can_push: true, has_internal_rules: true,
    pages: [], pages_blocked: { desktop: 1, mobile: 0 }, blocked_reasons: { tier2_cf_challenge: 1 }, blocked_stacks: {},
    pages_errored: { count: 0, reasons: {} }, pages_one_device: 1, pages_one_device_with_rules: 1 }, data);
  const h = createHarness({ fetch: (u, o) => { const a = o && o.body ? o.body.get('action') : null;
    return Promise.resolve({ ok: true, json: () => Promise.resolve(a === 'drspeed_aias_build_result' ? { success: true, data: d } : { success: true, data: {} }) }); } });
  if (map !== null) h.sandbox.drspeedAiasReasonCopy = map;
  h.sandbox.window.__cuTest.handleStatusUpdate({ status: 'complete', total: 2, completed: 2, pages: [] });
  return flush().then(() => ({
    table: h.els['cu-result-url-list']._html || '',
    count: h.els['cu-results-success-count'].textContent,
  }));
}

function partialRow(extra) {
  return Object.assign({ n: 1, url: 'https://site.test/a/', status_class: 'partial', status_label: 'Desktop not scanned: Cloudflare challenge',
    survivor_device: 'mobile', safe: 4, aggressive: 0, needed: 10, credits: 1 }, extra || {});
}
function okRow() {
  return { n: 2, url: 'https://site.test/b/', status_class: 'ok', status_label: 'OK', safe: 2, aggressive: 1, needed: 7, credits: 1 };
}
// The status <span> of the partial row (row 0), inner HTML.
function statusCell(table) {
  const m = /<td data-label="Status"><span class="cu-row-status cu-row-status--partial">([\s\S]*?)<\/span><\/td>/.exec(table);
  if (!m) throw new Error('partial status cell not found');
  return m[1];
}
const LABEL = 'Desktop not scanned: Cloudflare challenge';

async function run() {
  let r = await render({ pages: [ partialRow(), okRow() ] });
  assert.strictEqual(statusCell(r.table), '✓ Mobile rules ready to Push/Sync<br>' + LABEL, 'push-ready partial row');
  assert.strictEqual(r.count, '2 of 2 successful', 'a partial row counts as successful');
  console.log('OK partial row: line 1 push + label; 2 of 2 successful');

  r = await render({ pages: [ partialRow(), okRow() ], can_push: false });
  assert.strictEqual(statusCell(r.table), '✓ Mobile rules ready to download<br>' + LABEL, 'can_push false -> download');
  r = await render({ pages: [ partialRow(), okRow() ], has_internal_rules: false, apply_safe_count: 0, apply_aggressive_count: 0 });
  assert.strictEqual(statusCell(r.table), '✓ Mobile rules ready to download<br>' + LABEL, 'no internal rules -> download');
  console.log('OK pushReady false (can_push false / no internal rules) -> ready to download');

  r = await render({ pages: [ partialRow({ survivor_device: 'desktop', status_label: 'Mobile not scanned: Cloudflare challenge' }), okRow() ] });
  assert.strictEqual(statusCell(r.table), '✓ Desktop rules ready to Push/Sync<br>Mobile not scanned: Cloudflare challenge', 'desktop survivor');

  r = await render({ pages: [ partialRow({ safe: 0, aggressive: 0 }), okRow() ] });
  assert.strictEqual(statusCell(r.table), '✓ Mobile: no unloads found<br>' + LABEL, 'zero rules -> none');
  r = await render({ pages: [ partialRow({ all_already: true }), okRow() ] });
  assert.strictEqual(statusCell(r.table), '✓ Mobile: no unloads found<br>' + LABEL, 'all_already displays 0 -> none');
  console.log('OK desktop survivor; zero rules / all_already -> no unloads found');

  r = await render({ pages: [ partialRow({ survivor_device: '' }), okRow() ] });
  assert.strictEqual(statusCell(r.table), LABEL, 'survivor "" -> no line 1');
  r = await render({ pages: [ partialRow({ survivor_device: '<img src=x onerror=1>' }), okRow() ] });
  assert.strictEqual(statusCell(r.table), LABEL, 'non-allowlisted survivor -> no line 1');
  r = await render({ pages: [ Object.assign(okRow(), { survivor_device: 'mobile' }) ] , total_pages: 1 });
  assert.ok(!/✓/.test(r.table) && r.count === '1 of 1 successful', 'an ok row never gets line 1');
  console.log('OK survivor "" / hostile survivor -> label only; ok row untouched');

  r = await render({ pages: [ partialRow(), okRow() ] }, Object.assign({}, MAP, { device: Object.assign({}, MAP.device, { Mobile: '<b>x</b>' }) }));
  assert.strictEqual(statusCell(r.table), '✓ &lt;b&gt;x&lt;/b&gt; rules ready to Push/Sync<br>' + LABEL, 'hostile device word escaped');
  r = await render({ pages: [ partialRow(), okRow() ] }, Object.assign({}, MAP, { partial_row: Object.assign({}, MAP.partial_row, { push: '<i>%s</i> ok' }) }));
  assert.strictEqual(statusCell(r.table), '&lt;i&gt;Mobile&lt;/i&gt; ok<br>' + LABEL, 'hostile template escaped');
  console.log('OK hostile device word / template escaped');

  r = await render({ pages: [ partialRow(), okRow() ] }, null);
  assert.strictEqual(statusCell(r.table), '✓ Mobile rules ready to Push/Sync<br>' + LABEL, 'no map -> English defaults');
  r = await render({ pages: [ partialRow({ safe: 0 }), okRow() ] }, Object.assign({}, MAP, { partial_row: [], device: [] }));
  assert.strictEqual(statusCell(r.table), '✓ Mobile: no unloads found<br>' + LABEL, 'empty PHP maps ([]) -> per-key defaults');
  console.log('OK no map / empty [] maps -> English defaults');
}

run().catch(e => { console.error(e); process.exit(1); });
