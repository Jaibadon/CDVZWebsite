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

$stage_id = $_POST['Stage_Id_box_new'] ?? '';
$task_name = $_POST['Task_Name_new'] ?? '';
$est_time = $_POST['Est_Time_new'] ?? '';
$task_order = $_POST['Task_Order_new'] ?? '';
$spec_subcat = $_POST['Spec_SubCat'] ?? '';

if ($spec_subcat !== '') {
    $stmt = $pdo->prepare("INSERT INTO Tasks_Types (Stage_ID, Task_Name, Estimated_Time, Task_Order, Spec_SubCat_ID) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$stage_id, $task_name, $est_time, $task_order, $spec_subcat]);
} else {
    $stmt = $pdo->prepare("INSERT INTO Tasks_Types (Stage_ID, Task_Name, Estimated_Time, Task_Order) VALUES (?, ?, ?, ?)");
    $stmt->execute([$stage_id, $task_name, $est_time, $task_order]);
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
