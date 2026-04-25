<?php
session_start();
require_once 'db_connect.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}

$pdo = get_db();

$proj_id  = $_POST['Proj_Id_box'] ?? '';
$rowcount = (int)($_POST['rowcount'] ?? 0);

for ($a = 1; $a <= $rowcount; $a++) {
    $project_info_id      = $_POST['Project_Info_ID' . $a] ?? '';
    $project_info_type_id = $_POST['Project_Info_Type' . $a] ?? '';
    $project_info_data    = $_POST['Project_Info_Data_box' . $a] ?? '';
    $hyperlink            = $_POST['Hyperlink_box' . $a] ?? '';

    $stmt = $pdo->prepare("UPDATE Project_Info SET Project_Info_Type_ID = ?, Project_Info_Data = ?, Proj_ID = ?, Hyperlink = ? WHERE Project_Info_ID = ?");
    $stmt->execute([$project_info_type_id, $project_info_data, $proj_id, $hyperlink, $project_info_id]);
}

header('Location: PROJECT_INFO.php?proj_id=' . urlencode($proj_id));
exit;
?>
<body>
Copyright &copy; 2012 CADViz Ltd All Rights Reserved
</body>
</html>
