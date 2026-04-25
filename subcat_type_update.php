<?php
session_start();
require_once 'db_connect.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}

$pdo = get_db();

$Spec_Cat_ID = $_POST['Spec_Cat_ID_box'] ?? '12';
// Note: original ASP hardcoded Spec_Cat_ID = "12" after reading from form; preserving that override here
// If you want dynamic behaviour, remove the next line:
$Spec_Cat_ID = "12";

$rowcount = (int)($_POST['rowcount'] ?? 0);

for ($a = 1; $a <= $rowcount; $a++) {
    $spec_subcat_id    = $_POST['Spec_SubCat_ID' . $a] ?? '';
    $spec_subcat_name  = $_POST['Spec_SubCat_Name' . $a] ?? '';
    $spec_subcat_order = $_POST['Spec_SubCat_Order' . $a] ?? '';

    if ($spec_subcat_order !== '') {
        $stmt = $pdo->prepare("UPDATE Spec_Subcats SET Spec_SubCat_Name = ?, Spec_SubCat_Order = ? WHERE Spec_SubCat_ID = ?");
        $stmt->execute([$spec_subcat_name, $spec_subcat_order, $spec_subcat_id]);
    } else {
        $stmt = $pdo->prepare("UPDATE Spec_Subcats SET Spec_SubCat_Name = ? WHERE Spec_SubCat_ID = ?");
        $stmt->execute([$spec_subcat_name, $spec_subcat_id]);
    }
}

header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'SUBCAT_TYPES.php'));
exit;
?>
<body>
Copyright &copy; 2020 CADViz Ltd All Rights Reserved
</body>
</html>
