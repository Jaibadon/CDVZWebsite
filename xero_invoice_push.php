<?php
/**
 * Push a CADViz invoice to Xero (creates / updates AUTHORISED invoice).
 *
 * Two entry points:
 *   1. POST handler (the "Push to Xero" / "Push + Email" buttons) — falls
 *      through this file end to end and renders an HTML success page.
 *   2. As a library — `require_once` this file with the
 *      XERO_INVOICE_PUSH_LIBRARY_ONLY constant defined to expose
 *      `push_invoice_to_xero(PDO, int): array` without running the
 *      script. Used by lib_invoice_email.php so every "Email" click
 *      pushes the invoice fresh first (so the attached PDF and email
 *      body always match the latest local data).
 *
 * POST params (script mode):
 *   Invoice_No (required)  — local invoice number to push
 *   email      (optional)  — '1' to also email the client after pushing
 */
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/xero_client.php';

/**
 * Push (or re-push) a CADViz invoice to Xero. Throws on failure. Returns:
 *   [
 *     'xero_id'     => 'GUID',
 *     'status'      => 'AUTHORISED' | 'PAID' | ...,
 *     'amount_due'  => float,
 *     'amount_paid' => float,
 *     'due_date'    => 'YYYY-MM-DD',
 *     'online_url'  => 'https://...' | '',
 *   ]
 */
