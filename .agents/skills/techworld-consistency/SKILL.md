---
name: techworld-consistency
description: Use this skill for ANY change to the TECHWRLD e-commerce codebase — new features, bug fixes, refactors, or database changes. It enforces the project's schema source of truth, file naming/cleanup rules, race-condition-safe write paths, and auth flow conventions so the codebase stays consistent as the team builds toward a real production app.
---

# TECHWRLD Consistency Skill

## Purpose
TECHWRLD is a PHP/MySQL e-commerce platform (`techworld_ecommerce` DB) with role-segmented
frontends (guest/authenticated/admin/super-admin), a race-condition-safe order processor, QR
order tracking, and PWA support. This skill exists because the codebase currently has known
drift risks (duplicate files, multiple schema dumps, inconsistent write paths). Follow these
rules on every change so the app doesn't accumulate more of the same problems.

## 1. Schema: single source of truth
- **Never edit `TWDB.sql` or `TWDB1.sql` directly again.** These are legacy full dumps and are
  now considered deprecated/frozen for reference only.
- The **only** place schema changes are made is `DBSQL/*.sql` (modular files, one per
  table/domain area).
- Before writing any migration or new table, check `DBSQL/` first to see if the table already
  exists in a modular file. If it only exists in `TWDB.sql`/`TWDB1.sql`, migrate it into
  `DBSQL/` as part of your change, don't just add on top of the old dumps.
- After any schema change, regenerate the Graphify graph (`graphify . --code-only` then
  `graphify cluster-only .`) so the map stays accurate.

## 2. No duplicate/versioned files
- Never create `*_old.php`, `*_fixed.php`, `*_v2.php`, `*_backup.php`, etc. as a way to keep an
  old version "just in case." Use git for history — Git already remembers old versions.
- If you find an existing duplicate pair (e.g. `index.php` vs `index-old.php`,
  `order_details.php` vs `order_details_fixed.php`), your task before adding new logic to that
  area is to determine which one is actually live (check includes/routing), delete the dead
  one, and note the deletion in your commit message.
- One canonical file per responsibility. If two files do the same job, that's a bug to fix, not
  a pattern to continue.

## 3. Cart/order writes MUST go through the race-safe path
- Any code that writes to `pending_orders`, `order_processing_locks`, or modifies cart/order
  state **must** go through `RaceSafeOrderProcessor` — never call `getConnection()` directly
  for these operations.
- Before merging any change touching `addToCart()`, `updateCartItem()`, checkout, or order
  status updates: verify the write path traces to `RaceSafeOrderProcessor` in the Graphify
  graph (`graphify query "what writes to pending_orders?"`). If it doesn't, that's a bug —
  route it through the processor before shipping.
- Read `RACE_CONDITION_PREVENTION_GUIDE.md` before touching any cart/order/inventory code.

## 4. Auth surface: don't add a 7th flow
- Current auth surface (OTP/email verification, remember-me 2FA cookies, forgot/reset
  password) is already broad. Any new auth-related feature must reuse existing
  session/token/cookie handling utilities — don't write a new parallel mechanism.
- If a new flow seems to require its own logic, flag it for discussion with the team first
  (this is a design decision, not a solo call).

## 5. New root-level scripts don't belong at the root
- One-off or recurring scripts (DB seeding, QR generation, debug utilities) currently sit
  loose at the project root (`setup_database.php`, `debug_qr.php`,
  `simple_qr_generator.php`). This breaks the project's own `directives/` + `execution/`
  pattern.
- Going forward: any new operational script goes in `execution/`, with a matching directive
  in `directives/` describing when/why to run it (a short SOP, not full documentation).
- As you touch existing root-level scripts, migrate them into this pattern rather than adding
  more files next to them.

## 6. Trust Graphify edges correctly
- Graphify graph edges are tagged EXTRACTED, INFERRED, or AMBIGUOUS. Treat INFERRED edges as
  "probably true, verify before relying on it for a critical decision" — not ground truth.
- Re-run `graphify . --code-only && graphify cluster-only .` after any structural change
  (new files, moved files, renamed functions) so the graph doesn't go stale. Commit
  `graphify-out/` so the whole team shares the same map.

## Workflow checklist for any new feature or fix
1. Query the graph first: `graphify query "<relevant question>"` to understand existing
   related code before writing new code.
2. Check `DBSQL/` for existing schema before adding tables.
3. If touching cart/order/inventory, confirm the write path goes through
   `RaceSafeOrderProcessor`.
4. If touching auth, reuse existing utilities — don't add a new mechanism.
5. Put new scripts in `execution/` with a directive in `directives/`, not at project root.
6. Delete/resolve duplicate files you encounter in the area you're working in, rather than
   adding a third version.
7. Re-run Graphify after structural changes and commit `graphify-out/`.
