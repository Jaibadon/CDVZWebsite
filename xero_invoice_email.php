<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/invoice_email_safety.php';

if (!in_array($_SESSION['UserID'] ?? '', ['erik', 'jen'], true)) {
    http_response_code(403);
    exit('Admin only.');
}
if (isset($_GET['test_to'])) {
    header('Location: email_templates.php');
    exit;
}
invoice_email_require_post();
require_once __DIR__ . '/lib_invoice_email.php';

try {
    $_SESSION['xero_flash'] = send_invoice_email_via_smtp(get_db(), (int)($_POST['Invoice_No'] ?? 0), !empty($_POST['cc_erik']));
} catch (Throwable $e) {
    $_SESSION['xero_flash_err'] = 'Invoice email failed: ' . $e->getMessage();
}
header('Location: invoice_list.php', true, 303);
