<?php
declare(strict_types=1);
// super-admin/ai_usage.php - what the AI features are costing, what the caps
// are, and what the bill looks like as the store grows.
//
// Read-only. Every figure comes from ai_usage_log, which config/ai_guard.php
// writes one row to per AI call (including the ones it blocked), costed from
// the real token counts Gemini reports.
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_SA'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/ai_guard.php';

require_role(['owner', 'super-admin']);

$pageTitle = 'AI Usage & Cost - TechWorld';
$activeNav = 'ai_usage';

$pdo = getConnection();
$report = ai_usage_report($pdo);

// The two assumptions behind the projection, adjustable from the page so the
// owner can try their own numbers rather than trusting mine.
$adoption  = isset($_GET['adoption']) ? max(0.01, min(1.0, (float)$_GET['adoption'])) : 0.25;
$callsEach = isset($_GET['calls']) ? max(0.1, min(50.0, (float)$_GET['calls'])) : 2.0;
$projection = ai_cost_projection($pdo, [100, 500, 1000, 5000, 10000], $adoption, $callsEach);

$limits = $report['limits'];
$todayUsd  = (float)($report['today']['usd'] ?? 0);
$monthUsd  = (float)($report['month']['usd'] ?? 0);
$dayPct    = $limits['global_usd_day'] > 0 ? min(100, ($todayUsd / $limits['global_usd_day']) * 100) : 0;
$monthPct  = $limits['global_usd_month'] > 0 ? min(100, ($monthUsd / $limits['global_usd_month']) * 100) : 0;

