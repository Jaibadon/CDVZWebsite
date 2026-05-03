<?php
/**
 * Pull AUTHORISED + PAID invoice statuses from Xero and refresh the local
 * Xero_* columns on Invoices. Designed to be called on demand from the
 * monthly_invoicing.php page (Jen clicks "Sync from Xero"), automatically
 * before every reminder push, and from cron. Safe to call repeatedly.
 *
 * Returns JSON when called with ?json=1 (used by an in-page sync button),
 * otherwise renders a small confirmation page.
 *
 * Trigger options:
 *   - Web: visit xero_sync.php as admin
 *   - CLI / cron:  /usr/bin/php /path/to/xero_sync.php cron
 *   - From PHP:    require_once 'xero_sync.php'; $r = run_xero_sync(get_db());
 */
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/xero_client.php';

/**
 * Pull invoice statuses from Xero and update local Xero_* columns.
 * Auto-flips Invoices.Paid=1 + DatePaid when Xero says PAID.
 *
 * Returns ['updated' => N, 'paid_marked' => N, 'errors' => [...]].
 */
function run_xero_sync(PDO $pdo): array
{
    $result = ['updated' => 0, 'paid_marked' => 0, 'errors' => []];
    try {
        if (!XeroClient::isConfigured() || !XeroClient::isConnected($pdo)) {
            $result['errors'][] = 'Xero not connected — sync skipped.';
            return $result;
        }
        $xc = new XeroClient($pdo);
        $invoices = $xc->getInvoicesByStatus(['AUTHORISED','SUBMITTED','PAID']);
        $byId = [];
        foreach ($invoices as $inv) {
            if (!empty($inv['InvoiceID'])) $byId[$inv['InvoiceID']] = $inv;
        }

        $rows = $pdo->query("SELECT Invoice_No, Xero_InvoiceID FROM Invoices WHERE Xero_InvoiceID IS NOT NULL")->fetchAll();
        $upd = $pdo->prepare(
            "UPDATE Invoices
                SET Xero_Status = ?, Xero_AmountDue = ?, Xero_AmountPaid = ?, Xero_DueDate = ?, Xero_LastSynced = UTC_TIMESTAMP(),
                    Paid = CASE WHEN ? = 'PAID' THEN 1 ELSE Paid END,
                    DatePaid = CASE WHEN ? = 'PAID' AND DatePaid IS NULL THEN ? ELSE DatePaid END
              WHERE Invoice_No = ?"
        );
        foreach ($rows as $r) {
            $xId = $r['Xero_InvoiceID'];
            if (!isset($byId[$xId])) continue;
            $inv = $byId[$xId];

            $due = null;
            if (!empty($inv['DueDateString'])) $due = substr($inv['DueDateString'], 0, 10);
            elseif (!empty($inv['DueDate']) && preg_match('/(\d{10})/', $inv['DueDate'], $m)) {
                $due = date('Y-m-d', (int)$m[1]);
            }

            $paidDate = null;
            if (!empty($inv['FullyPaidOnDateString'])) $paidDate = substr($inv['FullyPaidOnDateString'], 0, 10);
            elseif (!empty($inv['FullyPaidOnDate']) && preg_match('/(\d{10})/', $inv['FullyPaidOnDate'], $m)) {
                $paidDate = date('Y-m-d', (int)$m[1]);
            }
            if (($inv['Status'] ?? '') === 'PAID' && !$paidDate) $paidDate = date('Y-m-d');

            $status = $inv['Status'] ?? null;
            $upd->execute([
                $status,
                (float)($inv['AmountDue']  ?? 0),
                (float)($inv['AmountPaid'] ?? 0),
                $due,
                $status,
                $status,
                $paidDate,
                (int)$r['Invoice_No'],
            ]);
            $result['updated']++;
            if ($status === 'PAID') $result['paid_marked']++;
        }
    } catch (Exception $e) {
        $result['errors'][] = $e->getMessage();
    }
    return $result;
}

// Stop here when this file is loaded purely as a library (no CLI args, no web request body).
if (defined('XERO_SYNC_LIBRARY_ONLY')) return;

$isCli = (php_sapi_name() === 'cli');
$isCron = $isCli && in_array('cron', $argv ?? [], true);

if (!$isCli) {
    require_once __DIR__ . '/auth_check.php';
    if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
        http_response_code(403); die('Admin only.');
    }
}

$pdo    = get_db();
$json   = !$isCli && !empty($_GET['json']);
$result = run_xero_sync($pdo);

if ($isCli) {
    echo "Xero sync @ " . date('c') . "\n";
    echo "  updated:     " . (int)$result['updated'] . "\n";
    echo "  paid_marked: " . (int)$result['paid_marked'] . "\n";
    foreach ($result['errors'] as $e) echo "  ERROR: $e\n";
    exit;
}

if ($json) {
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><link href="site.css" rel="stylesheet"></head>
<body><div class="page"><div class="card">
  <h2>Xero sync</h2>
  <p>Updated <strong><?= (int)$result['updated'] ?></strong> invoice(s) from Xero.
     <?php if (!empty($result['paid_marked'])): ?>
       <br><span style="color:#1a6b1a">&#10003; <?= (int)$result['paid_marked'] ?> invoice(s) auto-marked as PAID locally.</span>
     <?php endif; ?>
  </p>
  <?php foreach ($result['errors'] as $e): ?>
    <p style="color:#c33"><?= htmlspecialchars($e) ?></p>
  <?php endforeach; ?>
  <p><a href="monthly_invoicing.php" class="btn-primary">Back to Monthly Invoicing</a></p>
</div></div></body></html>
