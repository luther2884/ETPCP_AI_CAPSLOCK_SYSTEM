/**
 * tools/clip.js - screenshot ONE element, so a detail is not lost in a
 * 15000px full-page capture.
 *
 *   node tools/clip.js <url-relative-to-public> <selector> [--w=390] [--mode=dark] [--out=name]
 *
 * Dev-only, same engine and session seeding as tools/shoot.js.
 */
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer-core');

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const ROOT = 'http://localhost/TECHWRLD/public/';
const OUT = path.join(__dirname, '..', '.tmp', 'shots');

const args = process.argv.slice(2);
const flag = (n, d) => { const a = args.find(x => x.startsWith(`--${n}=`)); return a ? a.split('=')[1] : d; };
const pos = args.filter(a => !a.startsWith('--'));
const [pageRel, selector] = pos;
const width = parseInt(flag('w', '390'), 10);
const mode = flag('mode', 'dark');

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await puppeteer.launch({
    executablePath: CHROME, headless: 'new',
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });
  const page = await browser.newPage();
  await page.setViewport({ width, height: 900, deviceScaleFactor: 2, isMobile: width < 768, hasTouch: width < 768 });
  await page.setRequestInterception(true);
  page.on('request', r => {
    if (r.resourceType() === 'media' || /\.(mp4|webm|mp3)(\?|$)/i.test(r.url())) r.abort(); else r.continue();
  });
  await page.setCookie({ name: 'TECHWRLD_CUST', value: 'aibcust', domain: 'localhost', path: '/' });
  await page.evaluateOnNewDocument(m => { try { localStorage.setItem('tw-theme', m); } catch (e) {} }, mode);
  // networkidle2 first for late images/fonts; fall back to domcontentloaded
  // plus a settle, because the landing page keeps a few long-poll style
  // requests open and the network never actually goes idle there.
  try { await page.goto(ROOT + pageRel, { waitUntil: 'networkidle2', timeout: 15000 }); }
  catch (e) {
    try { await page.goto(ROOT + pageRel, { waitUntil: 'domcontentloaded', timeout: 45000 }); }
    catch (e2) { /* already committed; measure what rendered */ }
    await new Promise(r => setTimeout(r, 2500));
  }

  // Entrance animations fade content in from opacity 0; finish them before
  // capturing or the shot (and any contrast reading) measures a mid-fade frame.
  await page.addStyleTag({ content: '*,*::before,*::after{animation-duration:.001s!important;animation-delay:0s!important;transition-duration:.001s!important}' });
  await new Promise(r => setTimeout(r, 700));

  const el = await page.$(selector);
  if (!el) { console.error('selector not found: ' + selector); process.exit(1); }
  await el.scrollIntoView();
  await new Promise(r => setTimeout(r, 300));
  const file = path.join(OUT, `${flag('out', 'clip')}.png`);
  await el.screenshot({ path: file });
  const box = await el.boundingBox();
  console.log(`saved ${file}  (${Math.round(box.width)}x${Math.round(box.height)})`);
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
