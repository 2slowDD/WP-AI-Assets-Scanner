const test = require('node:test');
const assert = require('assert');
const { createHarness, makeEl } = require('./r3-stage-c-harness');

// FU-SITE-DENIAL Part C — runAccessGate + stop dialog + own-site notice
// (spec Rev 3.1 §3.3-§4, AC-9 / AC-11 / AC-16, Review Focus 5).
//
// Harness boundary (see r3-stage-c-harness.js): an element's textContent getter does not
// walk markup set through innerHTML, so every copy assertion below reads innerHTML, where
// esc() may have turned an apostrophe into &#39;.
function flush() { return new Promise(function (r) { setImmediate(r); }); }
function harness(extra) {
  const h = createHarness(Object.assign({
    drspeedAias: { ajaxUrl: '', nonce: 'n', siteUrl: 'https://example.com', settingsUrl: '/wp-admin/admin.php?page=drspeed-aias-settings', outbox: { state: 'none' } },
  }, extra || {}));
  h.els['cu-access-notice'] = makeEl('cu-access-notice');
  return h;
}
function spinnerStub() {
  const s = { shown: 0, hidden: 0, messages: [], signal: { stub: true } };
  return { s: s, fn: function (msg) { s.shown++; s.messages.push(msg); return { signal: s.signal, hide: function () { s.hidden++; } }; } };
}
function noticeHtml(h) { return String(h.els['cu-access-notice'].innerHTML || '').trim(); }
function throwingStorage() {
  const boom = function () { throw new Error('SecurityError: storage blocked'); };
  return { getItem: boom, setItem: boom, removeItem: boom, key: boom, get length() { return boom(); } };
}
const DENIED_OWN = { host: 'example.com', own_site: true, verdict: 'denied', requests: 5, status: 425, reference_label: 'Reference ID', reference: 'WAF-425' };
const DENIED_EXT = { host: 'example.net', own_site: false, verdict: 'denied', requests: 5, status: 403, reference_label: null, reference: null };
const GENERAL_OWN = { host: 'www.example.com', own_site: true, verdict: 'refused_general', requests: 5 };
const GENERAL_EXT = { host: 'example.net', own_site: false, verdict: 'refused_general', requests: 5 };
const ok = function (hosts, denied) { return { success: true, data: { hosts: hosts, denied: !!denied } }; };

test('AC-9(a) fail-open: transport error / non-success / malformed → proceed, no dialog, no notice', async function () {
  const h = harness(); const T = h.sandbox.window.__cuTest; const sp = spinnerStub();
  let opened = 0; const openDialog = function () { opened++; return Promise.resolve({ choice: 'stop' }); };
  for (const post of [
    function () { return Promise.reject(new Error('boom')); },
    function () { return Promise.resolve({ ok: false, error: 'nonce_invalid' }); },
    function () { return Promise.resolve({ success: false, data: { hosts: [DENIED_OWN], denied: true } }); },
    function () { return Promise.resolve(-1); },                                   // admin-ajax "-1" body
    function () { return Promise.resolve(null); },
    function () { return Promise.resolve({ success: true, data: null }); },
    function () { return Promise.resolve({ success: true, data: 'denied' }); },     // Review Focus 5: data not an object
    function () { return Promise.resolve({ success: true, data: { hosts: 'denied', denied: true } }); },
    function () { return Promise.resolve({ success: true, data: { hosts: [null, 7, {}], denied: false } }); },   // Review Focus 5
    function () { return Promise.resolve(ok([{ host: 'example.com', own_site: true, verdict: 'unclear', requests: 3 }], false)); }, // budget unclear
  ]) {
    assert.strictEqual(await T.runAccessGate(['https://example.com/'], { post: post, spinner: sp.fn, openDialog: openDialog }), 'proceed');
  }
  assert.strictEqual(opened, 0);
  assert.strictEqual(sp.s.shown, 10);
  assert.strictEqual(sp.s.hidden, sp.s.shown, 'spinner hidden on every path');
  assert.strictEqual(noticeHtml(h), '');
});

