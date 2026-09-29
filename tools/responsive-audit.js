/**
 * Responsive audit: loads every plugin screen at 10 viewport widths and fails on
 * page-level horizontal overflow, elements poking out of their card, inner
 * horizontal scrollbars, and buttons whose label is clipped.
 *
 * Usage (a disposable WordPress with the plugin active, never a live site):
 *   AUDIT_BASE=http://localhost:8088 AUDIT_USER=admin AUDIT_PASS=... \
 *   [AUDIT_RESULT=result.json] [CHROMIUM=/path/to/chrome] \
 *   NODE_PATH=$(npm root -g) node tools/responsive-audit.js <screenshot dir>
 * AUDIT_RESULT is a stored scan result (the drspeed_aias_last_result option as
 * JSON); with it the Step 4 results screen is audited too. Exit code 1 on any failure.
 */
const { chromium } = require('playwright');
const OUT = process.argv[2] || '.';
const BASE = process.env.AUDIT_BASE || 'http://localhost:8088';
const RESULT = process.env.AUDIT_RESULT ? require('fs').readFileSync(process.env.AUDIT_RESULT, 'utf8') : null;
const widths = [1920, 1440, 1280, 1024, 900, 782, 600, 480, 390, 360];
const pages = ['drspeed-aias', 'drspeed-aias-settings', 'drspeed-aias-history'].concat(RESULT ? ['STEP4'] : []);
(async () => {
  const b = await chromium.launch(process.env.CHROMIUM ? { executablePath: process.env.CHROMIUM } : {});
  const ctx = await b.newContext();
  const p = await ctx.newPage();
  await p.goto(BASE + '/wp-login.php');
  await p.fill('#user_login', process.env.AUDIT_USER || 'admin'); await p.fill('#user_pass', process.env.AUDIT_PASS || '');
  await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);
  const results = [];
  for (const w of widths) {
    await p.setViewportSize({ width: w, height: 900 });
    for (const slug of pages) {
      if (slug === 'STEP4') {
        await p.goto(BASE + '/wp-admin/admin.php?page=drspeed-aias');
        await p.evaluate((r) => localStorage.setItem('drspeed_aias_result', r), RESULT);
        await p.reload(); await p.waitForTimeout(800);
        const step = await p.evaluate(() => document.getElementById('drspeed-aias-app').dataset.currentStep);
        if (step !== '4') { results.push({ w, slug, docOverflow: 'STEP4 NOT RESTORED', offenders: [], scrollers: [] }); continue; }
      } else {
        await p.goto(BASE + '/wp-admin/admin.php?page=' + slug);
        await p.waitForTimeout(400);
      }
      const r = await p.evaluate(() => {
        const vw = document.documentElement.clientWidth;
        const docOverflow = document.documentElement.scrollWidth - vw;
        const root = document.querySelector('.cu-wrap') || document.body;
        const offenders = [];
        const scrollers = [];
        for (const el of root.querySelectorAll('*')) {
          const cs = getComputedStyle(el);
          if (cs.display === 'none' || cs.visibility === 'hidden') continue;
          const rect = el.getBoundingClientRect();
          const box = el.parentElement && el.parentElement.closest('.cu-panel, .cu-settings-card, .cu-history-table-card, .cu-header, .cu-wrap');
          const boxRight = box ? box.getBoundingClientRect().right : vw;
          if (rect.width && (rect.right > vw + 1 || rect.right > boxRight + 1) && !el.closest('.screen-reader-text')) offenders.push((el.id ? '#' + el.id : el.tagName.toLowerCase() + '.' + [...el.classList].join('.')) + ' right=' + Math.round(rect.right));
          if ((el.matches('.button, button, input[type=submit]')) && el.scrollWidth > el.clientWidth + 1) offenders.push('CLIPPED ' + (el.id ? '#' + el.id : '.' + [...el.classList].join('.')) + ' ' + el.scrollWidth + '>' + el.clientWidth);
          if (/(auto|scroll)/.test(cs.overflowX) && el.scrollWidth > el.clientWidth + 1) scrollers.push((el.id ? '#' + el.id : '.' + [...el.classList].join('.')) + ' ' + el.scrollWidth + '>' + el.clientWidth);
        }
        return { docOverflow, offenders: offenders.slice(0, 6), scrollers };
      });
      results.push({ w, slug, ...r });
      if ([1440, 1024, 782, 390].includes(w)) await p.screenshot({ path: `${OUT}/audit-${w}-${slug}.png`, fullPage: true });
    }
  }
  for (const r of results) {
    const bad = (typeof r.docOverflow === 'string') || r.docOverflow > 0 || r.offenders.length || r.scrollers.length;
    console.log(`${bad ? 'XX' : 'ok'} ${String(r.w).padStart(4)} ${r.slug.padEnd(22)} pageOverflow=${r.docOverflow}` + (r.offenders.length ? ' | overflow: ' + r.offenders.join(', ') : '') + (r.scrollers.length ? ' | inner scroll: ' + r.scrollers.join(', ') : ''));
  }
  await b.close();
  process.exitCode = results.some(r => typeof r.docOverflow === 'string' || r.docOverflow > 0 || r.offenders.length || r.scrollers.length) ? 1 : 0;
})();
