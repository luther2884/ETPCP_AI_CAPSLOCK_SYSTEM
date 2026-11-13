/**
 * tools/shoot.js - screenshot and measure customer pages in a real browser.
 *
 * Dev-only. Drives the Chrome already installed on this machine through
 * puppeteer-core (no bundled Chromium), so it checks the same engine the shop
 * is actually viewed in. Nothing here ships: node_modules/ is gitignored and
 * nothing in public/ imports this.
 *
 *   node tools/shoot.js <page> [--w=390] [--mode=light|dark] [--out=name]
 *   node tools/shoot.js --all --w=360,390,430,820,1280 --mode=light,dark
 *
 * --w and --mode take comma-separated lists, so a whole matrix (5 widths x
 * 2 modes x 12 pages) runs inside ONE browser launch instead of one launch
 * per combination. --out only names the file for a single capture; in a
 * matrix the name is derived from page/width/mode as before.
 *
 * It logs in once with a seeded session cookie rather than driving the OTP
 * screen, and reports the four things that are hard to judge from CSS alone:
 * horizontal overflow, elements narrower than 44px that are meant to be
 * tapped, text colour contrast, and content sitting under one of the fixed
 * overlays (the bottom tab bar, the scroll-to-top button).
 */
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer-core');

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = 'http://localhost/TECHWRLD/public/authenticated/customer/';
const OUT = path.join(__dirname, '..', '.tmp', 'shots');

// --all: every customer page the responsive pass covers, in reading order.
const ALL_PAGES = [
  'index.php', 'products.php', 'dashboard.php', 'orders.php',
  'notifications.php', 'cart.php', 'checkout.php', 'track_order.php',
  'settings.php', 'support.php', 'ai_builder.php', 'order_success.php',
];

const args = process.argv.slice(2);
const flag = (n, d) => {
  const a = args.find(x => x.startsWith(`--${n}=`));
  return a ? a.split('=')[1] : d;
};
const list = (n, d) => flag(n, d).split(',').map(s => s.trim()).filter(Boolean);
let pages = args.filter(a => !a.startsWith('--'));
if (args.includes('--all')) { pages = ALL_PAGES; }
const widths = list('w', '390').map(w => parseInt(w, 10));
const modes = list('mode', 'dark');
const single = pages.length === 1 && widths.length === 1 && modes.length === 1;

/* The measurement, run inside the page. Serialised by page.evaluate, so it
   closes over nothing in this file. */
