const test = require('node:test');
const assert = require('assert');
const { createHarness, makeEl } = require('./r3-stage-c-harness');

// FU-SITE-DENIAL spec AC-10 — order of AJAX actions and "no reserve when denied", driven
// through the REAL click handlers (Start Scan + partial-banner Re-queue). No deps are
// injected into runAccessGate: the wiring calls it with its production defaults (module
// post(), real showInlineSpinner, real stop dialog), and only the harness fetch is scripted.
function flush() { return new Promise(function (r) { setImmediate(r); }); }
async function settle(n) { for (let i = 0; i < (n || 12); i++) await flush(); }

// `actions` records every AJAX action posted through fetch, plus a 'confirm' marker each
// time the external-host window.confirm is shown, so the ordering assertions can see it.
function flowHarness(answers, extra) {
  const actions = [];
  const h = createHarness(Object.assign({
    drspeedAias: { ajaxUrl: '', nonce: 'n', siteUrl: 'https://example.com', settingsUrl: '/s', outbox: { state: 'none' } },
    confirm: function () { actions.push('confirm'); return true; },
    fetch: function (url, opt) {
      const m = /action=([a-z_]+)/.exec(String(opt && opt.body));
      const action = m ? m[1] : '?';
      actions.push(action);
      const a = answers[action];
      const payload = typeof a === 'function' ? a() : (a || {});
      return Promise.resolve({ ok: true, json: function () { return Promise.resolve(payload); } });
    },
  }, extra || {}));
  h.els['cu-access-notice'] = makeEl('cu-access-notice');
  h.sandbox.scrollTo = function () {};
  return { h: h, actions: actions };
}
const GATE_SEQ = /^(confirm|drspeed_aias_check_scanner_access|drspeed_aias_probe_target_stack|drspeed_aias_reserve_job)$/;
function seqOf(f) { return f.actions.filter(function (a) { return GATE_SEQ.test(a); }); }
function count(f, action) { return f.actions.filter(function (a) { return a === action; }).length; }
function openDialogs(h) { return h.body.querySelectorAll('.cu-access-denied-dialog'); }

const CHECK = 'drspeed_aias_check_scanner_access';
const RESERVE = 'drspeed_aias_reserve_job';
const OK = { success: true, data: { hosts: [{ host: 'example.com', own_site: true, verdict: 'ok', requests: 1 }], denied: false } };
const DENIED = { success: true, data: { hosts: [{ host: 'example.com', own_site: true, verdict: 'denied', requests: 5, status: 425 }], denied: true } };
const RESERVE_STOP = { success: false, data: { message: 'stop here' } };
const PROBE_CLEAN = { success: true, data: { per_host_results: [], suggested_bypass_per_url: {}, resolved_per_url: {}, warning_needed: false, summary: {} } };

// Rule 31 — no access-check request before the operator's explicit Start / Re-queue click.
test('loading scanner.js sends no access check (no request before the click)', async function () {
  const f = flowHarness({ [CHECK]: OK, [RESERVE]: RESERVE_STOP });
  await settle();
  assert.strictEqual(count(f, CHECK), 0);
  assert.strictEqual(count(f, RESERVE), 0);
});

test('own-site Start: check runs before reserve; no probe; no confirm', async function () {
  const f = flowHarness({ [CHECK]: OK, [RESERVE]: RESERVE_STOP });
  f.h.els['cu-included-urls'].value = 'https://example.com/a';
  f.h.els['cu-btn-next-1'].click(); await settle();
  assert.deepStrictEqual(seqOf(f), [CHECK, RESERVE]);
});

test('external Start: confirm → check → probe → reserve', async function () {
  const f = flowHarness({ [CHECK]: OK, drspeed_aias_probe_target_stack: PROBE_CLEAN, [RESERVE]: RESERVE_STOP });
  f.h.els['cu-included-urls'].value = 'https://example.net/a';
  f.h.els['cu-btn-next-1'].click(); await settle();
  assert.deepStrictEqual(seqOf(f), ['confirm', CHECK, 'drspeed_aias_probe_target_stack', RESERVE]);
});

test('external Start: declining the external confirm sends nothing', async function () {
  const f = flowHarness({ [CHECK]: OK, drspeed_aias_probe_target_stack: PROBE_CLEAN, [RESERVE]: RESERVE_STOP });
  let asked = 0;
  f.h.sandbox.confirm = function () { asked++; return false; };
  f.h.els['cu-included-urls'].value = 'https://example.net/a';
  f.h.els['cu-btn-next-1'].click(); await settle();
  assert.strictEqual(asked, 1, 'the external confirm was shown');
  assert.strictEqual(count(f, CHECK), 0, 'no access check after a declined confirm');
  assert.strictEqual(count(f, 'drspeed_aias_probe_target_stack'), 0);
  assert.strictEqual(count(f, RESERVE), 0);
});

