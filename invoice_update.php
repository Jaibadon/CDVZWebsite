<?php
session_start();
require_once 'db_connect.php';
require_once 'helpers.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}

$pdo = get_db();

$invoice_no = (int)$_POST['Invoice_No'];
$curdate = date('Y-m-d');

// Fetch current record
$stmt = $pdo->prepare("SELECT * FROM Invoices WHERE Invoice_No = ?");
$stmt->execute([$invoice_no]);
$rs = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$rs) {
    die("Invoice $invoice_no not found.");
}

// Build update fields
$client_id = (int)($_POST['CLIENT_BOX'] ?? 0);

$inv_date = !empty($_POST['INV_DATE']) ? to_mysql_date($_POST['INV_DATE']) : null;

// SENT handling
if (isset($_POST['SENT']) && $_POST['SENT'] === 'ON') {
    $sent = 1;
    if (is_null($rs['DATE_SENT'])) {
        $date_sent = $curdate;
    } else {
        $date_sent = $rs['DATE_SENT'];
    }
} else {
    $sent = 0;
    $date_sent = $rs['DATE_SENT'];
}

// PAID handling
if (isset($_POST['PAID']) && $_POST['PAID'] === 'ON') {
    $paid = 1;
    if (is_null($rs['DATEPAID'])) {
        $datepaid = $curdate;
    } else {
        $datepaid = $rs['DATEPAID'];
    }
} else {
    $paid = 0;
    $datepaid = $rs['DATEPAID'];
}

// Notes handling
$notes = $rs['Notes'] ?? '';
$postedNotes = $_POST['Notes'] ?? '';
if ($postedNotes !== $notes) {
    $new_notes = $postedNotes . ' - ' . $_SESSION['UserID'] . ' - ' . $curdate;
} else {
    $new_notes = $notes;
}

// Status_INV
$status_inv = (empty($_POST['Status_INV'])) ? 0 : (int)$_POST['Status_INV'];

$payment_opt = (int)$_POST['Payment_Opt'];
$order_no_inv = $_POST['ORDER_NO_INV'] ?? '';

$stmt = $pdo->prepare("UPDATE Invoices SET
    CLIENT_ID = ?,
    DATE = ?,
    SENT = ?,
    DATE_SENT = ?,
    PAID = ?,
    DATEPAID = ?,
    Notes = ?,
    Status_INV = ?,
    PaymentOption = ?,
    Order_No_INV = ?
    WHERE Invoice_No = ?");

$stmt->execute([
    $client_id,
    $inv_date,
    $sent,
    $date_sent,
    $paid,
    $datepaid,
    $new_notes,
    $status_inv,
    $payment_opt,
    $order_no_inv,
    $invoice_no
]);

header('Location: invoice_edit.php?invoice_no=' . $invoice_no);
exit;
?>
<html>
<body>
Copyright &copy; 2016 CADViz Ltd All Rights Reserved
</body>
</html>
