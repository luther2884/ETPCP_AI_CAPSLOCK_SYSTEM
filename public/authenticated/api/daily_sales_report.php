<?php
// api/daily_sales_report.php - End-of-day sales report submission.
//
// Closing the day is a statement about money, so two things are deliberate
// here:
//
// 1. The figures are NEVER taken from the request. The browser posts a date
//    (plus the admin's own cash count and notes); every peso figure stored is
//    recomputed server-side by config/sales_figures.php. A posted total would
//    let anyone who can reach this endpoint write whatever day's sales they
//    liked into the record.
// 2. A day closes once. report_date is UNIQUE, so a second submission is
//    refused unless it explicitly says amend=1 - which overwrites the snapshot
//    and records who amended it and how many times, rather than silently
//    replacing the original.
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/sales_figures.php';

header('Content-Type: application/json');

// isAdmin() covers both 'admin' and 'manager' - the two roles that run the
// store's day - which is the same audience sales_report.php serves.
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/** Y-m-d or null. Rejects "2026-02-31"-style dates that strtotime would roll over. */
function dsrParseDate(?string $raw): ?string
{
    $raw = trim((string)$raw);
    if ($raw === '') { return null; }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    return ($d && $d->format('Y-m-d') === $raw) ? $raw : null;
}

switch ($action) {
    case 'submit':
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'POST required']);
            break;
        }
        // Not strict: matches how the rest of the admin panel's POST endpoints
        // are checked, so a local dev install without a token in the form is
        // still usable. The form on sales_report.php does send one.
        if (!check_csrf(false)) {
            http_response_code(419);
            echo json_encode(['success' => false, 'message' => 'Your session expired. Reload the page and try again.']);
            break;
        }

        $date = dsrParseDate($_POST['report_date'] ?? null);
        if ($date === null) {
            echo json_encode(['success' => false, 'message' => 'A valid report date is required.']);
            break;
        }
        // A day cannot be closed before it has happened. Past days stay open to
        // submission on purpose - a day missed at closing time has to be
        // closable the next morning.
        if ($date > date('Y-m-d')) {
            echo json_encode(['success' => false, 'message' => 'That date is in the future - there is no day to close yet.']);
            break;
        }

        $amend = !empty($_POST['amend']);

        // Blank means "not counted", which the schema stores as NULL - a
        // different claim from a counted ₱0.00.
        $cashRaw = trim((string)($_POST['cash_counted'] ?? ''));
        $cash = null;
        if ($cashRaw !== '') {
            $cashRaw = str_replace([',', '₱', ' '], '', $cashRaw);
            if (!is_numeric($cashRaw) || (float)$cashRaw < 0) {
                echo json_encode(['success' => false, 'message' => 'Cash counted must be a number, or left blank if you did not count it.']);
                break;
            }
            $cash = round((float)$cashRaw, 2);
        }

        $notes = trim((string)($_POST['notes'] ?? ''));
        if ($notes === '') { $notes = null; }
        elseif (mb_strlen($notes) > 2000) { $notes = mb_substr($notes, 0, 2000); }

        try {
            $pdo = getConnection();
            $fig = sfDayFigures($pdo, $date);

            $existing = $pdo->prepare('SELECT id, amend_count FROM daily_sales_reports WHERE report_date = ? LIMIT 1');
            $existing->execute([$date]);
            $prior = $existing->fetch();

            if ($prior && !$amend) {
                // Not an error the admin did anything wrong - the day is
                // already closed. 409 so the client can offer to amend.
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'already_submitted' => true,
                    'message' => 'This day has already been submitted. Re-submit it as an amendment if the figures have changed.',
                ]);
                break;
            }

            if ($prior) {
                $stmt = $pdo->prepare("
                    UPDATE daily_sales_reports SET
                        instore_sales = ?, cod_sales = ?, online_sales = ?, total_sales = ?,
                        order_count = ?, refund_count = ?, refund_amount = ?,
                        cash_counted = ?, notes = ?,
                        amended_by = ?, amended_at = NOW(), amend_count = amend_count + 1
                    WHERE id = ?
                ");
                $stmt->execute([
                    $fig['instore_sales'], $fig['cod_sales'], $fig['online_sales'], $fig['total_sales'],
                    $fig['order_count'], $fig['refund_count'], $fig['refund_amount'],
                    $cash, $notes, getUserId(), (int)$prior['id'],
                ]);
                logAudit('amend_daily_sales_report', 'daily_sales_reports', (int)$prior['id'], null, [
                    'report_date' => $date,
                    'total_sales' => $fig['total_sales'],
                    'order_count' => $fig['order_count'],
                ], 'success', 'Amendment #' . ((int)$prior['amend_count'] + 1) . ' for ' . $date);
                $reportId = (int)$prior['id'];
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO daily_sales_reports
                        (report_date, instore_sales, cod_sales, online_sales, total_sales,
                         order_count, refund_count, refund_amount, cash_counted, notes,
                         submitted_by, submitted_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $date,
                    $fig['instore_sales'], $fig['cod_sales'], $fig['online_sales'], $fig['total_sales'],
                    $fig['order_count'], $fig['refund_count'], $fig['refund_amount'],
                    $cash, $notes, getUserId(),
                ]);
                $reportId = (int)$pdo->lastInsertId();
                logAudit('submit_daily_sales_report', 'daily_sales_reports', $reportId, null, [
                    'report_date' => $date,
                    'total_sales' => $fig['total_sales'],
                    'order_count' => $fig['order_count'],
                ], 'success', 'End-of-day report for ' . $date);
            }

            echo json_encode([
                'success'  => true,
                'amended'  => (bool)$prior,
                'id'       => $reportId,
                'figures'  => $fig,
                'message'  => $prior
                    ? 'Amended report saved for ' . date('M j, Y', strtotime($date)) . '.'
                    : 'End-of-day report submitted for ' . date('M j, Y', strtotime($date)) . '.',
            ]);
        } catch (PDOException $e) {
            error_log('Daily sales report submit error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Could not save the report. Please try again.']);
        }
        break;

    case 'status':
        // Whether one date is already closed, and with what. Used by the page
        // to render the panel without a reload after a submission.
        $date = dsrParseDate($_GET['report_date'] ?? null) ?? date('Y-m-d');
        try {
            $pdo = getConnection();
            $stmt = $pdo->prepare("
                SELECT r.*, CONCAT_WS(' ', u.firstname, u.lastname) AS submitted_by_name
                FROM daily_sales_reports r
                LEFT JOIN users u ON u.id = r.submitted_by
                WHERE r.report_date = ? LIMIT 1
            ");
            $stmt->execute([$date]);
            $row = $stmt->fetch();
            echo json_encode([
                'success'   => true,
                'submitted' => (bool)$row,
                'report'    => $row ?: null,
                'live'      => sfDayFigures($pdo, $date),
            ]);
        } catch (PDOException $e) {
            error_log('Daily sales report status error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Server error']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
