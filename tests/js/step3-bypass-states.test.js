// 1.9.9 — FU-STEP3-BYPASS-LABEL-OVERCLAIMS (spec 2026-11-04 §4, AC-1..AC-5 + plan Review Focus).
// P17: every arm drives the REAL scanner.js through handleStatusUpdate with RAW worker-shaped page
// records. poll_status forwards get_status() unvalidated, so the loss flag here is the INTEGER 1.
// Every arm runs even when an earlier one fails, so a mutation names all the arms it turns red.
const assert = require('assert');
const { createHarness } = require('./r3-stage-c-harness');
const MAP = require('./fixtures/copy-map.json');
const C = MAP.step3_bypass;
const ICON = { pending: '–', none: '–', requested: '…', applied: '✓', lost: '⚠' };
const CLS = { pending: ['is-pending'], none: ['is-not-applicable'], requested: ['is-requested'], applied: [], lost: ['is-lost'] };
const A = 'https://x.test/a/', B = 'https://x.test/b/';
const LOST = { bypass_lost: 1, bypass_keys_lost: ['cu_scan_token', 'nowprocket'] };

const failed = [];
function arm(name, fn) { try { fn(); console.log('OK ' + name); } catch (e) { failed.push(name); console.error('RED ' + name + ': ' + e.message); } }
function fresh(urls) {
  const h = createHarness();
  h.sandbox.drspeedAiasReasonCopy = MAP;
  const T = h.sandbox.window.__cuTest;
  T.setScanUrlsForTest(urls || [A, B], {});
  return { h, T };
}
function poll(T, pages) {
  T.handleStatusUpdate({ status: 'in_progress', completed: (pages || []).filter((p) => p && p.status === 'done').length, total: (pages || []).length, pages });
}
function expectState(h, state, why) {
  const r = h.els['cu-bypass-status-row'];
  const cls = ['is-pending', 'is-requested', 'is-lost', 'is-not-applicable'].filter((c) => r.classList.contains(c));
  const w = (why ? why + ': ' : '') + state;
  assert.strictEqual(h.els['cu-bypass-status-label'].textContent, C[state + '_label'], w + ' label');
  assert.strictEqual(h.els['cu-bypass-status-copy'].textContent, C[state + '_copy'], w + ' sub-copy');
  assert.strictEqual(h.els['cu-bypass-status-icon'].textContent, ICON[state], w + ' icon');
  assert.deepStrictEqual(cls, CLS[state], w + ' row class');
}

// AC-1 — each state from poll input alone.
arm('AC-1 pending: no page has a url yet', () => {
  const { h, T } = fresh(); poll(T, [{ status: 'pending' }, { status: 'pending' }]); expectState(h, 'pending');
});
arm('AC-1 none: every page resolved, no bypass', () => {
  const { h, T } = fresh(); poll(T, [{ status: 'done', url: A }, { status: 'done', url: B }]); expectState(h, 'none');
});
arm('AC-1 applied: a done bypass page with no loss flag', () => {
  const { h, T } = fresh(); poll(T, [{ status: 'done', url: A + '?nowprocket' }, { status: 'processing', url: B + '?nowprocket' }]); expectState(h, 'applied');
});
arm('AC-1 lost: a done page lost an optimizer key', () => {
  const { h, T } = fresh(); poll(T, [Object.assign({ status: 'done', url: A + '?nowprocket' }, LOST), { status: 'processing', url: B }]); expectState(h, 'lost');
});

// AC-2 — the over-claim removed.
arm('AC-2 requested: a processing page with ?nowprocket never reads Applied', () => {
  const { h, T } = fresh(); poll(T, [{ status: 'processing', url: A + '?nowprocket' }, { status: 'pending' }]); expectState(h, 'requested');
});

// AC-3 — the unlatch, and Lost is terminal.
arm('AC-3 Applied -> Lost -> still Lost', () => {
  const { h, T } = fresh();
  poll(T, [{ status: 'done', url: A + '?nowprocket' }, { status: 'processing', url: B + '?nowprocket' }]); expectState(h, 'applied', 'poll 1');
  poll(T, [{ status: 'done', url: A + '?nowprocket' }, Object.assign({ status: 'done', url: B + '?nowprocket' }, LOST)]); expectState(h, 'lost', 'poll 2');
  poll(T, [{ status: 'done', url: A + '?nowprocket' }, { status: 'processing', url: B + '?nowprocket' }]); expectState(h, 'lost', 'poll 3');
});

