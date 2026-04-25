<?php
session_start();
require_once 'db_connect.php';
require_once 'helpers.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}

$pdo = get_db();

$invoice_no = $_POST['Invoice_Number'] ?? 0;

// Get the billing rate from Staff for the selected employee
$staffId = $_POST['staff_box_new'];
$stmtRate = $pdo->prepare("SELECT `BILLING RATE` AS BILLING_RATE FROM Staff WHERE Employee_id = ?");
$stmtRate->execute([$staffId]);
$staffRow = $stmtRate->fetch(PDO::FETCH_ASSOC);
$billingRate = $staffRow ? $staffRow['BILLING_RATE'] : 0;

// Insert new timesheet entry
$stmt = $pdo->prepare("INSERT INTO Timesheets (TS_DATE, Invoice_No, proj_id, Employee_id, Task, Hours, Rate) VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmt->execute([
    to_mysql_date($_POST['TS_date_new'] ?? ''),
    (int)($_POST['TS_Inv_box_new'] ?? 0),
    (int)($_POST['Project_box_new'] ?? 0),
    (int)$staffId,
    $_POST['desc_box_new'] ?? '',
    (float)($_POST['TS_HOURS_new'] ?? 0),
    $billingRate
]);

if ((int)$invoice_no !== 0) {
    header('Location: invoice_edit.php?invoice_no=' . $invoice_no);
} else {
    header('Location: unprocessed.php');
}
exit;
?>
<body>
Copyright &copy; 2012 CADViz Ltd All Rights Reserved
</body>

</html>
