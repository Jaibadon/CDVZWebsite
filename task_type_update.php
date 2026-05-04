<?php
session_start();
require_once 'db_connect.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}
if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403);
    echo '<p>Admin only. <a href="menu.php">Back to menu</a></p>';
    exit;
}

$pdo = get_db();

$stage_id = $_POST['Stage_Id_box'] ?? '';
$rowcount = (int)($_POST['rowcount'] ?? 0);

for ($a = 1; $a <= $rowcount; $a++) {
    $task_id    = $_POST['Task_ID' . $a] ?? '';
    $task_name  = $_POST['Task_Name' . $a] ?? '';
    $est_time   = $_POST['Est_Time' . $a] ?? '';
    $task_order = $_POST['Task_Order' . $a] ?? '';
    $spec_subcat_id = $_POST['Spec_Subcat_ID' . $a] ?? '';

    if ($task_order !== '' && $spec_subcat_id !== '') {
        $stmt = $pdo->prepare("UPDATE Tasks_Types SET Task_Name = ?, Estimated_Time = ?, Task_Order = ?, Spec_Subcat_ID = ? WHERE Task_ID = ?");
        $stmt->execute([$task_name, $est_time, $task_order, $spec_subcat_id, $task_id]);
    } elseif ($task_order !== '') {
        $stmt = $pdo->prepare("UPDATE Tasks_Types SET Task_Name = ?, Estimated_Time = ?, Task_Order = ? WHERE Task_ID = ?");
        $stmt->execute([$task_name, $est_time, $task_order, $task_id]);
    } elseif ($spec_subcat_id !== '') {
        $stmt = $pdo->prepare("UPDATE Tasks_Types SET Task_Name = ?, Estimated_Time = ?, Spec_Subcat_ID = ? WHERE Task_ID = ?");
        $stmt->execute([$task_name, $est_time, $spec_subcat_id, $task_id]);
    } else {
        $stmt = $pdo->prepare("UPDATE Tasks_Types SET Task_Name = ?, Estimated_Time = ? WHERE Task_ID = ?");
        $stmt->execute([$task_name, $est_time, $task_id]);
    }
}

// Same-host referrer only — guards against open-redirect via a forged
// Referer header. If the value isn't an internal path we fall through
// to the fixed page below.
$ref = $_SERVER['HTTP_REFERER'] ?? '';
$dest = 'TASK_TYPES.php';
if ($ref !== '') {
    $p = parse_url($ref);
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (empty($p['host']) || strcasecmp($p['host'], $host) === 0) {
        $dest = $ref;
    }
}
header('Location: ' . $dest);
exit;
?>
<body>
Copyright &copy; 2020 CADViz Ltd All Rights Reserved
</body>
</html>
