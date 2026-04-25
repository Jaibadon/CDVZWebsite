<?php
session_start();
require_once 'db_connect.php';

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
$sql = "SELECT Timesheets.*, Staff.`BILLING RATE` AS BILLING_RATE, MULTIPLIER, Projects.CLIENT_ID
        FROM ((Timesheets LEFT JOIN Staff ON Timesheets.Employee_id = Staff.Employee_ID)
        LEFT JOIN Projects ON Timesheets.proj_id = Projects.proj_id)
        LEFT JOIN Clients ON Projects.Client_ID = Clients.Client_ID
        WHERE INVOICE_NO = 0 AND Timesheets.PROJ_ID = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$proj_id]);
$firstRow = $stmt->fetch(PDO::FETCH_ASSOC);

if ($firstRow === false) {
    echo "No uninvoiced timesheets found for this project.";
    exit;
}

$client_id = (int)$firstRow['CLIENT_ID'];
$ts_proj_id = (int)$firstRow['proj_id'];

// Insert new invoice
$pdo->exec("INSERT INTO Invoices (Client_ID, Proj_ID) VALUES ($client_id, $ts_proj_id)");

// Get the new invoice number
$maxStmt = $pdo->query("SELECT MAX(INVOICE_NO) as maxy FROM Invoices");
$maxRow = $maxStmt->fetch(PDO::FETCH_ASSOC);
$maxy = (int)$maxRow['maxy'];

// Re-fetch all uninvoiced timesheets for this project
$stmt2 = $pdo->prepare("SELECT Timesheets.*, Staff.`BILLING RATE` AS BILLING_RATE, MULTIPLIER
        FROM ((Timesheets LEFT JOIN Staff ON Timesheets.Employee_id = Staff.Employee_ID)
        LEFT JOIN Projects ON Timesheets.proj_id = Projects.proj_id)
        LEFT JOIN Clients ON Projects.Client_ID = Clients.Client_ID
        WHERE INVOICE_NO = 0 AND Timesheets.PROJ_ID = ?");
$stmt2->execute([$proj_id]);

while ($ts = $stmt2->fetch(PDO::FETCH_ASSOC)) {
    $rate = (float)$ts['BILLING_RATE'] * (float)$ts['Multiplier'];
    $ts_id = (int)$ts['TS_ID'];
    $updateStmt = $pdo->prepare("UPDATE Timesheets SET Invoice_No = ?, Rate = ? WHERE TS_ID = ?");
    $updateStmt->execute([$maxy, $rate, $ts_id]);
}

header('Location: invoice_edit.php?invoice_no=' . $maxy);
exit;
?>
<html>
<body>
Copyright &copy; 2012 CADViz Ltd All Rights Reserved
</body>
</html>
