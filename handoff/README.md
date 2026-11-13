# TechWorld landing page — handoff package (v2)

Plain PHP includes + one vanilla stylesheet + one small vanilla JS file.
No npm, no build step, no framework, no animation library. Every looping
animation is a pure CSS keyframe; JS only tracks the carousel index and
provides a scroll-reveal fallback.

---

## 1. Where the files go

```
htdocs\<your-site>\
├─ index.php                            ← see index.example.php
├─ assets\
│  ├─ css\techworld-anim.css            ← from this package
│  └─ js\techworld.js                   ← from this package
└─ includes\
   ├─ header.php                        (yours — apply the 4 edits below)
   ├─ footer.php                         (yours — add the <script> line)
   ├─ animated-background.php           ← from this package
   ├─ rail-and-fabs.php                 ←
   ├─ section-head.php                  ←  (required by the sections)
   ├─ hero-section.php                  ←
   ├─ category-carousel.php             ←
   ├─ featured-products.php             ←
   ├─ ai-configurator.php               ←
   ├─ why-techworld.php                 ←
   └─ site-footer.php                   ←
```

`header.example.php`, `footer.example.php` and `index.example.php` are
references — read them, apply the parts you need, don't deploy them.

**Commit before you start:** `git add -A && git commit -m "before redesign"`

---

## 2. Four edits to your header include

1. Add the two Google Fonts `<link>`s (Figtree + JetBrains Mono).
2. Add `<link rel="stylesheet" href="assets/css/techworld-anim.css">` as the
   **last** stylesheet in `<head>`.
   Use the relative path (no leading `/`) if the site lives in a subfolder
   of `htdocs` — a leading slash is the usual 404 cause.
3. Change the body tag to `<body class="tw-page" id="top">` and include
   `animated-background.php` then `rail-and-fabs.php` right after it.
4. Open `<main class="tw-main">` after the rail.

In your footer include: close `</main>` and add
`<script src="assets/js/techworld.js" defer></script>`.

---

## 3. Assembling the page

`index.php`, in order — each is one line:

```php
include __DIR__ . '/includes/hero-section.php';
include __DIR__ . '/includes/category-carousel.php';
include __DIR__ . '/includes/featured-products.php';
include __DIR__ . '/includes/ai-configurator.php';
include __DIR__ . '/includes/why-techworld.php';
include __DIR__ . '/includes/site-footer.php';
```

Every section ships with sample data and falls back to it, so the page
renders before you wire anything up. Override by setting the variable
before the include — each file documents its own variables at the top.

`ai-configurator.php` uses negative margins to break out of the gutter,
so include it as a **direct child of `<main class="tw-main">`**, not
nested inside another max-width wrapper.

### Wiring real data

```php
// featured-products.php
$products = [];
$res = $conn->query("SELECT name, kind, spec, price, stock, image
                     FROM products WHERE featured = 1 LIMIT 4");
while ($r = $res->fetch_assoc()) {
  $products[] = [
    'kind'      => $r['kind'],
    'name'      => $r['name'],
    'spec'      => $r['spec'],
    'price'     => '₱' . number_format($r['price']),
    'stock'     => $r['stock'] . ($r['stock'] <= 4 ? ' LEFT' : ' IN STOCK'),
    'stockTone' => $r['stock'] <= 3 ? 'low' : ($r['stock'] >= 7 ? 'ok' : null),
    'badge'     => $r['is_new'] ? 'new' : ($r['best_value'] ? 'value' : null),
    'img'       => $r['image'],
  ];
}
include __DIR__ . '/includes/featured-products.php';
```

---

## 4. Design system in the CSS

**Palette** — all CSS custom properties on `:root`, change once:
`--tw-pink #e0567a`, `--tw-pink-lt #ff9ab5`, `--tw-cyan #67e8f9`,
`--tw-violet #7f6ae0`, backgrounds `--tw-bg #060d18` / `--tw-bg-2` /
`--tw-bg-3`, panel `--tw-panel #0b1626`, `--tw-accent` is the pink→cyan
gradient used by every eyebrow rule, active dot and hover edge.

**Rail width** — `--tw-rail-w: 74px` drives the rail and `.tw-main`'s
offset. If your rail isn't fixed-position, remove `margin-left` from
`.tw-main`.

