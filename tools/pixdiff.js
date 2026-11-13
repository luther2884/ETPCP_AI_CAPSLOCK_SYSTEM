/**
 * tools/pixdiff.js - compare two folders of screenshots, pixel for pixel.
 *
 * Dev-only, pairs with tools/shoot.js. Used to prove that a change which was
 * meant to affect only mobile did not move the desktop layout.
 *
 *   node tools/pixdiff.js baseline after
 *
 * Full-page screenshots of the same page can differ in HEIGHT (a taller page
 * means more pixels), so a raw size mismatch is not automatically a failure -
 * it is reported as a height change and the overlapping region is still
 * compared, which is where a real layout shift shows up.
 */
const fs = require('fs');
const path = require('path');
const { PNG } = require('pngjs');
const pixelmatch = require('pixelmatch').default || require('pixelmatch');

const SHOTS = path.join(__dirname, '..', '.tmp', 'shots');
const [aDir, bDir] = process.argv.slice(2);
const A = path.join(SHOTS, aDir || 'baseline');
const B = path.join(SHOTS, bDir || 'after');
const OUT = path.join(SHOTS, 'diff');
fs.mkdirSync(OUT, { recursive: true });

const files = fs.readdirSync(B).filter(f => f.endsWith('.png'));
let worst = 0;
const rows = [];

for (const f of files) {
  const pa = path.join(A, f), pb = path.join(B, f);
  if (!fs.existsSync(pa)) { rows.push({ f, note: 'no baseline' }); continue; }

  const a = PNG.sync.read(fs.readFileSync(pa));
  const b = PNG.sync.read(fs.readFileSync(pb));

  // Compare the region the two images share. Different heights are reported
  // separately rather than failing the comparison outright.
  const w = Math.min(a.width, b.width);
  const h = Math.min(a.height, b.height);
  const crop = (src) => {
    if (src.width === w && src.height === h) return src;
    const out = new PNG({ width: w, height: h });
    PNG.bitblt(src, out, 0, 0, w, h, 0, 0);
    return out;
  };
  const ca = crop(a), cb = crop(b);
  const diff = new PNG({ width: w, height: h });
  const changed = pixelmatch(ca.data, cb.data, diff.data, w, h, { threshold: 0.1 });
  const pct = (changed / (w * h)) * 100;
  if (pct > worst) worst = pct;

  if (changed > 0) fs.writeFileSync(path.join(OUT, f), PNG.sync.write(diff));

  rows.push({
    f,
    pct,
    changed,
    sizeA: `${a.width}x${a.height}`,
    sizeB: `${b.width}x${b.height}`,
    heightDelta: b.height - a.height,
  });
}

rows.sort((x, y) => (y.pct || 0) - (x.pct || 0));
console.log(`\n${aDir || 'baseline'} vs ${bDir || 'after'}\n`);
console.log('  ' + 'file'.padEnd(34) + 'changed%'.padStart(9) + '  px'.padStart(10) + '   height');
for (const r of rows) {
  if (r.note) { console.log('  ' + r.f.padEnd(34) + '  ' + r.note); continue; }
  const hd = r.heightDelta === 0 ? 'same' : (r.heightDelta > 0 ? `+${r.heightDelta}px` : `${r.heightDelta}px`);
  console.log('  ' + r.f.padEnd(34) + r.pct.toFixed(3).padStart(8) + '%' + String(r.changed).padStart(10) + '   ' + hd);
}
console.log(`\n  worst: ${worst.toFixed(3)}%   diff images: ${OUT}\n`);
