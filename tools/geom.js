/**
 * tools/geom.js - dump a page's box geometry at a width, for an exact
 * before/after comparison.
 *
 * Dev-only, pairs with tools/shoot.js and tools/desktop-guard.js. A pixel diff
 * is the wrong instrument for "did desktop move?": these pages animate, and
 * products_results.php rolls mt_rand() to decide whether a card shows a
 * struck-through price, so two captures of identical code differ by several
 * percent and the full-page height of index.php is not even stable run to run.
 * Boxes are. This prints the position and size of every structural element,
 * which is what a layout regression would actually change.
 *
 *   node tools/geom.js --w=1280 --mode=dark            # every page
 *   node tools/geom.js index.php --w=390 --mode=light
 *
 * Diff two runs with diff(1).
 */
const puppeteer = require('puppeteer-core');

const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const BASE = 'http://localhost/TECHWRLD/public/authenticated/customer/';
const ALL = ['index.php', 'products.php', 'dashboard.php', 'orders.php',
  'notifications.php', 'cart.php', 'checkout.php', 'track_order.php',
  'settings.php', 'support.php', 'ai_builder.php', 'order_success.php'];

const args = process.argv.slice(2);
const flag = (n, d) => {
  const a = args.find(x => x.startsWith(`--${n}=`));
  return a ? a.split('=')[1] : d;
};
const pages = args.filter(a => !a.startsWith('--'));
const width = parseInt(flag('w', '1280'), 10);
const mode = flag('mode', 'dark');
const list = pages.length ? pages : ALL;

(async () => {
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox'] });
  for (const name of list) {
    const page = await browser.newPage();
    await page.setViewport({ width, height: 900 });
    await page.setRequestInterception(true);
    page.on('request', r => {
      if (r.resourceType() === 'media' || /\.(mp4|webm|mp3)(\?|$)/i.test(r.url())) { r.abort(); } else { r.continue(); }
    });
    await page.setCookie({ name: 'TECHWRLD_CUST', value: 'aibcust', domain: 'localhost', path: '/' });
    await page.evaluateOnNewDocument(m => { try { localStorage.setItem('tw-theme', m); } catch (e) {} }, mode);
    try { await page.goto(BASE + name, { waitUntil: 'networkidle2', timeout: 15000 }); }
    catch (e) { await page.goto(BASE + name, { waitUntil: 'domcontentloaded', timeout: 20000 }); await new Promise(r => setTimeout(r, 2000)); }
    await new Promise(r => setTimeout(r, 900));

    if (/\/guest\//.test(page.url())) {
      console.error(`!! ${name}: redirected to the guest site - run php tools/seed-session.php`);
      await browser.close();
      process.exit(3);
    }

    const rows = await page.evaluate(() => {
      /* Every element that holds layout, not every element: the point is to
         catch a box that moved or resized, and a leaf <span> inside a line of
         text moves with its paragraph. Animated transforms are read off the
         untransformed box (offsetTop/offsetHeight) so a frozen-mid-entrance
         element does not read as a layout change. */
      const out = [];
      document.querySelectorAll('body *').forEach(el => {
        if (!el.offsetParent && el.tagName !== 'BODY') { return; }
        const w = el.offsetWidth, h = el.offsetHeight;
        if (w === 0 && h === 0) { return; }
        const id = el.tagName.toLowerCase() +
          (el.id ? '#' + el.id : '') +
          (typeof el.className === 'string' && el.className
            ? '.' + el.className.trim().split(/\s+/).slice(0, 3).join('.') : '');
        out.push(`${id}  x=${el.offsetLeft} y=${el.offsetTop} w=${w} h=${h}`);
      });
      return out;
    });

    console.log(`=== ${name} ${width}px ${mode} : ${rows.length} boxes ===`);
    rows.forEach(r => console.log(r));
    await page.close();
  }
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