test('denied Start: no reserve; Close stops; spinner hidden', async function () {
  const f = flowHarness({ [CHECK]: DENIED, [RESERVE]: RESERVE_STOP });
  f.h.els['cu-included-urls'].value = 'https://example.com/a';
  f.h.els['cu-btn-next-1'].click(); await settle();
  const dlg = f.h.body.querySelector('.cu-access-denied-dialog');
  assert.ok(dlg, 'stop dialog shown');
  assert.strictEqual(count(f, CHECK), 1);
  assert.strictEqual(count(f, RESERVE), 0, 'no reserve while the dialog is open');
  dlg.querySelector('.cu-access-close').click(); await settle();
  assert.strictEqual(count(f, RESERVE), 0, 'no reserve after Close');
  assert.strictEqual(openDialogs(f.h).length, 0, 'dialog removed');
  const spinner = f.h.els['cu-probe-spinner-host'];
  assert.strictEqual(spinner.style.display, 'none', 'spinner hidden');
  assert.strictEqual(String(f.h.els['cu-access-notice'].innerHTML || ''), '', 'notice cleared');
});

test('denied Start (external): Esc stops before the probe and the reserve', async function () {
  const f = flowHarness({ [CHECK]: DENIED, drspeed_aias_probe_target_stack: PROBE_CLEAN, [RESERVE]: RESERVE_STOP });
  f.h.els['cu-included-urls'].value = 'https://example.net/a';
  f.h.els['cu-btn-next-1'].click(); await settle();
  const dlg = f.h.body.querySelector('.cu-access-denied-dialog');
  assert.ok(dlg, 'stop dialog shown');
  dlg.close(); await settle();   // Esc fires the dialog's close event
  assert.deepStrictEqual(seqOf(f), ['confirm', CHECK]);
  assert.strictEqual(f.h.els['cu-probe-spinner-host'].style.display, 'none', 'spinner hidden');
});

// Re-queue arrange: seed the persisted partial payload BEFORE scanner.js loads, so the
// load-time restorePartialBanner() sets currentPartialInfo and wires #cu-partial-requeue-btn
// to reQueueRemainder — the same path a page reload takes in production.
function requeueHarness(answers) {
  return flowHarness(answers, {
    localStorage: {
      drspeed_aias_partial: JSON.stringify({
        status: 'user_cancel', completed: 1, total: 2, remainder_urls: ['https://example.com/a'],
      }),
    },
  });
}

test('Re-queue denied: no reserve, _inFlight cleared after each Close', async function () {
  const f = requeueHarness({ [CHECK]: DENIED, [RESERVE]: RESERVE_STOP });
  await settle();
  assert.strictEqual(count(f, CHECK), 0, 'restoring the banner sends no check');
  const btn = f.h.els['cu-partial-requeue-btn'];

  btn.click(); await settle();
  assert.strictEqual(count(f, CHECK), 1);
  const dlg1 = f.h.body.querySelector('.cu-access-denied-dialog');
  assert.ok(dlg1, 'stop dialog shown');
  btn.click(); await settle();   // _inFlight guard holds while the dialog is open
  assert.strictEqual(count(f, CHECK), 1, 'no second check while the first re-queue is in flight');
  dlg1.querySelector('.cu-access-close').click(); await settle();
  assert.strictEqual(count(f, RESERVE), 0, 'no reserve after Close');

  btn.click(); await settle();   // _inFlight reset by finally{} → a fresh check fires
  assert.strictEqual(count(f, CHECK), 2, '_inFlight cleared after the first Close');
  const dlg2 = f.h.body.querySelector('.cu-access-denied-dialog');
  assert.ok(dlg2, 'stop dialog shown again');
  dlg2.querySelector('.cu-access-close').click(); await settle();
  assert.strictEqual(count(f, RESERVE), 0, 'no reserve after the second Close');

  btn.click(); await settle();
  assert.strictEqual(count(f, CHECK), 3, '_inFlight cleared after the second Close');
  assert.strictEqual(count(f, RESERVE), 0);
  assert.strictEqual(f.h.els['cu-probe-spinner-host'].style.display, 'none', 'spinner hidden');
});

test('Re-queue allowed: check runs before reserve', async function () {
  const f = requeueHarness({ [CHECK]: OK, [RESERVE]: RESERVE_STOP });
  await settle();
  f.h.els['cu-partial-requeue-btn'].click(); await settle();
  assert.deepStrictEqual(seqOf(f), [CHECK, RESERVE]);
});
