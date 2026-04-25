<?php
session_start();
require_once 'db_connect.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}

$pdo = get_db();

$Project_Info_ID = (string)($_GET['Project_Info_ID'] ?? '');

if ($Project_Info_ID !== '') {
    $stmt = $pdo->prepare("DELETE FROM Project_Info WHERE Project_Info_ID = ?");
    $stmt->execute([$Project_Info_ID]);
}

header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'PROJECT_INFO.php'));
exit;
?>
<body>
Copyright &copy; 2020 CADViz Ltd All Rights Reserved
</body>
</html>
