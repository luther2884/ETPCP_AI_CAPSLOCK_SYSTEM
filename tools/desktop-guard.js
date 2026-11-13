/**
 * tools/desktop-guard.js - prove the responsive pass cannot reach desktop.
 *
 * A pixel diff is the wrong instrument for this question: half the customer
 * pages animate, and products.php rolls mt_rand() to decide whether a card
 * shows a struck-through "original price", so two captures of IDENTICAL code
 * differ by several percent. This asks the browser the question directly
 * instead, at 1280px:
 *
 *   1. Of every CSS rule the responsive pass adds (tw-mobile.css, and the
 *      <style> block mobile_nav.php emits), which ones are inside a media
 *      query that MATCHES at this width?
 *   2. For any that do, which elements do they select, and is every one of
 *      them markup the pass itself added - i.e. display:none furniture?
 *
 * A clean run means desktop is not being restyled, however the pixels land.
 */
const puppeteer = require('puppeteer-core');
const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const BASE = 'http://localhost/TECHWRLD/public/authenticated/customer/';
const PAGES = ['index.php', 'products.php', 'dashboard.php', 'cart.php', 'notifications.php', 'settings.php'];
const WIDTH = parseInt((process.argv.find(a => a.startsWith('--w=')) || '--w=1280').split('=')[1], 10);

(async () => {
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox'] });
  let problems = 0;

  for (const name of PAGES) {
    const page = await browser.newPage();
    await page.setViewport({ width: WIDTH, height: 900 });
    await page.setRequestInterception(true);
    page.on('request', r => { (r.resourceType() === 'media') ? r.abort() : r.continue(); });
    await page.setCookie({ name: 'TECHWRLD_CUST', value: 'aibcust', domain: 'localhost', path: '/' });
    try { await page.goto(BASE + name, { waitUntil: 'networkidle2', timeout: 15000 }); }
    catch (e) { await page.goto(BASE + name, { waitUntil: 'domcontentloaded', timeout: 20000 }); }
    await new Promise(r => setTimeout(r, 600));

    const report = await page.evaluate(() => {
      const out = { matched: [], selected: [], visible: [] };
      // The two sources the responsive pass owns. The inline block is found by
      // the marker comment at the top of mobile_nav.php's <style>.
      const mine = [...document.styleSheets].filter(s =>
        (s.href && /tw-mobile\.css/.test(s.href)) ||
        (!s.href && s.ownerNode && /tw-mnav-sheet|tw-mnav-tabs/.test(s.ownerNode.textContent || '')));

      const walk = (rules, media) => {
        for (const r of rules) {
          if (r.media) {
            const text = [media, r.conditionText || r.media.mediaText].filter(Boolean).join(' and ');
            if (!window.matchMedia(r.conditionText || r.media.mediaText).matches) { continue; }
            out.matched.push(text);
            walk(r.cssRules, text);
          } else if (r.selectorText) {
            if (!media) { out.matched.push('(no media) ' + r.selectorText); }
            let els = [];
            try { els = [...document.querySelectorAll(r.selectorText)]; } catch (e) { continue; }
            for (const el of els) {
              const tag = el.tagName.toLowerCase() +
                (el.id ? '#' + el.id : '') +
                (typeof el.className === 'string' && el.className ? '.' + el.className.trim().split(/\s+/)[0] : '');
              out.selected.push(tag + '   <- ' + r.selectorText);
              if (getComputedStyle(el).display !== 'none') { out.visible.push(tag + '   <- ' + r.selectorText); }
            }
          }
        }
      };
      for (const s of mine) { try { walk(s.cssRules, ''); } catch (e) { out.matched.push('UNREADABLE SHEET'); } }
      out.matched = [...new Set(out.matched)];
      out.selected = [...new Set(out.selected)];
      out.visible = [...new Set(out.visible)];
      return out;
    });

    console.log(`\n=== ${name} @ ${WIDTH}px ===`);
    console.log(`  media blocks that match here: ${report.matched.length}`);
    report.matched.forEach(m => console.log(`     ${m}`));
    console.log(`  elements they select: ${report.selected.length}`);
    report.selected.forEach(s => console.log(`     ${s}`));
    console.log(`  of those, NOT display:none: ${report.visible.length}`);
    report.visible.forEach(s => console.log(`     !! ${s}`));
    problems += report.visible.length;

    await page.close();
  }
  await browser.close();
  console.log(`\n---- ${problems} rendered elements touched by the responsive pass at ${WIDTH}px ----`);
  process.exit(problems ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
