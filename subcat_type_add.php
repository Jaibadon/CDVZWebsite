<?php
session_start();
require_once 'db_connect.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}

$pdo = get_db();

$spec_cat_id   = $_POST['Spec_Cat_ID_box_new']  ?? '';
$subcat_name   = $_POST['Spec_SubCat_Name_new'] ?? '';
$subcat_order  = $_POST['Spec_SubCat_Order_new'] ?? '';

if ($subcat_name !== '') {
    $stmt = $pdo->prepare("INSERT INTO Spec_SubCats (Spec_Cat_ID, Spec_SubCat_Name, Spec_SubCat_Order) VALUES (?, ?, ?)");
    $stmt->execute([$spec_cat_id, $subcat_name, $subcat_order]);
}

header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'SUBCAT_TYPES.php'));
exit;
