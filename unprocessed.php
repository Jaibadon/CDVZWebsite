<?php
session_start();
require_once 'db_connect.php';
?>
<script language="Javascript">
	document.onkeydown = function() {
		if (event.keyCode == 13) {
			window.location="more.php"
		}
	}
</script>
<html>

<head>
<meta http-equiv="Content-Type" content="text/html; charset=windows-1252">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="Content-Language" content="en-nz">
<!-- style sheet -->
<link href="site.css" rel="stylesheet">
<link href="invoice.css" rel="stylesheet" type="text/css" />
<title>UNPROCESSED TIMESHEET ENTRIES</title>
<basefont face="arial" size="2">
<style type="text/css">
.style1 {
	text-align: left;
}
.style2 {
	text-align: right;
}
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
<?php
if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}
if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403);
    echo '<p>Admin only. <a href="menu.php">Back to menu</a></p>';
    exit;
}
?>
<body bgcolor="#FFFFFF" text="black">

<?php
$pdo = get_db();

$subtot = 0;
$a = 0;

// Explicit aliased SELECT — `SELECT *` over a JOIN drops columns
// when names collide (e.g. proj_id is in both Timesheets and Projects).
// Use IFNULL on Invoice_No so rows where it is NULL are still considered
// "uninvoiced" (some old rows may have NULL instead of 0).
$strSQL = "SELECT Timesheets.TS_ID        AS TS_ID,
                  Timesheets.TS_DATE      AS TS_DATE,
                  Timesheets.proj_id      AS proj_id,
                  Timesheets.Task         AS Task,
                  Timesheets.Hours        AS Hours,
                  Timesheets.Rate         AS Rate,
                  IFNULL(Timesheets.Invoice_No, 0) AS Invoice_No,
                  Timesheets.Employee_id  AS Employee_id,
                  Staff.Login             AS Login,
                  Projects.JobName        AS JobName,
                  (Timesheets.Rate * Timesheets.Hours) AS amt
             FROM Timesheets
             LEFT OUTER JOIN Staff    ON Timesheets.Employee_id = Staff.Employee_ID
             LEFT OUTER JOIN Projects ON Timesheets.proj_id     = Projects.proj_id
            WHERE IFNULL(Timesheets.Invoice_No, 0) = 0
            ORDER BY Timesheets.TS_DATE";
$stmt = $pdo->query($strSQL);

// Optional diagnostic: append ?debug=1 to the URL to see why a project
// might be missing. Lists row counts grouped by proj_id and JobName,
// plus what's filtering rows out.
if (isset($_GET['debug'])) {
    echo '<div style="background:#ffe;border:1px solid #cc9;padding:8px;margin:8px;font:12px monospace">';
    echo '<b>Debug — uninvoiced timesheet entries by project</b><br>';
    $diag = $pdo->query(
        "SELECT t.proj_id,
                p.JobName,
                p.Active,
                COUNT(*)                                  AS rows_total,
                SUM(IFNULL(t.Invoice_No,0) = 0)           AS rows_uninvoiced,
                SUM(IFNULL(t.Invoice_No,0) <> 0)          AS rows_invoiced,
                SUM(t.Invoice_No IS NULL)                 AS rows_inv_null
           FROM Timesheets t
           LEFT JOIN Projects p ON t.proj_id = p.proj_id
          GROUP BY t.proj_id, p.JobName, p.Active
          ORDER BY rows_uninvoiced DESC, t.proj_id"
    );
    echo '<table border="1" cellpadding="2" cellspacing="0">';
    echo '<tr><th>proj_id</th><th>JobName</th><th>Project Active</th>'
       . '<th>total</th><th>uninvoiced</th><th>invoiced</th><th>NULL Invoice_No</th></tr>';
    while ($r = $diag->fetch(PDO::FETCH_ASSOC)) {
        $highlight = ((int)$r['proj_id'] === 1436) ? ' style="background:#ffd"' : '';
        echo '<tr' . $highlight . '>';
        echo '<td>' . htmlspecialchars((string)$r['proj_id']) . '</td>';
        echo '<td>' . htmlspecialchars((string)($r['JobName'] ?? '<em>(no Projects row)</em>')) . '</td>';
        echo '<td align=center>' . htmlspecialchars((string)($r['Active'] ?? '?')) . '</td>';
        echo '<td align=right>' . (int)$r['rows_total'] . '</td>';
        echo '<td align=right><b>' . (int)$r['rows_uninvoiced'] . '</b></td>';
        echo '<td align=right>' . (int)$r['rows_invoiced'] . '</td>';
        echo '<td align=right>' . (int)$r['rows_inv_null'] . '</td>';
        echo '</tr>';
    }
    echo '</table></div>';
}
?>

<form method="POST" name="ts_update_form" action="ts_update.php">
<table width="810" border="1">
<tr>
<td width="88" align="Center">Date</td>
<td width="40" align="Center">INV</td>
<td width="50" align="Center">PROJ</td>
<td width="50" align="Center">STAFF</td>
<td width="261" align="Center">Item</td>
<td width="60" align="Center">Hours/Qty</td>
<td width="60" align="Center">Rate</td>
<td width="80" align="Center">Subtotal</td>
</tr>

