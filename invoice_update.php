<?php
session_start();
require_once 'db_connect.php';
require_once 'helpers.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}
if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403); die('Admin only.');
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

// Case-insensitive helper — PDO row keys are case-sensitive on Linux MySQL,
// and the actual column names are `date_sent` (lowercase) and `DatePaid`
// (TitleCase). The previous code read `$rs['DATE_SENT']` / `$rs['DATEPAID']`
// which were silently undefined → NULL → original send/paid dates were
// overwritten with today's date on every Update_Invoice click.
$ci = function(array $row, array $keys) {
    foreach ($keys as $k) {
        foreach ($row as $rk => $rv) {
            if (strcasecmp($rk, $k) === 0) return $rv;
        }
    }
    return null;
};
$prevDateSent = $ci($rs, ['date_sent']);
$prevDatePaid = $ci($rs, ['DatePaid']);

// Build update fields
$client_id = (int)($_POST['CLIENT_BOX'] ?? 0);

$inv_date = !empty($_POST['INV_DATE']) ? to_mysql_date($_POST['INV_DATE']) : null;

// SENT handling — preserve the original send date when already set.
if (isset($_POST['SENT']) && $_POST['SENT'] === 'ON') {
    $sent = 1;
    $date_sent = $prevDateSent ?: $curdate;
} else {
    $sent = 0;
    $date_sent = $prevDateSent;
}

// PAID handling — preserve the original paid date when already set.
if (isset($_POST['PAID']) && $_POST['PAID'] === 'ON') {
    $paid = 1;
    $datepaid = $prevDatePaid ?: $curdate;
} else {
    $paid = 0;
    $datepaid = $prevDatePaid;
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

// Recompute PayBy from the (possibly updated) invoice date and PaymentOption
// so the due date stays in sync after any edit.
$payByDate = compute_pay_by($inv_date, $payment_opt);

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
    PayBy = ?,
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
    $payByDate,
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
