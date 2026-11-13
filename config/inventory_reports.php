<?php
declare(strict_types=1);

/**
 * config/inventory_reports.php - the five inventory reports, as data.
 *
 * ONE SHAPE, FIVE QUERIES. Every report here returns the same structure:
 *
 *   ['title' => string, 'subtitle' => string,
 *    'columns' => [['key','Label','align'], ...],
 *    'rows' => [[key => value, ...], ...],
 *    'totals' => [key => value, ...]]
 *
 * so the page renders all five with one table renderer and exports all five
 * with one Excel writer. Adding a sixth report is a query and a column list,
 * not another page and another exporter - which is the only reason five
 * reports fit in one screen without five near-identical code paths.
 *
 * Reads only. Nothing here writes, so a report can never change what it is
 * reporting on.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/inventory_stock.php';

/** The reports this module offers, in menu order. */
const INV_REPORTS = [
    'stock_in'    => 'Stock In Report',
    'defective'   => 'Defective / Bad Order Report',
    'pull_out'    => 'Pull-Out Report',
    'slow_moving' => 'Slow-Moving / Discontinued Stock Report',
    'summary'     => 'Stock Movement Summary',
];

/**
 * Build one report.
 *
 * @param array{from:string,to:string,brand?:string,supplier_id?:int,product_id?:int} $f
 * @return array{title:string,subtitle:string,columns:array,rows:array,totals:array}
 */
