<?php
session_start();
require_once 'db_connect.php';
require_once 'helpers.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}

$pdo = get_db();

$rowcount = (int)($_POST['rowcount'] ?? 0);

for ($a = 1; $a <= $rowcount; $a++) {
    $payment_id    = (int)$_POST['Payment_ID_' . $a];
    $date_received = to_mysql_date($_POST['Date_Received_box' . $a] ?? '');
    $invoice_no    = (int)($_POST['Inv_box' . $a] ?? 0);
    $client_id     = (int)($_POST['Client_box' . $a] ?? 0);
    $notes         = $_POST['notes_box' . $a] ?? '';
    $amount        = (float)($_POST['amount' . $a] ?? 0);

    $stmt = $pdo->prepare("UPDATE Payments SET Date_Received = ?, Invoice_No = ?, Client_ID = ?, Notes = ?, Amount = ? WHERE Payment_ID = ?");
    $stmt->execute([$date_received, $invoice_no, $client_id, $notes, $amount, $payment_id]);
}

header('Location: payments.php');
exit;
?>
<html>
<body>
Copyright &copy; 2012 CADViz Ltd All Rights Reserved
</body>
</html>
