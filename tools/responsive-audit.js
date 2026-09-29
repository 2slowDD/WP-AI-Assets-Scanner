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
const widths = [1920, 1440, 1366, 1352, 1300, 1280, 1260, 1240, 1226, 1210, 1200, 1190, 1181, 1180, 1100, 1044, 1043, 1024, 1000, 961, 960, 900, 860, 820, 800, 790, 783, 782, 760, 740, 720, 700, 680, 660, 640, 600, 540, 480, 390, 360];
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
          // Content spilling out of its own cell (overlapping the next column).
          if (el.matches('th, td, .cu-th-inner') && cs.overflowX === 'visible' && el.scrollWidth > el.clientWidth + 1) offenders.push('SPILL ' + el.tagName.toLowerCase() + ' "' + el.textContent.trim().slice(0, 30) + '" ' + el.scrollWidth + '>' + el.clientWidth);
          if (/(auto|scroll)/.test(cs.overflowX) && el.scrollWidth > el.clientWidth + 1) scrollers.push((el.id ? '#' + el.id : '.' + [...el.classList].join('.')) + ' ' + el.scrollWidth + '>' + el.clientWidth);
        }
        // Collisions: visible neighbours in a flex or grid row that overlap on screen.
        for (const c of root.querySelectorAll('*')) {
          const ccs = getComputedStyle(c);
          if (!/(flex|grid)/.test(ccs.display) || c.children.length < 2 || c.children.length > 16) continue;
          const kids = [...c.children].filter((k) => { const kc = getComputedStyle(k); const kr = k.getBoundingClientRect(); return kr.width > 0 && kr.height > 0 && kc.visibility !== 'hidden' && kc.position !== 'absolute' && kc.position !== 'fixed' && !k.matches('.screen-reader-text'); });
          for (let i = 0; i < kids.length; i++) for (let j = i + 1; j < kids.length; j++) {
            const a = kids[i].getBoundingClientRect(), b = kids[j].getBoundingClientRect();
            const ox = Math.min(a.right, b.right) - Math.max(a.left, b.left), oy = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);
            if (ox > 2 && oy > 2) offenders.push('COLLIDE ' + (kids[i].id ? '#' + kids[i].id : kids[i].tagName.toLowerCase() + '.' + [...kids[i].classList].join('.')) + ' x ' + (kids[j].id ? '#' + kids[j].id : kids[j].tagName.toLowerCase() + '.' + [...kids[j].classList].join('.')));
          }
        }
        // Text collisions: compare the drawn boxes of text runs (Range rects), which also
        // catches content moved by relative positioning, transforms or negative margins.
        const runs = [];
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, { acceptNode: (n) => n.textContent.trim() ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT });
        for (let n = walker.nextNode(); n; n = walker.nextNode()) {
          const host = n.parentElement;
          if (!host || host.closest('.screen-reader-text, .cu-help-box, [hidden], script, style, template')) continue;
          const hcs = getComputedStyle(host);
          if (hcs.visibility === 'hidden' || hcs.display === 'none' || +hcs.opacity === 0) continue;
          const range = document.createRange(); range.selectNodeContents(n);
          for (const r of range.getClientRects()) if (r.width > 1 && r.height > 1) runs.push({ r, host, text: n.textContent.trim().slice(0, 18) });
        }
        const seen = new Set();
        for (let i = 0; i < runs.length; i++) for (let j = i + 1; j < runs.length; j++) {
          if (runs[i].host === runs[j].host) continue;
          const a = runs[i].r, b = runs[j].r;
          const ox = Math.min(a.right, b.right) - Math.max(a.left, b.left), oy = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);
          if (ox > 2 && oy > 3) { const k = runs[i].text + '|' + runs[j].text; if (!seen.has(k)) { seen.add(k); offenders.push('TEXT-COLLIDE "' + runs[i].text + '" x "' + runs[j].text + '"'); } }
        }
        const th = document.querySelector('#cu-result-url-list .cu-url-table thead');
        const mode = th ? (getComputedStyle(th).display === 'none' ? 'cards' : 'table') : null;
        return { docOverflow, offenders: offenders.slice(0, 8), scrollers, mode };
      });
      // Tooltips: open each help marker by keyboard focus, as a user would, and check its box.
      const helps = await p.$$('.cu-wrap .cu-help');
      for (const h of helps) {
        if (!(await h.isVisible())) continue;
        await h.scrollIntoViewIfNeeded(); await h.focus(); await p.waitForTimeout(60);
        const t = await h.evaluate((el) => {
          // The scanner shows help text in one viewport-level popover; other screens show the box in place.
          const pop = document.querySelector('.cu-help-popover:not([hidden])');
          const inPlace = el.querySelector('.cu-help-box');
          const box = (pop && pop.getBoundingClientRect().width) ? pop : inPlace;
          if (!box) return null;
          const r = box.getBoundingClientRect(); const vw = document.documentElement.clientWidth; const vh = window.innerHeight;
          if (!r.width) return 'TOOLTIP "' + el.textContent.trim().slice(0, 24) + '" did not open';
          // The tooltip must not hide its own trigger.
          const tr = el.getBoundingClientRect();
          const coversTrigger = !(r.right <= tr.left || r.left >= tr.right || r.bottom <= tr.top || r.top >= tr.bottom);
          if (coversTrigger) return 'TOOLTIP "' + box.textContent.trim().slice(0, 24) + '" covers its trigger';
          if (r.bottom > vh + 1 || r.top < 0) return 'TOOLTIP "' + box.textContent.trim().slice(0, 24) + '" off-screen vertically';
          // Clipped by an ancestor that hides overflow?
          let clip = null;
          for (let a = el.parentElement; a && a !== document.body; a = a.parentElement) {
            const cs = getComputedStyle(a);
            if (/(hidden|clip|auto|scroll)/.test(cs.overflowX + cs.overflowY)) { const ar = a.getBoundingClientRect(); if (r.left < ar.left - 1 || r.right > ar.right + 1 || r.top < ar.top - 1 || r.bottom > ar.bottom + 1) { clip = (a.id ? '#' + a.id : '.' + [...a.classList].join('.')); break; } }
          }
          const off = r.left < 0 || r.right > vw + 1;
          return (off || clip) ? 'TOOLTIP "' + box.textContent.trim().slice(0, 24) + '" ' + (off ? 'off-screen [' + Math.round(r.left) + ',' + Math.round(r.right) + '] ' : '') + (clip ? 'clipped by ' + clip : '') : null;
        });
        if (t) r.offenders.push(t);
        await h.evaluate((el) => el.blur());
      }
      results.push({ w, slug, ...r });
      if ([1440, 1024, 782, 390].includes(w)) await p.screenshot({ path: `${OUT}/audit-${w}-${slug}.png`, fullPage: true });
    }
  }
  // Layout stability: as the window narrows the results may switch table -> cards once,
  // never back and forth (a flip-flop means a breakpoint tracks the wrong width).
  const modes = results.filter((r) => r.slug === 'STEP4' && r.mode).map((r) => r.w + ':' + r.mode);
  let switches = 0;
  for (let i = 1; i < modes.length; i++) if (modes[i].split(':')[1] !== modes[i - 1].split(':')[1]) switches++;
  if (switches > 1) results.push({ w: 0, slug: 'LAYOUT-FLIP', docOverflow: 0, offenders: ['results layout switches ' + switches + ' times: ' + modes.join(' ')], scrollers: [] });
  for (const r of results) {
    const bad = (typeof r.docOverflow === 'string') || r.docOverflow > 0 || r.offenders.length || r.scrollers.length;
    console.log(`${bad ? 'XX' : 'ok'} ${String(r.w).padStart(4)} ${r.slug.padEnd(22)} pageOverflow=${r.docOverflow}` + (r.offenders.length ? ' | overflow: ' + r.offenders.join(', ') : '') + (r.scrollers.length ? ' | inner scroll: ' + r.scrollers.join(', ') : ''));
  }
  await b.close();
  process.exitCode = results.some(r => typeof r.docOverflow === 'string' || r.docOverflow > 0 || r.offenders.length || r.scrollers.length) ? 1 : 0;
})();
