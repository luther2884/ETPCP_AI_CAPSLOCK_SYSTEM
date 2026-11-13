<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/inventory_reports.php';

require_role(['admin']);

/**
 * admin/inventory_reports.php - the five inventory reports.
 *
 * One page for all five, because they are the same screen with a different
 * query behind it: same filters, same table, same export. invrep_build()
 * returns a uniform shape (title / columns / rows / totals), so this page has
 * one renderer and one exporter rather than five of each.
 *
 * EXPORT matches what this admin panel already does rather than introducing a
 * third mechanism:
 *   Excel  PhpSpreadsheet, the same library and the same download headers
 *          inventory_control.php uses.
 *   PDF    the browser's print dialog over a print stylesheet, which is how
 *          every other printable document here works (order_invoice.php,
 *          delivery_note_print.php). No PDF renderer is installed, and
 *          "Save as PDF" from that dialog produces the same file without
 *          adding a dependency.
 */

$pdo = getConnection();

$reportKey = (string)($_GET['report'] ?? 'stock_in');
if (!isset(INV_REPORTS[$reportKey])) { $reportKey = 'stock_in'; }

// Default range: this month to date - the period the summary cards describe.
$from = trim((string)($_GET['from'] ?? date('Y-m-01')));
$to   = trim((string)($_GET['to']   ?? date('Y-m-d')));
foreach (['from', 'to'] as $k) {
    $v = $k === 'from' ? $from : $to;
    $d = DateTime::createFromFormat('Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) {
        // A bad date would bind into BETWEEN and match nothing, which reads as
        // an empty report rather than a bad filter.
        if ($k === 'from') { $from = date('Y-m-01'); } else { $to = date('Y-m-d'); }
    }
}
if (strtotime($from) > strtotime($to)) { [$from, $to] = [$to, $from]; }

$filters = [
    'from'        => $from,
    'to'          => $to,
    'brand'       => trim((string)($_GET['brand'] ?? '')),
    'supplier_id' => (int)($_GET['supplier_id'] ?? 0),
    'product_id'  => (int)($_GET['product_id'] ?? 0),
];

/* ---------------------------------------------------------------- export --- */
// Before any output: invrep_stream_xlsx() sends download headers and exits.
if (($_GET['export'] ?? '') === 'xlsx') {
    try {
        $report = invrep_build($pdo, $reportKey, $filters);
        logAudit('inventory_report_export', 'stock_movements', null, null,
            ['report' => $reportKey, 'from' => $from, 'to' => $to, 'rows' => count($report['rows'])]);
        invrep_stream_xlsx($report, 'techworld-' . str_replace('_', '-', $reportKey) . '-' . $from . '-to-' . $to . '.xlsx');
    } catch (Throwable $e) {
        error_log('inventory report export: ' . $e->getMessage());
        // Fall through to the page with a notice rather than a blank download.
        $exportError = 'Could not build that export. The report is still shown below.';
    }
}

$pageTitle = 'Inventory Reports - TechWorld Admin';
$activeNav = 'inventory';
$isAdminTheme = true;

$report = ['title' => INV_REPORTS[$reportKey], 'subtitle' => '', 'columns' => [], 'rows' => [], 'totals' => []];
$counts = [];
$brands = [];
$suppliers = [];
$loadError = null;
try {
    $report    = invrep_build($pdo, $reportKey, $filters);
    $counts    = inv_dashboard_counts($pdo);
    $brands    = inv_brands($pdo);
    $suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('inventory_reports: ' . $e->getMessage());
    $loadError = 'Could not build that report.';
}

