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

// (Legacy ASP port had a $Spec_Cat_ID variable read from POST then
// hardcoded to "12" — but it was never actually used in the UPDATE
// statements below. Removed as dead code; previously made it look like
// only category 12 subcategories could be edited.)

$rowcount = (int)($_POST['rowcount'] ?? 0);

for ($a = 1; $a <= $rowcount; $a++) {
    $spec_subcat_id    = $_POST['Spec_SubCat_ID' . $a] ?? '';
    $spec_subcat_name  = $_POST['Spec_SubCat_Name' . $a] ?? '';
    $spec_subcat_order = $_POST['Spec_SubCat_Order' . $a] ?? '';

    if ($spec_subcat_order !== '') {
        $stmt = $pdo->prepare("UPDATE Spec_SubCats SET Spec_SubCat_Name = ?, Spec_SubCat_Order = ? WHERE Spec_SubCat_ID = ?");
        $stmt->execute([$spec_subcat_name, $spec_subcat_order, $spec_subcat_id]);
    } else {
        $stmt = $pdo->prepare("UPDATE Spec_SubCats SET Spec_SubCat_Name = ? WHERE Spec_SubCat_ID = ?");
        $stmt->execute([$spec_subcat_name, $spec_subcat_id]);
    }
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
?>
<body>
Copyright &copy; 2020 CADViz Ltd All Rights Reserved
</body>
</html>
