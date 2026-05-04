<?php
/**
 * POST handler for the per-invoice "Email from CADViz" button on
 * invoice_list.php. Delegates the heavy lifting to lib_invoice_email.php
 * so xero_invoice_push.php's "Push + Email" path can use the exact same
 * code (same body, same PDF, same flag updates).
 *
 * Why we don't use Xero's built-in email:
 *   POST /Invoices/{id}/Email always sends from a Xero-controlled
 *   address (something@post.xero.com). That hits client spam folders
 *   and breaks the "from CADViz" branding. Sending from our own
 *   accounts@cadviz.co.nz address with proper SPF/DKIM is the
 *   recommended path.
 */

require_once 'auth_check.php';
require_once 'db_connect.php';
require_once 'lib_invoice_email.php';

if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403); die('Admin only.');
}
// Test mode (?test_to=…) accepts GET so the email_templates.php "Send
// test" link works without needing a form. Real production sends still
// require POST.
$isTest    = !empty($_GET['test_to']);
$invoiceNo = (int)($_POST['Invoice_No'] ?? $_GET['Invoice_No'] ?? 0);
$ccErik    = !empty($_POST['cc_erik']);
$back      = $_POST['back'] ?? ($_SERVER['HTTP_REFERER'] ?? ($isTest ? 'email_templates.php' : 'invoice_list.php'));

if (!$isTest && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); die('POST only.');
}
if ($invoiceNo <= 0) die('Missing Invoice_No.');

$pdo = get_db();

try {
    if ($isTest) {
        $testTo = (string)$_GET['test_to'];
        $msg    = send_invoice_email_via_smtp($pdo, $invoiceNo, false, false, $testTo);
        echo "<!DOCTYPE html><html><body style=\"font-family:Arial;padding:18px\">"
           . "<h2 style=\"color:#7a4d00\">&#129514; Test invoice email sent</h2>"
           . "<p>" . htmlspecialchars($msg) . "</p>"
           . "<p>Diverted to <code>" . htmlspecialchars($testTo) . "</code>. "
           . "No real client email was sent. Sent flag / Status_INV NOT updated.</p>"
           . "<p><a href=\"" . htmlspecialchars($back) . "\">&larr; Back</a></p>"
           . "</body></html>";
        exit;
    }
    $msg = send_invoice_email_via_smtp($pdo, $invoiceNo, $ccErik);
    $_SESSION['xero_flash'] = $msg;
} catch (Exception $e) {
    if ($isTest) {
        die('Test failed: ' . htmlspecialchars($e->getMessage()));
    }
    $_SESSION['xero_flash_err'] = 'Email failed: ' . $e->getMessage();
}

header('Location: ' . $back);
exit;
