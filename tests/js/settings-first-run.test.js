const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

// The new-install dim on Settings. Operator 2026-11-02: it must not show once a key is in place.
// Validate reloads the page, but a pasted paid key and a bought key arrive with NO reload, so the
// dim has to end in the browser on those two paths. This drives the REAL admin/js/settings.js.

function makeEl(id, classes) {
  const listeners = {};
  const set = new Set(classes || []);
  const el = {
    id, value: '', disabled: false, className: '', style: {}, dataset: {},
    textContent: '', appended: [],
    addEventListener(ev, fn) { (listeners[ev] = listeners[ev] || []).push(fn); },
    fire(ev, arg) { (listeners[ev] || []).forEach((fn) => fn.call(this, arg || { preventDefault() {} })); },
    click() { this.fire('click'); },
    appendChild(child) { this.appended.push(child); return child; },
    querySelector() { return null; },
    removeAttribute() {},
    setAttribute() {},
    classList: {
      add(c) { set.add(c); },
      remove(c) { set.delete(c); },
      toggle(c, on) { if (on) set.add(c); else set.delete(c); },
      contains(c) { return set.has(c); },
    },
  };
  return el;
}

function harness(respond, { dimmed = true, hasKey = '0' } = {}) {
  const els = {};
  ['drspeed-aias-settings-form', 'cu-settings-message', 'cu-credit-balance', 'cu-refresh-balance',
    'cu_api_key', 'cu-balance-card', 'cu-free-key-optin', 'cu-get-free-key'].forEach((id) => { els[id] = makeEl(id); });
  els['drspeed-aias-settings'] = makeEl('drspeed-aias-settings', dimmed ? ['cu-first-run'] : []);
  els['cu-refresh-balance'].dataset.hasKey = hasKey;

  const domReady = [];
  const sandbox = {
    console, setTimeout, clearTimeout, parseInt, isNaN, Promise,
    drspeedAiasSettings: { ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'nonce-1' },
    navigator: { clipboard: { writeText: () => Promise.resolve() } },
    fetch: (url, opts) => respond(opts.body.get('action')),
    FormData: class {
      constructor() { this._d = []; }
      append(k, v) { this._d.push([k, v]); }
      delete(k) { this._d = this._d.filter(([kk]) => kk !== k); }
      get(k) { const h = this._d.find(([kk]) => kk === k); return h ? h[1] : null; }
    },
    document: {
      getElementById: (id) => els[id] || null,
      addEventListener: (ev, fn) => { if (ev === 'DOMContentLoaded') domReady.push(fn); },
      querySelectorAll: () => [],
    },
  };
  sandbox.window = sandbox;
  sandbox.globalThis = sandbox;
  sandbox.window.addEventListener = () => {};
  sandbox.window.location = { reload() {} };

  const code = fs.readFileSync(path.join(__dirname, '../../admin/js/settings.js'), 'utf8');
  vm.createContext(sandbox);
  vm.runInContext(code, sandbox);
  domReady.forEach((fn) => fn());
  return els;
}

const json = (body) => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(body) });
const flush = () => new Promise((r) => setImmediate(r))
  .then(() => new Promise((r) => setImmediate(r)))
  .then(() => new Promise((r) => setImmediate(r)));
const dimmed = (els) => els['drspeed-aias-settings'].classList.contains('cu-first-run');

async function run() {
  // A paid key pasted and saved: no reload happens on this path.
  {
    const els = harness(() => json({ success: true, data: { credits: 42 } }));
    assert.strictEqual(dimmed(els), true, 'the page starts dimmed');
    els['drspeed-aias-settings-form'].fire('submit');
    await flush();
    assert.strictEqual(dimmed(els), false, 'a saved key must end the dim without a reload');
    console.log('OK saving a key ends the dim');
  }

  // A refused key: nothing changed, so the guide stays and the error must be readable.
  {
    const els = harness(() => json({ success: false, data: 'Invalid API key' }));
    els['drspeed-aias-settings-form'].fire('submit');
    await flush();
    assert.strictEqual(dimmed(els), true, 'a refused key must not end the dim');
    assert.ok(els['cu-free-key-optin'].appended.includes(els['cu-settings-message']),
      'the error must move into the bright segment, or it stays out of sight under the dim');
    assert.ok(/Invalid API key/.test(els['cu-settings-message'].textContent));
    console.log('OK a refused key keeps the dim and shows its error in the segment');
  }

  // A failed Validate: the message must also land in the segment.
  {
    const els = harness(() => json({ success: false, data: 'Service unavailable' }));
    els['cu-get-free-key'].click();
    await flush();
    assert.strictEqual(dimmed(els), true, 'a failed Validate must not end the dim');
    assert.ok(els['cu-free-key-optin'].appended.includes(els['cu-settings-message']),
      'a failed Validate must be readable');
    console.log('OK a failed Validate shows its error in the segment');
  }

  // A bought key delivered through the balance check: no reload here either.
  {
    const els = harness((action) => json(action === 'drspeed_aias_fetch_balance'
      ? { success: true, data: { balance: 500, api_key_updated: true } }
      : { success: false, data: 'unused' }), { hasKey: '1' });
    await flush();
    assert.strictEqual(dimmed(els), false, 'a bought key arriving must end the dim');
    console.log('OK a bought key ends the dim');
  }

  // A balance check with no new key: the guide must stay.
  {
    const els = harness(() => json({ success: true, data: { balance: 0 } }), { hasKey: '1' });
    await flush();
    assert.strictEqual(dimmed(els), true, 'an ordinary balance refresh must not end the dim');
    console.log('OK an ordinary balance refresh keeps the dim');
  }

  // Not a first-run page: the message stays in its usual place.
  {
    const els = harness(() => json({ success: false, data: 'Invalid API key' }), { dimmed: false });
    els['drspeed-aias-settings-form'].fire('submit');
    await flush();
    assert.strictEqual(els['cu-free-key-optin'].appended.length, 0,
      'with no dim, the message must not move');
    console.log('OK with no dim the message stays where it was');
  }

  console.log('settings-first-run: all assertions passed');
}

run().catch(function (e) { console.error(e); process.exit(1); });
