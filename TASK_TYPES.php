<?php
session_start();
require_once 'db_connect.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}

$pdo = get_db();

$stage_id = (string)($_GET['stage_id'] ?? '1');

// Check if stage exists; if not, move to next
$stmt = $pdo->prepare("SELECT * FROM Stage_Types WHERE Stage_Type_ID = ?");
$stmt->execute([$stage_id]);
$rscheck = $stmt->fetch(PDO::FETCH_ASSOC);

$minusvalue = 1;
if (!$rscheck) {
    $stage_id = (string)((int)$stage_id + 1);
    $minusvalue = 2;
}

$sql = "SELECT Stage_Types.Stage_Type_ID, Stage_Types.Stage_Type_Name, Stage_Types.Stage_Order,
        Tasks_Types.Task_ID, Tasks_Types.Task_Name, Tasks_Types.Estimated_Time,
        Tasks_Types.Task_Desc, Tasks_Types.Task_Order, Tasks_Types.Spec_Subcat_ID
        FROM Stage_Types LEFT OUTER JOIN Tasks_Types ON Stage_Types.Stage_Type_ID = Tasks_Types.Stage_ID
        WHERE Stage_Types.Stage_Type_ID = " . (int)$stage_id . "
        ORDER BY Tasks_Types.Task_Order";
$rs = $pdo->query($sql);
$rows = $rs->fetchAll(PDO::FETCH_ASSOC);

$rsmaxStmt = $pdo->query("SELECT MAX(Stage_Type_ID) AS max_id FROM Stage_Types");
$rsmax = $rsmaxStmt->fetch(PDO::FETCH_ASSOC);
$max_id = $rsmax['max_id'];

// Get Stage_Type_Name from first row (LEFT JOIN so first row always has stage info)
$stage_name = !empty($rows) ? $rows[0]['Stage_Type_Name'] : '';
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
.style4 {
    background-color: #FFFFFF;
    font:Arial, Gadget, sans-serif;
    font-weight:bold;
    font-size:20px;
    color:#000000;
}
.nrml {
    font-family: Verdana, Geneva, sans-serif;
    font-size:9px;
}
</style>
</head>
<body bgcolor="#FFFFFF" text="black">

<table width="964" border="0">
  <tr class="style3">
    <td width="225" rowspan="3" valign="top"><img src="cadviz_logo_bw1.gif" width="200" height="89" alt="CADVIZ"></td>
    <td width="431" rowspan="3" class="style3"><div align="center">Tasks</div></td>
    <td width="294"><div align="right"><span class="nrml">CADViz Ltd </span></div></td>
  </tr>
  <tr class="style3">
    <td><div align="right" class="nrml">PO Box 302387<br>North Harbour<br>Auckland 0751</div></td>
  </tr>
  <tr class="style3"><td><div align="right"></div></td></tr>
</table>
<p>&nbsp;</p>
<table width="910" border="0">
  <tr>
    <td width="100">Stage:</td>
    <td width="350" class="style4"><?= htmlspecialchars($stage_name) ?></td>
    <td>
      <?php if ((int)$stage_id > 1): ?>
        <a href='TASK_TYPES.php?stage_id=<?= (int)$stage_id - $minusvalue ?>'><font size='2'>PREVIOUS STAGE</font></a>
      <?php endif; ?>
    </td>
    <td>
      <?php if ((int)$stage_id < (int)$max_id): ?>
        <a href='TASK_TYPES.php?stage_id=<?= (int)$stage_id + 1 ?>'><font size='2'>NEXT STAGE</font></a>
      <?php endif; ?>
      &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; Page <?= htmlspecialchars($stage_id) ?> of <?= htmlspecialchars($max_id) ?>
    </td>
  </tr>
</table>

<p>&nbsp;</p>
<form method="POST" name="task_type_update_form" action="task_type_update.php">
<table width="800" border="1">
<tr>
  <td width="200" align="Center">Task Name</td>
  <td width="100" align="Center">Estimated Time</td>
  <td width="50" align="Center">Task Order</td>
  <td width="200" align="Center">Spec Subcat</td>
  <td width="50" align="Center">Drop</td>
</tr>

<input name="Stage_Id_box" type="hidden" value="<?= htmlspecialchars($stage_id) ?>">

<?php
$a = 0;
foreach ($rows as $row):
    $a++;
    echo "<tr>";
    echo "<td width='200'>";
    echo "<input size='5' type='hidden' name='Task_ID" . $a . "' value='" . htmlspecialchars($row['Task_ID']) . "'>";
    echo "<textarea cols='53' rows='1' name='Task_Name" . $a . "'>" . htmlspecialchars($row['Task_Name']) . "</textarea>";
    echo "</td>";
    echo "<td width='100'>";
    echo "<input size='6' name='Est_Time" . $a . "' value='" . htmlspecialchars($row['Estimated_Time']) . "'>";
    echo "</td>";
    echo "<td width='50'>";
    echo "<input size='6' name='Task_Order" . $a . "' value='" . htmlspecialchars($row['Task_Order']) . "'>";
    echo "</td>";
    echo "<td width='200'>";
    print_dd_box($pdo, "Spec_SubCats", "Spec_SubCat_ID", "Spec_SubCat_Name", $row['Spec_Subcat_ID'], "Spec_Subcat_ID" . $a);
    echo "<input size='5' type='hidden' name='stage_box" . $a . "' value='" . htmlspecialchars($stage_id) . "'>";
    echo "</td>";
    echo "<td><a href='task_type_drop.php?task_id=" . htmlspecialchars($row['Task_ID']) . "' onclick=\"return confirm('Are you sure you want to delete?')\"><img src='drop.gif'></a></td>";
    echo "</tr>";
endforeach;
echo "<input size='5' type='hidden' name='rowcount' value='" . $a . "'>";
?>

<tr><td></td><td></td><td></td></tr>
</table>
<input type="submit" name="Update_Entries" value="Update_Entries">
</form>

<form method="POST" name="task_type_add_form" action="task_type_add.php">
<table width="800" border="1">
<tr>
  <td width="200" align="Center">Task Name</td>
  <td width="100" align="Center">Estimated Time</td>
  <td width="50" align="Center">Task Order</td>
  <td width="200" align="Center">Spec Subcat</td>
</tr>
<?php
echo "<tr>";
echo "<td width='200'><textarea cols='53' rows='1' name='Task_Name_new'></textarea></td>";
echo "<td width='200'><input size='6' name='Est_Time_new' value=''></td>";
echo "<td width='200'><input size='6' name='Task_Order_new' value=''></td>";
echo "<td width='200'>";
print_dd_box($pdo, "Spec_SubCats", "Spec_SubCat_ID", "Spec_SubCat_Name", "", "Spec_SubCat");
echo "</td>";
echo "</tr>";
?>
</table>
<input type="submit" name="Add_Entry" value="Add_Entry">
<input name="Stage_Id_box_new" type="hidden" value="<?= htmlspecialchars($stage_id) ?>">
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
