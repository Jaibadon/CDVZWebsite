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
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); die('POST only.');
}

$invoiceNo = (int)($_POST['Invoice_No'] ?? 0);
$ccErik    = !empty($_POST['cc_erik']);
$back      = $_POST['back'] ?? ($_SERVER['HTTP_REFERER'] ?? 'invoice_list.php');
if ($invoiceNo <= 0) die('Missing Invoice_No.');

$pdo = get_db();

try {
    $msg = send_invoice_email_via_smtp($pdo, $invoiceNo, $ccErik);
    $_SESSION['xero_flash'] = $msg;
} catch (Exception $e) {
    $_SESSION['xero_flash_err'] = 'Email failed: ' . $e->getMessage();
}

header('Location: ' . $back);
exit;