/** This page's URL with one parameter changed. */
function ir_url(array $over = []): string
{
    global $reportKey, $filters;
    $qs = array_merge([
        'report'      => $reportKey,
        'from'        => $filters['from'],
        'to'          => $filters['to'],
        'brand'       => $filters['brand'],
        'supplier_id' => $filters['supplier_id'] ?: '',
        'product_id'  => $filters['product_id'] ?: '',
    ], $over);
    return '?' . http_build_query(array_filter($qs, static fn($v) => $v !== '' && $v !== 0));
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>
<main class="container">
  <style>
    html body.admin main.container{max-width:min(1500px,96vw);}
    .ir-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin-bottom:22px;}
    .ir-kpi{background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:12px;padding:18px;}
    .ir-kpi-n{font-size:1.9rem;font-weight:800;color:var(--admin-text);line-height:1.1;}
    .ir-kpi-l{font-size:.76rem;color:var(--admin-muted);margin-top:4px;line-height:1.4;}
    .ir-kpi--warn .ir-kpi-n{color:#d68a00;}
    .ir-kpi--bad  .ir-kpi-n{color:#c2686f;}

    .ir-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;}
    .ir-tab{padding:9px 16px;border-radius:9px;border:1px solid var(--admin-border);
      background:var(--admin-surface);color:var(--admin-muted);text-decoration:none;
      font-size:.82rem;font-weight:700;}
    .ir-tab:hover{color:var(--admin-text);}
    .ir-tab.is-on{background:var(--admin-primary-2,#174871);color:#fff;border-color:transparent;}

    .ir-filters{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;align-items:end;}
    @media (max-width:980px){.ir-filters{grid-template-columns:1fr 1fr;}}
    .ir-num{text-align:right;font-variant-numeric:tabular-nums;}
    .ir-mono{font-family:ui-monospace,Consolas,monospace;font-size:12.5px;}
    .ir-total-row td{font-weight:800;border-top:2px solid var(--admin-border);}

    /* PDF = the browser's print dialog, same as every other printable
       document in this panel. Everything but the report itself is dropped. */
    @media print {
      .adm-head, .ir-cards, .ir-tabs, .ir-filter-card, .ir-actions,
      .admin-sidebar, .admin-topbar, nav, footer, .scan-fab { display: none !important; }
      html body.admin main.container { max-width: 100% !important; padding: 0 !important; }
      .ad-card { border: 0 !important; box-shadow: none !important; }
      .ad-table { font-size: 10pt; }
      .ad-table th { background: #e8edf3 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .ir-print-head { display: block !important; }
      @page { size: A4 landscape; margin: 12mm; }
    }
    .ir-print-head{display:none;margin-bottom:14px;}
    .ir-print-head h2{margin:0 0 4px;font-size:16pt;}
    .ir-print-head p{margin:0;font-size:10pt;color:#555;}
  </style>

  <div class="adm-head">
    <div>
      <nav class="adm-crumb" aria-label="Breadcrumb">
        <a href="<?= WEB_ROOT ?>public/authenticated/admin/RepAna.php">Reports</a>
        <span class="adm-crumb-sep" aria-hidden="true">&rsaquo;</span>
        <span class="adm-crumb-cur" aria-current="page">Inventory Reports</span>
      </nav>
    </div>
    <div class="adm-actions ir-actions">
      <a class="ad-btn ad-btn--secondary" href="<?= WEB_ROOT ?>public/authenticated/admin/stock_movements.php">Movement history</a>
      <a class="ad-btn ad-btn--secondary" href="<?= htmlspecialchars(ir_url(['export' => 'xlsx'])) ?>">Export Excel</a>
      <button type="button" class="ad-btn ad-btn--primary" onclick="window.print()">Print / Save PDF</button>
    </div>
  </div>

  <div class="admin-dashboard" style="background:transparent;border:none;box-shadow:none;color:inherit;margin:0 auto;padding:0;max-width:100%;">
    <h1 style="margin:0 0 18px;color:var(--admin-text);">Inventory Reports</h1>

    <?php if (!empty($exportError)): ?>
      <div style="background:#fdecf1;color:#a8264d;border:1px solid #f4c4d4;border-radius:8px;padding:12px 14px;margin-bottom:18px;font-size:14px;">
        <?= htmlspecialchars($exportError) ?>
      </div>
    <?php endif; ?>
    <?php if ($loadError): ?>
      <div style="background:#fdecf1;color:#a8264d;border:1px solid #f4c4d4;border-radius:8px;padding:12px 14px;margin-bottom:18px;font-size:14px;">
        <?= htmlspecialchars($loadError) ?>
      </div>
    <?php endif; ?>

    <!-- Summary cards -->
    <div class="ir-cards">
      <div class="ir-kpi">
        <div class="ir-kpi-n"><?= number_format((int)($counts['received_this_month'] ?? 0)) ?></div>
        <div class="ir-kpi-l">Units received this month</div>
      </div>
      <div class="ir-kpi ir-kpi--bad">
        <div class="ir-kpi-n"><?= number_format((int)($counts['defective_this_month'] ?? 0)) ?></div>
        <div class="ir-kpi-l">Defective units this month</div>
      </div>
      <div class="ir-kpi ir-kpi--warn">
        <div class="ir-kpi-n"><?= number_format((int)($counts['flagged_items'] ?? 0)) ?></div>
        <div class="ir-kpi-l">Slow-moving items flagged</div>
      </div>
      <div class="ir-kpi">
        <div class="ir-kpi-n"><?= number_format((int)($counts['pullouts_pending'] ?? 0)) ?></div>
        <div class="ir-kpi-l">Pull-outs awaiting approval</div>
      </div>
      <div class="ir-kpi">
        <div class="ir-kpi-n"><?= number_format((int)($counts['defective_on_hand'] ?? 0)) ?></div>
        <div class="ir-kpi-l">Defective units on hand</div>
      </div>
    </div>

    <!-- Which report -->
    <div class="ir-tabs">
      <?php foreach (INV_REPORTS as $k => $label): ?>
        <a class="ir-tab <?= $k === $reportKey ? 'is-on' : '' ?>" href="<?= htmlspecialchars(ir_url(['report' => $k])) ?>">
          <?= htmlspecialchars($label) ?>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <form method="GET" class="ad-card ad-field ir-filter-card" style="margin-bottom:18px;">
      <input type="hidden" name="report" value="<?= htmlspecialchars($reportKey) ?>">
      <div class="ad-card-body">
        <div class="ir-filters">
          <div>
            <label class="ad-label" for="ir_from">From</label>
            <input type="date" name="from" id="ir_from" value="<?= htmlspecialchars($filters['from']) ?>">
          </div>
          <div>
            <label class="ad-label" for="ir_to">To</label>
            <input type="date" name="to" id="ir_to" value="<?= htmlspecialchars($filters['to']) ?>">
          </div>
          <div>
            <label class="ad-label" for="ir_brand">Brand</label>
            <select name="brand" id="ir_brand">
              <option value="">All brands</option>
              <?php foreach ($brands as $b): ?>
                <option value="<?= htmlspecialchars((string)$b) ?>" <?= $filters['brand'] === $b ? 'selected' : '' ?>><?= htmlspecialchars((string)$b) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="ad-label" for="ir_supplier">Supplier</label>
            <select name="supplier_id" id="ir_supplier">
              <option value="">All suppliers</option>
              <?php foreach ($suppliers as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= $filters['supplier_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$s['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="ad-hint">Stock In and Defective only.</p>
          </div>
          <div style="display:flex;gap:8px;align-items:flex-end;">
            <button type="submit" class="ad-btn ad-btn--primary">Apply</button>
            <a class="ad-btn ad-btn--secondary" href="?report=<?= htmlspecialchars($reportKey) ?>">Clear</a>
          </div>
        </div>
      </div>
    </form>

    <!-- The report -->
    <div class="ad-card">
      <div class="ir-print-head">
        <h2><?= htmlspecialchars((string)$report['title']) ?></h2>
        <p>
          <?= htmlspecialchars((string)$report['subtitle']) ?><br>
          TechWorld Computer Store &mdash; printed <?= htmlspecialchars(date('j M Y, g:ia')) ?>
        </p>
      </div>
      <div class="ad-card-head">
        <h2 class="ad-card-title"><?= htmlspecialchars((string)$report['title']) ?></h2>
        <span class="ad-card-note"><?= htmlspecialchars((string)$report['subtitle']) ?></span>
      </div>
      <div class="ad-table-wrap">
        <table class="ad-table">
          <thead>
            <tr><?php foreach ($report['columns'] as $c): ?>
              <th<?= ($c[2] ?? 'left') === 'right' ? ' class="ir-num"' : '' ?>><?= htmlspecialchars((string)$c[1]) ?></th>
            <?php endforeach; ?></tr>
          </thead>
          <tbody>
            <?php if (!$report['rows']): ?>
              <tr><td colspan="<?= max(1, count($report['columns'])) ?>" class="ad-table-empty">
                Nothing to report for this period.
              </td></tr>
            <?php else: foreach ($report['rows'] as $row): ?>
              <tr>
                <?php foreach ($report['columns'] as $c): ?>
                  <?php
                    $key = (string)$c[0];
                    $val = $row[$key] ?? '';
                    $align = ($c[2] ?? 'left') === 'right';
                    $mono = in_array($key, ['serial_number', 'dr_number', 'reference_no', 'pull_out_ref'], true);
                    $cls = trim(($align ? 'ir-num ' : '') . ($mono ? 'ir-mono' : ''));
                    // Dates arrive as Y-m-d from SQL; shown the way the rest
                    // of this panel shows them.
                    if (in_array($key, ['delivery_date','found_date','raised_date','final_date','last_sale_date','date_added','discontinued_at'], true)
                        && $val !== '' && $val !== '—' && $val !== 'never sold' && strtotime((string)$val)) {
                        $val = date('j M Y', strtotime((string)$val));
                    }
                    if (is_numeric($val) && $align) { $val = number_format((float)$val); }
                  ?>
                  <td<?= $cls !== '' ? ' class="' . $cls . '"' : '' ?>><?= htmlspecialchars((string)$val) ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; endif; ?>
            <?php if ($report['rows'] && !empty($report['totals'])): ?>
              <tr class="ir-total-row">
                <?php foreach ($report['columns'] as $c): ?>
                  <?php
                    $key = (string)$c[0];
                    $has = array_key_exists($key, $report['totals']);
                    $val = $has ? $report['totals'][$key] : '';
                    $align = ($c[2] ?? 'left') === 'right';
                    if (is_numeric($val)) { $val = number_format((float)$val); }
                  ?>
                  <td<?= $align ? ' class="ir-num"' : '' ?>><?= htmlspecialchars((string)$val) ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <p class="ad-hint" style="margin-top:14px;">
      <?= count($report['rows']) ?> row<?= count($report['rows']) === 1 ? '' : 's' ?>.
      <strong>Export Excel</strong> downloads this exact report; <strong>Print / Save PDF</strong> opens the
      print dialog, where &ldquo;Save as PDF&rdquo; produces the PDF.
    </p>
  </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
