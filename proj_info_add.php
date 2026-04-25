<?php
session_start();
require_once 'db_connect.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}

$pdo = get_db();

$proj_id             = $_POST['Proj_Id_box_new'] ?? '';
$project_info_type   = $_POST['Project_Info_type'] ?? '';
$info_data           = $_POST['Info_Data_new'] ?? '';
$hyperlink           = $_POST['Hyperlink_new'] ?? '';

$stmt = $pdo->prepare("INSERT INTO Project_Info (proj_ID, Project_Info_Type_ID, Project_Info_Data, Hyperlink) VALUES (?, ?, ?, ?)");
$stmt->execute([$proj_id, $project_info_type, $info_data, $hyperlink]);

header('Location: PROJECT_INFO.php?proj_id=' . urlencode($proj_id));
exit;
?>
<body>
Copyright &copy; 2020 CADViz Ltd All Rights Reserved
</body>
</html>