test('spinner: spec message, and its abort signal is handed to the POST', async function () {
  const h = harness(); const T = h.sandbox.window.__cuTest; const sp = spinnerStub();
  let seen = null;
  const post = function (action, data, opts) { seen = { action: action, urls: data.urls, signal: opts && opts.signal }; return Promise.resolve(ok([], false)); };
  assert.strictEqual(await T.runAccessGate(['https://example.com/a', 'https://example.net/b'], { post: post, spinner: sp.fn }), 'proceed');
  assert.strictEqual(sp.s.messages[0], 'Checking that the site lets our scanner in…');
  assert.strictEqual(seen.action, 'drspeed_aias_check_scanner_access');
  assert.strictEqual(seen.urls.join(','), 'https://example.com/a,https://example.net/b');
  assert.strictEqual(seen.signal, sp.s.signal, 'Cancel can abort the in-flight check');
});

test('runAccessGate tolerates malformed hosts (Review Focus 5)', async function () {
  const h = harness(); const T = h.sandbox.window.__cuTest;
  const post = function () { return Promise.resolve(ok([null, 7, 'x', { verdict: 'denied' }], true)); };
  let gotHosts = null;
  const r = await T.runAccessGate(['https://example.com/'], { post: post, spinner: spinnerStub().fn,
    openDialog: function (hosts) { gotHosts = hosts; return Promise.resolve({ choice: 'stop' }); } });
  assert.strictEqual(r, 'stop');
  assert.strictEqual(gotHosts.length, 1, 'non-object entries dropped, the denied object kept');

  // The real dialog renders a host with every field missing without throwing.
  const p = T.runAccessGate(['https://example.com/'], { post: post, spinner: spinnerStub().fn });
  await flush();
  const dlg = h.body.querySelector('.cu-access-denied-dialog');
  assert.ok(dlg, 'dialog shown for a field-less denied host');
  assert.doesNotMatch(dlg.innerHTML, /undefined|null/);
  dlg.querySelector('.cu-access-close').click();
  assert.strictEqual(await p, 'stop');
  assert.doesNotThrow(function () { T.buildAccessDeniedHtml([null, {}, { verdict: 'denied', host: 'example.com' }]); });
  assert.doesNotThrow(function () { T.renderAccessNotice([null, 7, { verdict: 'refused_general' }]); });
});

test('AC-9(b) denied → dialog without Continue; Close → stop, dialog removed', async function () {
  const h = harness(); const T = h.sandbox.window.__cuTest;
  const post = function () { return Promise.resolve(ok([DENIED_OWN], true)); };
  const p = T.runAccessGate(['https://example.com/'], { post: post, spinner: spinnerStub().fn });
  await flush();
  const dlg = h.body.querySelector('.cu-access-denied-dialog');
  assert.ok(dlg, 'dialog shown');
  assert.strictEqual(dlg.open, true, 'opened modally');
  assert.strictEqual(dlg.querySelector('.cu-access-continue'), null, 'no Continue button');
  assert.doesNotMatch(dlg.innerHTML, />\s*Continue\s*</);
  // Parsed-from-innerHTML nodes carry their text as child text nodes, which textContent walks.
  assert.strictEqual(dlg.querySelector('.cu-access-recheck').textContent, 'Check again and start scan');
  assert.strictEqual(dlg.querySelector('.cu-access-close').textContent, 'Close');
  dlg.querySelector('.cu-access-close').click();
  assert.strictEqual(await p, 'stop');
  assert.strictEqual(h.body.querySelector('.cu-access-denied-dialog'), null, 'dialog torn down');
  assert.strictEqual(noticeHtml(h), '', 'no notice on stop');
});