// AC-4 — edges.
arm('AC-4 token-only loss: not Lost, not confirmed', () => {
  const { h, T } = fresh(); poll(T, [{ status: 'done', url: A + '?nowprocket', bypass_lost: 1, bypass_keys_lost: ['cu_scan_token'] }, { status: 'pending' }]); expectState(h, 'requested');
});
// Final review #1: a loss flag in the VALIDATED shape (true) is not a raw-path Lost, but it is not a
// confirmation either — reading it as Applied would bring back the over-claim if poll data were ever validated.
arm('AC-4 boolean true is the validated shape, not the raw one: not Lost, not confirmed', () => {
  const { h, T } = fresh(); poll(T, [{ status: 'done', url: A + '?nowprocket', bypass_lost: true, bypass_keys_lost: ['nowprocket'] }, { status: 'pending' }]); expectState(h, 'requested');
});
arm('AC-4 keys missing: not Lost, not confirmed', () => {
  const { h, T } = fresh(); poll(T, [{ status: 'done', url: A + '?nowprocket', bypass_lost: 1 }, { status: 'pending' }]); expectState(h, 'requested');
});
arm('AC-4 keys a string: not Lost, no throw', () => {
  const { h, T } = fresh(); poll(T, [{ status: 'done', url: A + '?nowprocket', bypass_lost: 1, bypass_keys_lost: 'nowprocket' }, { status: 'pending' }]); expectState(h, 'requested');
});
arm('AC-4 keys an array holding a non-key markup string: not Lost, nothing rendered', () => {
  const { h, T } = fresh(); poll(T, [{ status: 'done', url: A + '?nowprocket', bypass_lost: 1, bypass_keys_lost: ['<img src=x onerror=alert(1)>'] }, { status: 'pending' }]);
  expectState(h, 'requested');
  for (const id of ['cu-bypass-status-label', 'cu-bypass-status-copy', 'cu-bypass-status-icon']) assert.ok(!/<img/i.test(h.els[id].textContent + (h.els[id]._html || '')), id + ' carries no payload');
});
arm('AC-4 error page with a bypass key: Requested, not Applied', () => {
  const { h, T } = fresh(); poll(T, [{ status: 'error', url: A + '?nowprocket' }, { status: 'pending' }]); expectState(h, 'requested');
});
arm('AC-4 error or processing page carrying the loss flag: not Lost', () => {
  const { h, T } = fresh(); poll(T, [Object.assign({ status: 'error', url: A + '?nowprocket' }, LOST), Object.assign({ status: 'processing', url: B + '?nowprocket' }, LOST)]); expectState(h, 'requested');
});
arm('AC-4 Requested never falls back to Checking', () => {
  const { h, T } = fresh();
  poll(T, [{ status: 'processing', url: A + '?nowprocket' }, { status: 'pending' }]); expectState(h, 'requested', 'poll 1');
  poll(T, [{ status: 'pending' }, { status: 'pending' }]); expectState(h, 'requested', 'poll 2');
});

// AC-5 — a re-queued scan starts clean.
arm('AC-5 beginScanPolling resets Lost; a clean poll reaches N/A', () => {
  const { h, T } = fresh();
  poll(T, [Object.assign({ status: 'done', url: A + '?nowprocket' }, LOST), { status: 'processing', url: B }]); expectState(h, 'lost', 'before reset');
  T.beginScanPolling(); expectState(h, 'pending', 'after reset');
  poll(T, [{ status: 'done', url: A }, { status: 'done', url: B }]); expectState(h, 'none', 'clean scan');
});

// Review Focus.
arm('RF1 a lost page at index 25 of 30 (off the first table screen) still shows Lost', () => {
  const urls = Array.from({ length: 30 }, (_, i) => 'https://x.test/p' + i + '/');
  const { h, T } = fresh(urls);
  const pages = urls.map((u) => ({ status: 'done', url: u + '?nowprocket' }));
  pages[25] = Object.assign({ status: 'done', url: urls[25] + '?nowprocket' }, LOST);
  poll(T, pages); expectState(h, 'lost');
});
arm('RF2 a poll with no pages: no throw, Checking', () => {
  const { h, T } = fresh(); T.handleStatusUpdate({ status: 'in_progress', completed: 0, total: 2 }); expectState(h, 'pending');
});
arm('RF5 accepted residual: # before the suffix on a done page reads Applied (FU-SCAN-URL-FRAGMENT-DROPS-BYPASS)', () => {
  const { h, T } = fresh(); poll(T, [{ status: 'done', url: A + '#top?nowprocket' }, { status: 'pending' }]); expectState(h, 'applied');
});

if (failed.length) { console.error('FAILED arms: ' + failed.join(', ')); process.exit(1); }
console.log('ALL step3-bypass-states tests passed');
