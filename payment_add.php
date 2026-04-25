<?php
session_start();
require_once 'db_connect.php';
require_once 'helpers.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}

$pdo = get_db();

$date_received = to_mysql_date($_POST['Date_Received_new'] ?? '');
$invoice_no    = (int)($_POST['Inv_box_new'] ?? 0);
$client_id     = (int)($_POST['Client_box_new'] ?? 0);
$notes         = $_POST['Notes_box_new'] ?? '';
$amount        = (float)($_POST['amount_new'] ?? 0);

$stmt = $pdo->prepare("INSERT INTO Payments (Date_Received, Invoice_No, Client_ID, Notes, Amount) VALUES (?, ?, ?, ?, ?)");
$stmt->execute([$date_received, $invoice_no, $client_id, $notes, $amount]);

header('Location: payments.php');
exit;
?>
<html>
<body>
Copyright &copy; 2012 CADViz Ltd All Rights Reserved
</body>
</html>
