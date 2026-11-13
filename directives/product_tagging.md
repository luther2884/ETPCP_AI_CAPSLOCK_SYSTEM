# Directive: Product Tagging & Similar-Product Recommendations

## Goal

Every active product carries structured tags, so the product detail page can
show **Similar Products** that are genuinely similar — and that stay inside the
product's own category ("motherboards only").

## Why tags live on products, not categories

The older `tags` / `category_tags` tables attach tags to **categories**. With 19
categories that is at most 19 distinct descriptions for 445 products, so every
motherboard looks identical to a recommender. It also restates `category_id`,
which the product row already has. Those tables are still there and still drive
`admin/categories.php`; nothing reads them for recommendations.

`product_tags` attaches **key/value** tags to individual products. Key/value
rather than flat labels because `socket=AM4` and `ram_type=DDR4` are comparable
dimensions — the recommender can weight a socket match above a brand match
instead of treating every tag as equal set membership.

## Why content-based, not collaborative filtering

As of this writing the catalog has **4 customers who have ever ordered** and
**43 of 46 orders are single-item**. There is no co-purchase graph and no user
overlap, so "customers who bought X also bought Y" has nothing to learn from.
Scoring products against each other on their own attributes works from day one
and stays explainable. Revisit once order volume and basket size grow — the
tags remain useful as features either way.

## Inputs

- `products.name`, `products.description` — the only place specs exist in this
  catalog (there are no spec columns)
- `products.price` — relative to its own category, for `price_tier`
- `products.category_id` — selects which rules apply, and is the hard filter
  at recommendation time

## Tools

| File | Layer | Role |
|---|---|---|
| `DBSQL/add_product_tags.sql` | — | Creates `product_tags` |
| `config/product_tagger.php` | 3 | Derives tags from a product's own text |
| `execution/backfill_product_tags.php` | 3 | Tags the whole catalog |
| `config/product_recommendations.php` | 3 | `getSimilarProducts()`, `describeSimilarity()` |

## Procedure

**First-time setup**

```bash
mysql -u root techworld_ecommerce < DBSQL/add_product_tags.sql
php execution/backfill_product_tags.php --dry-run    # inspect first
php execution/backfill_product_tags.php              # write
```

**After adding or editing tagging rules**

```bash
php execution/backfill_product_tags.php --dry-run --category=6 --verbose
php execution/backfill_product_tags.php
```

Always dry-run a single category with `--verbose` first and read the derived
tags. A regex that over-matches is silent — it produces confident, wrong tags.

**New products** are tagged automatically on save in `admin/add_product.php`.
A bulk import (`DBSQL/*seed*.sql`) bypasses that, so re-run the backfill after
one.

## Rules of the system

1. **Never invent a spec.** Every tag traces to a literal substring of the
   product's own name/description, to its price rank within its category, or to
   its stock. Same discipline `config/ai_builder_engine.php` follows.
2. **First rule per key wins.** Register specific patterns before broad ones —
   `Mini-ITX` before `ATX`, `NVMe` before `SSD`, or the broad one swallows it.
3. **Band numeric values, don't store them raw.** A 480GB and a 500GB SSD are
   the same shelf; exact-value tags match almost nothing.
4. **Category-relative, not catalog-relative.** A ₱5,000 motherboard and a
   ₱5,000 headset sit at opposite ends of their categories, so `price_tier` is
   a percentile within the product's own category.
5. **`confidence` 100 is human.** The backfill deletes and rewrites only rows
   below 100, so admin corrections survive a re-run.
6. **Category is a hard filter, never a score.** No amount of brand or price
   similarity may pull a headset into a motherboard's carousel.

## Known constraints and edge cases

- **Category 6 (Power Supply) has a special wattage rule.** Wattage is often
  baked into the model name (`GS700`, `CV550`, `B550`). The pattern that reads
  those is safe *only* because category 6 holds PSUs exclusively — the same
  pattern would read a motherboard's B550 chipset as 550 watts. Do not widen
  its category list.
- **`80PLUS` is a certification, not a wattage.** The PSU rule requires 3–4
  digits and a 300–2000 range to avoid reading it as 80W.
- **Brand is the first token of the name** in ~99% of rows (443/445). Two-word
  brands (`Cooler Master`, `Power Logic`) need an entry in
  `PT_BRAND_PREFIXES`, or they truncate to `Cooler` / `Power`. Model-number
  tokens (`LC-8700BTXN`) and product lines (`Ryzen`) are rejected as brands.
- **Alias every spelling.** `Hp` and `HP` as separate brands silently halve
  brand affinity. Add to `PT_BRAND_ALIASES`.
- **Laptops state RAM and storage together** ("8GB RAM, 512GB SSD"), so each
  needs its own anchored pattern; the generic capacity extractor would collapse
  them into one meaningless number.
- **13 of 29 laptops have a description that is just the name repeated**, so
  there is nothing to parse for RAM/storage/OS. Screen size is recovered from
  the model code instead (`14ACH5`, `15-EC2087AX`, `15s-FQ5082TU`), restricted
  to 13–17 inches and to category 8 so it cannot grab a generation number like
  `A11M`. Those products still get fewer tags than laptops with real
  descriptions — the fix is better descriptions, not more regex.
- **Monitor (25) has 0 products** and `Laptop Stands` (10) has 1. `price_tier`
  needs ≥4 products in a category to rank meaningfully and is skipped below
  that — expected, not a bug.

## Where tags show up

- **Product detail page** — a "Specifications" chip row under the description
  (`public/guest/product.php`, which `customer/product.php` embeds). Rendered
  from the same `product_tags` rows that drive the ranking, so what a shopper
  reads is exactly what the recommender matched on.
- **Similar Products cards** — a one-line reason under each
  (`describeSimilarity()`), naming the two highest-weighted shared tags.
- Display names live in `tagKeyLabel()` / `tagValueLabel()` in
  `config/product_recommendations.php`. `tagValueLabel()` takes the key as
  well as the value, because the same value means different things under
  different keys — `mid` is "Mid-range" under `price_tier` but "Mid tower"
  under `tower`.

## Current state (last backfill)

- 445 active products, **1807 tags**, avg 4.1 per product, **0 untagged**
- Coverage: `brand` 438/445, `price_tier` 444/445
- Richest: Memory 5.9 tags/product, Networking 4.9, Storage 4.8
- Thinnest: `Laptop Stands` 1.0 (single product), Speakers 2.7

## Extending it

To tag a new attribute, add one row to `pt_tag_rules()` in
`config/product_tagger.php` (key, value, regex, category ids) and a weight in
`pt_tag_weights()`. If it needs arithmetic rather than a literal match, add it
to `pt_extract_numeric_tags()` instead. Then dry-run that category verbose,
check the output, and re-run the backfill.

Weights are the tuning dial: hard compatibility facts (socket 10, ram_type 8)
outrank preferences (brand 6, price_tier 5), which outrank nice-to-haves
(lighting 2). An unlisted key defaults to 2, so a new rule cannot quietly
dominate ranking.