<input name="Invoice_Num" type="hidden" align="right" value="0">
<?php while ($row = $stmt->fetch(PDO::FETCH_ASSOC)): $a++; ?>
<tr>
<td width="88"><input size="5" name="TS_Date_box<?= $a ?>" value="<?= htmlspecialchars(date('d/m/Y', strtotime($row['TS_DATE'] ?? $row['TS_Date'] ?? ''))) ?>"></td>
<td width="40">
<input size="5" type="hidden" name="TS_ID_<?= $a ?>" value="<?= htmlspecialchars($row['TS_ID'] ?? '') ?>">
<input size="5" name="TS_Inv_box<?= $a ?>" value="<?= htmlspecialchars($row['Invoice_No'] ?? '') ?>">
</td>
<td width="50"><?= htmlspecialchars($row['JobName'] ?? '') ?></td>
<td width="50">
<input size="5" type="hidden" name="staff_box<?= $a ?>" value="<?= htmlspecialchars($row['Employee_id'] ?? '') ?>">
<?= htmlspecialchars($row['Login'] ?? $row['login'] ?? '') ?>
</td>
<td><input size="35" name="desc_box<?= $a ?>" value="<?= htmlspecialchars($row['Task'] ?? $row['task'] ?? '') ?>"></td>
<td width="60" align="right"><input size="5" name="TS_HOURS<?= $a ?>" value="<?= htmlspecialchars((string)($row['Hours'] ?? '')) ?>"></td>
<td width="60" align="right"><input size="5" name="TS_RATE<?= $a ?>" value="<?= htmlspecialchars((string)($row['Rate'] ?? '')) ?>"></td>
<td width="70" align="right"><?= '$' . number_format((float)($row['amt'] ?? 0), 2) ?></td>
</tr>
<?php $subtot += (float)($row['amt'] ?? 0); endwhile; ?>
<input size="5" type="hidden" name="rowcount" value="<?= $a ?>">
<tr>
<td></td>
<td></td>
<td></td>
<td></td>
<td></td>
<td></td>
<td width="66" align="right">Subtotal:</td>
<td width="54" align="right"><?= $subtot ?></td>
</tr>

</table>
<input type="submit" name="Update_Entries" value="Update_Entries">
</form>


<form method="POST" name="ts_add_form" action="ts_add.php">
<table width="810" border="1">
<tr>
<td width="88" align="Center">Date</td>
<td width="40" align="Center">INV</td>
<td width="50" align="Center">PROJ</td>
<td width="50" align="Center">STAFF</td>
<td width="261" align="Center">Item</td>
<td width="60" align="Center">Hours/Qty</td>
<td width="60" align="Center">Rate</td>
<td width="80" align="Center">Subtotal</td>
</tr>

<?php
echo "<tr>";
echo "<td width='88'><input size='5' name='TS_date_new' value='" . date('d/m/Y') . "'></td>";
echo "<td width='40'><input size='5' name='TS_Inv_box_new' value='0'></td>";
echo "<td width='50'>";
// Show ALL projects in this dropdown (no Active filter) so leave/sick
// pseudo-projects like proj_id 1436 are pickable for new entries
$projOpts = $pdo->query("SELECT proj_id, JobName FROM Projects ORDER BY JobName");
echo '<select name="Project_box_new"><option value=""></option>';
while ($p = $projOpts->fetch(PDO::FETCH_ASSOC)) {
    echo '<option value="' . htmlspecialchars((string)$p['proj_id']) . '">'
       . htmlspecialchars((string)($p['JobName'] ?? '')) . '</option>';
}
echo '</select>';
echo "</td>";
echo "<td width='50'>";
print_dd_box($pdo, "Staff", "Employee_id", "Login", 7, "staff_box_new");
echo "</td>";
echo "<td><input size='35' name='desc_box_new' value='Services Rendered'></td>";
echo "<td width='60' align='right'><input size='5' name='TS_HOURS_new' value=''></td>";
echo "<td width='60' align='right'>calculated</td>";
echo "<td width='70' align='right'>calculated</td>";
echo "</tr>";
?>

</table>
<input type="submit" name="Add_Entry" value="Add_Entry">
<input name="Invoice_Number" type="hidden" align="right" value="0">
</form>


</body>

<?php
// print out a drop down box based on the values in a table
function print_dd_box($pdo, $table_name, $index_name, $display_name, $default_value, $obj_name, $filter = '') {
    // Try to fetch with active column; fall back without it for tables that don't have it
    try {
        $where = $filter ? " WHERE $filter" : '';
        $sql = "SELECT `$index_name`, `$display_name`, active FROM `$table_name`$where ORDER BY `$display_name`";
        $stmtTable = $pdo->query($sql);
        $hasActive = true;
    } catch (Exception $e) {
        $where = $filter ? " WHERE $filter" : '';
        $sql = "SELECT `$index_name`, `$display_name` FROM `$table_name`$where ORDER BY `$display_name`";
        $stmtTable = $pdo->query($sql);
        $hasActive = false;
    }
    echo "<SELECT name=\"$obj_name\">";
    echo "<OPTION VALUE=\"\"> ";
    while ($rowTable = $stmtTable->fetch(PDO::FETCH_ASSOC)) {
        $active = $hasActive ? ($rowTable['active'] ?? 1) : 1;
        if ($rowTable[$index_name] == $default_value) {
            echo "<OPTION SELECTED VALUE=\"" . htmlspecialchars($rowTable[$index_name]) . "\">" . htmlspecialchars($rowTable[$display_name] ?? '');
        } elseif ($active != 0) {
            echo "<OPTION VALUE=\"" . htmlspecialchars($rowTable[$index_name]) . "\">" . htmlspecialchars($rowTable[$display_name] ?? '');
        }
    }
    echo "</SELECT>";
}
?>
</html>
