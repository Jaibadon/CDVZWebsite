<?php
/**
 * Shared invoice-email helper. Sends one invoice to the client via SMTP
 * (accounts@cadviz.co.nz) with the Xero PDF attached, instead of going
 * through Xero's mailer (which uses a Xero-controlled From: address that
 * many clients spam-filter).
 *
 * Used by xero_invoice_email.php (the per-invoice "Email from CADViz"
 * button) AND xero_invoice_push.php (the "Push + Email to Client" button
 * on invoice.php). Keeping the logic in one place ensures both buttons
 * send the same body, attach the same PDF, and update the same flags.
 */

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/xero_client.php';
require_once __DIR__ . '/smtp_mailer.php';

/**
 * Email an invoice via accounts@cadviz.co.nz with the Xero PDF attached.
 *
 * Sets Invoices.Sent = 1 + date_sent, and marks SentToContact = true on
 * the Xero record. Throws on any failure.
 *
 * Returns a one-line success message ("Invoice CAD-09489 sent to ...").
 */
function send_invoice_email_via_smtp(PDO $pdo, int $invoiceNo, bool $ccErik = false): string
{
    if ($invoiceNo <= 0) throw new Exception('Missing Invoice_No.');

    // Only reference c.Contact if the migration has been applied; otherwise
    // substitute NULL so the query still runs.
    $contactCol = clients_has_contact($pdo) ? 'c.Contact' : 'NULL AS Contact';
    $h = $pdo->prepare(
        "SELECT i.Invoice_No, i.Subtotal, i.Tax_Rate, i.PayBy, i.Date,
                i.Status_INV,
                i.Xero_InvoiceID, i.Xero_OnlineUrl,
                c.Client_Name, {$contactCol}, c.billing_email, c.email
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

    // Pick deliverable addresses — billing_email first, fall back to email.
    // Either column may carry multiple addresses separated by `;` or `,`
    // (e.g. "ap@acme.co; cfo@acme.co"). All valid ones get the email.
    // Refuse to send (with a clear error for the admin) when neither
    // column has anything that passes FILTER_VALIDATE_EMAIL.
    $toAddrs = pick_billing_emails($row['billing_email'] ?? null, $row['email'] ?? null);
    if (empty($toAddrs)) {
        throw new Exception("Client " . ($row['Client_Name'] ?? '') . " has no valid email (Billing Email or Email). Fix it on the Client page first.");
    }

    $client = new XeroClient($pdo);
    $pdf    = $client->getInvoicePdf($row['Xero_InvoiceID']);

    $invNumStr   = 'CAD-' . str_pad((string)$invoiceNo, 5, '0', STR_PAD_LEFT);
    $totalIncTax = (float)$row['Subtotal'] * (1 + (float)$row['Tax_Rate']);
    // Greet by Contact's first name when available; fall back to the
    // helper's "Valued Customer" default (NOT the company name — that
    // reads oddly in a first-person greeting).
    $greetName  = client_first_name($row['Contact'] ?? null);
    $clientName = trim($row['Client_Name'] ?: '');
    $payBy      = $row['PayBy'] ? date('d/m/Y', strtotime($row['PayBy'])) : null;
    $onlineUrl  = $row['Xero_OnlineUrl'] ?: '';

    $textBody  = "Dear {$greetName},\r\n\r\n";
    $textBody .= "Please find attached invoice {$invNumStr} from CADViz Limited for $" . number_format($totalIncTax, 2) . " (incl. GST).\r\n\r\n";
    if ($payBy)     $textBody .= "Payment due: {$payBy}\r\n\r\n";
    if ($onlineUrl) $textBody .= "View online: {$onlineUrl}\r\n\r\n";
    $textBody .= "If you have already paid this invoice, please disregard this email — our records simply haven't caught up with your payment yet.\r\n\r\n";
    $textBody .= "If you have any other queries please reply to this email.\r\n\r\n";
    $textBody .= "Kind regards,\r\nCADViz Accounts\r\naccounts@cadviz.co.nz\r\n";

    $htmlBody  = '<p>Dear ' . htmlspecialchars($greetName) . ',</p>';
    $htmlBody .= '<p>Please find attached invoice <strong>' . $invNumStr . '</strong> from CADViz Limited for <strong>$' . number_format($totalIncTax, 2) . '</strong> (incl. GST).</p>';
    if ($payBy)     $htmlBody .= '<p><strong>Payment due:</strong> ' . htmlspecialchars($payBy) . '</p>';
    if ($onlineUrl) $htmlBody .= '<p><a href="' . htmlspecialchars($onlineUrl) . '">View online</a></p>';
    $htmlBody .= '<p style="color:#666"><em>If you have already paid this invoice, please disregard this email, our records simply haven\'t caught up with your payment yet.</em></p>';
    $htmlBody .= '<p>If you have any other queries please reply to this email.</p>';
    $htmlBody .= '<p>Kind regards,<br>CADViz Accounts<br>accounts@cadviz.co.nz</p>';

    SmtpMailer::send([
        'to'          => $toAddrs,
        'cc'          => $ccErik ? ['erik@cadviz.co.nz'] : [],
        'bcc'         => ['accounts@cadviz.co.nz'],
        'reply_to'    => 'accounts@cadviz.co.nz',
        'subject'     => "Invoice {$invNumStr} from CADViz Limited",
        'text'        => $textBody,
        'html'        => $htmlBody,
        'attachments' => [[
            'name' => "{$invNumStr}.pdf",
            'mime' => 'application/pdf',
            'data' => $pdf,
        ]],
    ]);

    try { $client->markSentToContact($row['Xero_InvoiceID']); } catch (Exception $e) { /* non-fatal */ }
    // Set Sent=1, refresh date_sent on every send (so the admin can see
    // *when* the most recent email went out), and bump Status_INV to 2
    // ("Sent") so the dashboard label moves out of "Ready to Send".
    $pdo->prepare("UPDATE Invoices SET Sent = 1, date_sent = NOW(), Status_INV = 2 WHERE Invoice_No = ?")->execute([$invoiceNo]);

    return "Invoice {$invNumStr} sent from accounts@cadviz.co.nz to " . implode(', ', $toAddrs) . '.';
}