function invrep_build(PDO $pdo, string $key, array $f): array
{
    $from = $f['from'];
    $to   = $f['to'];
    $range = 'From ' . date('j M Y', strtotime($from)) . ' to ' . date('j M Y', strtotime($to));

    switch ($key) {

        /* ---------------------------------------------------------------- */
        case 'stock_in':
            $where  = ['d.delivery_date BETWEEN ? AND ?'];
            $params = [$from, $to];
            if (!empty($f['supplier_id'])) { $where[] = 'd.supplier_id = ?'; $params[] = (int)$f['supplier_id']; }
            if (!empty($f['brand']))       { $where[] = 'p.brand = ?';       $params[] = $f['brand']; }
            if (!empty($f['product_id']))  { $where[] = 'p.id = ?';          $params[] = (int)$f['product_id']; }

            $stmt = $pdo->prepare(
                "SELECT d.delivery_date, d.dr_number,
                        COALESCE(s.name, '(not recorded)') AS supplier_name,
                        p.name AS product_name, COALESCE(p.brand,'—') AS brand,
                        di.quantity,
                        /* Serials actually on file from THIS delivery for this
                           product - the proof that a serialised line was
                           received per unit rather than as a bare count. */
                        (SELECT COUNT(*) FROM product_units pu
                          WHERE pu.delivery_id = d.id AND pu.product_id = p.id) AS serials_recorded,
                        TRIM(CONCAT(COALESCE(u.firstname,''), ' ', COALESCE(u.lastname,''))) AS received_by
                   FROM stock_delivery_items di
                   JOIN stock_deliveries d ON d.id = di.delivery_id
                   JOIN products p         ON p.id = di.product_id
              LEFT JOIN suppliers s        ON s.id = d.supplier_id
              LEFT JOIN users u            ON u.id = d.received_by
                  WHERE " . implode(' AND ', $where) . "
                  ORDER BY d.delivery_date DESC, d.dr_number, p.name"
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'title'    => INV_REPORTS[$key],
                'subtitle' => $range,
                'columns'  => [
                    ['delivery_date', 'Date', 'left'],
                    ['dr_number', 'DR No.', 'left'],
                    ['supplier_name', 'Supplier', 'left'],
                    ['product_name', 'Product', 'left'],
                    ['brand', 'Brand', 'left'],
                    ['quantity', 'Qty', 'right'],
                    ['serials_recorded', 'Serials', 'right'],
                    ['received_by', 'Received by', 'left'],
                ],
                'rows'   => $rows,
                'totals' => [
                    'product_name'     => 'Total received',
                    'quantity'         => array_sum(array_column($rows, 'quantity')),
                    'serials_recorded' => array_sum(array_column($rows, 'serials_recorded')),
                ],
            ];

        /* ---------------------------------------------------------------- */
        case 'defective':
            // Driven off the movement log rather than off product_units.status,
            // because a unit that has since been disposed of is no longer
            // 'defective' - but it WAS defective in the period, and a report
            // that forgets that undercounts the month it is about.
            $where  = ["sm.movement_type IN ('defective','customer_return')", 'DATE(sm.created_at) BETWEEN ? AND ?'];
            $params = [$from, $to];
            if (!empty($f['brand']))      { $where[] = 'p.brand = ?';      $params[] = $f['brand']; }
            if (!empty($f['product_id'])) { $where[] = 'p.id = ?';         $params[] = (int)$f['product_id']; }
            if (!empty($f['supplier_id'])) { $where[] = 'd.supplier_id = ?'; $params[] = (int)$f['supplier_id']; }

            $stmt = $pdo->prepare(
                "SELECT DATE(sm.created_at) AS found_date,
                        p.name AS product_name, COALESCE(p.brand,'—') AS brand,
                        COALESCE(s.name,'—') AS supplier_name,
                        COALESCE(sm.serial_number,'—') AS serial_number,
                        sm.reason,
                        COALESCE(r.reference_no,'—') AS pull_out_ref,
                        CASE
                          WHEN r.id IS NULL THEN 'No pull-out raised'
                          WHEN r.status = 'pending' THEN 'Pending approval'
                          WHEN r.status = 'rejected' THEN 'Rejected'
                          WHEN r.final_action IS NOT NULL THEN CONCAT('Approved - ', REPLACE(r.final_action,'_',' '))
                          ELSE 'Approved - action pending'
                        END AS pull_out_status,
                        TRIM(CONCAT(COALESCE(u.firstname,''), ' ', COALESCE(u.lastname,''))) AS logged_by
                   FROM stock_movements sm
                   JOIN products p ON p.id = sm.product_id
              LEFT JOIN users u     ON u.id = sm.user_id
              LEFT JOIN product_units pu ON pu.unit_id = sm.product_unit_id
              LEFT JOIN stock_deliveries d ON d.id = pu.delivery_id
              LEFT JOIN suppliers s ON s.id = d.supplier_id
              LEFT JOIN pull_out_requests r
                     ON r.reference_no = sm.reference_no AND sm.reference_type = 'pull_out'
                  WHERE " . implode(' AND ', $where) . "
                  ORDER BY sm.created_at DESC"
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'title'    => INV_REPORTS[$key],
                'subtitle' => $range,
                'columns'  => [
                    ['found_date', 'Date', 'left'],
                    ['product_name', 'Product', 'left'],
                    ['brand', 'Brand', 'left'],
                    ['supplier_name', 'Supplier', 'left'],
                    ['serial_number', 'Serial / IMEI', 'left'],
                    ['reason', 'Reason', 'left'],
                    ['pull_out_ref', 'Pull-out', 'left'],
                    ['pull_out_status', 'Pull-out status', 'left'],
                    ['logged_by', 'Logged by', 'left'],
                ],
                'rows'   => $rows,
                'totals' => ['product_name' => 'Defective units', 'serial_number' => count($rows)],
            ];

        /* ---------------------------------------------------------------- */
        case 'pull_out':
            $where  = ['DATE(r.created_at) BETWEEN ? AND ?'];
            $params = [$from, $to];
            if (!empty($f['brand'])) {
                $where[] = 'EXISTS (SELECT 1 FROM pull_out_items i2 JOIN products p2 ON p2.id = i2.product_id
                                     WHERE i2.pull_out_id = r.id AND p2.brand = ?)';
                $params[] = $f['brand'];
            }
            $stmt = $pdo->prepare(
                "SELECT r.reference_no, DATE(r.created_at) AS raised_date, r.source, r.reason, r.status,
                        r.final_action, DATE(r.final_action_at) AS final_date,
                        (SELECT COUNT(*) FROM pull_out_items i WHERE i.pull_out_id = r.id) AS unit_count,
                        (SELECT GROUP_CONCAT(DISTINCT p3.name ORDER BY p3.name SEPARATOR ', ')
                           FROM pull_out_items i3 JOIN products p3 ON p3.id = i3.product_id
                          WHERE i3.pull_out_id = r.id) AS products,
                        TRIM(CONCAT(COALESCE(rq.firstname,''), ' ', COALESCE(rq.lastname,''))) AS requested_by,
                        TRIM(CONCAT(COALESCE(rv.firstname,''), ' ', COALESCE(rv.lastname,''))) AS approver
                   FROM pull_out_requests r
              LEFT JOIN users rq ON rq.id = r.requested_by
              LEFT JOIN users rv ON rv.id = r.reviewed_by
                  WHERE " . implode(' AND ', $where) . "
                  ORDER BY r.created_at DESC"
            );
            $stmt->execute($params);
            $rows = array_map(static function ($r) {
                $r['source']       = INV_PULLOUT_SOURCES[(string)$r['source']] ?? (string)$r['source'];
                $r['status']       = ucfirst((string)$r['status']);
                $r['final_action'] = $r['final_action']
                    ? (INV_FINAL_ACTIONS[(string)$r['final_action']] ?? (string)$r['final_action'])
                    : '—';
                $r['final_date']   = $r['final_date'] ?: '—';
                $r['approver']     = trim((string)$r['approver']) ?: '—';
                $r['requested_by'] = trim((string)$r['requested_by']) ?: '—';
                return $r;
            }, $stmt->fetchAll(PDO::FETCH_ASSOC));

            return [
                'title'    => INV_REPORTS[$key],
                'subtitle' => $range,
                'columns'  => [
                    ['reference_no', 'Reference', 'left'],
                    ['raised_date', 'Raised', 'left'],
                    ['source', 'Source', 'left'],
                    ['products', 'Product(s)', 'left'],
                    ['unit_count', 'Units', 'right'],
                    ['status', 'Decision', 'left'],
                    ['final_action', 'Final action', 'left'],
                    ['final_date', 'Action date', 'left'],
                    ['requested_by', 'Raised by', 'left'],
                    ['approver', 'Approved by', 'left'],
                ],
                'rows'   => $rows,
                'totals' => [
                    'products'   => 'Total pulled out',
                    'unit_count' => array_sum(array_column($rows, 'unit_count')),
                ],
            ];

        /* ---------------------------------------------------------------- */
        case 'slow_moving':
            // Not date-filtered: "what is dead right now" is a snapshot, and a
            // date range on it would be meaningless. Says so in the subtitle
            // rather than silently ignoring the filter the page shows.
            $days  = inv_slow_moving_days($pdo);
            $items = inv_flagged_items($pdo, $days);
            if (!empty($f['brand'])) {
                $items = array_values(array_filter($items, static fn($i) => (string)($i['brand'] ?? '') === $f['brand']));
            }
            $rows = array_map(static function ($i) {
                return [
                    'product_name'    => (string)$i['name'],
                    'brand'           => (string)($i['brand'] ?? '—'),
                    'category_name'   => (string)($i['category_name'] ?? '—'),
                    'stock'           => (int)$i['stock'],
                    'days_unsold'     => (int)$i['days_unsold'],
                    'last_sale_date'  => $i['last_sale_date'] ?: 'never sold',
                    'date_added'      => (string)$i['date_added'],
                    'discontinued_at' => $i['discontinued_at'] ? date('Y-m-d', strtotime((string)$i['discontinued_at'])) : '—',
                ];
            }, $items);

            return [
                'title'    => INV_REPORTS[$key],
                'subtitle' => 'Snapshot as of ' . date('j M Y') . ' - Discontinued, still holding stock, and unsold for '
                            . $days . '+ days. Not affected by the date range.',
                'columns'  => [
                    ['product_name', 'Product', 'left'],
                    ['brand', 'Brand', 'left'],
                    ['category_name', 'Category', 'left'],
                    ['stock', 'Units left', 'right'],
                    ['days_unsold', 'Days unsold', 'right'],
                    ['last_sale_date', 'Last sale', 'left'],
                    ['date_added', 'Added to stock', 'left'],
                    ['discontinued_at', 'Discontinued', 'left'],
                ],
                'rows'   => $rows,
                'totals' => [
                    'product_name' => count($rows) . ' flagged item' . (count($rows) === 1 ? '' : 's'),
                    'stock'        => array_sum(array_column($rows, 'stock')),
                ],
            ];

        /* ---------------------------------------------------------------- */
        case 'summary':
        default:
            /* Total in, total out, and ENDING stock per product.
               Ending stock is products.stock as it is now, labelled as such -
               not a reconstruction from the movement log. The log only starts
               where this module was installed, so "opening + in - out" would
               silently disagree with the shelf for every product that existed
               before it. One honest current figure beats a derived one that
               cannot be right. */
            $inList  = "'" . implode("','", INV_MOVEMENT_IN) . "'";
            $outList = "'" . implode("','", INV_MOVEMENT_OUT) . "'";

            $where  = ['DATE(sm.created_at) BETWEEN ? AND ?'];
            $params = [$from, $to];
            if (!empty($f['brand']))      { $where[] = 'p.brand = ?';  $params[] = $f['brand']; }
            if (!empty($f['product_id'])) { $where[] = 'p.id = ?';     $params[] = (int)$f['product_id']; }

            $stmt = $pdo->prepare(
                "SELECT p.id, p.name AS product_name, COALESCE(p.brand,'—') AS brand,
                        p.stock AS ending_stock, p.status,
                        COALESCE(SUM(CASE WHEN sm.movement_type IN ($inList)  THEN sm.quantity ELSE 0 END),0) AS total_in,
                        COALESCE(SUM(CASE WHEN sm.movement_type IN ($outList) THEN sm.quantity ELSE 0 END),0) AS total_out
                   FROM stock_movements sm
                   JOIN products p ON p.id = sm.product_id
                  WHERE " . implode(' AND ', $where) . "
                  GROUP BY p.id, p.name, p.brand, p.stock, p.status
                  ORDER BY total_out DESC, total_in DESC, p.name"
            );
            $stmt->execute($params);
            $rows = array_map(static function ($r) {
                $r['net']    = (int)$r['total_in'] - (int)$r['total_out'];
                $r['status'] = ucfirst((string)$r['status']);
                return $r;
            }, $stmt->fetchAll(PDO::FETCH_ASSOC));

            return [
                'title'    => INV_REPORTS['summary'],
                'subtitle' => $range . ' - ending stock is the current figure on the shelf.',
                'columns'  => [
                    ['product_name', 'Product', 'left'],
                    ['brand', 'Brand', 'left'],
                    ['status', 'Status', 'left'],
                    ['total_in', 'Total in', 'right'],
                    ['total_out', 'Total out', 'right'],
                    ['net', 'Net', 'right'],
                    ['ending_stock', 'Ending stock', 'right'],
                ],
                'rows'   => $rows,
                'totals' => [
                    'product_name' => 'Totals',
                    'total_in'     => array_sum(array_column($rows, 'total_in')),
                    'total_out'    => array_sum(array_column($rows, 'total_out')),
                    'net'          => array_sum(array_column($rows, 'net')),
                    'ending_stock' => array_sum(array_column($rows, 'ending_stock')),
                ],
            ];
    }
}