**Section heads** are centered via `tw_section_head()` in
`section-head.php`. Where a control sits beside the head (the carousel
counter/arrows, the VIEW ALL link) the section uses `.tw-head-row` — a
three-column grid `[spacer][centered head][controls]` so the head reads
centered while the controls stay right. Below 900px it collapses to one
centered column.

**Section rhythm** is deliberately unequal: `.tw-sec` (tight),
`.tw-sec--lg`, `.tw-sec--xl`, and `.tw-band` for the full-bleed AI
section with its own top/bottom accent borders.

**Badge system** — `.tw-badge--new` (cyan), `--value` (pink, also makes
the card the pink-framed feature card), `--stock` (green). One per card
or none; the text comes from `$badgeText` in `featured-products.php`.

---

## 5. Animation inventory

| Effect | Class | Keyframe |
|---|---|---|
| Grid drift | `.tw-bg-grid` | `tw-grid` 26s |
| Aurora blobs | `.tw-bg-aurora-a/-b` | `tw-auroraA/B` |
| Headline word rise | `.tw-word` (`nth-of-type` delays) | `tw-word` |
| Typing caret | `.tw-caret` | `tw-blink` |
| Fade-ups | `.tw-lede`, `.tw-cta-row`, `.tw-stats` | `tw-rise` |
| Button light sweep | `.tw-shine` | `tw-shine` |
| Orbiting rings | `.tw-ring--outer/--inner` | `tw-spin`, `tw-spinRev` |
| Hero image float + scanline | `.tw-shot`, `.tw-scan` | `tw-float`, `tw-scan` |
| Price chips bob | `.tw-chip` (per-child durations) | `tw-floatSm` |
| Brand marquee | `.tw-marquee__track` | `tw-marquee` |
| Travelling hairline | `.tw-divider span`, `.tw-hairline i` | `tw-hairline` |
| Coverflow tiles | `.tw-tile` (`--d`/`--ad` from JS) | CSS transition |
| Active progress bar | `.tw-progress span.is-active` | `flex` transition |
| Card reveal | `.tw-card` | `tw-rise` + `animation-timeline: view()` |
| Meters / engine bars | `.tw-meter i`, `.tw-engine__track i` | `tw-meter` |
| Inventory bars | `.tw-bars span` | `tw-bar` |
| AI typing dots | `.tw-typing i` | `tw-typing` |
| Cart pulse | `.tw-fab-cart` | `tw-pulse` |

Scroll reveals use `animation-timeline: view()` where supported;
`techworld.js` adds an IntersectionObserver fallback for older browsers.

---

## 6. Carousel

The wrapper carries `data-tw-carousel` and `data-speed="3400"` (ms).
`techworld.js` computes each tile's signed distance from the active index
and writes `--d` / `--ad`; the transform, blur, opacity and size all live
in CSS on `.tw-tile` / `.tw-tile.is-active`.

- Auto-advances, pauses on hover and on focus-in
- Arrows, tile clicks and progress-bar clicks all jump
- Left/Right arrow keys work when the section has focus
- Respects `prefers-reduced-motion` (no autoplay)
- Category labels come from each tile's `data-label`
- The active tile's inner panel is **opaque** (`--tw-panel`) so the
  gradient frame reads as a frame, with a light `#dbe6f5` label

---

## 7. Notes

- Text is ≥13.5px with ≥4.5:1 contrast; no mid-grey on pink or on photos.
- Images are capped: every product/category image is
  `width:100%; height:100%; object-fit:contain` inside a sized container,
  so nothing can render at natural pixel size.
- Missing images fall back to a striped diagonal placeholder with a mono
  label rather than a broken `<img>`.
- Fully fluid down to 380px: `max-width` + `minmax(0,1fr)` tracks, the
  rail offset drops to 0 under 900px, chips stack under the hero image,
  the bento wide cell becomes full width, and the coverflow shows a single
  tile under 560px.
- `prefers-reduced-motion: reduce` disables every animation and resets
  opacity/transform to visible.
- All classes are `tw-` prefixed — nothing collides with your stylesheet.
- No PHP logic, queries, routes or cart behaviour is touched by this
  package; it's markup and styles only.

## If something goes wrong

```
git status            # what actually changed
git restore <file>    # undo one file
git checkout -- .     # undo everything since the last commit
```

If a page renders unstyled: F12 → Network → confirm
`techworld-anim.css` returns 200, not 404.
