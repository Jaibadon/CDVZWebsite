<?php
/**
 * Push a CADViz invoice to Xero (creates AUTHORISED invoice + optional email).
 *
 * POST params:
 *   Invoice_No (required)  — local invoice number to push
 *   email      (optional)  — '1' to also trigger email send via Xero
 */
require_once 'auth_check.php';
require_once 'db_connect.php';
require_once 'helpers.php';
require_once 'xero_client.php';
require_once 'lib_invoice_email.php';

if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403); die('Admin only.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); die('POST only.');
}

$invoiceNo = (int)($_POST['Invoice_No'] ?? 0);
$alsoEmail = !empty($_POST['email']);
if ($invoiceNo <= 0) die('Missing Invoice_No.');

$pdo = get_db();

try {
    $xc = new XeroClient($pdo);

    // Pull invoice header + client. Schema:
    //   Invoices.Date (timestamp), .PayBy (datetime), .Notes, .Order_No_INV,
    //            .Subtotal, .Tax_Rate, .Xero_InvoiceID (for re-push/update)
    //   Clients.billing_email
    // Pull the buyer-identifier columns (Address1/Address2, Phone, Mobile,
    // billing_email/email, Contact) so ensureContact() can push at least
    // one of the legally-required identifier details to Xero on every
    // invoice push (NZ tax-invoice rule).
    $contactCol = clients_has_contact($pdo) ? 'c.Contact AS ContactPerson' : 'NULL AS ContactPerson';
    $h = $pdo->prepare(
        "SELECT i.Invoice_No, i.Date AS Invoice_Date, i.PayBy AS Due_Date,
                i.PaymentOption,
                i.Notes AS Comments, i.Order_No_INV, i.Subtotal, i.Tax_Rate,
                i.Xero_InvoiceID,
                c.Client_id, c.Client_Name, c.Multiplier,
                c.Address1, c.Address2, c.Phone, c.Mobile,
                c.billing_email AS BillingEmail, c.email AS Email,
                {$contactCol}
           FROM Invoices i
           LEFT JOIN Clients c ON i.Client_ID = c.Client_id
          WHERE i.Invoice_No = ?"
    );
    $h->execute([$invoiceNo]);
    $head = $h->fetch();
    if (!$head) throw new Exception("Invoice #$invoiceNo not found locally.");

    // Status_INV = 0 ("Not Checked") means the invoice hasn't been reviewed
    // and must not be pushed to Xero or emailed. Bump it to "Ready to Send"
    // (1) on the edit screen first.
    $statusInvCheck = $pdo->prepare("SELECT Status_INV FROM Invoices WHERE Invoice_No = ?");
    $statusInvCheck->execute([$invoiceNo]);
    if ((int)$statusInvCheck->fetchColumn() === 0) {
        throw new Exception("Invoice #$invoiceNo is still marked Not Checked (Status_INV=0). Set it to Ready to Send before pushing to Xero.");
    }

    // Pull line items from the Timesheets that belong to this invoice. We
    // collapse by (proj, task) so the Xero invoice isn't a wall of rows.
    $lines = $pdo->prepare(
        "SELECT t.proj_id, p.JobName, t.Task,
                SUM(t.Hours)               AS Hours,
                COALESCE(MAX(t.Rate),0)    AS Rate
           FROM Timesheets t
           LEFT JOIN Projects p ON t.proj_id = p.proj_id
          WHERE t.Invoice_No = ? AND t.Hours > 0
          GROUP BY t.proj_id, t.Task
          ORDER BY p.JobName, t.Task"
    );
    $lines->execute([$invoiceNo]);
    $items = $lines->fetchAll();

    // Note: empty $items is no longer fatal — we fall back to a lump-sum
    // line built from Invoices.Subtotal further down (small fixed-price jobs).

    $multiplier = (float)($head['Multiplier'] ?? 1) ?: 1;
    $baseRate   = 90.00;

    $xeroLines = [];
    foreach ($items as $li) {
        $hrs  = (float)$li['Hours'];
        $rate = (float)$li['Rate'];
        if ($rate <= 0) $rate = $baseRate;
        $rate *= $multiplier;
        $xeroLines[] = [
            'Description' => trim(($li['JobName'] ?? '') . ' — ' . ($li['Task'] ?? '')),
            'Quantity'    => round($hrs, 2),
            'UnitAmount'  => round($rate, 2),
            'AccountCode' => '240',           // 240 Sales (CADViz Xero account)
            'TaxType'     => 'OUTPUT2',       // NZ GST 15% sales
        ];
    }

    // Fallback: lump-sum invoice (no timesheet rows e.g. small fixed-price jobs)
    if (empty($xeroLines) && (float)($head['Subtotal'] ?? 0) > 0) {
        $xeroLines[] = [
            'Description' => trim(($head['Comments'] ?? '') ?: ('Invoice #' . $invoiceNo)),
            'Quantity'    => 1,
            'UnitAmount'  => round((float)$head['Subtotal'], 2),
            'AccountCode' => '240',
            'TaxType'     => 'OUTPUT2',
        ];
    }

    if (empty($head['Client_Name'])) throw new Exception("Client missing name; can't create Xero contact.");

    // Build the buyer identifier set. Email is picked via the same fallback
    // the email helpers use so we never push an "invalid placeholder" that
    // Xero would reject (e.g. "No Email - erik").
    $buyerEmail = pick_billing_email($head['BillingEmail'] ?? null, $head['Email'] ?? null);
    $contactId  = $xc->ensureContact($head['Client_Name'], [
        'email'         => $buyerEmail,
        'phone'         => $head['Phone']         ?? null,
        'mobile'        => $head['Mobile']        ?? null,
        'addressLine1'  => $head['Address1']      ?? null,   // postal (multi-line)
        'addressLine2'  => $head['Address2']      ?? null,   // physical
        'country'       => 'New Zealand',
        'contactPerson' => $head['ContactPerson'] ?? null,
    ]);

    if (empty($xeroLines)) throw new Exception("Invoice #$invoiceNo has no line items and no subtotal — nothing to send.");

    // Resolve the DueDate from PayBy first; fall back to compute_pay_by()
    // (which honours the "20th of next month, ALWAYS" rule for option 1)
    // and persist it back on Invoices.PayBy so subsequent views agree.
    $invoiceDateForPush = $head['Invoice_Date'] ? date('Y-m-d', strtotime($head['Invoice_Date'])) : date('Y-m-d');
    $dueDate            = $head['Due_Date'] ? date('Y-m-d', strtotime($head['Due_Date'])) : null;
    if (!$dueDate) {
        $dueDate = compute_pay_by($invoiceDateForPush, $head['PaymentOption'] ?? null);
        if ($dueDate) {
            $pdo->prepare("UPDATE Invoices SET PayBy = ? WHERE Invoice_No = ?")
                ->execute([$dueDate, $invoiceNo]);
        }
    }
    if (!$dueDate) $dueDate = date('Y-m-d', strtotime('+20 days'));

    $invoiceBody = [
        'Type'            => 'ACCREC',
        'Contact'         => ['ContactID' => $contactId],
        'Date'            => $invoiceDateForPush,
        'DueDate'         => $dueDate,
        'InvoiceNumber'   => 'CAD-' . str_pad((string)$invoiceNo, 5, '0', STR_PAD_LEFT),
        'Reference'       => $head['Order_No_INV'] ?: ($head['Comments'] ?? ''),
        'LineAmountTypes' => 'Exclusive',
        'Status'          => 'AUTHORISED',
        'LineItems'       => $xeroLines,
    ];
    // Update an existing Xero invoice instead of creating a duplicate
    if (!empty($head['Xero_InvoiceID'])) {
        $invoiceBody['InvoiceID'] = $head['Xero_InvoiceID'];
    }

    $xeroResp = $xc->postInvoice($invoiceBody);
    $xeroId   = $xeroResp['InvoiceID'] ?? null;
    if (!$xeroId) throw new Exception('Xero returned no InvoiceID. ' . json_encode($xeroResp));

    // Persist the Xero IDs + initial status
    $pdo->prepare(
        "UPDATE Invoices SET Xero_InvoiceID = ?, Xero_Status = ?, Xero_AmountDue = ?, Xero_AmountPaid = ?, Xero_DueDate = ?, Xero_LastSynced = UTC_TIMESTAMP() WHERE Invoice_No = ?"
    )->execute([
        $xeroId,
        $xeroResp['Status']     ?? 'AUTHORISED',
        (float)($xeroResp['AmountDue']  ?? 0),
        (float)($xeroResp['AmountPaid'] ?? 0),
        $invoiceBody['DueDate'],
        $invoiceNo,
    ]);

    // Optional: email the client. Routes through accounts@cadviz.co.nz
    // (NOT Xero's mailer — Xero sends from a Xero-controlled From: address
    // that hits client spam folders). lib_invoice_email.php is the single
    // source of truth so this matches the Email-from-CADViz button exactly.
    $emailedNote = '';
    if ($alsoEmail) {
        try {
            $emailedNote = ' ' . send_invoice_email_via_smtp($pdo, $invoiceNo, false);
        } catch (Exception $em) {
            $emailedNote = ' WARNING: invoice created but email failed: ' . $em->getMessage();
        }
    }

    // Online URL (handy for Erik to copy)
    $onlineUrl = '';
    try {
        $onlineUrl = $xc->getOnlineInvoiceUrl($xeroId) ?? '';
        if ($onlineUrl) {
            $pdo->prepare("UPDATE Invoices SET Xero_OnlineUrl = ? WHERE Invoice_No = ?")
                ->execute([$onlineUrl, $invoiceNo]);
        }
    } catch (Exception $u) { /* non-fatal */ }

    ?>
    <!DOCTYPE html><html><head><meta charset="utf-8"><link href="site.css" rel="stylesheet"></head><body>
    <div class="page"><div class="card" style="background:#d6f5d6;border:2px solid #1a6b1a">
      <h2 style="margin-top:0;color:#1a6b1a">✓ Invoice #<?= $invoiceNo ?> pushed to Xero</h2>
      <p>Status: <strong><?= htmlspecialchars($xeroResp['Status'] ?? '?') ?></strong>
         &middot; Xero InvoiceID: <code><?= htmlspecialchars($xeroId) ?></code></p>
      <?php if ($emailedNote): ?><p><?= htmlspecialchars($emailedNote) ?></p><?php endif; ?>
      <?php if ($onlineUrl): ?><p>Online invoice URL: <a href="<?= htmlspecialchars($onlineUrl) ?>" target="_blank"><?= htmlspecialchars($onlineUrl) ?></a></p><?php endif; ?>
      <p><a href="invoice.php?Invoice_No=<?= $invoiceNo ?>" class="btn-primary">Back to invoice</a> &nbsp; <a href="invoice_list.php">Invoice list</a></p>
    </div></div></body></html>
    <?php
} catch (Exception $e) {
    ?>
    <!DOCTYPE html><html><head><meta charset="utf-8"><link href="site.css" rel="stylesheet"></head><body>
    <div class="page"><div class="card" style="background:#ffd6d6;border:2px solid #c33;color:#a00">
      <h2 style="margin-top:0;color:#a00">✗ Push to Xero failed</h2>
      <pre><?= htmlspecialchars($e->getMessage()) ?></pre>
      <p><a href="invoice.php?Invoice_No=<?= $invoiceNo ?>">Back</a></p>
    </div></div></body></html>
    <?php
}
