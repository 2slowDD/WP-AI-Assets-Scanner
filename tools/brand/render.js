/**
 * Renders the WordPress.org directory assets from .wordpress-org/icon.svg and
 * tools/brand/banner.html. Needs Playwright and the Inter font file next to
 * banner.html (tools/brand/inter.woff2, from Google Fonts, SIL Open Font License).
 *
 *   NODE_PATH=$(npm root -g) [CHROMIUM=/path/to/chrome] node tools/brand/render.js
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '../..');
const out = path.join(root, '.wordpress-org');
const svg = fs.readFileSync(path.join(out, 'icon.svg'), 'utf8');
(async () => {
  const b = await chromium.launch(process.env.CHROMIUM ? { executablePath: process.env.CHROMIUM } : {});
  for (const size of [128, 256]) {
    const p = await b.newPage({ viewport: { width: size, height: size } });
    await p.setContent('<html><body style="margin:0">' + svg.replace('width="256" height="256"', `width="${size}" height="${size}"`) + '</body></html>');
    await p.screenshot({ path: path.join(out, `icon-${size}x${size}.png`), clip: { x: 0, y: 0, width: size, height: size } });
    await p.close();
  }
  const mark = svg.replace('width="256" height="256"', 'width="100%" height="100%"').replace(/<title>[^<]*<\/title>/, '');
  const html = fs.readFileSync(path.join(__dirname, 'banner.html'), 'utf8').replace('<!-- ICON_SVG -->', mark);
  const tmp = path.join(__dirname, '.banner-rendered.html');
  fs.writeFileSync(tmp, html);
  for (const [dsf, name] of [[1, 'banner-772x250.png'], [2, 'banner-1544x500.png']]) {
    const p = await b.newPage({ viewport: { width: 772, height: 250 }, deviceScaleFactor: dsf });
    await p.goto('file://' + tmp); await p.evaluate(() => document.fonts.ready);
    await p.screenshot({ path: path.join(out, name), clip: { x: 0, y: 0, width: 772, height: 250 } });
    await p.close();
  }
  fs.unlinkSync(tmp);
  await b.close();
})();
