<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/invoice_email_safety.php';
require_once __DIR__ . '/smtp_mailer.php';

$user = $_SESSION['UserID'] ?? '';
if (!in_array($user, ['erik', 'jen'], true)) {
    http_response_code(403);
    exit('Admin only.');
}
invoice_email_require_post();
$key = (string)($_POST['test_template'] ?? '');
$templates = default_email_templates();
if (!isset($templates[$key]) || (!in_array($key, ['invoice', 'statement_manual'], true) &&
    !str_starts_with($key, 'reminder_') && !str_starts_with($key, 'overdue_statement_'))) {
    http_response_code(400);
    exit('Choose a valid invoice email template.');
}

$to = $user === 'erik' ? 'erik@cadviz.co.nz' : 'accounts@cadviz.co.nz';
$sample = array_merge(default_email_boilerplate(), [
    'name' => 'Sample recipient', 'client_name' => 'Sample client',
    'invoice_no' => 'CAD-00000', 'amount' => '$123.45', 'due_date' => '01/01/2030', 'pay_by' => '01/01/2030',
    'days_overdue' => '59', 'online_url' => 'https://example.invalid/sample-invoice',
    'count' => '2', 'total_due' => '$200.00', 'example_ref' => 'CAD-00000',
    'invoice_lines_text' => "CAD-00000  \$123.45\r\nCAD-00001  \$76.55",
    'invoice_table_html' => '<table><tr><td>CAD-00000</td><td>$123.45</td></tr><tr><td>CAD-00001</td><td>$76.55</td></tr></table>',
    'bank_details' => 'Sample bank details for testing only.',
    'bank_details_html' => '<p>Sample bank details for testing only.</p>',
]);
try {
    $message = invoice_email_render(get_db(), $key, $sample);
    $message['subject'] = '[TEST · SAMPLE DATA] ' . $message['subject'];
    SmtpMailer::send($message + ['to' => [$to], 'reply_to' => 'accounts@cadviz.co.nz']);
    $_SESSION['template_flash'] = 'Sample email sent to ' . $to . '.';
} catch (Throwable $e) {
    $_SESSION['template_flash_err'] = 'Test email failed: ' . $e->getMessage();
}
header('Location: email_templates.php#' . urlencode($key), true, 303);
