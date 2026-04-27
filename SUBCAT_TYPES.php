<?php
session_start();
require_once 'db_connect.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}

$pdo = get_db();

$Spec_Cat_ID = (string)($_GET['Spec_Cat_ID'] ?? '1');

// Get max id
$rsmaxStmt = $pdo->query("SELECT MAX(Spec_Cat_ID) AS max_id FROM Spec_Cats");
$rsmax = $rsmaxStmt->fetch(PDO::FETCH_ASSOC);
$max_id = $rsmax['max_id'];

// Find next valid ID
$nextid = (string)((int)$Spec_Cat_ID + 1);
if ((int)$Spec_Cat_ID < (int)$max_id) {
    while (true) {
        $stmtCheck = $pdo->prepare("SELECT * FROM Spec_Cats WHERE Spec_Cat_ID = ?");
        $stmtCheck->execute([$nextid]);
        if ($stmtCheck->fetch()) break;
        $nextid = (string)((int)$nextid + 1);
        if ((int)$nextid > (int)$max_id) break;
    }
}

// Find previous valid ID
$previd = (string)((int)$Spec_Cat_ID - 1);
if ((int)$Spec_Cat_ID > 1) {
    while (true) {
        $stmtCheck = $pdo->prepare("SELECT * FROM Spec_Cats WHERE Spec_Cat_ID = ?");
        $stmtCheck->execute([$previd]);
        if ($stmtCheck->fetch()) break;
        $previd = (string)((int)$previd - 1);
        if ((int)$previd < 1) break;
    }
}

$sql = "SELECT Spec_Cats.Spec_Cat_ID, Spec_Cats.Spec_Cat_Name, Spec_Cats.Spec_Cat_Order,
        Spec_SubCats.Spec_SubCat_ID, Spec_SubCats.Spec_SubCat_Name, Spec_SubCats.Spec_SubCat_Order
        FROM Spec_Cats LEFT OUTER JOIN Spec_SubCats ON Spec_Cats.Spec_Cat_ID = Spec_SubCats.Spec_Cat_ID
        WHERE Spec_Cats.Spec_Cat_ID = " . (int)$Spec_Cat_ID . "
        ORDER BY Spec_SubCats.Spec_SubCat_Order";
$rs = $pdo->query($sql);
$rows = $rs->fetchAll(PDO::FETCH_ASSOC);

$spec_cat_name = !empty($rows) ? $rows[0]['Spec_Cat_Name'] : '';
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
    <td width="431" rowspan="3" class="style3"><div align="center">Subcat Types</div></td>
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
    <td width="100">SPEC CATEGORY:</td>
    <td width="350" class="style4"><?= htmlspecialchars($spec_cat_name) ?></td>
    <td>
      <?php if ((int)$Spec_Cat_ID > 1): ?>
        <a href='SUBCAT_TYPES.php?Spec_Cat_ID=<?= htmlspecialchars($previd) ?>'><font size='2'>PREVIOUS</font></a>
      <?php else: ?>
        PREVIOUS
      <?php endif; ?>
    </td>
    <td>
      <?php if ((int)$Spec_Cat_ID < (int)$max_id): ?>
        <a href='SUBCAT_TYPES.php?Spec_Cat_ID=<?= htmlspecialchars($nextid) ?>'><font size='2'>NEXT</font></a>
      <?php else: ?>
        NEXT
      <?php endif; ?>
      &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; Page <?= htmlspecialchars($Spec_Cat_ID) ?> of <?= htmlspecialchars($max_id) ?>
    </td>
  </tr>
</table>

<p>&nbsp;</p>
<form method="POST" name="subcat_type_update_form" action="subcat_type_update.php">
<table width="800" border="1">
<tr>
  <td width="200" align="Center">Subcat Name</td>
  <td width="50" align="Center">Subcat Order</td>
  <td width="50" align="Center">Drop</td>
</tr>

<input name="Spec_Cat_ID_box" type="hidden" value="<?= htmlspecialchars($Spec_Cat_ID) ?>">

<?php
$a = 0;
foreach ($rows as $row):
    $a++;
    echo "<tr>";
    echo "<td width='200'>";
    echo "<input size='5' type='hidden' name='Spec_SubCat_ID" . $a . "' value='" . htmlspecialchars($row['Spec_SubCat_ID']) . "'>";
    echo "<textarea cols='80' rows='1' name='Spec_SubCat_Name" . $a . "'>" . htmlspecialchars($row['Spec_SubCat_Name']) . "</textarea>";
    echo "</td>";
    echo "<td width='50'>";
    echo "<input size='6' name='Spec_SubCat_Order" . $a . "' value='" . htmlspecialchars($row['Spec_SubCat_Order']) . "'>";
    echo "<input size='5' type='hidden' name='spec_cat_box" . $a . "' value='" . htmlspecialchars($Spec_Cat_ID) . "'>";
    echo "</td>";
    echo "<td><a href='subcat_type_drop.php?Spec_SubCat_ID=" . htmlspecialchars($row['Spec_SubCat_ID']) . "' onclick=\"return confirm('Are you sure you want to delete?')\"><img src='drop.gif'></a></td>";
    echo "</tr>";
endforeach;
echo "<input size='5' type='hidden' name='rowcount' value='" . $a . "'>";
?>

<tr><td></td><td></td><td></td></tr>
</table>
<input type="submit" name="Update_Entries" value="Update_Entries">
</form>

<form method="POST" name="subcat_type_add_form" action="subcat_type_add.php">
<table width="800" border="1">
<tr>
  <td width="200" align="Center">Subcat Name</td>
  <td width="50" align="Center">Subcat Order</td>
</tr>
<?php
echo "<tr>";
echo "<td width='200'><textarea cols='80' rows='1' name='Spec_SubCat_Name_new'></textarea></td>";
echo "<td width='200'><input size='6' name='Spec_SubCat_Order_new' value=''></td>";
echo "</tr>";
?>
</table>
<input type="submit" name="Add_Entry" value="Add_Entry">
<input name="Spec_Cat_ID_box_new" type="hidden" value="<?= htmlspecialchars($Spec_Cat_ID) ?>">
</form>

</body>
</html>