function push_invoice_to_xero(PDO $pdo, int $invoiceNo): array
{
    if ($invoiceNo <= 0) throw new Exception('Missing Invoice_No.');

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

    // Pull line items from the Timesheets that belong to this invoice.
    // We group by (proj, task, employee) so each staff member's portion of
    // a task gets its own line — the Xero description then shows who did
    // the work, and the per-line Rate matches that staff member's actual
    // billing rate (the old (proj, task) grouping used MAX(Rate) which
    // could over/under-state the line when two staff with different rates
    // worked the same task).
    $lines = $pdo->prepare(
        "SELECT t.proj_id, p.JobName, t.Task, t.Employee_id,
                s.Login                    AS StaffLogin,
                SUM(t.Hours)               AS Hours,
                COALESCE(MAX(t.Rate),0)    AS Rate
           FROM Timesheets t
           LEFT JOIN Projects p ON t.proj_id     = p.proj_id
           LEFT JOIN Staff    s ON t.Employee_id = s.Employee_ID
          WHERE t.Invoice_No = ? AND t.Hours > 0
          GROUP BY t.proj_id, t.Task, t.Employee_id, s.Login
          ORDER BY p.JobName, t.Task, s.Login"
    );
    $lines->execute([$invoiceNo]);
    $items = $lines->fetchAll();

    // Note: empty $items is no longer fatal — we fall back to a lump-sum
    // line built from Invoices.Subtotal further down (small fixed-price jobs).

    $multiplier = (float)($head['Multiplier'] ?? 1) ?: 1;
    $baseRate   = 90.00;

    // NOTE on the multiplier: invoice_gen.php already bakes the client
    // Multiplier into Timesheets.Rate when it generates the invoice
    // (Rate = BILLING_RATE × Multiplier). Re-multiplying here would
    // apply the discount/markup TWICE, which was the user-reported
    // "doubling 10% discount" bug. We only multiply by $multiplier when
    // we fall back to the base rate (since that path hasn't been touched
    // by invoice_gen).

    $xeroLines = [];
    foreach ($items as $li) {
        $hrs  = (float)$li['Hours'];
        $rate = (float)$li['Rate'];
        if ($rate <= 0) {
            // Fallback: legacy / fixed-cost timesheet rows where Rate
            // wasn't set by invoice_gen.php. Apply the multiplier here
            // because it never had a chance to be applied earlier.
            $rate = $baseRate * $multiplier;
        }
        // else: $rate already includes the multiplier (baked in by
        // invoice_gen.php) — DO NOT multiply again.

        $job        = trim((string)($li['JobName']    ?? ''));
        $task       = trim((string)($li['Task']       ?? ''));
        $staffLogin = trim((string)($li['StaffLogin'] ?? ''));

        // "JobName — Task (by staffLogin)" — falls back gracefully when any
        // piece is missing so we never end up with leading dashes or empty
        // parens in the Xero description.
        $description = $job;
        if ($task !== '')       $description = $description !== '' ? $description . ' — ' . $task : $task;
        if ($staffLogin !== '') $description .= ' (by ' . $staffLogin . ')';

        $xeroLines[] = [
            'Description' => $description,
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

    // Online URL (handy for the email body and the admin to copy)
    $onlineUrl = '';
    try {
        $onlineUrl = $xc->getOnlineInvoiceUrl($xeroId) ?? '';
        if ($onlineUrl) {
            $pdo->prepare("UPDATE Invoices SET Xero_OnlineUrl = ? WHERE Invoice_No = ?")
                ->execute([$onlineUrl, $invoiceNo]);
        }
    } catch (Exception $u) { /* non-fatal */ }

    return [
        'xero_id'     => $xeroId,
        'status'      => $xeroResp['Status']     ?? 'AUTHORISED',
        'amount_due'  => (float)($xeroResp['AmountDue']  ?? 0),
        'amount_paid' => (float)($xeroResp['AmountPaid'] ?? 0),
        'due_date'    => $invoiceBody['DueDate'],
        'online_url'  => (string)$onlineUrl,
    ];
}

// ── Library mode: stop here when the file is required just to expose
//    the push_invoice_to_xero() function. lib_invoice_email.php sets
//    XERO_INVOICE_PUSH_LIBRARY_ONLY before requiring so the script body
//    below never fires from inside the email path.
if (defined('XERO_INVOICE_PUSH_LIBRARY_ONLY')) return;

// ── Script mode: POST handler for the "Push to Xero" / "Push + Email" buttons.
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/lib_invoice_email.php';

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
    $result = push_invoice_to_xero($pdo, $invoiceNo);

    // Optional: email the client. Routes through accounts@cadviz.co.nz
    // (NOT Xero's mailer — Xero sends from a Xero-controlled From: address
    // that hits client spam folders). Pass skipPush=true so the email
    // helper doesn't push AGAIN — we just did it ourselves above.
    $emailedNote = '';
    if ($alsoEmail) {
        try {
            $emailedNote = ' ' . send_invoice_email_via_smtp($pdo, $invoiceNo, false, true);
        } catch (Exception $em) {
            $emailedNote = ' WARNING: invoice created but email failed: ' . $em->getMessage();
        }
    }
    ?>
    <!DOCTYPE html><html><head><meta charset="utf-8"><link href="site.css" rel="stylesheet"></head><body>
    <div class="page"><div class="card" style="background:#d6f5d6;border:2px solid #1a6b1a">
      <h2 style="margin-top:0;color:#1a6b1a">&#10003; Invoice #<?= $invoiceNo ?> pushed to Xero</h2>
      <p>Status: <strong><?= htmlspecialchars($result['status']) ?></strong>
         &middot; Xero InvoiceID: <code><?= htmlspecialchars($result['xero_id']) ?></code></p>
      <?php if ($emailedNote): ?><p><?= htmlspecialchars($emailedNote) ?></p><?php endif; ?>
      <?php if (!empty($result['online_url'])): ?>
        <p>Online invoice URL: <a href="<?= htmlspecialchars($result['online_url']) ?>" target="_blank"><?= htmlspecialchars($result['online_url']) ?></a></p>
      <?php endif; ?>
      <p><a href="invoice.php?Invoice_No=<?= $invoiceNo ?>" class="btn-primary">Back to invoice</a> &nbsp; <a href="invoice_list.php">Invoice list</a></p>
    </div></div></body></html>
    <?php
} catch (Exception $e) {
    ?>
    <!DOCTYPE html><html><head><meta charset="utf-8"><link href="site.css" rel="stylesheet"></head><body>
    <div class="page"><div class="card" style="background:#ffd6d6;border:2px solid #c33;color:#a00">
      <h2 style="margin-top:0;color:#a00">&#10007; Push to Xero failed</h2>
      <pre><?= htmlspecialchars($e->getMessage()) ?></pre>
      <p><a href="invoice.php?Invoice_No=<?= $invoiceNo ?>">Back</a></p>
    </div></div></body></html>
    <?php
}
