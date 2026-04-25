<?php
session_start();
require_once 'db_connect.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}

$pdo = get_db();

$task_id = (string)($_GET['task_id'] ?? '');

if ($task_id !== '') {
    $stmt = $pdo->prepare("DELETE FROM Tasks_Types WHERE Task_ID = ?");
    $stmt->execute([$task_id]);
}

header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'TASK_TYPES.php'));
exit;
?>
<body>
Copyright &copy; 2020 CADViz Ltd All Rights Reserved
</body>
</html>
