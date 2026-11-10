<?php
require_once __DIR__ . '/invoice_reminder_policy.php';

function invoice_reminder_message(PDO $pdo, array $batch, bool $testCopy = false): array {
    $rows = $batch['rows'];
    $first = $rows[0];
    $vars = array_merge(default_email_boilerplate(), [
        'name' => client_first_name($first['Contact']),
        'client_name' => trim((string)$first['Client_Name']),
        'invoice_no' => invoice_reminder_number((int)$first['Invoice_No']),
        'amount' => '$' . number_format((float)$first['Xero_AmountDue'], 2),
        'due_date' => date('d/m/Y', strtotime($first['Xero_DueDate'])),
        'days_overdue' => (string)$first['days_overdue'],
        'online_url' => str_starts_with((string)$first['Xero_OnlineUrl'], 'https://') ? $first['Xero_OnlineUrl'] : '',
    ]);
    if ($testCopy) {
        $vars['online_url'] = 'https://example.invalid/test-copy';
        $vars['bank_details'] = 'Bank details omitted from the test copy.';
        $vars['bank_details_html'] = '<p>Bank details omitted from the test copy.</p>';
    }
    $statement = count($rows) > 1;
    if ($statement) {
        $text = $html = [];
        foreach ($rows as $row) {
            $no = invoice_reminder_number((int)$row['Invoice_No']);
            $due = date('d/m/Y', strtotime($row['Xero_DueDate']));
            $days = (int)$row['days_overdue'];
            $amount = '$' . number_format((float)$row['Xero_AmountDue'], 2);
            $text[] = "  $no · due $due · $days days overdue · $amount";
            $html[] = "<tr><td>$no</td><td>$due</td><td>$days</td><td>$amount</td></tr>";
        }
        $vars = array_merge($vars, [
            'count' => (string)count($rows),
            'total_due' => '$' . number_format(array_sum(array_column($rows, 'Xero_AmountDue')), 2),
            'days_overdue' => (string)max(array_column($rows, 'days_overdue')),
            'invoice_lines_text' => implode("\r\n", $text),
            'invoice_table_html' => '<table border="1" cellpadding="6"><thead><tr><th>Invoice</th><th>Due</th><th>Days overdue</th><th>Amount</th></tr></thead><tbody>'
                . implode('', $html) . '</tbody></table>',
            'example_ref' => $vars['invoice_no'],
        ]);
    }
    $message = invoice_email_render($pdo, $batch['template'], $vars);
    if ($statement) {
        $message['text'] = rtrim($message['text']) . "\r\n\r\nThe total covers the invoices listed above.";
        $message['html'] .= '<p><small>The total covers the invoices listed above.</small></p>';
    }
    if ($testCopy) {
        // Also neutralise literal web links in saved template overrides.
        foreach (['subject', 'text', 'html'] as $part) {
            $message[$part] = preg_replace('~https?://[^\s<>"\']+~i', 'https://example.invalid/test-copy', $message[$part]);
        }
    }
    return $message + [
        'to' => $batch['to'], 'bcc' => ['accounts@cadviz.co.nz'], 'reply_to' => 'accounts@cadviz.co.nz',
    ];
}

function invoice_reminder_manifest(array $batch, array $message): array {
    return [
        'stage' => $batch['stage'], 'template' => $batch['template'], 'advance' => $batch['advance'],
        'invoices' => array_map(fn($row) => [
            (int)$row['Invoice_No'], (int)$row['Client_ID'], $row['Xero_InvoiceID'], $row['verified_contact_id'],
            $row['Xero_DueDate'], $row['Xero_AmountDue'],
        ], $batch['rows']),
        'email' => $message,
    ];
}

function invoice_reminder_preview_key(array $manifests): string {
    return hash('sha256', json_encode($manifests, JSON_THROW_ON_ERROR));
}

function invoice_reminder_live_invoice(PDO $pdo, XeroClient $xero, array $row): void {
    $remote = $xero->getInvoice($row['Xero_InvoiceID']);
    invoice_email_xero_contact($row, $remote);
    $status = $remote['Status'] ?? null;
    if (!$status || !is_numeric($remote['AmountDue'] ?? null) || !is_numeric($remote['AmountPaid'] ?? null)) {
        throw new RuntimeException('Xero did not confirm the current invoice and payment status.');
    }
    $due = invoice_email_xero_due($remote);
    $amount = (float)$remote['AmountDue'];
    $paid = (float)$remote['AmountPaid'];
    $pdo->prepare(
        'UPDATE Invoices SET Xero_Status = ?, Xero_DueDate = ?, Xero_AmountDue = ?, Xero_AmountPaid = ?,
                Xero_LastSynced = UTC_TIMESTAMP()
          WHERE Invoice_No = ? AND Client_ID = ? AND Xero_InvoiceID = ?'
    )->execute([$status, $due, $amount, $paid, $row['Invoice_No'], $row['Client_ID'], $row['Xero_InvoiceID']]);
    if ($status !== 'AUTHORISED' || $amount <= 0 || $paid > 0.005 ||
        $due !== $row['Xero_DueDate'] || abs($amount - (float)$row['Xero_AmountDue']) > 0.005) {
        throw new RuntimeException('The payment status, amount or due date changed in Xero. The local copy was refreshed; review the new preview.');
    }
}
