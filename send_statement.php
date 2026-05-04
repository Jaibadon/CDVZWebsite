<?php
/**
 * Email an "Overdue Statement" plus the Xero PDFs of every unpaid invoice
 * for one client. Sets each unsent invoice's Sent flag to 1 once mailed.
 *
 * Different from xero_invoice_email.php (single-invoice send): this builds
 * a per-client summary in the body, attaches one PDF per outstanding
 * invoice, and asks the client to pay each invoice individually using
 * its CAD-0xxxxx reference.
 *
 * POST params:
 *   client_id (required)
 *   cc_erik   (optional)
 */

require_once 'auth_check.php';
require_once 'db_connect.php';
require_once 'helpers.php';
require_once 'xero_client.php';
require_once 'smtp_mailer.php';
// Sync first so paid invoices drop off and the email reflects current truth.
define('XERO_SYNC_LIBRARY_ONLY', true);
require_once 'xero_sync.php';

if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403); die('Admin only.');
}

// Test mode: GET ?test_to=email@example.com&invoice_no=N picks the
// client of invoice N and renders the manual statement, but diverts
// recipient + skips all persistence (no Sent flags, no SentToContact).
$isTest = !empty($_GET['test_to']);
if (!$isTest && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); die('POST only.');
}

$ccErik = !empty($_POST['cc_erik']);
$back   = $_POST['back'] ?? ($_SERVER['HTTP_REFERER'] ?? ($isTest ? 'email_templates.php' : 'invoice_list.php'));

$pdo = get_db();

$clientId = (int)($_POST['client_id'] ?? 0);
if ($isTest && $clientId <= 0) {
    // Test mode: derive client_id from the invoice_no the admin selected.
    $invSeed = (int)($_GET['invoice_no'] ?? 0);
    if ($invSeed > 0) {
        try {
            $st = $pdo->prepare("SELECT Client_ID FROM Invoices WHERE Invoice_No = ?");
            $st->execute([$invSeed]);
            $clientId = (int)($st->fetchColumn() ?: 0);
        } catch (Exception $e) {}
    }
}
if ($clientId <= 0) die('Missing client_id.');

