<?php
session_start();
require_once 'db_connect.php';
require_once 'helpers.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}

$pdo = get_db();

$proj_id = (int)($_POST['PROJ_BOX'] ?? $_POST['Proj_box'] ?? $_POST['proj_id'] ?? 0);
if ($proj_id <= 0) {
    echo "<p>No project selected. <a href=\"more.php\">Back</a></p>";
    exit;
}

// Get timesheets for the project that have no invoice yet
$sql = "SELECT Timesheets.*, Staff.`BILLING RATE` AS BILLING_RATE, Clients.Multiplier AS Multiplier, Projects.Client_ID AS Client_ID
        FROM Timesheets
        LEFT JOIN Staff    ON Timesheets.Employee_id = Staff.Employee_ID
        LEFT JOIN Projects ON Timesheets.proj_id     = Projects.proj_id
        LEFT JOIN Clients  ON Projects.Client_ID     = Clients.Client_id
        WHERE Timesheets.Invoice_No = 0 AND Timesheets.proj_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$proj_id]);
$firstRow = $stmt->fetch(PDO::FETCH_ASSOC);

if ($firstRow === false) {
    echo "No uninvoiced timesheets found for this project.";
    exit;
}

$client_id  = (int)($firstRow['Client_ID'] ?? $firstRow['CLIENT_ID'] ?? 0);
$ts_proj_id = (int)$firstRow['proj_id'];

// Compute next invoice number (Invoices.Invoice_No isn't auto_increment)
$maxStmt = $pdo->query("SELECT COALESCE(MAX(Invoice_No), 0) AS maxy FROM Invoices");
$maxy    = (int)$maxStmt->fetch(PDO::FETCH_ASSOC)['maxy'] + 1;

// Insert new invoice with explicit Invoice_No. Default PaymentOption=1
// (20th of next month) and pre-compute PayBy so Xero, statements, and the
// invoice page all agree on the due date from the first save.
$today        = date('Y-m-d');
$defaultOpt   = 1;
$defaultPayBy = compute_pay_by($today, $defaultOpt);
$ins = $pdo->prepare("INSERT INTO Invoices (Invoice_No, Client_ID, Proj_ID, Date, PaymentOption, PayBy) VALUES (?, ?, ?, NOW(), ?, ?)");
$ins->execute([$maxy, $client_id, $ts_proj_id, $defaultOpt, $defaultPayBy]);

// Re-fetch all uninvoiced timesheets for this project
$stmt2 = $pdo->prepare("SELECT Timesheets.TS_ID, Staff.`BILLING RATE` AS BILLING_RATE, Clients.Multiplier AS Multiplier
        FROM Timesheets
        LEFT JOIN Staff    ON Timesheets.Employee_id = Staff.Employee_ID
        LEFT JOIN Projects ON Timesheets.proj_id     = Projects.proj_id
        LEFT JOIN Clients  ON Projects.Client_ID     = Clients.Client_id
        WHERE Timesheets.Invoice_No = 0 AND Timesheets.proj_id = ?");
$stmt2->execute([$proj_id]);

while ($ts = $stmt2->fetch(PDO::FETCH_ASSOC)) {
    $rate  = (float)($ts['BILLING_RATE'] ?? 0) * (float)($ts['Multiplier'] ?? 1);
    $ts_id = (int)$ts['TS_ID'];
    $updateStmt = $pdo->prepare("UPDATE Timesheets SET Invoice_No = ?, Rate = ? WHERE TS_ID = ?");
    $updateStmt->execute([$maxy, $rate, $ts_id]);
}

header('Location: invoice_edit.php?Invoice_No=' . $maxy);
exit;
?>
<html>
<body>
Copyright &copy; 2012 CADViz Ltd All Rights Reserved
</body>
</html>