/** Peso view, for an owner who thinks in PHP rather than USD. */
$phpPerUsd = (float)(getenv('USD_TO_PHP') ?: 58.0);
function ai_php(float $usd): string { global $phpPerUsd; return '₱' . number_format($usd * $phpPerUsd, 2); }

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>
<style>
.aiu-wrap { max-width: 1100px; margin: 0 auto; padding: 24px 20px 40px; }
.aiu-wrap h1 { color: var(--admin-text); font-size: 24px; font-weight: 800; margin: 0 0 6px; }
.aiu-lead { color: var(--admin-muted); font-size: 14px; line-height: 1.6; margin: 0 0 24px; max-width: 70ch; }
.aiu-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 16px; margin-bottom: 26px; }
.aiu-card { background: var(--admin-surface-2); border: 1px solid var(--admin-border); border-radius: var(--radius-lg); padding: 18px 20px; }
.aiu-card .k { display: block; color: var(--admin-muted); font-size: 11px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase; margin-bottom: 8px; }
.aiu-card .v { color: var(--admin-text); font-size: 26px; font-weight: 800; line-height: 1.1; }
.aiu-card .sub { color: var(--admin-muted); font-size: 12.5px; margin-top: 6px; }
.aiu-meter { height: 6px; border-radius: 999px; background: var(--admin-border); margin-top: 12px; overflow: hidden; }
.aiu-meter span { display: block; height: 100%; border-radius: 999px; background: var(--admin-success, #5fa88a); }
.aiu-meter.warn span { background: #d6a23a; }
.aiu-meter.hot span { background: var(--admin-danger, #c2686f); }
.aiu-sec { margin-bottom: 30px; }
.aiu-sec h2 { color: var(--admin-text); font-size: 17px; font-weight: 800; margin: 0 0 4px; }
.aiu-sec p.note { color: var(--admin-muted); font-size: 13px; line-height: 1.6; margin: 0 0 14px; max-width: 78ch; }
.aiu-table-wrap { overflow-x: auto; border: 1px solid var(--admin-border); border-radius: var(--radius-lg); background: var(--admin-surface-2); }
.aiu-table { width: 100%; border-collapse: collapse; font-size: 13.5px; min-width: 520px; }
.aiu-table th, .aiu-table td { padding: 11px 16px; text-align: left; border-bottom: 1px solid var(--admin-border); color: var(--admin-text); white-space: nowrap; }
.aiu-table th { color: var(--admin-muted); font-size: 11px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase; }
.aiu-table tr:last-child td { border-bottom: none; }
.aiu-table td.num, .aiu-table th.num { text-align: right; font-variant-numeric: tabular-nums; }
.aiu-empty { padding: 26px; text-align: center; color: var(--admin-muted); font-size: 14px; }
.aiu-callout { border: 1px solid var(--admin-border); border-left: 3px solid var(--admin-accent); border-radius: var(--radius-sm); background: var(--admin-surface); padding: 14px 18px; color: var(--admin-muted); font-size: 13.5px; line-height: 1.65; margin-bottom: 22px; }
.aiu-callout strong { color: var(--admin-text); }
.aiu-form { display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end; margin-bottom: 16px; }
.aiu-form label { display: block; color: var(--admin-muted); font-size: 12px; font-weight: 700; margin-bottom: 5px; }
.aiu-form input { width: 120px; padding: 9px 11px; border-radius: var(--radius-sm); border: 1px solid var(--admin-border); background: var(--admin-surface); color: var(--admin-text); font: inherit; font-size: 14px; }
.aiu-form button { padding: 10px 18px; border: none; border-radius: var(--radius-sm); background: var(--admin-primary-2); color: #f2f3f4; font: inherit; font-weight: 700; cursor: pointer; }
@media (max-width: 767px) { .aiu-wrap { padding: 16px 16px 30px; } .aiu-card .v { font-size: 22px; } }
</style>

<main class="container">
<div class="aiu-wrap">
  <h1>AI Usage &amp; Cost</h1>
  <p class="aiu-lead">
    Gemini is the only API this store pays per request for. Every call goes through
    <code>config/ai_guard.php</code>, which records it here and refuses it outright once a cap is reached &mdash;
    so the figures below are both the history and the ceiling.
  </p>

  <div class="aiu-grid">
    <div class="aiu-card">
      <span class="k">Today</span>
      <div class="v"><?= ai_php($todayUsd) ?></div>
      <div class="sub">$<?= number_format($todayUsd, 4) ?> of $<?= number_format((float)$limits['global_usd_day'], 2) ?> cap &middot; <?= (int)($report['today']['calls'] ?? 0) ?> calls</div>
      <div class="aiu-meter <?= $dayPct >= 90 ? 'hot' : ($dayPct >= 70 ? 'warn' : '') ?>"><span style="width: <?= number_format($dayPct, 1) ?>%"></span></div>
    </div>
    <div class="aiu-card">
      <span class="k">Last 30 days</span>
      <div class="v"><?= ai_php($monthUsd) ?></div>
      <div class="sub">$<?= number_format($monthUsd, 4) ?> of $<?= number_format((float)$limits['global_usd_month'], 2) ?> cap &middot; <?= (int)($report['month']['calls'] ?? 0) ?> calls</div>
      <div class="aiu-meter <?= $monthPct >= 90 ? 'hot' : ($monthPct >= 70 ? 'warn' : '') ?>"><span style="width: <?= number_format($monthPct, 1) ?>%"></span></div>
    </div>
    <div class="aiu-card">
      <span class="k">Served from cache</span>
      <div class="v"><?= (int)($report['cache']['served'] ?? 0) ?></div>
      <div class="sub">Answers reused in 30 days &mdash; these cost nothing</div>
    </div>
    <div class="aiu-card">
      <span class="k">Refused by a limit</span>
      <div class="v"><?= (int)($report['blocked']['blocked'] ?? 0) ?></div>
      <div class="sub">Requests stopped before reaching the API</div>
    </div>
  </div>

  <div class="aiu-callout">
    <strong>The hard ceiling.</strong> Whatever the traffic, this store cannot be billed for more than
    <strong>$<?= number_format((float)$limits['global_usd_month'], 2) ?> (<?= ai_php((float)$limits['global_usd_month']) ?>) in any 30-day window</strong>,
    or $<?= number_format((float)$limits['global_usd_day'], 2) ?> in a day. Past that, AI requests are refused and the
    AI Builder quietly falls back to its deterministic engine &mdash; customers still get builds, they just aren't AI-written.
    Change the caps with <code>AI_GLOBAL_MONTHLY_USD</code> / <code>AI_GLOBAL_DAILY_USD</code> in <code>.env</code>.
  </div>

  <div class="aiu-sec">
    <h2>Limits in force</h2>
    <p class="note">All of these are <code>.env</code>-overridable. Pricing is $<?= number_format((float)$limits['price_in'], 2) ?> per million input tokens and
      $<?= number_format((float)$limits['price_out'], 2) ?> per million output tokens &mdash; <strong>check these against Google's current price list</strong>, because every
      cost figure on this page derives from them.</p>
    <div class="aiu-table-wrap">
      <table class="aiu-table">
        <tbody>
          <tr><th>Per customer</th><td><?= (int)$limits['user_per_min'] ?> / minute &middot; <?= (int)$limits['user_per_hour'] ?> / hour &middot; <?= (int)$limits['user_per_day'] ?> / day &middot; $<?= number_format((float)$limits['user_usd_day'], 2) ?> / day</td></tr>
          <tr><th>Whole store</th><td><?= (int)$limits['global_calls_day'] ?> calls / day &middot; $<?= number_format((float)$limits['global_usd_day'], 2) ?> / day &middot; $<?= number_format((float)$limits['global_usd_month'], 2) ?> / 30 days</td></tr>
          <tr><th>Retries</th><td>At most <?= (int)$limits['max_attempts'] ?> attempts per call, transient failures only (429 / 5xx / timeout)</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="aiu-sec">
    <h2>Where it goes</h2>
    <div class="aiu-table-wrap">
      <?php if (empty($report['by_feature'])): ?>
        <div class="aiu-empty">No AI calls recorded yet.</div>
      <?php else: ?>
        <table class="aiu-table">
          <thead><tr><th>Feature</th><th class="num">Calls (30d)</th><th class="num">Cost</th></tr></thead>
          <tbody>
          <?php foreach ($report['by_feature'] as $r): ?>
            <tr>
              <td><?= htmlspecialchars((string)$r['feature']) ?></td>
              <td class="num"><?= (int)$r['calls'] ?></td>
              <td class="num"><?= ai_php((float)$r['usd']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <div class="aiu-sec">
    <h2>Heaviest users (30 days)</h2>
    <p class="note">A single account well clear of the rest is the usual shape of abuse. Each is already capped at
      $<?= number_format((float)$limits['user_usd_day'], 2) ?> a day on its own.</p>
    <div class="aiu-table-wrap">
      <?php if (empty($report['top_users'])): ?>
        <div class="aiu-empty">No AI calls recorded yet.</div>
      <?php else: ?>
        <table class="aiu-table">
          <thead><tr><th>Customer</th><th class="num">Calls</th><th class="num">Cost</th></tr></thead>
          <tbody>
          <?php foreach ($report['top_users'] as $r): ?>
            <tr>
              <td><?= htmlspecialchars((string)($r['name'] ?? 'Unknown')) ?></td>
              <td class="num"><?= (int)$r['calls'] ?></td>
              <td class="num"><?= ai_php((float)$r['usd']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <div class="aiu-sec">
    <h2>Daily history</h2>
    <div class="aiu-table-wrap">
      <?php if (empty($report['by_day'])): ?>
        <div class="aiu-empty">No AI calls recorded yet.</div>
      <?php else: ?>
        <table class="aiu-table">
          <thead><tr><th>Date</th><th class="num">Calls</th><th class="num">Cost</th></tr></thead>
          <tbody>
          <?php foreach ($report['by_day'] as $r): ?>
            <tr>
              <td><?= htmlspecialchars((string)$r['d']) ?></td>
              <td class="num"><?= (int)$r['calls'] ?></td>
              <td class="num"><?= ai_php((float)$r['usd']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <div class="aiu-sec">
    <h2>What this costs as you grow</h2>
    <p class="note">
      Cost per call: <strong>$<?= number_format((float)$projection['per_call_usd'], 5) ?></strong>
      (<?= htmlspecialchars((string)$projection['per_call_source']) ?>).
      Cache hit rate so far: <?= number_format($projection['cache_hit_rate'] * 100, 1) ?>%.
      &ldquo;Uncapped&rdquo; is what the traffic would cost with no limits; &ldquo;capped&rdquo; is what the limits above actually allow you to be billed.
    </p>
    <form class="aiu-form" method="get">
      <div>
        <label for="aiuAdoption">Customers who use AI</label>
        <input id="aiuAdoption" type="number" name="adoption" step="0.01" min="0.01" max="1" value="<?= htmlspecialchars((string)$adoption) ?>">
      </div>
      <div>
        <label for="aiuCalls">Builds each, per month</label>
        <input id="aiuCalls" type="number" name="calls" step="0.1" min="0.1" max="50" value="<?= htmlspecialchars((string)$callsEach) ?>">
      </div>
      <button type="submit">Recalculate</button>
    </form>
    <div class="aiu-table-wrap">
      <table class="aiu-table">
        <thead><tr><th class="num">Customers</th><th class="num">AI requests/mo</th><th class="num">Billable</th><th class="num">Uncapped</th><th class="num">Capped (your bill)</th></tr></thead>
        <tbody>
        <?php foreach ($projection['rows'] as $r): ?>
          <tr>
            <td class="num"><?= number_format((int)$r['users']) ?></td>
            <td class="num"><?= number_format((int)$r['calls_per_month']) ?></td>
            <td class="num"><?= number_format((int)$r['billable_calls']) ?></td>
            <td class="num"><?= ai_php((float)$r['uncapped_usd']) ?></td>
            <td class="num"><strong><?= ai_php((float)$r['capped_usd']) ?></strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="note" style="margin-top:12px;">
      Pesos are converted at ₱<?= number_format($phpPerUsd, 2) ?> to the dollar (set <code>USD_TO_PHP</code> in <code>.env</code>).
      The capped column is the answer to &ldquo;how bad can this get&rdquo;: it never exceeds your monthly cap, no matter how many customers you have.
    </p>
  </div>
</div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
