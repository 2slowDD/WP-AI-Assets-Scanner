const assert = require('assert');
const { createHarness } = require('./r3-stage-c-harness');

// FU-AAS-STEP3-REDIRECTED-LIVE-LABEL (operator 2026-11-22): while a scan runs, the Step-3 live
// row of a page the worker did not scan because its document redirected elsewhere reads
// "✗ Not scanned: redirected", not the bare "✗ Error". Fixed text only — the redirect path is
// untrusted worker input and is shown (validated, escaped) on the results screen, never here.
//
// P17: drives the real path — handleStatusUpdate('in_progress') -> the live tbody rows.

function tbodyRows(h) { return h.els['cu-pages-tbody'].children; }

function run() {
  const h = createHarness();
  const T = h.sandbox.window.__cuTest;
  const submitted = ['https://x.test/checkout/', 'https://x.test/a', 'https://x.test/b', 'https://x.test/c'];
  T.setScanUrlsForTest(submitted, {});
  T.handleStatusUpdate({
    status: 'in_progress', completed: 3, total: 4,
    pages: [
      { status: 'error', url: 'https://x.test/checkout/', error_reason: 'redirected', redirected_to: '/<img src=x onerror=1>/' },
      { status: 'error', url: 'https://x.test/a', error_reason: 'redirected', redirected_off_site: 1 },
      { status: 'error', url: 'https://x.test/b', error_reason: 'page_time_limit' },
      { status: 'done',  url: 'https://x.test/c' },
    ],
  });
  const rows = tbodyRows(h);
  assert.strictEqual(rows.length, 4, 'all 4 rows rendered');
  assert.ok(rows[0]._html.includes('✗ Not scanned: redirected'), 'redirected (on-site) row: ' + rows[0]._html);
  assert.ok(rows[1]._html.includes('✗ Not scanned: redirected'), 'redirected (off-site) row: ' + rows[1]._html);
  assert.ok(!rows[0]._html.includes('img') && !rows[0]._html.includes('onerror'), 'the untrusted path never reaches the live row');
  assert.ok(rows[2]._html.includes('✗ Error') && !rows[2]._html.includes('redirected'), 'other errors keep "✗ Error"');
  assert.ok(rows[3]._html.includes('✓ Done'), 'done row unchanged');
  console.log('OK redirected live rows read "✗ Not scanned: redirected"; other errors keep "✗ Error"; no path rendered');
}

try { run(); } catch (e) { console.error(e); process.exit(1); }