function measure() {
  const out = { overflow: null, smallTaps: [], lowContrast: [], overlaps: [] };

  // 1. Horizontal overflow, and what is causing it.
  const de = document.documentElement;
  if (de.scrollWidth > de.clientWidth + 1) {
    const guilty = [...document.querySelectorAll('*')]
      .filter(el => {
        const r = el.getBoundingClientRect();
        return r.width > 0 && (r.right > de.clientWidth + 1 || r.left < -1);
      })
      .slice(0, 6)
      .map(el => {
        const r = el.getBoundingClientRect();
        return `${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}${el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : ''} [${Math.round(r.left)}..${Math.round(r.right)}]`;
      });
    out.overflow = { scrollWidth: de.scrollWidth, clientWidth: de.clientWidth, guilty };
  }

  // 2. Tap targets under 44px that are genuinely interactive and visible.
  [...document.querySelectorAll('a,button,input,select,[role="tab"],[role="button"]')].forEach(el => {
    const r = el.getBoundingClientRect();
    const cs = getComputedStyle(el);
    if (r.width === 0 || r.height === 0) return;
    if (cs.display === 'none' || cs.visibility === 'hidden' || cs.opacity === '0') return;
    /* A 1x1 box is the visually-hidden pattern, not a tap target: the real
       control is the <label> styled beside it, which is measured on its own
       (checkout.php's delivery and payment radios, ai_builder.php's native
       <select> behind a custom one). Flagging these would mean "fixing"
       something that is deliberately not on screen. */
    if (r.width <= 2 && r.height <= 2) return;
    if (r.height < 44 || r.width < 44) {
      out.smallTaps.push({
        el: el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') +
            (typeof el.className === 'string' && el.className ? '.' + el.className.trim().split(/\s+/)[0] : ''),
        w: Math.round(r.width), h: Math.round(r.height),
      });
    }
  });

  // 3. Contrast. Only text nodes with real content, against the nearest
  //    opaque background we can resolve.
  const lum = c => {
    const s = c.map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
    return 0.2126 * s[0] + 0.7152 * s[1] + 0.0722 * s[2];
  };
  const parse = s => {
    const m = s.match(/[\d.]+/g);
    if (!m) return null;
    const v = m.map(Number);
    return [v[0], v[1], v[2], v.length > 3 ? v[3] : 1];
  };
  const over = (fg, bg) => [0, 1, 2].map(i => fg[i] * fg[3] + bg[i] * (1 - fg[3]));

  /* Two things this used to get wrong, both of which invented failures by the
     hundred rather than finding them.

     1. The walk stopped one element short of <html>, and fell back to white.
        Every customer page paints its canvas on <html> (rgb(6,13,24) dark,
        rgb(242,245,250) light) and leaves <body> transparent, so in dark mode
        every light-on-dark string was measured against white: ~1.1:1 on text
        that is in fact perfectly legible.

     2. A translucent background was read as though it were opaque - the alpha
        was parsed and thrown away. A status pill painted rgba(224,86,122,.16)
        over a dark card was treated as solid #e0567a, so pink text on it came
        back at 1:1 when the pill is really a 16% wash.

     Both are fixed by collecting the layers and compositing them: every
     translucent background is blended over the one behind it until an opaque
     one is reached, and the text colour is blended over the result. */
  const bgOf = el => {
    const layers = [];
    let n = el;
    while (n) {
      const s = getComputedStyle(n);
      /* A gradient under the text means there is no one colour to measure
         against - the arrow chip inside the CTA, the active pagination pill.
         Those come back unmeasurable (null) rather than as a fabricated
         failure, and are judged in the shots instead. <body> and <html> are
         exempt: every page paints a soft radial wash on the body over an
         opaque <html>, and treating that as unmeasurable would silently skip
         almost every string on the site. */
      if (n !== document.body && n !== document.documentElement &&
          /gradient/.test(s.backgroundImage)) { return null; }
      const c = parse(s.backgroundColor);
      if (c && c[3] > 0) {
        layers.push(c);
        if (c[3] >= 1) break;
      }
      n = n.parentElement;
    }
    let base = (layers.length && layers[layers.length - 1][3] >= 1)
      ? layers.pop().slice(0, 3)
      : [255, 255, 255];
    for (let i = layers.length - 1; i >= 0; i--) { base = over(layers[i], base); }
    return base;
  };
  [...document.querySelectorAll('a,p,span,h1,h2,h3,h4,label,button,small,b,strong,li,td')].forEach(el => {
    const txt = (el.textContent || '').trim();
    if (!txt || txt.length > 120) return;
    if (el.children.length) return;              // leaf text only
    const r = el.getBoundingClientRect();
    if (r.width === 0 || r.height === 0) return;
    const cs = getComputedStyle(el);
    if (cs.visibility === 'hidden' || cs.opacity === '0') return;
    /* Gradient text - background-clip:text with a transparent fill. The
       colour computed here is the one being painted over, not the one you
       see, so any ratio from it is fiction. The headline figures and the
       cart total are drawn this way. */
    if (cs.webkitTextFillColor && /rgba\([^)]*,\s*0\s*\)/.test(cs.webkitTextFillColor)) return;
    /* Text sitting directly on its own gradient (the active pagination pill,
       the CTA's arrow chip). There is no single background colour to measure
       against - backgroundColor computes to transparent and the walk finds the
       page behind the gradient, which is not what the eye sees. Reported as
       unmeasurable rather than as a failure; these are checked in the shots. */
    if (cs.backgroundImage && cs.backgroundImage !== 'none') return;
    let fg = parse(cs.color); if (!fg) return;
    const bg = bgOf(el); if (!bg) return;
    // Text can be translucent too (the faded prices this pass is fixing are
    // exactly that), so it is composited over the background it sits on.
    fg = over(fg, bg);
    const L1 = lum(fg), L2 = lum(bg);
    const ratio = (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
    const size = parseFloat(cs.fontSize);
    const bold = parseInt(cs.fontWeight, 10) >= 700;
    const need = (size >= 24 || (size >= 18.66 && bold)) ? 3 : 4.5;
    if (ratio < need) {
      out.lowContrast.push({
        el: el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') +
            (typeof el.className === 'string' && el.className ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : ''),
        txt: txt.slice(0, 40), ratio: +ratio.toFixed(2), need, size: Math.round(size),
      });
    }
  });

  out.smallTaps = out.smallTaps.slice(0, 50);
  out.lowContrast = out.lowContrast.slice(0, 50);
  return out;
}

