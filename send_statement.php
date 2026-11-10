<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/invoice_email_safety.php';
require_once __DIR__ . '/xero_client.php';
require_once __DIR__ . '/smtp_mailer.php';

if (!in_array($_SESSION['UserID'] ?? '', ['erik', 'jen'], true)) {
    http_response_code(403);
    exit('Admin only.');
}
if (isset($_GET['test_to'])) {
    header('Location: email_templates.php');
    exit;
}
invoice_email_require_post();

$pdo = get_db();
$clientId = (int)($_POST['client_id'] ?? 0);
try {
    $contact = clients_has_contact($pdo) ? 'c.Contact' : 'NULL AS Contact';
    $st = $pdo->prepare(
        "SELECT i.Invoice_No, i.Client_ID, i.Xero_InvoiceID, c.Client_Name, c.billing_email, c.email, $contact,
                p.JobName, d.xero_contact_id AS verified_contact_id
           FROM Invoices i JOIN Clients c ON c.Client_id = i.Client_ID
           LEFT JOIN Projects p ON p.proj_id = i.Proj_ID
           LEFT JOIN Invoice_Original_Delivery d ON d.Invoice_No = i.Invoice_No AND d.Client_ID = i.Client_ID
                 AND d.xero_invoice_id = i.Xero_InvoiceID
          WHERE i.Client_ID = ? AND COALESCE(i.Paid, 0) = 0
            AND COALESCE(i.Status_INV, 0) <> 0 AND i.Xero_InvoiceID IS NOT NULL
          ORDER BY i.Invoice_No"
    );
    $st->execute([$clientId]);
    $rows = $st->fetchAll();
    if (!$rows) {
        throw new RuntimeException('There are no reviewed Xero invoices to include.');
    }
    $first = $rows[0];
    $to = pick_billing_emails($first['billing_email'], $first['email']);
    if (!$to) {
        throw new RuntimeException('This client has no valid billing address.');
    }
    $xero = new XeroClient($pdo);
    $total = 0;
    $attachments = $text = $html = $verified = [];
    foreach ($rows as $row) {
        $remote = $xero->getInvoice($row['Xero_InvoiceID']);
        $contactId = invoice_email_xero_contact($row, $remote);
        if (in_array($remote['Status'] ?? '', ['PAID', 'VOIDED', 'DELETED', 'DRAFT', 'SUBMITTED'], true)) {
            continue;
        }
        if (($remote['Status'] ?? '') !== 'AUTHORISED' || !is_numeric($remote['AmountDue'] ?? null)) {
            throw new RuntimeException('Xero could not verify an outstanding invoice.');
        }
        if ((float)$remote['AmountDue'] <= 0) {
            continue;
        }
        $no = invoice_email_number((int)$row['Invoice_No']);
        $dueIso = invoice_email_xero_due($remote);
        $due = $dueIso ? date('d/m/Y', strtotime($dueIso)) : 'Unknown';
        $total += (float)$remote['AmountDue'];
        $amount = '$' . number_format((float)$remote['AmountDue'], 2);
        $job = trim((string)$row['JobName']);
        $safeJob = htmlspecialchars($job);
        $text[] = "$no · due $due · $job · $amount";
        $html[] = "<tr><td>$no</td><td>$due</td><td>$safeJob</td><td>$amount</td></tr>";
        $attachments[] = invoice_email_pdf($xero, $row);
        $verified[] = [$row, $contactId];
    }
    if (!$verified) {
        throw new RuntimeException('Xero has no outstanding invoices for this statement.');
    }
    $st->execute([$clientId]);
    if ($st->fetchAll() !== $rows) {
        throw new RuntimeException('The invoice list or billing addresses changed. Review the statement again.');
    }
    $vars = array_merge(default_email_boilerplate(), [
        'name' => client_first_name($first['Contact']), 'client_name' => $first['Client_Name'],
        'count' => (string)count($verified), 'total_due' => '$' . number_format($total, 2),
        'invoice_lines_text' => implode("\r\n", $text),
        'invoice_table_html' => '<table border="1" cellpadding="6"><tr><th>Invoice</th><th>Due</th><th>Job</th><th>Balance</th></tr>'
            . implode('', $html) . '</table>',
        'example_ref' => invoice_email_number((int)$verified[0][0]['Invoice_No']),
    ]);
    $message = invoice_email_render($pdo, 'statement_manual', $vars);
    SmtpMailer::send($message + [
        'to' => $to, 'cc' => !empty($_POST['cc_erik']) ? ['erik@cadviz.co.nz'] : [],
        'bcc' => ['accounts@cadviz.co.nz'], 'reply_to' => 'accounts@cadviz.co.nz', 'attachments' => $attachments,
    ]);
    $_SESSION['xero_flash'] = 'Statement sent to ' . implode(', ', $to) . ' with ' . count($attachments) . ' invoice(s).';
    try {
        $pdo->beginTransaction();
        $mark = $pdo->prepare('UPDATE Invoices SET Sent = 1, date_sent = NOW(), Status_INV = 2 WHERE Invoice_No = ? AND Client_ID = ? AND Xero_InvoiceID = ?');
        foreach ($verified as [$row, $contactId]) {
            invoice_email_record_delivery($pdo, $row, $contactId, $to, 'email');
            $mark->execute([$row['Invoice_No'], $row['Client_ID'], $row['Xero_InvoiceID']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Statement delivery record failed: ' . $e->getMessage());
        $_SESSION['xero_flash_err'] = 'The statement was sent, but delivery records could not be saved. Check the sent mailbox before attempting another send.';
    }
    foreach ($verified as [$row]) {
        try {
            $xero->markSentToContact($row['Xero_InvoiceID']);
        } catch (Throwable $e) {
            error_log('Could not mark invoice sent in Xero: ' . $e->getMessage());
        }
    }
} catch (Throwable $e) {
    $_SESSION['xero_flash_err'] = 'Statement email failed: ' . $e->getMessage();
}
header('Location: invoice_list.php', true, 303);
