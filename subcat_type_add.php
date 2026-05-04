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

$spec_cat_id   = $_POST['Spec_Cat_ID_box_new']  ?? '';
$subcat_name   = $_POST['Spec_SubCat_Name_new'] ?? '';
$subcat_order  = $_POST['Spec_SubCat_Order_new'] ?? '';

if ($subcat_name !== '') {
    $stmt = $pdo->prepare("INSERT INTO Spec_SubCats (Spec_Cat_ID, Spec_SubCat_Name, Spec_SubCat_Order) VALUES (?, ?, ?)");
    $stmt->execute([$spec_cat_id, $subcat_name, $subcat_order]);
}

// Same-host referrer only — guards against open-redirect via a forged
// Referer header. If the value isn't an internal path we fall through
// to the fixed page below.
$ref = $_SERVER['HTTP_REFERER'] ?? '';
$dest = 'SUBCAT_TYPES.php';
if ($ref !== '') {
    $p = parse_url($ref);
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (empty($p['host']) || strcasecmp($p['host'], $host) === 0) {
        $dest = $ref;
    }
}
header('Location: ' . $dest);
exit;
