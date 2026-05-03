<?php
/**
 * Email an invoice from accounts@cadviz.co.nz instead of from Xero.
 *
 * Why we don't use Xero's built-in email:
 *   POST /Invoices/{id}/Email always sends from a Xero-controlled
 *   address (something@post.xero.com). That hits client spam folders
 *   and breaks the "from CADViz" branding. Sending from our own
 *   accounts@cadviz.co.nz address with proper SPF/DKIM is the
 *   recommended path.
 *
 * Flow:
 *   1. Look up the local invoice + its Xero_InvoiceID
 *   2. Pull the PDF from Xero (Accept: application/pdf)
 *   3. Build a multipart MIME message with proper headers + the PDF
 *   4. Send via PHP mail() (or sendmail at the OS level)
 *   5. Mark SentToContact = true on the Xero invoice so the UI shows "sent"
 *   6. Set local Invoices.Sent = 1 + date_sent
 *
 * Hosting note for deliverability:
 *   - cadviz.co.nz DNS must include an SPF record permitting the server's
 *     mail relay to send on behalf of accounts@cadviz.co.nz, and ideally
 *     DKIM signing configured by the host. cPanel servers typically have
 *     this on by default for the primary domain.
 *   - If clients still get the email in spam, switch this file to use
 *     SMTP via PHPMailer with credentials for accounts@cadviz.co.nz.
 */

require_once 'auth_check.php';
require_once 'db_connect.php';
require_once 'xero_client.php';
require_once 'smtp_mailer.php';

if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403); die('Admin only.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); die('POST only.');
}

$invoiceNo = (int)($_POST['Invoice_No'] ?? 0);
$ccErik    = !empty($_POST['cc_erik']);
$back      = $_POST['back'] ?? ($_SERVER['HTTP_REFERER'] ?? 'invoice_list.php');
if ($invoiceNo <= 0) die('Missing Invoice_No.');

$pdo = get_db();

try {
    // ── Pull invoice + client + Xero ID ───────────────────────────────────
    $h = $pdo->prepare(
        "SELECT i.Invoice_No, i.Subtotal, i.Tax_Rate, i.PayBy, i.Date,
                i.Status_INV,
                i.Xero_InvoiceID, i.Xero_OnlineUrl,
                c.Client_Name, c.billing_email
           FROM Invoices i
           LEFT JOIN Clients c ON i.Client_ID = c.Client_id
          WHERE i.Invoice_No = ?"
    );
    $h->execute([$invoiceNo]);
    $row = $h->fetch();
    if (!$row) throw new Exception("Invoice #$invoiceNo not found.");
    if ((int)($row['Status_INV'] ?? 0) === 0) {
        throw new Exception("Invoice #$invoiceNo is still marked Not Checked (Status_INV=0). Set it to Ready to Send before emailing.");
    }
    if (empty($row['Xero_InvoiceID'])) throw new Exception("Invoice #$invoiceNo hasn't been pushed to Xero yet — push first.");
    if (empty($row['billing_email'])) throw new Exception("Client has no billing_email set — fill it on the Client page first.");

    // ── Fetch the PDF from Xero ───────────────────────────────────────────
    $client = new XeroClient($pdo);
    $pdf    = $client->getInvoicePdf($row['Xero_InvoiceID']);

    // ── Compose the email ─────────────────────────────────────────────────
    $invNumStr  = 'CAD-' . str_pad((string)$invoiceNo, 5, '0', STR_PAD_LEFT);
    $totalIncTax = (float)$row['Subtotal'] * (1 + (float)$row['Tax_Rate']);
    $clientName = trim($row['Client_Name'] ?: 'there');
    $payBy      = $row['PayBy'] ? date('d/m/Y', strtotime($row['PayBy'])) : null;
    $onlineUrl  = $row['Xero_OnlineUrl'] ?: '';

    $textBody  = "Hi {$clientName},\r\n\r\n";
    $textBody .= "Please find attached invoice {$invNumStr} from CADViz Limited for $" . number_format($totalIncTax, 2) . " (incl. GST).\r\n\r\n";
    if ($payBy)     $textBody .= "Payment due: {$payBy}\r\n\r\n";
    if ($onlineUrl) $textBody .= "View / pay online: {$onlineUrl}\r\n\r\n";
    $textBody .= "If you have any queries please reply to this email.\r\n\r\n";
    $textBody .= "Kind regards,\r\nCADViz Accounts\r\naccounts@cadviz.co.nz\r\n";

    $htmlBody  = "<p>Hi " . htmlspecialchars($clientName) . ",</p>";
    $htmlBody .= "<p>Please find attached invoice <strong>{$invNumStr}</strong> from CADViz Limited for <strong>$" . number_format($totalIncTax, 2) . "</strong> (incl. GST).</p>";
    if ($payBy)     $htmlBody .= "<p><strong>Payment due:</strong> {$payBy}</p>";
    if ($onlineUrl) $htmlBody .= "<p><a href=\"" . htmlspecialchars($onlineUrl) . "\">View / pay invoice online</a></p>";
    $htmlBody .= "<p>If you have any queries please reply to this email.</p>";
    $htmlBody .= "<p>Kind regards,<br>CADViz Accounts<br>accounts@cadviz.co.nz</p>";

    $subject = "Invoice {$invNumStr} from CADViz Limited";

    SmtpMailer::send([
        'to'          => $row['billing_email'],
        'cc'          => $ccErik ? ['erik@cadviz.co.nz'] : [],
        'bcc'         => ['accounts@cadviz.co.nz'],     // self-archive
        'reply_to'    => 'accounts@cadviz.co.nz',
        'subject'     => $subject,
        'text'        => $textBody,
        'html'        => $htmlBody,
        'attachments' => [[
            'name' => "{$invNumStr}.pdf",
            'mime' => 'application/pdf',
            'data' => $pdf,
        ]],
    ]);

    // ── Mark sent in Xero + locally ───────────────────────────────────────
    try { $client->markSentToContact($row['Xero_InvoiceID']); } catch (Exception $e) { /* non-fatal */ }
    $pdo->prepare("UPDATE Invoices SET Sent = 1, date_sent = NOW() WHERE Invoice_No = ?")->execute([$invoiceNo]);

    $_SESSION['xero_flash'] = "Invoice {$invNumStr} sent from accounts@cadviz.co.nz to " . $row['billing_email'] . ".";
} catch (Exception $e) {
    $_SESSION['xero_flash_err'] = 'Email failed: ' . $e->getMessage();
}

header('Location: ' . $back);
exit;