test('AC-9(b) Esc (dialog close event) → stop', async function () {
  const h = harness(); const T = h.sandbox.window.__cuTest;
  const post = function () { return Promise.resolve(ok([DENIED_EXT], true)); };
  const p = T.runAccessGate(['https://example.net/'], { post: post, spinner: spinnerStub().fn });
  await flush();
  const dlg = h.body.querySelector('.cu-access-denied-dialog');
  dlg.close();   // what Esc does to a modal <dialog>
  assert.strictEqual(await p, 'stop');
  assert.strictEqual(h.body.querySelector('.cu-access-denied-dialog'), null);
});

test('AC-9(c) recheck: denied → still blocked; error → message, not passing; ok → proceed', async function () {
  const h = harness(); const T = h.sandbox.window.__cuTest;
  const answers = [
    ok([DENIED_OWN], true),
    ok([DENIED_OWN], true),
    'REJECT',   // rejected lazily below, so no unhandled rejection exists before it is awaited
    { success: false, data: { hosts: [], denied: false } },
    { success: true, data: 'x' },   // malformed success: data not an object
    ok([{ host: 'example.com', own_site: true, verdict: 'ok', requests: 2 }], false),
  ];
  let i = 0; const posted = [];
  const post = function (action) { posted.push(action); const a = answers[i++]; return a === 'REJECT' ? Promise.reject(new Error('net')) : Promise.resolve(a); };
  let settled = false;
  const p = T.runAccessGate(['https://example.com/'], { post: post, spinner: spinnerStub().fn, now: function () { return new Date(2026, 10, 6, 14, 52); } });
  p.then(function () { settled = true; });
  await flush();
  const dlg = h.body.querySelector('.cu-access-denied-dialog');
  dlg.querySelector('.cu-access-recheck').click(); await flush(); await flush();
  assert.match(dlg.innerHTML, /Still blocked \(checked at 14:52\)\./);
  dlg.querySelector('.cu-access-recheck').click(); await flush(); await flush();
  assert.match(dlg.innerHTML, /Couldn(?:'|&#39;)t check again — try again in a moment\./);
  assert.doesNotMatch(dlg.innerHTML, /Still blocked/, 'previous extra line replaced');
  assert.strictEqual(settled, false, 'a failed re-check is not treated as passing');
  dlg.querySelector('.cu-access-recheck').click(); await flush(); await flush();
  assert.match(dlg.innerHTML, /Couldn(?:'|&#39;)t check again/, 'success !== true is a failure too');
  assert.strictEqual(settled, false);
  dlg.querySelector('.cu-access-recheck').click(); await flush(); await flush();
  assert.match(dlg.innerHTML, /Couldn(?:'|&#39;)t check again — try again in a moment\./, 'success with non-object data is a failure');
  assert.strictEqual(settled, false, 'a malformed success re-check is not treated as passing');
  assert.ok(h.body.querySelector('.cu-access-denied-dialog'), 'dialog stays open');
  assert.strictEqual(dlg.querySelector('.cu-access-recheck').disabled, false, 'buttons re-enabled after a failure');
  dlg.querySelector('.cu-access-recheck').click(); await flush(); await flush();
  assert.strictEqual(await p, 'proceed');
  assert.strictEqual(h.body.querySelector('.cu-access-denied-dialog'), null);
  assert.strictEqual(posted.length, 6);
  posted.forEach(function (a) { assert.strictEqual(a, 'drspeed_aias_check_scanner_access', 'every recheck is a fresh POST'); });
});

test('AC-9(c) while a re-check runs both buttons are disabled and the primary reads "Checking…"', async function () {
  const h = harness(); const T = h.sandbox.window.__cuTest;
  let release = null; let n = 0;
  const post = function () {
    n++;
    if (n === 1) return Promise.resolve(ok([DENIED_EXT], true));
    return new Promise(function (r) { release = r; });
  };
  const p = T.runAccessGate(['https://example.net/'], { post: post, spinner: spinnerStub().fn });
  await flush();
  const dlg = h.body.querySelector('.cu-access-denied-dialog');
  const recheck = dlg.querySelector('.cu-access-recheck');
  const close = dlg.querySelector('.cu-access-close');
  recheck.click(); await flush();
  assert.strictEqual(recheck.disabled, true);
  assert.strictEqual(close.disabled, true);
  assert.strictEqual(recheck.textContent, 'Checking…');
  release(ok([], false)); await flush(); await flush();
  assert.strictEqual(await p, 'proceed');
});

test('AC-9(d) spinner cancel → abort', async function () {
  const h = harness(); const T = h.sandbox.window.__cuTest; const sp = spinnerStub();
  const post = function () { const e = new Error('aborted'); e.name = 'AbortError'; return Promise.reject(e); };
  let opened = 0;
  assert.strictEqual(await T.runAccessGate(['https://example.com/'], { post: post, spinner: sp.fn, openDialog: function () { opened++; return Promise.resolve({ choice: 'proceed' }); } }), 'abort');
  assert.strictEqual(opened, 0);
  assert.strictEqual(sp.s.hidden, 1);
  assert.strictEqual(noticeHtml(h), '');
});

test('AC-9(e) own-site refused_general → proceed + notice; dismiss persists per host (verbatim key)', async function () {
  const h = harness(); const T = h.sandbox.window.__cuTest;
  const post = function () { return Promise.resolve(ok([GENERAL_OWN, GENERAL_EXT], false)); };
  assert.strictEqual(await T.runAccessGate(['https://example.com/'], { post: post, spinner: spinnerStub().fn }), 'proceed');
  const el = h.els['cu-access-notice'];
  const html = noticeHtml(h);
  assert.match(html, /class="notice notice-warning inline cu-access-notice"/);
  assert.match(html, /Your site refused a test request from this server, for reasons not specific to our scanner\. If this scan fails, see the firewall and CDN steps in <a href="\/wp-admin\/admin\.php\?page=drspeed-aias-settings">AI Assets Scanner settings<\/a>\./);
  assert.match(html, /Don(?:'|&#39;)t show this again for this site/);
  assert.doesNotMatch(html, /x-cu-scanner|CU-Scanner/, 'no header / user-agent advice in the R8 notice');
  el.querySelector('.cu-access-notice-dismiss').click();
  assert.strictEqual(noticeHtml(h), '', 'dismiss clears the notice');
  assert.strictEqual(h.sandbox.localStorage.getItem('cu_suppress_access_notice:www.example.com'), '1', 'key = server host verbatim');
  assert.strictEqual(h.sandbox.localStorage.getItem('cu_suppress_access_notice:example.net'), null, 'external host never stored');
  await T.runAccessGate(['https://example.com/'], { post: post, spinner: spinnerStub().fn });
  assert.strictEqual(noticeHtml(h), '', 'dismissed for this host');
  // A different host string is a different site: no www folding on the client.
  const post2 = function () { return Promise.resolve(ok([{ host: 'example.com', own_site: true, verdict: 'refused_general', requests: 5 }], false)); };
  await T.runAccessGate(['https://example.com/'], { post: post2, spinner: spinnerStub().fn });
  assert.match(noticeHtml(h), /refused a test request/);
});

test('AC-9(e) a later check replaces the notice; a stop or fail-open run clears it', async function () {
  const h = harness(); const T = h.sandbox.window.__cuTest;
  const general = function () { return Promise.resolve(ok([GENERAL_OWN], false)); };
  await T.runAccessGate(['https://example.com/'], { post: general, spinner: spinnerStub().fn });
  assert.match(noticeHtml(h), /refused a test request/);
  await T.runAccessGate(['https://example.com/'], { post: function () { return Promise.reject(new Error('x')); }, spinner: spinnerStub().fn });
  assert.strictEqual(noticeHtml(h), '', 'cleared when another scan starts');
  await T.runAccessGate(['https://example.com/'], { post: general, spinner: spinnerStub().fn });
  assert.match(noticeHtml(h), /refused a test request/);
  await T.runAccessGate(['https://example.com/'], { post: function () { return Promise.resolve(ok([GENERAL_OWN, DENIED_EXT], true)); }, spinner: spinnerStub().fn,
    openDialog: function () { return Promise.resolve({ choice: 'stop' }); } });
  assert.strictEqual(noticeHtml(h), '', 'no notice on stop');
  await T.runAccessGate(['https://example.com/'], { post: function () { return Promise.resolve(ok([DENIED_EXT], true)); }, spinner: spinnerStub().fn,
    openDialog: function () { return Promise.resolve({ choice: 'proceed', data: { hosts: [GENERAL_OWN], denied: false } }); } });
  assert.match(noticeHtml(h), /refused a test request/, 'a passing re-check shows the notice from the re-check result');
});

test('PF8: external-only refused_general stays silent', async function () {
  const h = harness(); const T = h.sandbox.window.__cuTest;
  const post = function () { return Promise.resolve(ok([GENERAL_EXT], false)); };
  assert.strictEqual(await T.runAccessGate(['https://example.net/'], { post: post, spinner: spinnerStub().fn }), 'proceed');
  assert.strictEqual(noticeHtml(h), '');
});

test('PF6: blocked storage still shows the notice and nothing throws', async function () {
  const post = function () { return Promise.resolve(ok([GENERAL_OWN], false)); };
  // (1) every storage method throws
  const h1 = harness(); const T1 = h1.sandbox.window.__cuTest;
  h1.sandbox.localStorage = throwingStorage();
  assert.strictEqual(await T1.runAccessGate(['https://example.com/'], { post: post, spinner: spinnerStub().fn }), 'proceed');
  assert.match(noticeHtml(h1), /refused a test request/);
  assert.doesNotThrow(function () { h1.els['cu-access-notice'].querySelector('.cu-access-notice-dismiss').click(); });
  // (2) merely touching window.localStorage throws (SecurityError in a sandboxed frame)
  const h2 = harness(); const T2 = h2.sandbox.window.__cuTest;
  Object.defineProperty(h2.sandbox, 'localStorage', { configurable: true, get: function () { throw new Error('SecurityError'); } });
  assert.strictEqual(await T2.runAccessGate(['https://example.com/'], { post: post, spinner: spinnerStub().fn }), 'proceed');
  assert.match(noticeHtml(h2), /refused a test request/);
  assert.doesNotThrow(function () { h2.els['cu-access-notice'].querySelector('.cu-access-notice-dismiss').click(); });
});

test('AC-11 copy: one own-site host, reference present', function () {
  const T = harness().sandbox.window.__cuTest;
  const html = T.buildAccessDeniedHtml([DENIED_OWN]);
  assert.match(html, /<h2>Your site&#39;s firewall is blocking our scanner<\/h2>/);
  // Fixed copy is literal (apostrophes as-is); only dynamic values and the title go through esc().
  assert.match(html, /example\.com refused our scanner on every page we checked \(HTTP 425, Reference ID WAF-425\), while letting an ordinary browser request through — so the block is on our scanner's name\. A scan would fail, so it hasn't started\. Nothing has been reserved or charged for these pages\./);
  assert.match(html, /To fix it, ask whoever manages your firewall or hosting to allow requests that carry the <code>x-cu-scanner<\/code> header with your Scanner Secret \(shown in <a href="\/wp-admin\/admin\.php\?page=drspeed-aias-settings">AI Assets Scanner settings<\/a>\)\. We sent this header with the check, so if you already have such a rule, it isn't reaching the firewall that blocked us\. If your firewall can't match headers, ask them instead to remove the rule that blocks user agents containing <code>CU-Scanner<\/code>\./);
  assert.doesNotMatch(html, /owner would need to stop/);
  assert.match(html, /Send them the reference above\./);
  assert.match(html, /More about our scanner: <a href="https:\/\/wpservice\.pro\/bot" target="_blank" rel="noopener">wpservice\.pro\/bot<\/a>/);
  assert.match(html, /Then click &quot;Check again and start scan&quot;\./);
});

test('AC-11 copy: external, no reference → no reference sentence', function () {
  const T = harness().sandbox.window.__cuTest;
  const ext = T.buildAccessDeniedHtml([DENIED_EXT]);
  assert.match(ext, /<h2>This site&#39;s firewall is blocking our scanner<\/h2>/);
  assert.match(ext, /example\.net refused our scanner on every page we checked \(HTTP 403\), while letting/);
  assert.match(ext, /This site's owner would need to stop blocking user agents containing <code>CU-Scanner<\/code>\./);
  assert.doesNotMatch(ext, /reference above/);
  assert.doesNotMatch(ext, /x-cu-scanner|Scanner Secret/, 'own-site fix not shown for an external host');
  assert.match(ext, /rel="noopener"/);
});

test('AC-11 copy: several hosts — list, removal line, own-site fix when any is own-site, titles', function () {
  const T = harness().sandbox.window.__cuTest;
  const mixed = T.buildAccessDeniedHtml([DENIED_OWN, DENIED_EXT]);
  assert.match(mixed, /<h2>Your site&#39;s firewall is blocking our scanner<\/h2>/, 'own-site wording when any listed host is own-site');
  assert.match(mixed, /<li>example\.com — HTTP 425, Reference ID WAF-425<\/li>/);
  assert.match(mixed, /<li>example\.net — HTTP 403<\/li>/);
  assert.match(mixed, /Remove these sites from the selection, or ask their owners to stop blocking our scanner, then start again\./);
  assert.match(mixed, /We sent this header with the check/, 'own-site fix under the list (r3 Minor 8)');
  assert.match(mixed, /Send them the reference above\./);
  const twoExt = T.buildAccessDeniedHtml([DENIED_EXT, Object.assign({}, DENIED_EXT, { host: 'shop.example.net' })]);
  assert.match(twoExt, /<h2>These sites&#39; firewalls are blocking our scanner<\/h2>/, 'plan E5 title');
  assert.doesNotMatch(twoExt, /We sent this header|reference above/);
  assert.match(twoExt, /Then click &quot;Check again and start scan&quot;\./);
});

test('AC-11 escaping: hostile host/status/reference/settingsUrl never reach markup unescaped', function () {
  const T = harness({ drspeedAias: { ajaxUrl: '', nonce: 'n', siteUrl: 'https://example.com', settingsUrl: '"><img src=x onerror=2>', outbox: { state: 'none' } } }).sandbox.window.__cuTest;
  const bad = { host: '<img src=x onerror=1>', own_site: true, verdict: 'denied', status: '<b>', reference_label: '<i>', reference: '"><script>' };
  for (const html of [T.buildAccessDeniedHtml([bad]), T.buildAccessDeniedHtml([bad, DENIED_EXT])]) {
    assert.doesNotMatch(html, /<img|<script>|<b>|<i>|onerror=\d>/);
    assert.match(html, /&lt;img src=x onerror=1&gt;/);
    assert.match(html, /&quot;&gt;&lt;script&gt;/);
  }
  const extraHtml = T.buildAccessDeniedHtml([DENIED_EXT], '<script>x</script>');
  assert.doesNotMatch(extraHtml, /<script>/);
});

test('AC-11 notice escapes the settings URL', async function () {
  const h = harness({ drspeedAias: { ajaxUrl: '', nonce: 'n', siteUrl: 'https://example.com', settingsUrl: '"><img src=x onerror=2>', outbox: { state: 'none' } } });
  h.sandbox.window.__cuTest.renderAccessNotice([GENERAL_OWN]);
  assert.doesNotMatch(noticeHtml(h), /<img/);
  assert.match(noticeHtml(h), /href="&quot;&gt;&lt;img/);
});

test('AC-16 (JS side): isExternalUrl agrees with the PHP own-site rule', function () {
  const T = harness().sandbox.window.__cuTest;
  assert.strictEqual(T.isExternalUrl('https://www.example.com/x'), false);
  assert.strictEqual(T.isExternalUrl('https://shop.example.com/x'), true);
  assert.strictEqual(T.isExternalUrl('https://example.com:8443/x'), true);
  assert.strictEqual(T.isExternalUrl('http://example.com/x'), false);
});
