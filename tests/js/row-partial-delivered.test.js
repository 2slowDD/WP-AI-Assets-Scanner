const assert = require('assert');
const fs = require('fs');
const path = require('path');
const { createHarness } = require('./r3-stage-c-harness');
const MAP = require('./fixtures/copy-map.json');

// D-OP6 (stage-2 spec §5.3): a partial row that delivered rules (displayed S+A > 0) and cost
// >= 1 displayed credit is light green, not the yellow of rows that got nothing.
//
// P17: drives the real path — handleStatusUpdate('complete') -> buildResult() -> restoreStep4()
// -> renderResultUrlList() -> renderResultUrlListPage(). Only the build_result AJAX body is supplied.

function flush() { return new Promise(r => setImmediate(r)); }
function render(pages) {
  const d = { scan_id: 's1', total_pages: pages.length, safe_count: 1, aggressive_count: 0, can_push: true, has_internal_rules: true,
    pages, pages_blocked: { desktop: 0, mobile: 0 }, blocked_reasons: {}, blocked_stacks: {},
    pages_errored: { count: 0, reasons: {} }, pages_one_device: 0, pages_one_device_with_rules: 0 };
  const h = createHarness({ fetch: (u, o) => { const a = o && o.body ? o.body.get('action') : null;
    return Promise.resolve({ ok: true, json: () => Promise.resolve(a === 'drspeed_aias_build_result' ? { success: true, data: d } : { success: true, data: {} }) }); } });
  h.sandbox.drspeedAiasReasonCopy = MAP;
  h.sandbox.window.__cuTest.handleStatusUpdate({ status: 'complete', total: pages.length, completed: pages.length, pages: [] });
  return flush().then(() => h.els['cu-result-url-list']._html || '');
}
function row(o) {
  return Object.assign({ n: 1, url: 'https://site.test/a/', status_class: 'partial', status_label: 'Desktop not scanned: Cloudflare challenge',
    survivor_device: 'mobile', safe: 0, aggressive: 5, needed: 10, credits: 1 }, o || {});
}
// The class attribute of the first body row.
function trClass(html) {
  const m = /<tr class="(cu-row-[^"]*)"/.exec(html);
  if (!m) throw new Error('no result row rendered');
  return m[1];
}

async function run() {
  assert.match(trClass(await render([row()])), /^cu-row-partial cu-row-partial-delivered$/, 'partial + rules + credit -> delivered');
  assert.match(trClass(await render([row({ safe: 3, aggressive: 0 })])), /cu-row-partial-delivered/, 'safe-only rules count too');
  console.log('OK partial + rules + credit -> cu-row-partial-delivered');

  assert.doesNotMatch(trClass(await render([row({ safe: 0, aggressive: 0 })])), /cu-row-partial-delivered/, 'partial + 0 rules stays yellow');
  assert.doesNotMatch(trClass(await render([row({ credits: 0 })])), /cu-row-partial-delivered/, 'partial + 0 credits stays yellow');
  assert.doesNotMatch(trClass(await render([row({ all_already: true })])), /cu-row-partial-delivered/, 'all_already (credited back, shows 0) stays yellow');
  console.log('OK 0 rules / 0 credits / all_already -> stays yellow');

  assert.doesNotMatch(trClass(await render([row({ status_class: 'ok', status_label: 'OK', safe: 0, aggressive: 0 })])), /cu-row-partial-delivered/, 'ok noopt');
  assert.doesNotMatch(trClass(await render([row({ status_class: 'ok', status_label: 'OK' })])), /cu-row-partial-delivered/, 'ok with rules');
  assert.doesNotMatch(trClass(await render([row({ status_class: 'error', status_label: 'Error' })])), /cu-row-partial-delivered/, 'error row');
  console.log('OK ok / error rows never get the partial-delivered class');

  const css = fs.readFileSync(path.join(__dirname, '../../admin/css/dr-speed-ai-assets-scanner-admin.css'), 'utf8');
  const yellow = (css.match(/tr\.cu-row-partial,/g) || []).length;
  const green = (css.match(/tr\.cu-row-partial\.cu-row-partial-delivered\s*\{[^}]*#f6fbee/g) || []).length;
  assert.ok(yellow >= 3, 'three yellow layers expected, got ' + yellow);
  assert.strictEqual(green, yellow, 'every layer that paints partial yellow also paints partial-delivered green');
  assert.match(css, /\.cu-url-table \.cu-row-status\s*\{[^}]*overflow-wrap:\s*anywhere/, 'status cell wraps a long path');
  console.log('OK each stylesheet layer has the green rule; the status cell wraps long text');
}

run().catch(e => { console.error(e); process.exit(1); });
