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

// Insert new timesheet entry.
// TS_ID is assigned explicitly, MAX+1 across Timesheets AND Timesheets_HIST
// (same rule as submit.php / timesheet_hook.php): the AFTER DELETE trigger
// archives rows into HIST, so a TS_ID that only checks Timesheets can
// collide there and kill the archive on a later delete. Inserting without
// TS_ID relied on the AUTO_INCREMENT migration (schema_upgrades.sql) having
// run — installs without it got TS_ID=0 zombie rows.
$maxExpr = "(SELECT COALESCE(MAX(TS_ID), 0) FROM Timesheets)";
try {
    if ($pdo->query("SHOW TABLES LIKE 'Timesheets_HIST'")->fetch()) {
        $maxExpr = "GREATEST($maxExpr, (SELECT COALESCE(MAX(TS_ID), 0) FROM Timesheets_HIST))";
    }
} catch (Exception $e) { /* HIST table doesn't exist — fine */ }

$stmt = $pdo->prepare("INSERT INTO Timesheets (TS_ID, TS_DATE, Invoice_No, proj_id, Employee_id, Task, Hours, Rate) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$attempts = 0;
while (true) {
    $nextTsId = (int)($pdo->query("SELECT $maxExpr + 1 AS nxt")->fetch(PDO::FETCH_ASSOC)['nxt'] ?? 0);
    if ($nextTsId < 1) $nextTsId = 1;
    try {
        $stmt->execute([
            $nextTsId,
            to_mysql_date($_POST['TS_date_new'] ?? ''),
            (int)($_POST['TS_Inv_box_new'] ?? 0),
            (int)($_POST['Project_box_new'] ?? 0),
            (int)$staffId,
            $_POST['desc_box_new'] ?? '',
            (float)($_POST['TS_HOURS_new'] ?? 0),
            $billingRate
        ]);
        break;
    } catch (PDOException $e) {
        $isDupe = ($e->getCode() === '23000' || strpos($e->getMessage(), '1062') !== false);
        if (!$isDupe || ++$attempts >= 5) throw $e;
    }
}

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
