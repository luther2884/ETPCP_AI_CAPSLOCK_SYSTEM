# Directive: Demand Forecasting & Smart Reorder Suggestions

## Goal

Tell an admin **how many units to order**, derived from how fast each product
actually sells — not just that stock is low. A low-stock flag says a number
crossed a line; a reorder suggestion is an instruction you can act on.

## The model

Per product, in order:

```
velocity   = units sold in window / EFFECTIVE days in window
forecast   = velocity × horizon days × trend factor
required   = forecast + (velocity × lead time) + (velocity × safety days)
suggestion = ceil(required − available)      then clamped and capped
```

`available = stock − committed`, where committed is units on open orders
(`preparing`, `packed`, `ready`, `shipped`). Reordering against raw `stock`
under-orders every product that has open orders against it.

**Worked example from the spec** (lead time 0, safety 0), reproduced exactly by
`df_forecast_product()`:

| | Product A | Product B |
|---|---|---|
| Sold, last 7 days | 5 units | 5 units |
| Average sales rate | 0.71 units/day | 0.71 units/day |
| Current stock | 2 units | 10 units |
| Forecast 7-day demand | 5 units | 5 units |
| **Suggested reorder** | **3 units** | **0 units** |
| Status | Reorder Recommended | Stock Sufficient |

With the shipped defaults (lead 7d, safety 3d) Product A's suggestion becomes
11, because the order also has to cover the wait for delivery and a buffer.
Set both to 0 for the plain forecast-minus-stock figure.

## Why the two guards exist

**1. Effective days, not nominal days.** A 30-day window over a store with 17
days of order history divides by 17, not 30. Dividing by the nominal window
halves every velocity and silently under-orders the whole catalog. The page
states which divisor it used. Per-product, the divisor is also capped by how
long the product has been listed — a product added yesterday is not judged on
30 days of "no sales".

**2. Max-stock ceiling.** A suggestion may never push stock past
`max_stock_days` of demand, so one busy week cannot trigger a year of
inventory. Verified: 200 units sold in 7 days against an explicit
`max_stock_level` of 50 suggests 50, not 486, and says it was capped.

> **Limitation worth knowing:** the ceiling derived from `max_stock_days`
> scales with velocity, so during a genuine spike the ceiling inflates too. An
> explicit per-product `products.max_stock_level` is the stronger guard — set
> it on anything expensive or slow-moving.

**Trend factor** compares the current window to the one before it, clamped to
0.5–1.5×. With sales data this thin one large order can double a window, and an
unclamped ratio would turn that into a 5× forecast. A prior window of zero
returns 1.0 — going from 0 to 3 units is one sale, not an infinite trend.

**Cancelled orders are excluded** from demand everywhere. A cancelled order is
not demand the warehouse has to cover; counting it inflates every suggestion.

## Tools

| File | Layer | Role |
|---|---|---|
| `DBSQL/add_demand_forecasting.sql` | — | `inventory_settings`, plus `products.lead_time_days` / `max_stock_level` |
| `config/demand_forecast.php` | 3 | The engine: settings, velocity, forecast, suggestion |
| `public/authenticated/admin/demand_forecast.php` | — | Admin UI, settings form, CSV export |
| `execution/reorder_report.php` | 3 | CLI reorder plan, with run-only overrides |

Nothing stores a forecast. Everything is computed from `order_items` on read,
so a forecast can never drift from the orders it describes.

## Settings

Global, in `inventory_settings`, editable from the admin page:

| Key | Default | Meaning |
|---|---|---|
| `forecast_window_days` | 7 | History window for the sales rate (7, 14 or 30) |
| `forecast_horizon_days` | 7 | How far ahead demand is forecast |
| `safety_stock_days` | 3 | Buffer against a spike or a late delivery |
| `default_lead_time_days` | 7 | Supplier lead time when the product has none |
| `max_stock_days` | 60 | Overstock ceiling, in days of demand |
| `min_order_qty` | 1 | Suggestions round up to this; 0 stays 0 |
| `trend_weighting` | 1 | Whether a rising/falling trend adjusts the forecast |

Per product, `products.lead_time_days` and `products.max_stock_level` override
the globals. `NULL` means "use the global", so defaults live in one place and
only exceptions carry their own value. Every value is clamped on read — a
window of 0 would divide by zero, a horizon of 3650 would order a decade of
stock.

## Procedure

**First-time setup**

```bash
mysql -u root techworld_ecommerce < DBSQL/add_demand_forecasting.sql
php execution/reorder_report.php
```

**Routine use** — Admin → Inventory Control → *Demand Forecast & Reorder*, or:

```bash
php execution/reorder_report.php                    # current settings
php execution/reorder_report.php --window=30 --horizon=14
php execution/reorder_report.php --lead=0 --safety=0   # plain forecast − stock
php execution/reorder_report.php --csv > plan.csv
```

CLI flags override for that run only and are never written back.

## Statuses

| Status | Meaning |
|---|---|
| `Oversold` | Open orders promise more units than are on hand (`available < 0`) |
| `Reorder Urgently` | Suggestion > 0 and stock runs out within the lead time — it will stock out before a delivery can land |
| `Reorder Recommended` | Suggestion > 0, with time to spare |
| `Stock Sufficient` | Suggestion is 0 |

## Known constraints

- **The store has ~17 days of order history** (first order 2026-09-02). Every
  rate is honest about that, but a 7-day window over 46 orders is a small
  sample — treat suggestions as a starting point, not gospel, until volume
  builds.
- **No seasonality.** The model is a moving average with a trend nudge. It
  cannot anticipate a Christmas peak or a payday spike. A year of history would
  make a seasonal index worth adding; there isn't one yet.
- **Lead times are guesses until entered.** `products.lead_time_days` is NULL
  for every product, so all of them use the global 7 days. Filling in real
  supplier lead times is the single highest-value improvement to accuracy.
- **Products that have never sold get no forecast** and no suggestion — there
  is nothing to extrapolate from. They still appear under `--all` and in the
  "All products" view with a `Stock Sufficient` status and a reason saying so.

## Extending it

The engine is one pure function, `df_forecast_product($product, $ctx)`, with no
database access of its own — everything it needs arrives in `$ctx`. To change
the model (add seasonality, weight recent days more heavily, use a different
trend clamp), change that function; the admin page, the CSV export and the CLI
report all follow automatically and cannot disagree with each other.
