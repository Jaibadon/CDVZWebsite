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

$task_id = (string)($_GET['task_id'] ?? '');

if ($task_id !== '') {
    $stmt = $pdo->prepare("DELETE FROM Tasks_Types WHERE Task_ID = ?");
    $stmt->execute([$task_id]);
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
