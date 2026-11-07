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
// `bodies[action]` keeps each request's serialised FormData body, in order. An answer may be
// a function returning a Promise, which lets a test hold a response back.
function flowHarness(answers, extra) {
  const actions = [];
  const bodies = {};
  const h = createHarness(Object.assign({
    drspeedAias: { ajaxUrl: '', nonce: 'n', siteUrl: 'https://example.com', settingsUrl: '/s', outbox: { state: 'none' } },
    confirm: function () { actions.push('confirm'); return true; },
    fetch: function (url, opt) {
      const body = String(opt && opt.body);
      const m = /action=([a-z_]+)/.exec(body);
      const action = m ? m[1] : '?';
      actions.push(action);
      (bodies[action] = bodies[action] || []).push(body);
      const a = answers[action];
      const payload = typeof a === 'function' ? a() : (a || {});
      return Promise.resolve({ ok: true, json: function () { return Promise.resolve(payload); } });
    },
  }, extra || {}));
  h.els['cu-access-notice'] = makeEl('cu-access-notice');
  // PF30 step-4 slot. The step stub below may already have created it at load (showStep runs while
  // scanner.js loads); keep that node so it stays a step-4 child.
  if (!h.els['cu-access-notice-end']) h.els['cu-access-notice-end'] = makeEl('cu-access-notice-end');
  h.sandbox.scrollTo = function () {};
  return { h: h, actions: actions, bodies: bodies };
}
// The `urls` list one request posted (post() serialises arrays as urls[0]=…&urls[1]=…).
function postedUrls(f, action, i) {
  const body = (f.bodies[action] || [])[i || 0];
  assert.ok(body !== undefined, 'request ' + (i || 0) + ' of ' + action + ' was sent');
  const out = [];
  body.split('&').forEach(function (pair) {
    const m = /^urls\[(\d+)\]=(.*)$/.exec(pair);
    if (m) out[Number(m[1])] = decodeURIComponent(m[2]);
  });
  return out;
}
function deferred() {
  let resolve;
  const promise = new Promise(function (r) { resolve = r; });
  return { promise: promise, resolve: resolve };
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

test('mixed Start: the check posts every selected URL, own site and external', async function () {
  const f = flowHarness({ [CHECK]: OK, drspeed_aias_probe_target_stack: PROBE_CLEAN, [RESERVE]: RESERVE_STOP });
  f.h.els['cu-included-urls'].value = 'https://example.com/a\nhttps://example.net/a';
  f.h.els['cu-btn-next-1'].click(); await settle();
  assert.deepStrictEqual(seqOf(f), ['confirm', CHECK, 'drspeed_aias_probe_target_stack', RESERVE]);
  assert.deepStrictEqual(postedUrls(f, CHECK), ['https://example.com/a', 'https://example.net/a']);
  assert.ok(f.bodies[CHECK][0].includes('urls[0]=https%3A%2F%2Fexample.com%2Fa'), 'own-site URL in the check body');
});

test('own-site Start: a second click while the check is pending sends no second check or reserve', async function () {
  const held = deferred();
  const f = flowHarness({ [CHECK]: function () { return held.promise; }, [RESERVE]: RESERVE_STOP });
  f.h.els['cu-included-urls'].value = 'https://example.com/a';
  f.h.els['cu-btn-next-1'].click(); await settle();
  f.h.els['cu-btn-next-1'].click(); await settle();
  f.h.els['cu-btn-next-1-top'].click(); await settle();   // the top button shares the handler
  assert.strictEqual(count(f, CHECK), 1, 'one check while the first is pending');
  held.resolve(OK); await settle();
  assert.strictEqual(count(f, CHECK), 1, 'exactly one check');
  assert.strictEqual(count(f, RESERVE), 1, 'exactly one reserve');
});

test('denied Start: after Close, a new Start click runs a new check', async function () {
  const f = flowHarness({ [CHECK]: DENIED, [RESERVE]: RESERVE_STOP });
  f.h.els['cu-included-urls'].value = 'https://example.com/a';
  f.h.els['cu-btn-next-1'].click(); await settle();
  f.h.body.querySelector('.cu-access-denied-dialog').querySelector('.cu-access-close').click(); await settle();
  f.h.els['cu-btn-next-1'].click(); await settle();
  assert.strictEqual(count(f, CHECK), 2, 'the in-flight flag was reset after Close');
  assert.ok(f.h.body.querySelector('.cu-access-denied-dialog'), 'stop dialog shown again');
  assert.strictEqual(count(f, RESERVE), 0);
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
// Test-side stub: the base harness returns [] for document.querySelectorAll, so showStep()
// toggles nothing. Model the four .cu-step containers (step-1 visible, the rest display:none,
// as in admin/views/scanner-page.php) so showStep() and the spinner's visible-step mount run
// against real elements. Step 4 gets its first four children in markup order —
// #cu-banner-area, #cu-access-notice-end, #cu-bypass-lost-panel, .cu-results-shell — with a nextSibling getter
// (the base stub has none) so "insert right after the banner" can be observed.
function withNextSibling(el) {
  Object.defineProperty(el, 'nextSibling', {
    configurable: true,
    get() {
      const sibs = this.parentNode ? this.parentNode.children : [];
      const i = sibs.indexOf(this);
      return i === -1 ? null : (sibs[i + 1] || null);
    },
  });
  return el;
}
function stepsQuerySelectorAll(selector, els) {
  if (selector !== '.cu-step') return [];
  return [1, 2, 3, 4].map(function (i) {
    const id = 'step-' + i;
    if (!els[id]) els[id] = makeEl(id, 'div');
    if (els[id].style.display === undefined) els[id].style.display = i === 1 ? '' : 'none';
    if (i === 4 && !els[id]._stubbedKids) {
      els[id]._stubbedKids = true;
      const shell = makeEl('', 'div');
      shell.className = 'cu-results-shell';
      els[id].appendChild(withNextSibling(els['cu-banner-area']));
      if (!els['cu-access-notice-end']) els['cu-access-notice-end'] = makeEl('cu-access-notice-end');
      els[id].appendChild(els['cu-access-notice-end']);
      els[id].appendChild(els['cu-bypass-lost-panel']);
      els[id].appendChild(shell);
    }
    return els[id];
  });
}
// Ids/classes of step 4's direct children, in order.
function step4Order(f) {
  return f.h.els['step-4'].children.map(function (c) { return c.id || c.className; });
}
function requeueHarness(answers, extra) {
  return flowHarness(answers, Object.assign({
    localStorage: {
      drspeed_aias_partial: JSON.stringify({
        status: 'user_cancel', completed: 1, total: 2, remainder_urls: ['https://example.com/a'],
      }),
    },
  }, extra || {}));
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
  assert.deepStrictEqual(postedUrls(f, CHECK), ['https://example.com/a'], 'the check carries the remainder URL');
});

// PF25 — the spinner (and its Cancel) mounts in the step that is VISIBLE when the check runs.
// Re-queue runs from step 4 (#cu-banner-area); a host left in hidden step 1 would show nothing
// for up to 20 s. The base harness pre-creates #cu-probe-spinner-host detached, so these tests
// exercise the reuse/move path; the last one deletes it to exercise the create path.
function spinnerHost(f) { return f.h.els['cu-probe-spinner-host']; }
function hasCancel(host) {
  return host.children.some(function (c) { return c && String(c.className || '').includes('cu-probe-spinner-cancel'); });
}

test('Start: while the check is held, the spinner and its Cancel sit in visible step 1', async function () {
  const held = deferred();
  const f = flowHarness({ [CHECK]: function () { return held.promise; }, [RESERVE]: RESERVE_STOP },
    { querySelectorAll: stepsQuerySelectorAll });
  f.h.els['cu-included-urls'].value = 'https://example.com/a';
  f.h.els['cu-btn-next-1'].click(); await settle();
  assert.strictEqual(count(f, CHECK), 1, 'check in flight');
  const host = spinnerHost(f);
  assert.strictEqual(host.parentNode, f.h.els['step-1'], 'spinner host mounted in step-1');
  assert.notStrictEqual(f.h.els['step-1'].style.display, 'none', 'step-1 is the visible step');
  assert.strictEqual(host.style.display, 'flex', 'spinner shown');
  assert.ok(hasCancel(host), 'Cancel control rendered in the host');
  held.resolve(OK); await settle();
});

test('Re-queue: while the check is held, the spinner and its Cancel sit in visible step 4, right under the banner', async function () {
  const held = deferred();
  const f = requeueHarness({ [CHECK]: function () { return held.promise; }, [RESERVE]: RESERVE_STOP },
    { querySelectorAll: stepsQuerySelectorAll });
  await settle();
  assert.strictEqual(f.h.els['step-4'].style.display, 'block', 'restored banner shows step 4');
  assert.strictEqual(f.h.els['step-1'].style.display, 'none', 'step 1 is hidden');
  f.h.els['cu-partial-requeue-btn'].click(); await settle();
  assert.strictEqual(count(f, CHECK), 1, 'check in flight');
  const host = spinnerHost(f);
  assert.strictEqual(host.parentNode, f.h.els['step-4'], 'spinner host mounted in visible step-4, not hidden step-1');
  assert.deepStrictEqual(step4Order(f),
    ['cu-banner-area', 'cu-probe-spinner-host', 'cu-access-notice-end', 'cu-bypass-lost-panel', 'cu-results-shell'],
    'spinner host directly after #cu-banner-area (the Re-queue button), before the results shell');
  assert.strictEqual(host.style.display, 'flex', 'spinner shown');
  assert.ok(hasCancel(host), 'Cancel control rendered in the host');
  held.resolve(OK); await settle();
});

test('Re-queue: a spinner host created on demand is created inside visible step 4', async function () {
  const held = deferred();
  const f = requeueHarness({ [CHECK]: function () { return held.promise; }, [RESERVE]: RESERVE_STOP },
    { querySelectorAll: stepsQuerySelectorAll });
  delete f.h.els['cu-probe-spinner-host'];   // no host in the page yet → showInlineSpinner creates it
  await settle();
  f.h.els['cu-partial-requeue-btn'].click(); await settle();
  assert.strictEqual(count(f, CHECK), 1, 'check in flight');
  const inStep = function (n) {
    return f.h.els['step-' + n].children.filter(function (c) { return c && c.id === 'cu-probe-spinner-host'; });
  };
  assert.strictEqual(inStep(4).length, 1, 'created host appended to step-4');
  assert.strictEqual(inStep(1).length, 0, 'nothing appended to hidden step-1');
  assert.deepStrictEqual(step4Order(f),
    ['cu-banner-area', 'cu-probe-spinner-host', 'cu-access-notice-end', 'cu-bypass-lost-panel', 'cu-results-shell'],
    'spinner host directly after #cu-banner-area (the Re-queue button), before the results shell');
  assert.ok(hasCancel(inStep(4)[0]), 'Cancel control rendered in the host');
  held.resolve(OK); await settle();
});

// PF30 — the own-site refusal notice also lands in step 4's #cu-access-notice-end, driven through
// the real Start / Re-queue clicks (no injected deps: the production renderAccessNotice runs).
const GENERAL = { success: true, data: { hosts: [{ host: 'example.com', own_site: true, verdict: 'refused_general', requests: 5 }], denied: false } };
function slotHtml(f, id) { return String(f.h.els[id].innerHTML || ''); }
function sequence(list) { let i = 0; return function () { return list[Math.min(i++, list.length - 1)]; }; }

test('PF30/PF31 Start: own-site refused_general → notice in both slots, no dismiss; a second Start shows it again', async function () {
  const f = flowHarness({ [CHECK]: GENERAL, [RESERVE]: RESERVE_STOP });
  f.h.els['cu-included-urls'].value = 'https://example.com/a';
  f.h.els['cu-btn-next-1'].click(); await settle();
  assert.deepStrictEqual(seqOf(f), [CHECK, RESERVE], 'the gate proceeded to reserve');
  assert.match(slotHtml(f, 'cu-access-notice'), /Your site refused a test request from this server, for reasons not specific to our scanner\./);
  assert.match(slotHtml(f, 'cu-access-notice-end'), /Your site refused a test request from this server, for reasons not specific to our scanner\./);
  assert.strictEqual(slotHtml(f, 'cu-access-notice-end'), slotHtml(f, 'cu-access-notice'), 'same markup in both slots');
  assert.doesNotMatch(slotHtml(f, 'cu-access-notice-end'), /show this again|<button/i, 'PF31: no dismiss control');
  f.h.els['cu-btn-next-1'].click(); await settle();
  assert.strictEqual(count(f, CHECK), 2, 'second check ran');
  assert.match(slotHtml(f, 'cu-access-notice'), /refused a test request/, 'shown again in step 3');
  assert.match(slotHtml(f, 'cu-access-notice-end'), /refused a test request/, 'shown again in step 4');
});

test('PF30 Start: a later check clears both slots', async function () {
  const f = flowHarness({ [CHECK]: sequence([GENERAL, OK]), [RESERVE]: RESERVE_STOP });
  f.h.els['cu-included-urls'].value = 'https://example.com/a';
  f.h.els['cu-btn-next-1'].click(); await settle();
  assert.match(slotHtml(f, 'cu-access-notice-end'), /refused a test request/);
  f.h.els['cu-btn-next-1'].click(); await settle();
  assert.strictEqual(count(f, CHECK), 2, 'second check ran');
  assert.strictEqual(slotHtml(f, 'cu-access-notice'), '', 'step-3 slot cleared');
  assert.strictEqual(slotHtml(f, 'cu-access-notice-end'), '', 'end slot cleared');
});

test('PF30 Start: external-only refused_general → both slots empty', async function () {
  const ext = { success: true, data: { hosts: [{ host: 'example.net', own_site: false, verdict: 'refused_general', requests: 5 }], denied: false } };
  const f = flowHarness({ [CHECK]: ext, drspeed_aias_probe_target_stack: PROBE_CLEAN, [RESERVE]: RESERVE_STOP });
  f.h.els['cu-included-urls'].value = 'https://example.net/a';
  f.h.els['cu-btn-next-1'].click(); await settle();
  assert.strictEqual(count(f, CHECK), 1);
  assert.strictEqual(slotHtml(f, 'cu-access-notice'), '');
  assert.strictEqual(slotHtml(f, 'cu-access-notice-end'), '');
});

test('PF30 Re-queue: spinner host and end notice are separate step-4 nodes; neither overwrites the other', async function () {
  const held = deferred();
  const f = requeueHarness({ [CHECK]: function () { return held.promise; }, [RESERVE]: RESERVE_STOP },
    { querySelectorAll: stepsQuerySelectorAll });
  await settle();
  f.h.els['cu-partial-requeue-btn'].click(); await settle();
  const host = spinnerHost(f);
  assert.deepStrictEqual(step4Order(f),
    ['cu-banner-area', 'cu-probe-spinner-host', 'cu-access-notice-end', 'cu-bypass-lost-panel', 'cu-results-shell']);
  held.resolve(GENERAL); await settle();
  assert.strictEqual(host.style.display, 'none', 'spinner hidden after the check');
  assert.match(slotHtml(f, 'cu-access-notice-end'), /refused a test request/, 'end notice rendered on the results screen');
  assert.doesNotMatch(String(host.innerHTML || ''), /refused a test request/, 'notice not written into the spinner host');
  assert.ok(f.h.els['cu-partial-requeue-btn'], 'banner area untouched');
  assert.strictEqual(f.h.els['step-4'].children.indexOf(f.h.els['cu-access-notice-end']), 2, 'end slot still a step-4 child after the banner + spinner');
});
