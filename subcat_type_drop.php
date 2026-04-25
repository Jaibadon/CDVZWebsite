<?php
session_start();
require_once 'db_connect.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}

$pdo = get_db();

$subcat_id = (string)($_GET['Spec_SubCat_ID'] ?? '');

if ($subcat_id !== '') {
    $stmt = $pdo->prepare("DELETE FROM Spec_Subcats WHERE Spec_SubCat_ID = ?");
    $stmt->execute([$subcat_id]);
}

header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'SUBCAT_TYPES.php'));
exit;
