<?php
/**
 * Pull AUTHORISED + PAID invoice statuses from Xero and refresh the local
 * Xero_* columns on Invoices. Designed to be called on demand from the
 * monthly_invoicing.php page (Jen clicks "Sync from Xero") and is safe to
 * call repeatedly.
 *
 * Returns JSON when called with ?json=1 (used by an in-page sync button),
 * otherwise renders a small confirmation page.
 */
require_once 'auth_check.php';
require_once 'db_connect.php';
require_once 'xero_client.php';

if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403); die('Admin only.');
}

$json = !empty($_GET['json']);
$pdo  = get_db();

$result = ['updated' => 0, 'errors' => []];

try {
    $xc = new XeroClient($pdo);
    // Pull a focused set: AUTHORISED (outstanding), SUBMITTED, PAID (recent)
    $invoices = $xc->getInvoicesByStatus(['AUTHORISED','SUBMITTED','PAID']);
    $byId = [];
    foreach ($invoices as $inv) {
        if (!empty($inv['InvoiceID'])) $byId[$inv['InvoiceID']] = $inv;
    }

    // Update only invoices we've previously pushed
    $rows = $pdo->query("SELECT Invoice_No, Xero_InvoiceID FROM Invoices WHERE Xero_InvoiceID IS NOT NULL")->fetchAll();
    // Update Xero_* columns AND, when Xero says PAID, flip our local
    // Paid=1 + DatePaid (so invoice_list.php / monthly_invoicing.php
    // automatically drop these from the unpaid views).
    $upd = $pdo->prepare(
        "UPDATE Invoices
            SET Xero_Status = ?, Xero_AmountDue = ?, Xero_AmountPaid = ?, Xero_DueDate = ?, Xero_LastSynced = UTC_TIMESTAMP(),
                Paid = CASE WHEN ? = 'PAID' THEN 1 ELSE Paid END,
                DatePaid = CASE WHEN ? = 'PAID' AND DatePaid IS NULL THEN ? ELSE DatePaid END
          WHERE Invoice_No = ?"
    );
    $paidCount = 0;
    foreach ($rows as $r) {
        $xId = $r['Xero_InvoiceID'];
        if (!isset($byId[$xId])) continue;
        $inv = $byId[$xId];

        // Due date — prefer the string variant, fall back to /Date(epoch)/
        $due = null;
        if (!empty($inv['DueDateString'])) $due = substr($inv['DueDateString'], 0, 10);
        elseif (!empty($inv['DueDate']) && preg_match('/(\d{10})/', $inv['DueDate'], $m)) {
            $due = date('Y-m-d', (int)$m[1]);
        }

        // Fully-paid date (only present once Xero marks it PAID)
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
            $status,                 // for the CASE WHEN comparing to 'PAID'
            $status,                 // same for DatePaid
            $paidDate,               // fed into DatePaid only when status=PAID
            (int)$r['Invoice_No'],
        ]);
        $result['updated']++;
        if ($status === 'PAID') $paidCount++;
    }
    $result['paid_marked'] = $paidCount;
} catch (Exception $e) {
    $result['errors'][] = $e->getMessage();
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
       <br><span style="color:#1a6b1a">✓ <?= (int)$result['paid_marked'] ?> invoice(s) auto-marked as PAID locally.</span>
     <?php endif; ?>
  </p>
  <?php foreach ($result['errors'] as $e): ?>
    <p style="color:#c33"><?= htmlspecialchars($e) ?></p>
  <?php endforeach; ?>
  <p><a href="monthly_invoicing.php" class="btn-primary">Back to Monthly Invoicing</a></p>
</div></div></body></html>
