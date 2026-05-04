<?php
session_start();
require_once 'db_connect.php';
require_once 'helpers.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}
// Generating invoices commits real billing rows — admin-only.
if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403); die('Admin only.');
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

// Same defensive pattern as submit.php for Invoice_No: drop the
// historical Invoice_No=0 zombie if any, compute MAX+1 explicitly (works
// regardless of AUTO_INCREMENT), and retry on duplicate-key in case two
// invoice generations race or AUTO_INCREMENT and our explicit value
// collide. The previous "MAX+1 once, fingers crossed" path raced under
// concurrent presses of "Generate Invoice".
try { $pdo->exec("DELETE FROM Invoices WHERE Invoice_No = 0"); } catch (Exception $e) { /* non-fatal */ }
$maxStmt = $pdo->query("SELECT COALESCE(MAX(Invoice_No), 0) + 1 AS nxt FROM Invoices");
$maxy    = (int)$maxStmt->fetch(PDO::FETCH_ASSOC)['nxt'];
if ($maxy < 1) $maxy = 1;

// Insert new invoice with explicit Invoice_No. Default PaymentOption=1
// (20th of next month) and pre-compute PayBy so Xero, statements, and the
// invoice page all agree on the due date from the first save.
$today        = date('Y-m-d');
$defaultOpt   = 1;
$defaultPayBy = compute_pay_by($today, $defaultOpt);
$ins = $pdo->prepare("INSERT INTO Invoices (Invoice_No, Client_ID, Proj_ID, Date, PaymentOption, PayBy) VALUES (?, ?, ?, NOW(), ?, ?)");
$insAttempts = 0;
while (true) {
    try {
        $ins->execute([$maxy, $client_id, $ts_proj_id, $defaultOpt, $defaultPayBy]);
        break;
    } catch (PDOException $insErr) {
        $isDupe = ($insErr->getCode() === '23000' || strpos($insErr->getMessage(), '1062') !== false);
        if (!$isDupe || ++$insAttempts >= 5) throw $insErr;
        $r = $pdo->query("SELECT COALESCE(MAX(Invoice_No), 0) + 1 AS nxt FROM Invoices");
        $maxy = max((int)$r->fetch(PDO::FETCH_ASSOC)['nxt'], $maxy + 1, 1);
    }
}

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
