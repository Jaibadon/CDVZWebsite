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
    $upd = $pdo->prepare(
        "UPDATE Invoices
            SET Xero_Status = ?, Xero_AmountDue = ?, Xero_AmountPaid = ?, Xero_DueDate = ?, Xero_LastSynced = UTC_TIMESTAMP()
          WHERE Invoice_No = ?"
    );
    foreach ($rows as $r) {
        $xId = $r['Xero_InvoiceID'];
        if (!isset($byId[$xId])) continue;  // not in this status set; skip
        $inv = $byId[$xId];
        // Xero dates come as "/Date(epoch+0000)/" — extract the digits
        $due = null;
        if (!empty($inv['DueDateString'])) $due = substr($inv['DueDateString'], 0, 10);
        elseif (!empty($inv['DueDate']) && preg_match('/(\d{10})/', $inv['DueDate'], $m)) {
            $due = date('Y-m-d', (int)$m[1]);
        }
        $upd->execute([
            $inv['Status'] ?? null,
            (float)($inv['AmountDue']  ?? 0),
            (float)($inv['AmountPaid'] ?? 0),
            $due,
            (int)$r['Invoice_No'],
        ]);
        $result['updated']++;
    }
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
  <p>Updated <strong><?= (int)$result['updated'] ?></strong> invoice(s) from Xero.</p>
  <?php foreach ($result['errors'] as $e): ?>
    <p style="color:#c33"><?= htmlspecialchars($e) ?></p>
  <?php endforeach; ?>
  <p><a href="monthly_invoicing.php" class="btn-primary">Back to Monthly Invoicing</a></p>
</div></div></body></html>
