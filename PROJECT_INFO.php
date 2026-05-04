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

$proj_id = (string)($_GET['proj_id'] ?? '');

$sql = "SELECT Projects.proj_ID, Projects.jobname, Project_Info.Project_Info_ID,
        Project_Info.Project_Info_Data, Project_Info.Project_Info_Type_ID, Project_Info.Hyperlink
        FROM Projects LEFT OUTER JOIN Project_Info ON Projects.proj_ID = Project_Info.proj_ID
        WHERE Projects.Proj_ID = " . (int)$proj_id;
$rs = $pdo->query($sql);
$rows = $rs->fetchAll(PDO::FETCH_ASSOC);

$job_name = !empty($rows) ? $rows[0]['jobname'] : '';
$proj_id_db = !empty($rows) ? $rows[0]['proj_ID'] : $proj_id;
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
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="Content-Language" content="en-nz">
<link href="site.css" rel="stylesheet">
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

<table width="800" border="0">
  <tr class="style3">
    <td width="207" rowspan="3" valign="top"><img src="cadviz_logo_bw1.gif" width="200" height="89" alt="CADVIZ"></td>
    <td width="397" rowspan="3" class="style3"><div align="center">Project Info</div></td>
    <td width="181"><div align="right"><span class="nrml">CADViz Ltd </span></div></td>
  </tr>
  <tr class="style3">
    <td><div align="right" class="nrml">PO Box 302387<br>North Harbour<br>Auckland 0751</div></td>
  </tr>
  <tr class="style3"><td><div align="right"></div></td></tr>
</table>
<table width="810" border="0">
  <tr>
    <td width="100">Project:</td>
    <td width="293"><?= htmlspecialchars($job_name) ?></td>
    <td width="191">&nbsp;</td>
    <td width="76">&nbsp;</td>
  </tr>
</table>

<form method="POST" name="proj_info_update_form" action="proj_info_update.php">
<table width="800" border="1">
<tr>
  <td width="200" align="Center">Info Type</td>
  <td width="400" align="Center">Info Data</td>
  <td width="200" align="Center">Hyperlink</td>
  <td width="50" align="Center">Drop</td>
</tr>

<input name="Proj_Id_box" type="hidden" value="<?= htmlspecialchars($proj_id_db) ?>">

<?php
$a = 0;
foreach ($rows as $row):
    $a++;
    echo "<tr>";
    echo "<td width='200'>";
    print_dd_box($pdo, "Project_Info_Types", "Project_Info_Type_ID", "Project_Info_Type", $row['Project_Info_Type_ID'], "Project_Info_type" . $a);
    echo "</td>";
    echo "<td width='400'>";
    echo "<input size='5' type='hidden' name='Project_Info_ID" . $a . "' value='" . htmlspecialchars($row['Project_Info_ID']) . "'>";
    echo "<textarea cols='53' rows='1' name='Project_Info_Data_box" . $a . "'>" . htmlspecialchars($row['Project_Info_Data']) . "</textarea>";
    echo "</td>";
    echo "<td width='200'>";
    echo "<input size='25' name='Hyperlink_box" . $a . "' value='" . htmlspecialchars($row['Hyperlink']) . "'>";
    echo "<input size='5' type='hidden' name='Project_box" . $a . "' value='" . htmlspecialchars($proj_id) . "'>";
    echo "</td>";
    echo "<td><a href='proj_info_drop.php?Project_Info_ID=" . htmlspecialchars($row['Project_Info_ID']) . "' onclick=\"return confirm('Are you sure you want to delete?')\"><img src='drop.gif'></a></td>";
    echo "</tr>";
endforeach;
echo "<input size='5' type='hidden' name='rowcount' value='" . $a . "'>";
?>

<tr><td></td><td></td><td></td></tr>
</table>
<input type="submit" name="Update_Entries" value="Update_Entries">
</form>

<form method="POST" name="proj_info_form" action="proj_info_add.php">
<table width="800" border="1">
<tr>
  <td width="200" align="Center">Info Type</td>
  <td width="400" align="Center">Info Data</td>
  <td width="200" align="Center">Hyperlink</td>
</tr>
<?php
echo "<tr>";
echo "<td width='200'>";
print_dd_box($pdo, "Project_Info_Types", "Project_Info_Type_ID", "Project_Info_Type", "", "Project_Info_type");
echo "</td>";
echo "<td width='400'><textarea cols='53' rows='1' name='Info_Data_new'></textarea></td>";
echo "<td width='200'><input size='25' name='Hyperlink_new' value=''></td>";
echo "</tr>";
?>
</table>
<input type="submit" name="Add_Entry" value="Add_Entry">
<input name="Proj_Id_box_new" type="hidden" value="<?= htmlspecialchars($proj_id) ?>">
</form>

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
