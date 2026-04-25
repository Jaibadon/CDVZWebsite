<?php
session_start();
require_once 'db_connect.php';
require_once 'helpers.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}

$pdo = get_db();

$invoice_no = $_POST['Invoice_Num'] ?? null;
if (is_null($invoice_no)) {
    $invoice_no = 0;
}

$rowcount = (int)($_POST['rowcount'] ?? 0);

for ($a = 1; $a <= $rowcount; $a++) {
    $ts_id      = (int)($_POST['TS_ID_' . $a] ?? 0);
    $ts_date    = to_mysql_date($_POST['TS_Date_box' . $a] ?? '');
    $ts_inv     = (int)($_POST['TS_Inv_box' . $a] ?? 0);
    $staff_id   = (int)($_POST['staff_box' . $a] ?? 0);
    $desc       = $_POST['desc_box' . $a] ?? '';
    $hours      = (float)($_POST['TS_HOURS' . $a] ?? 0);
    $rate       = (float)($_POST['TS_RATE' . $a] ?? 0);

    if ($ts_id > 0) {
        $stmt = $pdo->prepare("UPDATE Timesheets SET TS_DATE = ?, Invoice_No = ?, Employee_id = ?, Task = ?, Hours = ?, Rate = ? WHERE TS_ID = ?");
        $stmt->execute([$ts_date, $ts_inv, $staff_id, $desc, $hours, $rate, $ts_id]);
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
