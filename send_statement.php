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
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); die('POST only.');
}

$clientId = (int)($_POST['client_id'] ?? 0);
$ccErik   = !empty($_POST['cc_erik']);
$back     = $_POST['back'] ?? ($_SERVER['HTTP_REFERER'] ?? 'invoice_list.php');
if ($clientId <= 0) die('Missing client_id.');

$pdo = get_db();

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
    $billto = pick_billing_email($cli['Billing_Email'] ?? null, $cli['email'] ?? null);
    if (!$billto) throw new Exception("Client #$clientId (" . ($cli['Client_Name'] ?? '') . ") has no valid email (Billing Email or Email). Fix it on the Client page first.");

    // Pull all unpaid invoices for this client.
    $st = $pdo->prepare(
        "SELECT i.Invoice_No, i.Subtotal, i.Tax_Rate, i.PaymentOption, i.Date, i.PayBy,
                i.Sent, i.Status_INV,
                i.Xero_InvoiceID, i.Xero_AmountDue, i.Xero_DueDate,
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

        $linesText[] = sprintf("  %s  %s  due %s  %s  $%s",
            $invNumStr, $invDate ?: '          ', $dueDate ?: '          ', $job, number_format($amount, 2));
        $linesHtml[] = '<tr>'
            . '<td style="padding:4px 8px"><strong>' . $invNumStr . '</strong></td>'
            . '<td style="padding:4px 8px">' . htmlspecialchars($invDate) . '</td>'
            . '<td style="padding:4px 8px">' . htmlspecialchars($dueDate) . '</td>'
            . '<td style="padding:4px 8px">' . htmlspecialchars($job) . '</td>'
            . '<td style="padding:4px 8px;text-align:right">$' . number_format($amount, 2) . '</td>'
            . '</tr>';
    }

    $totalForDisplay = $xeroOutstanding ?? $localGrand;
    // Greet by Contact's first name when available; "there" otherwise.
    $greetName  = client_first_name($cli['Contact'] ?? null, 'there');
    $clientName = trim($cli['Client_Name'] ?: '');

    $textBody  = "Dear {$greetName},\r\n\r\n";
    $textBody .= "Please find attached a statement of outstanding invoices from CADViz Limited (" . count($invoices) . " invoice(s)).\r\n\r\n";
    $textBody .= "If you have already paid any of the invoices listed below, please disregard this statement for those — our records simply haven't caught up with your payment(s) yet.\r\n\r\n";
    $textBody .= "Outstanding balance: $" . number_format($totalForDisplay, 2) . "\r\n\r\n";
    $textBody .= "Outstanding invoices:\r\n" . implode("\r\n", $linesText) . "\r\n\r\n";
    $textBody .= "IMPORTANT: please pay each invoice INDIVIDUALLY using that invoice's number (e.g. CAD-09489) as the bank-transfer reference, so we can match each payment to the correct invoice.\r\n\r\n";
    $textBody .= "Internet Bank Transfer: 03 0275 0551274 00\r\n\r\n";
    if (!empty($missingPdf)) {
        $textBody .= "(PDF attachments could not be retrieved for: CAD-" . implode(', CAD-', array_map(fn($n) => str_pad((string)$n, 5, '0', STR_PAD_LEFT), $missingPdf)) . ". Please contact us if you need copies.)\r\n\r\n";
    }
    $textBody .= "If you have any other queries please reply to this email.\r\n\r\n";
    $textBody .= "Kind regards,\r\nCADViz Accounts\r\naccounts@cadviz.co.nz\r\n";

    $htmlBody  = '<p>Dear ' . htmlspecialchars($greetName) . ',</p>';
    $htmlBody .= '<p>Please find attached a statement of outstanding invoices from <strong>CADViz Limited</strong> (' . count($invoices) . ' invoice' . (count($invoices) === 1 ? '' : 's') . ').</p>';
    $htmlBody .= '<p style="color:#666"><em>If you have already paid any of the invoices listed below, please disregard this statement for those &mdash; our records simply haven\'t caught up with your payment(s) yet.</em></p>';
    $htmlBody .= '<p style="font-size:16px"><strong>Outstanding balance: $' . number_format($totalForDisplay, 2) . '</strong></p>';
    $htmlBody .= '<table border="1" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:13px">';
    $htmlBody .= '<thead><tr style="background:#eee">'
              .  '<th style="padding:6px 8px;text-align:left">Invoice</th>'
              .  '<th style="padding:6px 8px;text-align:left">Date</th>'
              .  '<th style="padding:6px 8px;text-align:left">Due</th>'
              .  '<th style="padding:6px 8px;text-align:left">Job</th>'
              .  '<th style="padding:6px 8px;text-align:right">Amount Due</th>'
              .  '</tr></thead><tbody>';
    $htmlBody .= implode('', $linesHtml);
    $htmlBody .= '</tbody></table>';
    $htmlBody .= '<p style="color:#a00000"><strong>Important:</strong> please pay each invoice <em>individually</em> and quote that invoice\'s reference (e.g. <strong>CAD-09489</strong>) on your bank transfer so we can match each payment to the correct invoice.</p>';
    $htmlBody .= '<p>Internet Bank Transfer: <strong>03 0275 0551274 00</strong></p>';
    if (!empty($missingPdf)) {
        $htmlBody .= '<p style="color:#a00">PDF attachments could not be retrieved for: '
                  . implode(', ', array_map(fn($n) => 'CAD-' . str_pad((string)$n, 5, '0', STR_PAD_LEFT), $missingPdf))
                  . '. Please reply to this email if you need copies.</p>';
    }
    $htmlBody .= '<p>If you have any other queries please reply to this email.</p>';
    $htmlBody .= '<p>Kind regards,<br>CADViz Accounts<br>accounts@cadviz.co.nz</p>';

    $subject = 'Statement of outstanding invoices from CADViz Limited';

    SmtpMailer::send([
        'to'          => $billto,
        'cc'          => $ccErik ? ['erik@cadviz.co.nz'] : [],
        'bcc'         => ['accounts@cadviz.co.nz'],
        'reply_to'    => 'accounts@cadviz.co.nz',
        'subject'     => $subject,
        'text'        => $textBody,
        'html'        => $htmlBody,
        'attachments' => $attachments,
    ]);

    // Mark every previously-unsent invoice in this batch as Sent. Also mark
    // SentToContact in Xero where we have a Xero ID, so the Xero UI agrees.
    $markSent = $pdo->prepare("UPDATE Invoices SET Sent = 1, date_sent = NOW() WHERE Invoice_No = ? AND COALESCE(Sent, 0) = 0");
    $markedCount = 0;
    foreach ($invoices as $inv) {
        if ((int)($inv['Sent'] ?? 0) !== 0) continue;
        $markSent->execute([(int)$inv['Invoice_No']]);
        $markedCount += $markSent->rowCount();
        if (!empty($inv['Xero_InvoiceID'])) {
            try { $xc->markSentToContact($inv['Xero_InvoiceID']); } catch (Exception $e) { /* non-fatal */ }
        }
    }

    $_SESSION['xero_flash'] = "Statement emailed to {$billto} (" . count($invoices) . " invoice(s), " . count($attachments) . " PDF(s) attached, {$markedCount} newly marked Sent).";
} catch (Exception $e) {
    $_SESSION['xero_flash_err'] = 'Statement email failed: ' . $e->getMessage();
}

header('Location: ' . $back);
exit;
