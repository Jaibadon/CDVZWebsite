<?php
session_start();
require_once 'db_connect.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}

$pdo = get_db();
$proj_id = (string)($_GET['proj_id'] ?? '');
$curdate = date('d/m/Y');

if ($proj_id === '') {
    header('Location: projects.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM Projects WHERE proj_id = ?");
$stmt->execute([$proj_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    header('Location: projects.php');
    exit;
}

$stat   = $row['Status']    ?? '';
$jnotes = $row['Job_Notes'] ?? '';

$newJnotes = $jnotes . " - Job set as Done / Inactive By - " . $_SESSION['UserID'] . " - " . $curdate;
$newStat   = $stat   . " - Job set as Done by: " . $_SESSION['UserID'] . " - " . $curdate;

$updateStmt = $pdo->prepare("UPDATE Projects SET JOB_NOTES = ?, Status = ?, Active = 0 WHERE proj_id = ?");
$updateStmt->execute([$newJnotes, $newStat, $proj_id]);

$referer = $_SERVER['HTTP_REFERER'] ?? 'projects.php';
header('Location: ' . $referer);
exit;
?>
<html>
<body>
Copyright &copy; 2020 CADViz Ltd All Rights Reserved
</body>
</html>
