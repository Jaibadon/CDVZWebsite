<?php
require_once __DIR__ . '/helpers.php';

function invoice_email_csrf(): string {
    return $_SESSION['invoice_email_csrf'] ??= bin2hex(random_bytes(32));
}

function invoice_email_require_post(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Open the email action from the invoicing page.');
    }
    if (empty($_SESSION['invoice_email_csrf']) ||
        !hash_equals($_SESSION['invoice_email_csrf'], (string)($_POST['email_csrf'] ?? ''))) {
        http_response_code(403);
        exit('Refresh the invoicing page and try again.');
    }
}

function invoice_email_number(int $no): string {
    return 'CAD-' . str_pad((string)$no, 5, '0', STR_PAD_LEFT);
}

function invoice_email_xero_contact(array $local, array $remote): string {
    $contactId = (string)($remote['Contact']['ContactID'] ?? '');
    $name = trim((string)($local['Client_Name'] ?? ''));
    if ($name === '' || $contactId === '' ||
        strcasecmp(trim((string)($remote['Contact']['Name'] ?? '')), $name) !== 0 ||
        strcasecmp((string)($remote['InvoiceID'] ?? ''), (string)$local['Xero_InvoiceID']) !== 0 ||
        ($remote['InvoiceNumber'] ?? '') !== invoice_email_number((int)$local['Invoice_No']) ||
        (!empty($local['verified_contact_id']) && strcasecmp($contactId, $local['verified_contact_id']) !== 0)) {
        throw new RuntimeException('The invoice and billing client do not match the Xero record. Review them in Xero before emailing.');
    }
    return $contactId;
}

function invoice_email_xero_due(array $remote): ?string {
    if (!empty($remote['DueDateString'])) {
        return substr((string)$remote['DueDateString'], 0, 10);
    }
    return preg_match('/(\d{10})/', (string)($remote['DueDate'] ?? ''), $parts) ? gmdate('Y-m-d', (int)$parts[1]) : null;
}

function invoice_email_pdf(XeroClient $xero, array $row): array {
    $pdf = $xero->getInvoicePdf($row['Xero_InvoiceID']);
    if (!str_starts_with($pdf, '%PDF-')) {
        throw new RuntimeException('Xero did not return a valid invoice PDF.');
    }
    return ['name' => invoice_email_number((int)$row['Invoice_No']) . '.pdf', 'mime' => 'application/pdf', 'data' => $pdf];
}

function invoice_email_record_delivery(PDO $pdo, array $row, string $contactId, array $to, string $source): void {
    $pdo->prepare(
        "INSERT INTO Invoice_Original_Delivery (Invoice_No, Client_ID, xero_invoice_id, xero_contact_id, recipients, source, verified_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE Client_ID = VALUES(Client_ID), xero_invoice_id = VALUES(xero_invoice_id),
         xero_contact_id = VALUES(xero_contact_id), recipients = VALUES(recipients), source = VALUES(source), verified_at = NOW()"
    )->execute([$row['Invoice_No'], $row['Client_ID'], $row['Xero_InvoiceID'], $contactId,
                 json_encode(array_values($to), JSON_THROW_ON_ERROR), $source]);
}

function invoice_email_render(PDO $pdo, string $key, array $vars): array {
    $defaults = default_email_templates()[$key];
    $prefix = 'tpl_' . $key . '_';
    $st = $pdo->prepare('SELECT meta_key, meta_value FROM App_Meta WHERE meta_key IN (?, ?, ?)');
    $st->execute([$prefix . 'subject', $prefix . 'text', $prefix . 'html']);
    $saved = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    $htmlVars = $vars;
    foreach ($vars as $name => $value) {
        if (!str_ends_with($name, '_html')) {
            $htmlVars[$name] = htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }
    return [
        'subject' => render_email_template($saved[$prefix . 'subject'] ?? $defaults['subject'], $vars),
        'text' => render_email_template($saved[$prefix . 'text'] ?? $defaults['text'], $vars),
        'html' => render_email_template($saved[$prefix . 'html'] ?? $defaults['html'], $htmlVars),
    ];
}
