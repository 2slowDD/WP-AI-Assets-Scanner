// FU-AAS-OUTBOX-CANNOT-BE-CANCELLED — Cancel Scan while the scan is queued LOCALLY (outbox).
//
// Before the fix the Cancel click fetched `null/jobs/null/status`, posted drspeed_aias_cancel_job
// (which answered "No active scan"), alerted "Could not cancel — please try again." and resumed
// polling a job that does not exist, while the outbox kept re-reserving and resubmitting on cron.
//
// Runs the REAL admin/js/scanner.js: the page loads with drspeedAias.outbox.state === 'queued'
// (the PHP-localised re-attach path), so the banner + 30 s tick are the production ones.
const assert = require('assert');
const { createHarness } = require('./r3-stage-c-harness');

function queuedHarness(response) {
  const seen = { cancelPosted: 0, statusFetched: 0, confirms: [], alerts: [] };
  const h = createHarness({
    drspeedAias: { ajaxUrl: '', nonce: 'n', siteUrl: 's', outbox: { state: 'queued', next_attempt_at: 0 } },
    confirm: (msg) => { seen.confirms.push(String(msg)); return true; },
    alert: (msg) => { seen.alerts.push(String(msg)); },
    fetch: (url, opt) => {
      if (/\/jobs\/.*\/status/.test(String(url))) seen.statusFetched++;
      if (String(opt && opt.body).indexOf('drspeed_aias_cancel_job') !== -1) {
        seen.cancelPosted++;
        return Promise.resolve({ json: () => Promise.resolve(response) });
      }
      return Promise.resolve({ ok: true, json: () => Promise.resolve({}) });
    },
  });
  return { h, seen };
}

const liveTicks = (h) => h.timers.filter((t) => t.type === 'interval' && t.ms === 30000 && !t.cleared).length;

function queuedCancelSucceeds() {
  const { h, seen } = queuedHarness({ success: true, data: { pages_completed: 0, queued_cancelled: true } });
  assert.strictEqual(liveTicks(h), 1, 'precondition: the outbox tick is running after re-attach');
  assert.notStrictEqual(h.els['cu-outbox-banner'].style.display, 'none', 'precondition: queued banner shown');

  h.els['cu-btn-cancel'].click();
  return new Promise((resolve) => setImmediate(() => {
    assert.strictEqual(seen.statusFetched, 0, 'no worker status fetch — there is no worker job to ask');
    assert.strictEqual(seen.cancelPosted, 1, 'drspeed_aias_cancel_job posted once');
    assert.strictEqual(seen.confirms.length, 1, 'one confirm, in queued wording');
    assert.ok(/queued/i.test(seen.confirms[0]), 'confirm names the queued scan: ' + seen.confirms[0]);
    assert.ok(!/charge you for/.test(seen.confirms[0]), 'no "charge you for N pages" wording for a scan that never ran');
    assert.deepStrictEqual(seen.alerts, ['Queued scan cancelled. No credits were charged.'], 'approved copy, verbatim');
    assert.strictEqual(liveTicks(h), 0, 'outbox tick stopped');
    assert.strictEqual(h.els['cu-outbox-banner'].style.display, 'none', 'queued banner hidden');
    assert.strictEqual(h.els['drspeed-aias-app'].getAttribute('data-current-step'), '1', 'back on Step 1');
    assert.strictEqual(h.timers.filter((t) => t.type === 'interval' && t.ms !== 30000 && !t.cleared).length, 0,
                       'no scan-status polling started for a job that does not exist');
    console.log('OK cancel-queued-scan-success');
    resolve();
  }));
}

function queuedCancelRefused() {
  // Server says no (e.g. a cron replay holds the outbox lock this second): keep the queued
  // state — banner, tick — and tell the user to retry; never pretend it was cancelled.
  const { h, seen } = queuedHarness({ success: false, data: { message: 'Could not cancel the queued scan — please try again.' } });
  h.els['cu-btn-cancel'].click();
  return new Promise((resolve) => setImmediate(() => {
    assert.strictEqual(seen.cancelPosted, 1);
    assert.deepStrictEqual(seen.alerts, ['Could not cancel the queued scan — please try again.']);
    assert.strictEqual(liveTicks(h), 1, 'outbox tick still running');
    assert.notStrictEqual(h.els['cu-outbox-banner'].style.display, 'none', 'queued banner still shown');
    console.log('OK cancel-queued-scan-refused');
    resolve();
  }));
}

function runningScanCancelUnchanged() {
  // Negative control: with no outbox entry the Cancel click still takes the worker path —
  // status fetch for the confirm wording, then drspeed_aias_cancel_job. Guards the branch
  // from being keyed on anything but the queued state.
  const seen = { statusFetched: 0, cancelPosted: 0, confirms: [] };
  const h = createHarness({
    confirm: (msg) => { seen.confirms.push(String(msg)); return false; }, // decline — just observe the path
    fetch: (url, opt) => {
      if (/\/jobs\/.*\/status/.test(String(url))) {
        seen.statusFetched++;
        return Promise.resolve({ ok: true, json: () => Promise.resolve({ status: 'in_progress', completed: 2, total: 5, pages: [] }) });
      }
      if (String(opt && opt.body).indexOf('drspeed_aias_cancel_job') !== -1) seen.cancelPosted++;
      return Promise.resolve({ ok: true, json: () => Promise.resolve({}) });
    },
  });
  h.els['cu-btn-cancel'].click();
  return new Promise((resolve) => setImmediate(() => {
    assert.strictEqual(seen.statusFetched, 1, 'worker path: progress fetched for the confirm');
    assert.ok(/charge you for 2 pages/.test(seen.confirms[0] || ''), 'worker confirm wording: ' + seen.confirms[0]);
    assert.strictEqual(seen.cancelPosted, 0, 'declined confirm posts nothing');
    console.log('OK cancel-queued-scan-negative-control');
    resolve();
  }));
}

queuedCancelSucceeds().then(queuedCancelRefused).then(runningScanCancelUnchanged);