/**
 * Stream one report as .xlsx.
 *
 * PhpSpreadsheet, which is already a dependency and already how
 * inventory_control.php exports - so a downloaded inventory report opens the
 * same way whichever page produced it. Dies after streaming; nothing may be
 * echoed before it.
 */
function invrep_stream_xlsx(array $report, string $filename): void
{
    require_once __DIR__ . '/../vendor/autoload.php';

    $book  = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $book->getActiveSheet();
    $sheet->setTitle(mb_substr(preg_replace('/[^A-Za-z0-9 ]/', '', $report['title']) ?: 'Report', 0, 31));

    $cols = $report['columns'];
    $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($cols));

    // Title block, so a printed or emailed sheet says what it is.
    $sheet->setCellValue('A1', $report['title']);
    $sheet->mergeCells("A1:{$last}1");
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

    $sheet->setCellValue('A2', $report['subtitle']);
    $sheet->mergeCells("A2:{$last}2");
    $sheet->getStyle('A2')->getFont()->setSize(10)->getColor()->setRGB('666666');

    $sheet->setCellValue('A3', 'TechWorld Computer Store - generated ' . date('j M Y, g:ia'));
    $sheet->mergeCells("A3:{$last}3");
    $sheet->getStyle('A3')->getFont()->setSize(9)->getColor()->setRGB('999999');

    $headRow = 5;
    foreach ($cols as $i => $c) {
        $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1) . $headRow;
        $sheet->setCellValue($cell, $c[1]);
    }
    $sheet->getStyle("A{$headRow}:{$last}{$headRow}")->getFont()->setBold(true);
    $sheet->getStyle("A{$headRow}:{$last}{$headRow}")->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setRGB('E8EDF3');

    $r = $headRow + 1;
    foreach ($report['rows'] as $row) {
        foreach ($cols as $i => $c) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1) . $r;
            $v = $row[$c[0]] ?? '';
            // Serials and DR numbers are written as text on purpose: Excel
            // would otherwise read something like 356938035643809 as a number
            // and render it in scientific notation.
            if (in_array($c[0], ['serial_number', 'dr_number', 'reference_no', 'pull_out_ref'], true)) {
                $sheet->setCellValueExplicit($cell, (string)$v,
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            } else {
                $sheet->setCellValue($cell, is_numeric($v) ? $v + 0 : (string)$v);
            }
            if (($c[2] ?? 'left') === 'right') {
                $sheet->getStyle($cell)->getAlignment()->setHorizontal('right');
            }
        }
        $r++;
    }

    if (!empty($report['totals'])) {
        foreach ($cols as $i => $c) {
            if (!array_key_exists($c[0], $report['totals'])) { continue; }
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1) . $r;
            $v = $report['totals'][$c[0]];
            $sheet->setCellValue($cell, is_numeric($v) ? $v + 0 : (string)$v);
            if (($c[2] ?? 'left') === 'right') {
                $sheet->getStyle($cell)->getAlignment()->setHorizontal('right');
            }
        }
        $sheet->getStyle("A{$r}:{$last}{$r}")->getFont()->setBold(true);
    }

    foreach (range(1, count($cols)) as $i) {
        $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i))
              ->setAutoSize(true);
    }
    $sheet->freezePane('A' . ($headRow + 1));

    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');
    exit;
}
