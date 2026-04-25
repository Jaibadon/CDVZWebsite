<?php
session_start();
require_once 'db_connect.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}

$pdo = get_db();

$sql = "SELECT Stage_Types.Stage_Type_ID, Stage_Types.Stage_Type_Name, Stage_Types.Stage_Order,
        Tasks_Types.Task_ID, Tasks_Types.Task_Name, Tasks_Types.Estimated_Time,
        Tasks_Types.Task_Desc, Tasks_Types.Task_Order, Tasks_Types.Spec_Subcat_ID
        FROM Stage_Types LEFT OUTER JOIN Tasks_Types ON Stage_Types.Stage_Type_ID = Tasks_Types.Stage_ID";

$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<script language="Javascript">
    document.onkeydown = function() {
        if (event.keyCode == 13) {
            window.location="projects.php"
        }
    }
</script>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=windows-1252">
<meta http-equiv="Content-Language" content="en-nz">
<link href="invoice.css" rel="stylesheet" type="text/css" />
<title>CADviz_Project</title>
<basefont face="arial" size="2">
<style type="text/css">
.style3 {
    background-color: #FFFFFF;
    font:Arial, Gadget, sans-serif;
    font-weight:bold;
    font-size:30px;
    color:#000000;
}
.nrml {
    font-family: Verdana, Geneva, sans-serif;
    font-size:9px;
}
</style>
</head>
<body bgcolor="#FFFFFF" text="black">
</body>
</html>

<?php
function print_dd_box($pdo, $table_name, $index_name, $display_name, $default_value, $obj_name) {
    $sql = "SELECT " . $index_name . ", " . $display_name . " FROM " . $table_name . " ORDER BY " . $display_name;
    $stmt = $pdo->query($sql);
    echo '<SELECT name="' . htmlspecialchars($obj_name) . '">';
    echo '<OPTION VALUE=""> ';
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($row[$index_name] == $default_value) {
            echo '<OPTION SELECTED VALUE="' . htmlspecialchars($row[$index_name]) . '">' . htmlspecialchars($row[$display_name]);
        } else {
            echo '<OPTION VALUE="' . htmlspecialchars($row[$index_name]) . '">' . htmlspecialchars($row[$display_name]);
        }
    }
    echo '</SELECT>';
}
?>
