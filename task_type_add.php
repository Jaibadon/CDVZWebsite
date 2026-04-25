<?php
session_start();
require_once 'db_connect.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
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

header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'TASK_TYPES.php'));
exit;
?>
<body>
Copyright &copy; 2020 CADViz Ltd All Rights Reserved
</body>
</html>
