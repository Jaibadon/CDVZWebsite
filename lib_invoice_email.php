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
// Pull the push function in library mode (no auth/POST checks, no script
// body) so we can re-push the invoice to Xero right before each email.
if (!function_exists('push_invoice_to_xero')) {
    if (!defined('XERO_INVOICE_PUSH_LIBRARY_ONLY')) define('XERO_INVOICE_PUSH_LIBRARY_ONLY', true);
    require_once __DIR__ . '/xero_invoice_push.php';
}

/**
 * Email an invoice via accounts@cadviz.co.nz with the Xero PDF attached.
 *
 * Sets Invoices.Sent = 1 + date_sent, and marks SentToContact = true on
 * the Xero record. Throws on any failure.
 *
 * Returns a one-line success message ("Invoice CAD-09489 sent to ...").
 */
function send_invoice_email_via_smtp(PDO $pdo, int $invoiceNo, bool $ccErik = false, bool $skipPush = false, string $testTo = ''): string
{
    $isTest = $testTo !== '';
    if ($invoiceNo <= 0) throw new Exception('Missing Invoice_No.');

    // Re-push to Xero before sending so the attached PDF + email body
    // always reflect the LATEST local data (rate fixes, edited line items,
    // updated dates). Soft-fails: if the push errors out (e.g. transient
    // Xero outage) we still try to email what's already in Xero. Skipped
    // when the caller is xero_invoice_push.php itself (it just pushed).
    if (!$skipPush) {
        try {
            push_invoice_to_xero($pdo, $invoiceNo);
        } catch (Exception $pushErr) {
            // Surface the message in the eventual exception (if any) but
            // don't block — Xero already has SOME version of the invoice
            // since the email button only shows on already-pushed rows.
            error_log("send_invoice_email_via_smtp: pre-push failed for invoice $invoiceNo: " . $pushErr->getMessage());
        }
    }

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
    if ($isTest) {
        // Divert in test mode — real client never sees this.
        $toAddrs = [$testTo];
    } else {
        $toAddrs = pick_billing_emails($row['billing_email'] ?? null, $row['email'] ?? null);
        if (empty($toAddrs)) {
            throw new Exception("Client " . ($row['Client_Name'] ?? '') . " has no valid email (Billing Email or Email). Fix it on the Client page first.");
        }
    }

    $client = new XeroClient($pdo);
    $pdf    = $client->getInvoicePdf($row['Xero_InvoiceID']);

    // Fetch the live invoice from Xero in the same round-trip so the
    // amount we put in the email body matches the PDF the client sees.
    // The previous version computed Total locally as
    //   Invoices.Subtotal × (1 + Tax_Rate)
    // which drifts whenever the invoice has been edited in Xero after
    // the local push, or whenever local rows were touched without
    // re-syncing — and resulted in emails saying "$X" while the
    // attached PDF showed a different figure. Xero's Total/AmountDue
    // are authoritative; we fall back to local only if the GET fails.
    $invNumStr = 'CAD-' . str_pad((string)$invoiceNo, 5, '0', STR_PAD_LEFT);
    $totalIncTax  = null;
    $amountDue    = null;
    $xeroDueDate  = null;
    try {
        $xeroInv = $client->getInvoice($row['Xero_InvoiceID']);
        if (!empty($xeroInv['Total']))     $totalIncTax = (float)$xeroInv['Total'];
        if (isset($xeroInv['AmountDue']))  $amountDue   = (float)$xeroInv['AmountDue'];
        // Xero's DueDate comes as either a /Date(epoch)/ string or a
        // DueDateString — handle both.
        if (!empty($xeroInv['DueDateString'])) {
            $xeroDueDate = substr((string)$xeroInv['DueDateString'], 0, 10);
        } elseif (!empty($xeroInv['DueDate']) && preg_match('/(\d{10})/', (string)$xeroInv['DueDate'], $m)) {
            $xeroDueDate = date('Y-m-d', (int)$m[1]);
        }
        // Persist the truth back to the local row so subsequent emails
        // / dashboards stay aligned without waiting on a full sync.
        try {
            $pdo->prepare("UPDATE Invoices SET Xero_AmountDue = ?, Xero_AmountPaid = ?, Xero_Status = ?, Xero_DueDate = ?, Xero_LastSynced = UTC_TIMESTAMP() WHERE Invoice_No = ?")
                ->execute([
                    $amountDue ?? 0,
                    (float)($xeroInv['AmountPaid'] ?? 0),
                    $xeroInv['Status'] ?? null,
                    $xeroDueDate,
                    $invoiceNo,
                ]);
        } catch (Exception $e) { /* non-fatal */ }
    } catch (Exception $e) { /* fall through to local fallback below */ }

    if ($totalIncTax === null) {
        // Local fallback when Xero is unreachable — clearly worse than
        // Xero's number, but better than refusing to send.
        $totalIncTax = (float)$row['Subtotal'] * (1 + (float)$row['Tax_Rate']);
    }

    // Greet by Contact's first name when available; fall back to the
    // helper's "Valued Customer" default (NOT the company name — that
    // reads oddly in a first-person greeting).
    $greetName  = client_first_name($row['Contact'] ?? null);
    $clientName = trim($row['Client_Name'] ?: '');
    // Prefer Xero's DueDate (just-fetched), then locally stored PayBy.
    $payByIso = $xeroDueDate ?: ($row['PayBy'] ?? null);
    $payBy    = $payByIso ? date('d/m/Y', strtotime($payByIso)) : null;
    $onlineUrl  = $row['Xero_OnlineUrl'] ?: '';

    // Render the email body via the editable template (App_Meta override
    // wins; falls back to default_email_templates() in helpers.php).
    $vars = array_merge(default_email_boilerplate(), [
        'name'        => $greetName,
        'client_name' => $clientName,
        'invoice_no'  => $invNumStr,
        'amount'      => '$' . number_format($totalIncTax, 2),
        'pay_by'      => $payBy ?: '(no due date set)',
        'online_url'  => $onlineUrl ?: '(no Xero online link)',
    ]);
    $subject  = render_email_template(email_template_get($pdo, 'invoice', 'subject'), $vars);
    $textBody = render_email_template(email_template_get($pdo, 'invoice', 'text'),    $vars);
    $htmlBody = render_email_template(email_template_get($pdo, 'invoice', 'html'),    $vars);

    SmtpMailer::send([
        'to'          => $toAddrs,
        'cc'          => $isTest ? [] : ($ccErik ? ['erik@cadviz.co.nz'] : []),
        'bcc'         => $isTest ? [] : ['accounts@cadviz.co.nz'],
        'reply_to'    => 'accounts@cadviz.co.nz',
        'subject'     => ($isTest ? '[TEST] ' : '') . $subject,
        'text'        => $textBody,
        'html'        => $htmlBody,
        'attachments' => [[
            'name' => "{$invNumStr}.pdf",
            'mime' => 'application/pdf',
            'data' => $pdf,
        ]],
    ]);

    if (!$isTest) {
        try { $client->markSentToContact($row['Xero_InvoiceID']); } catch (Exception $e) { /* non-fatal */ }
        // Set Sent=1, refresh date_sent on every send (so the admin can see
        // *when* the most recent email went out), and bump Status_INV to 2
        // ("Sent") so the dashboard label moves out of "Ready to Send".
        $pdo->prepare("UPDATE Invoices SET Sent = 1, date_sent = NOW(), Status_INV = 2 WHERE Invoice_No = ?")->execute([$invoiceNo]);
    }

    return ($isTest ? '[TEST] ' : '') . "Invoice {$invNumStr} sent from accounts@cadviz.co.nz to " . implode(', ', $toAddrs) . '.';
}
