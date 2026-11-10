<?php
/** Shared original-invoice delivery for Email from CADViz and Push + Email. */
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/invoice_email_safety.php';
require_once __DIR__ . '/xero_client.php';
require_once __DIR__ . '/smtp_mailer.php';

if (!function_exists('push_invoice_to_xero')) {
    define('XERO_INVOICE_PUSH_LIBRARY_ONLY', true);
    require_once __DIR__ . '/xero_invoice_push.php';
}

function send_invoice_email_via_smtp(PDO $pdo, int $invoiceNo, bool $ccErik = false, bool $skipPush = false): string {
    if ($invoiceNo <= 0) {
        throw new RuntimeException('Missing invoice number.');
    }
    // Check the delivery table before SMTP. An install missing the migration
    // must not send and then fail to record the original delivery.
    $contact = clients_has_contact($pdo) ? 'c.Contact' : 'NULL AS Contact';
    $st = $pdo->prepare(
        "SELECT i.Invoice_No, i.Client_ID, i.Status_INV, i.Xero_InvoiceID, c.Client_Name,
                c.billing_email, c.email, $contact, d.xero_contact_id AS verified_contact_id
           FROM Invoices i JOIN Clients c ON c.Client_id = i.Client_ID
           LEFT JOIN Invoice_Original_Delivery d ON d.Invoice_No = i.Invoice_No AND d.Client_ID = i.Client_ID
                 AND d.xero_invoice_id = i.Xero_InvoiceID
          WHERE i.Invoice_No = ?"
    );
    $st->execute([$invoiceNo]);
    $row = $st->fetch();
    if (!$row || !(int)$row['Status_INV']) {
        throw new RuntimeException('Set the invoice to Ready to Send before emailing.');
    }
    if (!$skipPush) {
        push_invoice_to_xero($pdo, $invoiceNo);
        $st->execute([$invoiceNo]);
        $row = $st->fetch();
    }
    if (!$row || !$row['Xero_InvoiceID']) {
        throw new RuntimeException('Push the invoice to Xero first.');
    }
    $to = pick_billing_emails($row['billing_email'], $row['email']);
    if (!$to) {
        throw new RuntimeException('This client has no valid billing address.');
    }
    $xero = new XeroClient($pdo);
    $pdf = invoice_email_pdf($xero, $row);
    $remote = $xero->getInvoice($row['Xero_InvoiceID']);
    $contactId = invoice_email_xero_contact($row, $remote);
    if (!in_array($remote['Status'] ?? '', ['AUTHORISED', 'PAID'], true) || !is_numeric($remote['Total'] ?? null)) {
        throw new RuntimeException('Xero could not verify the invoice status and total.');
    }
    $online = $xero->getOnlineInvoiceUrl($row['Xero_InvoiceID']);
    $st->execute([$invoiceNo]);
    if ($st->fetch() !== $row) {
        throw new RuntimeException('Invoice or recipient changed while preparing the email. Try again.');
    }

    $due = invoice_email_xero_due($remote);
    $vars = array_merge(default_email_boilerplate(), [
        'name' => client_first_name($row['Contact']),
        'client_name' => $row['Client_Name'],
        'invoice_no' => invoice_email_number($invoiceNo),
        'amount' => '$' . number_format((float)$remote['Total'], 2),
        'pay_by' => $due ? date('d/m/Y', strtotime($due)) : '(no due date set)',
        'online_url' => str_starts_with((string)$online, 'https://') ? $online : '',
    ]);
    $message = invoice_email_render($pdo, 'invoice', $vars);
    SmtpMailer::send($message + [
        'to' => $to, 'cc' => $ccErik ? ['erik@cadviz.co.nz'] : [], 'bcc' => ['accounts@cadviz.co.nz'],
        'reply_to' => 'accounts@cadviz.co.nz', 'attachments' => [$pdf],
    ]);

    $result = 'Invoice ' . invoice_email_number($invoiceNo) . ' sent to ' . implode(', ', $to) . '.';
    try {
        $pdo->beginTransaction();
        invoice_email_record_delivery($pdo, $row, $contactId, $to, 'email');
        $pdo->prepare(
            "UPDATE Invoices SET Sent = 1, date_sent = NOW(), Status_INV = 2, Xero_Status = ?,
             Xero_AmountDue = ?, Xero_AmountPaid = ?, Xero_DueDate = ?, Xero_LastSynced = UTC_TIMESTAMP()
             WHERE Invoice_No = ? AND Client_ID = ? AND Xero_InvoiceID = ?"
        )->execute([$remote['Status'], $remote['AmountDue'] ?? null, $remote['AmountPaid'] ?? null, $due,
                     $invoiceNo, $row['Client_ID'], $row['Xero_InvoiceID']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Invoice delivery record failed for ' . $invoiceNo . ': ' . $e->getMessage());
        return $result . ' The delivery record could not be saved. Check the sent mailbox before attempting another send.';
    }
    try {
        $xero->markSentToContact($row['Xero_InvoiceID']);
    } catch (Throwable $e) {
        error_log('Could not mark invoice sent in Xero: ' . $e->getMessage());
    }
    return $result;
}
