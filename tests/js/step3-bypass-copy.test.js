// 1.9.9 — FU-STEP3-BYPASS-LABEL-OVERCLAIMS (spec 2026-11-04 §4 "Copy plumbing"). The Step-3 row's ten
// strings live in the copy map; scanner.js carries the English defaults. Runs the REAL scanner.js.
const assert = require('assert');
const { createHarness } = require('./r3-stage-c-harness');
const MAP = require('./fixtures/copy-map.json');
const KEYS = ['pending_label', 'pending_copy', 'requested_label', 'requested_copy', 'applied_label',
              'applied_copy', 'lost_label', 'lost_copy', 'none_label', 'none_copy'];
const failed = [];
function arm(name, fn) { try { fn(); console.log('OK ' + name); } catch (e) { failed.push(name); console.error('RED ' + name + ': ' + e.message); } }

arm('copy-twin: JS defaults equal the PHP map, exactly ten keys', () => {
  const T = createHarness().sandbox.window.__cuTest;
  assert.ok(MAP.step3_bypass, 'fixture has step3_bypass');
  assert.deepStrictEqual(Object.keys(MAP.step3_bypass).sort(), KEYS.slice().sort());
  assert.deepStrictEqual(JSON.parse(JSON.stringify(T.bannerCopyDefaults.step3_bypass || null)), MAP.step3_bypass);
});

arm('copy-partial: a translation with some keys keeps English for the rest (Review Focus 3)', () => {
  const T = createHarness().sandbox.window.__cuTest;
  assert.strictEqual(typeof T.step3BypassCopy, 'function', 'seam exposes step3BypassCopy');
  const h = createHarness(); h.sandbox.drspeedAiasReasonCopy = { step3_bypass: { lost_label: 'Verloren' } };
  const c = h.sandbox.window.__cuTest.step3BypassCopy();
  assert.strictEqual(c.lost_label, 'Verloren');
  assert.strictEqual(c.lost_copy, MAP.step3_bypass.lost_copy);
});

arm('copy-hostile: non-string values fall back per key (Review Focus 3)', () => {
  const h = createHarness(); h.sandbox.drspeedAiasReasonCopy = { step3_bypass: { applied_label: { x: 1 }, none_copy: 42, requested_label: null } };
  const T = h.sandbox.window.__cuTest;
  assert.strictEqual(typeof T.step3BypassCopy, 'function', 'seam exposes step3BypassCopy');
  const c = T.step3BypassCopy();
  assert.strictEqual(c.applied_label, MAP.step3_bypass.applied_label);
  assert.strictEqual(c.none_copy, MAP.step3_bypass.none_copy);
  assert.strictEqual(c.requested_label, MAP.step3_bypass.requested_label);
});

if (failed.length) { console.error('FAILED arms: ' + failed.join(', ')); process.exit(1); }
console.log('ALL step3-bypass-copy tests passed');