try {
    // Refresh from Xero before we decide what's unpaid.
    $syncResult = run_xero_sync($pdo);

    // Pull both Billing_Email AND email so pick_billing_email() can fall
    // back if billing_email is blank/invalid. Contact is used for the
    // "Dear FirstName" greeting (gated on the column existing).
    $contactCol = clients_has_contact($pdo) ? 'Contact' : 'NULL AS Contact';
    $client = $pdo->prepare("SELECT Client_id, Client_Name, Address1, {$contactCol}, Billing_Email, email FROM Clients WHERE Client_id = ?");
    $client->execute([$clientId]);
    $cli = $client->fetch(PDO::FETCH_ASSOC);
    if (!$cli) throw new Exception("Client #$clientId not found.");
    // Multiple addresses (e.g. "ap@acme.co; cfo@acme.co") in either column
    // are split, validated, and all included on the To: line.
    if ($isTest) {
        $billtos = [(string)$_GET['test_to']];
    } else {
        $billtos = pick_billing_emails($cli['Billing_Email'] ?? null, $cli['email'] ?? null);
        if (empty($billtos)) throw new Exception("Client #$clientId (" . ($cli['Client_Name'] ?? '') . ") has no valid email (Billing Email or Email). Fix it on the Client page first.");
    }
    $billto = implode(', ', $billtos); // for the success-flash text below

    // Pull all unpaid invoices for this client. Xero_OnlineUrl is the
    // pay-online link Xero generates per invoice — embedded in each row of
    // the email so the client can click straight through to settle it.
    $st = $pdo->prepare(
        "SELECT i.Invoice_No, i.Subtotal, i.Tax_Rate, i.PaymentOption, i.Date, i.PayBy,
                i.Sent, i.Status_INV,
                i.Xero_InvoiceID, i.Xero_AmountDue, i.Xero_DueDate, i.Xero_OnlineUrl,
                p.JobName, p.Order_No
           FROM Invoices i
           LEFT JOIN Projects p ON i.Proj_ID = p.proj_id
          WHERE i.Paid = 0
            AND i.Client_ID = ?
          ORDER BY i.Invoice_No"
    );
    $st->execute([$clientId]);
    $invoices = $st->fetchAll(PDO::FETCH_ASSOC);
    if (empty($invoices)) throw new Exception("Client has no outstanding invoices to statement.");

    // Authoritative outstanding from Xero.
    $xc = new XeroClient($pdo);
    $xeroOutstanding = null;
    try { $xeroOutstanding = $xc->getContactOutstanding($cli['Client_Name']); }
    catch (Exception $e) { /* non-fatal */ }

    // Fetch a PDF per invoice that has a Xero_InvoiceID. Skip invoices
    // missing a Xero ID (just call them out in the body).
    $attachments = [];
    $missingPdf  = [];
    foreach ($invoices as $inv) {
        if (empty($inv['Xero_InvoiceID'])) {
            $missingPdf[] = (int)$inv['Invoice_No'];
            continue;
        }
        try {
            $pdf = $xc->getInvoicePdf($inv['Xero_InvoiceID']);
            $invNumStr = 'CAD-' . str_pad((string)$inv['Invoice_No'], 5, '0', STR_PAD_LEFT);
            $attachments[] = [
                'name' => $invNumStr . '.pdf',
                'mime' => 'application/pdf',
                'data' => $pdf,
            ];
        } catch (Exception $e) {
            $missingPdf[] = (int)$inv['Invoice_No'];
        }
    }

    // Build statement body.
    $localGrand = 0.0;
    $linesText  = [];
    $linesHtml  = [];
    foreach ($invoices as $inv) {
        $invNo     = (int)$inv['Invoice_No'];
        $invNumStr = 'CAD-' . str_pad((string)$invNo, 5, '0', STR_PAD_LEFT);
        $subtotal  = (float)($inv['Subtotal'] ?? 0);
        $taxRate   = (float)($inv['Tax_Rate'] ?? 0);
        $localTotal = $subtotal + ($subtotal * $taxRate);
        $xeroDue   = isset($inv['Xero_AmountDue']) && $inv['Xero_AmountDue'] !== null ? (float)$inv['Xero_AmountDue'] : null;
        $amount    = $xeroDue !== null && $xeroDue > 0 ? $xeroDue : $localTotal;
        $localGrand += $amount;

        $invDate    = $inv['Date'] ? date('d/m/Y', strtotime($inv['Date'])) : '';
        $dueIso     = $inv['PayBy'] ?: ($inv['Xero_DueDate'] ?: compute_pay_by($inv['Date'] ?? null, $inv['PaymentOption'] ?? null));
        $dueDate    = $dueIso ? date('d/m/Y', strtotime($dueIso)) : '';
        $job        = trim(($inv['Order_No'] ? $inv['Order_No'] . ' / ' : '') . ($inv['JobName'] ?? ''));
        $payUrl     = trim((string)($inv['Xero_OnlineUrl'] ?? ''));

        $linesText[] = sprintf("  %s  %s  due %s  %s  $%s%s",
            $invNumStr, $invDate ?: '          ', $dueDate ?: '          ', $job, number_format($amount, 2),
            $payUrl !== '' ? "\r\n      View online: {$payUrl}" : '');
        $payCell = $payUrl !== ''
            ? '<a href="' . htmlspecialchars($payUrl) . '" style="color:#0a6;text-decoration:underline">View online</a>'
            : '<span style="color:#888">&mdash;</span>';
        $linesHtml[] = '<tr>'
            . '<td style="padding:4px 8px"><strong>' . $invNumStr . '</strong></td>'
            . '<td style="padding:4px 8px">' . htmlspecialchars($invDate) . '</td>'
            . '<td style="padding:4px 8px">' . htmlspecialchars($dueDate) . '</td>'
            . '<td style="padding:4px 8px">' . htmlspecialchars($job) . '</td>'
            . '<td style="padding:4px 8px;text-align:right">$' . number_format($amount, 2) . '</td>'
            . '<td style="padding:4px 8px;text-align:center">' . $payCell . '</td>'
            . '</tr>';
    }

    $totalForDisplay = $xeroOutstanding ?? $localGrand;
    // Greet by Contact's first name when available; "Valued Customer" otherwise.
    $greetName  = client_first_name($cli['Contact'] ?? null);
    $clientName = trim($cli['Client_Name'] ?: '');

    // Show one of the actual invoice numbers in the "use this as your bank
    // reference" line (rather than a hard-coded CAD-09489), so the recipient
    // sees a number that's actually on their statement.
    $exampleRef = 'CAD-' . str_pad((string)($invoices[0]['Invoice_No'] ?? 0), 5, '0', STR_PAD_LEFT);

    // Pre-render the HTML invoice table and plain-text invoice list as
    // placeholder substitutions so the editable template doesn't have
    // to know how to format them.
    $invoiceTableHtml = '<table border="1" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:13px">'
        . '<thead><tr style="background:#eee">'
        . '<th style="padding:6px 8px;text-align:left">Invoice</th>'
        . '<th style="padding:6px 8px;text-align:left">Date</th>'
        . '<th style="padding:6px 8px;text-align:left">Due</th>'
        . '<th style="padding:6px 8px;text-align:left">Job</th>'
        . '<th style="padding:6px 8px;text-align:right">Amount Due</th>'
        . '<th style="padding:6px 8px;text-align:center">View</th>'
        . '</tr></thead><tbody>'
        . implode('', $linesHtml)
        . '</tbody></table>';
    if (!empty($missingPdf)) {
        $invoiceTableHtml .= '<p style="color:#a00">PDF attachments could not be retrieved for: '
                          . implode(', ', array_map(fn($n) => 'CAD-' . str_pad((string)$n, 5, '0', STR_PAD_LEFT), $missingPdf))
                          . '. Please reply to this email if you need copies.</p>';
    }
    $linesTextStr = implode("\r\n", $linesText);
    if (!empty($missingPdf)) {
        $linesTextStr .= "\r\n(PDF attachments could not be retrieved for: CAD-"
                       . implode(', CAD-', array_map(fn($n) => str_pad((string)$n, 5, '0', STR_PAD_LEFT), $missingPdf))
                       . ". Please contact us if you need copies.)";
    }

    $vars = array_merge(default_email_boilerplate(), [
        'name'              => $greetName,
        'client_name'       => $clientName,
        'count'             => (string)count($invoices),
        'total_due'         => '$' . number_format($totalForDisplay, 2),
        'invoice_table_html'=> $invoiceTableHtml,
        'invoice_lines_text'=> $linesTextStr,
        'example_ref'       => $exampleRef,
    ]);
    $subject  = render_email_template(email_template_get($pdo, 'statement_manual', 'subject'), $vars);
    $textBody = render_email_template(email_template_get($pdo, 'statement_manual', 'text'),    $vars);
    $htmlBody = render_email_template(email_template_get($pdo, 'statement_manual', 'html'),    $vars);

    SmtpMailer::send([
        'to'          => $billtos,
        'cc'          => $isTest ? [] : ($ccErik ? ['erik@cadviz.co.nz'] : []),
        'bcc'         => $isTest ? [] : ['accounts@cadviz.co.nz'],
        'reply_to'    => 'accounts@cadviz.co.nz',
        'subject'     => ($isTest ? '[TEST] ' : '') . $subject,
        'text'        => $textBody,
        'html'        => $htmlBody,
        'attachments' => $attachments,
    ]);

    if (!$isTest) {
        // Mark every invoice in this batch as Sent + Status_INV=2 (refreshing
        // date_sent on every statement send so Erik can see when the most
        // recent batch went out, even on resends). Also mark SentToContact
        // in Xero where we have a Xero ID, so the Xero UI agrees.
        $markSent = $pdo->prepare("UPDATE Invoices SET Sent = 1, date_sent = NOW(), Status_INV = 2 WHERE Invoice_No = ?");
        $markedCount = 0;
        foreach ($invoices as $inv) {
            $wasUnsent = (int)($inv['Sent'] ?? 0) === 0;
            $markSent->execute([(int)$inv['Invoice_No']]);
            if ($wasUnsent) $markedCount++;
            if (!empty($inv['Xero_InvoiceID'])) {
                try { $xc->markSentToContact($inv['Xero_InvoiceID']); } catch (Exception $e) { /* non-fatal */ }
            }
        }
    } else {
        $markedCount = 0;
    }

    if ($isTest) {
        echo "<!DOCTYPE html><html><body style=\"font-family:Arial;padding:18px\">"
           . "<h2 style=\"color:#7a4d00\">&#129514; Test statement sent</h2>"
           . "<p>Diverted to <code>" . htmlspecialchars($billto) . "</code>. "
           . count($invoices) . " invoice(s), " . count($attachments) . " PDF(s) attached. "
           . "Sent / Status_INV flags NOT updated.</p>"
           . "<p><a href=\"" . htmlspecialchars($back) . "\">&larr; Back</a></p>"
           . "</body></html>";
        exit;
    }
    $_SESSION['xero_flash'] = "Statement emailed to {$billto} (" . count($invoices) . " invoice(s), " . count($attachments) . " PDF(s) attached, {$markedCount} newly marked Sent).";
} catch (Exception $e) {
    $_SESSION['xero_flash_err'] = 'Statement email failed: ' . $e->getMessage();
}

header('Location: ' . $back);
exit;