/* Overlap, measured at one scroll position. Run separately from measure()
   because it has to be called more than once per page - see the note in the
   capture loop on why the two overlays are judged at different positions. */
function overlapAt(which) {
  const out = [];
  const label = el => el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') +
      (typeof el.className === 'string' && el.className ? '.' + el.className.trim().split(/\s+/)[0] : '');
  const vh = window.innerHeight;
  (which === 'bar' ? ['#twMnavTabs'] : ['.scroll-to-top']).forEach(sel => {
    const ov = document.querySelector(sel);
    if (!ov) return;
    const cs = getComputedStyle(ov);
    if (cs.display === 'none' || cs.visibility === 'hidden' || cs.opacity === '0' || ov.hidden) return;
    const a = ov.getBoundingClientRect();
    if (a.width === 0 || a.height === 0) return;
    [...document.querySelectorAll('a,button,p,h1,h2,h3,h4,span,li,td,input,select,label')].forEach(el => {
      if (ov.contains(el) || el.contains(ov)) return;
      // A modal sheet is above both overlays by design, and so is the tab bar
      // itself when the sheet is open.
      if (el.closest('#twMnavSheet,#twMnavDrawer,#pfSheet,.tw-mnav-top')) return;
      const txt = (el.textContent || '').trim();
      const interactive = /^(a|button|input|select)$/.test(el.tagName.toLowerCase());
      if (!txt && !interactive) return;
      if (el.children.length && !interactive) return;
      const s = getComputedStyle(el);
      if (s.display === 'none' || s.visibility === 'hidden' || s.opacity === '0') return;
      const b = el.getBoundingClientRect();
      if (b.width === 0 || b.height === 0) return;
      if (b.bottom < 0 || b.top > vh) return;            // off screen vertically
      const ox = Math.min(a.right, b.right) - Math.max(a.left, b.left);
      const oy = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);
      if (ox > 2 && oy > 2) {
        out.push({ over: sel, el: label(el), txt: txt.slice(0, 30), area: Math.round(ox * oy) });
      }
    });
  });
  return out.slice(0, 20);
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });

  let findings = 0;

  /* --scenes runs a list of named states - "open this, scroll there, call the
     file that" - through the SAME browser as everything else, so a review set
     (menu sheet open, filter sheet open, footer, hero) is one launch rather
     than one launch per shot. Format, semicolon separated:
       name|page.php|openSelector|scrollPos
     with the last two optional. It composes with --w and --mode as usual. */
  const scenes = flag('scenes', '')
    ? flag('scenes', '').split(';').filter(Boolean).map(spec => {
        const [sceneName, pageName, openSel, scrollPos] = spec.split('|');
        return { sceneName, pageName, openSel: openSel || '', scrollPos: scrollPos || '' };
      })
    : pages.map(p => ({ sceneName: '', pageName: p, openSel: flag('open', ''), scrollPos: flag('scroll', '') }));

  for (const mode of modes) {
  for (const width of widths) {
  for (const scene of scenes) {
    const name = scene.pageName;
    const page = await browser.newPage();
    await page.setViewport({ width, height: 844, deviceScaleFactor: 2, isMobile: width < 768, hasTouch: width < 768 });

    // Block the decorative background video. Headless Chrome aborts and
    // retries that media request indefinitely, so the network never goes idle
    // and pages that embed it (every customer page, via header.php) either
    // time out or get their execution context torn out mid-measurement. The
    // file is fine - 4MB and present - this is purely a headless artifact, and
    // a background video has no bearing on layout, overflow or contrast.
    await page.setRequestInterception(true);
    page.on('request', r => {
      if (r.resourceType() === 'media' || /\.(mp4|webm|mp3)(\?|$)/i.test(r.url())) { r.abort(); }
      else { r.continue(); }
    });

    /* --still freezes every animation and transition before the capture.
       Half the customer pages run ambient loops - floating chips, a brand
       marquee, a blinking caret, pulsing stock pills - so two captures of the
       SAME markup differ by several percent purely on animation phase. That
       noise is larger than the change a desktop regression would make, which
       makes an unfrozen pixel diff useless as proof. Reduced motion is
       emulated as well, because several of those loops are already written to
       stop under it. */
    if (args.includes('--still')) {
      await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: 'reduce' }]);
      await page.evaluateOnNewDocument(() => {
        const s = document.createElement('style');
        s.textContent = '*,*::before,*::after{animation:none!important;' +
          'transition:none!important;caret-color:transparent!important}';
        document.addEventListener('DOMContentLoaded', () => document.head.appendChild(s));
      });
    }

    // The seeded customer session, so this never has to drive the OTP step.
    await page.setCookie({ name: 'TECHWRLD_CUST', value: 'aibcust', domain: 'localhost', path: '/' });
    // Theme is a localStorage choice read by theme.js before first paint.
    await page.evaluateOnNewDocument(m => {
      try { localStorage.setItem('tw-theme', m); } catch (e) {}
    }, mode);

    const url = BASE + name;
    // networkidle2 first, because it waits for late-loading images and fonts.
    // Some pages poll on a timer (payment_qr.php asks whether the payment has
    // landed), so the network never goes idle and this would time out on a
    // page that is in fact fully rendered - fall back to domcontentloaded plus
    // a settle delay rather than failing the capture.
    try {
      await page.goto(url, { waitUntil: 'networkidle2', timeout: 15000 });
    } catch (e) {
      await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 20000 });
      await new Promise(r => setTimeout(r, 2000));
    }
    await new Promise(r => setTimeout(r, 600));   // let entrance animations settle

    /* Stop if the seeded session has gone. require_login() bounces an
       unauthenticated request to the guest login, and guest/login.php then
       writes a fresh, empty session back to the SAME id - so the next request
       is bounced too, and every capture from that point is quietly of the
       guest site while the run still reports success. That happened once; it
       should never be able to happen silently again.
       Fix: php tools/seed-session.php */
    const landed = page.url();
    if (/\/guest\//.test(landed)) {
      console.error(`\n!! ${name}: redirected to ${landed}`);
      console.error('!! The preview session is gone. Re-seed it and run again:');
      console.error('!!   php tools/seed-session.php\n');
      await browser.close();
      process.exit(3);
    }

    // Clicks one control before the capture, so a state that only exists
    // after a tap (the menu sheet, the filter sheet) can be shot too.
    const open = scene.openSel;
    if (open) {
      try {
        await page.evaluate(sel => { const el = document.querySelector(sel); if (el) { el.click(); } }, open);
        await new Promise(r => setTimeout(r, 500));
      } catch (e) { /* control absent on this page - shoot it closed */ }
    }
    // scroll=bottom parks the viewport at the footer, where the tab bar and
    // the scroll-to-top button are most likely to be covering something.
    if (scene.scrollPos === 'bottom') {
      await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
      await new Promise(r => setTimeout(r, 700));
    }

    // Screenshot BEFORE measuring. Some pages navigate or reload shortly after
    // load (payment_qr.php does), which destroys the execution context and
    // would otherwise throw away a capture that had already rendered fine.
    // --dir puts a whole batch in one subfolder, so every page of a run can go
    // through a single browser launch instead of one launch per --out.
    const safePre = name.replace(/\.php.*$/, '').replace(/[^a-z0-9_-]/gi, '_');
    const base = scene.sceneName ? `${scene.sceneName}-${width}-${mode}`
               : (single && flag('out', '')) ? flag('out', '')
               : `${safePre}-${width}-${mode}`;
    const file = path.join(OUT, flag('dir', ''), `${base}.png`);
    fs.mkdirSync(path.dirname(file), { recursive: true });
    await page.screenshot({ path: file, fullPage: flag('full', '1') === '1' });

    let report = { overflow: null, smallTaps: [], lowContrast: [], overlaps: [], note: null };
    try {
      report = Object.assign(report, await page.evaluate(measure));

      /* Overlap is judged at two scroll positions, because the two overlays
         fail in different ways.

         The bottom tab bar is fixed and floats over the page by design: at any
         mid-page scroll there is always something under it, and that something
         scrolls clear a moment later. The only position where content under it
         is genuinely unreachable is the end of the document - which is exactly
         what the body's bottom padding is for. So the bar is checked there and
         nowhere else.

         The scroll-to-top button is different: it covers one patch of card for
         as long as you stay there, so it is checked at a mid-page scroll where
         the grid is in view, and that is the position its ducking behaviour has
         to handle. */
      await page.evaluate(() => window.scrollTo(0, Math.round(document.body.scrollHeight * 0.45)));
      await new Promise(r => setTimeout(r, 450));
      const mid = await page.evaluate(overlapAt, 'fab');
      await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
      await new Promise(r => setTimeout(r, 450));
      const end = await page.evaluate(overlapAt, 'bar');
      report.overlaps = mid.concat(end).slice(0, 20);
    } catch (e) {
      // The page moved under us. The image is already on disk; say so plainly
      // rather than reporting "0 problems" from a measurement that never ran.
      report.note = 'measurement skipped: ' + String(e.message).split('\n')[0];
    }

    if (report.overflow || report.smallTaps.length || report.lowContrast.length || report.overlaps.length) { findings++; }

    console.log(`\n=== ${name}  ${width}px  ${mode} ===`);
    console.log(`  shot: ${file}`);
    if (report.note) { console.log(`  ${report.note}`); }
    console.log(`  horizontal overflow: ${report.overflow ? `YES ${report.overflow.scrollWidth} > ${report.overflow.clientWidth}` : 'none'}`);
    if (report.overflow) report.overflow.guilty.forEach(g => console.log(`     ${g}`));
    console.log(`  tap targets < 44px: ${report.smallTaps.length}`);
    report.smallTaps.forEach(t => console.log(`     ${t.el} ${t.w}x${t.h}`));
    console.log(`  contrast failures: ${report.lowContrast.length}`);
    report.lowContrast.forEach(c => console.log(`     ${c.el || '?'} "${c.txt}" ${c.ratio}:1 (needs ${c.need}) ${c.size}px`));
    console.log(`  overlaps: ${report.overlaps.length}`);
    report.overlaps.forEach(o => console.log(`     ${o.over} covers ${o.el} "${o.txt}" (${o.area}px2)`));

    await page.close();
  }
  }
  }
  await browser.close();
  console.log(`\n---- ${pages.length * widths.length * modes.length} captures, ${findings} with findings ----`);
})().catch(e => { console.error(e); process.exit(1); });
