<?php
session_start();
require_once 'db_connect.php';
require_once 'helpers.php';

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

$varDATE1 = $_POST['StartDate'] ?? '';
$varDATE2 = $_POST['EndDate'] ?? '';
$projectFilter = $_POST['Project'] ?? '';
$staffFilter   = $_POST['staff'] ?? $_POST['Staff'] ?? '';
?>
<html>

<head>
<META HTTP-EQUIV="Window-target" CONTENT="_top">
<title>Timesheet Report</title>
<link rel="stylesheet" href="global.css" type="text/css">
</head>

<body>
<center>
</center>
<div align="left"> </div>
<table width="100%" border="0" cellspacing="0" cellpadding="0" height="100%">
  <tr>
    <td align="center" valign="middle">
      <table width="95%" border="0" cellspacing="0" cellpadding="0" bgcolor="#EBEBEB">
        <tr>
          <td>&nbsp;</td>
        </tr>
        <tr>
          <td>
            <center>
              <h1><font face="Arial"><b>Timesheet Report</b> </font> </h1>
            </center>
            <hr noshade size="1">
            <p><font face="Arial" color='#000000'><br>
              <br>
              Date Range :
              <?php echo htmlspecialchars($varDATE1) . "<font color=blue> to </font> " . htmlspecialchars($varDATE2); ?>
              <br>
              <br>
              Project Number :
              <?php
if (!empty($projectFilter)) {
    echo htmlspecialchars($projectFilter);
} else {
    echo "not limited";
}
?>
              <br>
              <br>
              Staff Number :
              <?php
if (!empty($staffFilter)) {
    echo htmlspecialchars($staffFilter);
} else {
    echo "not limited";
}
?>

              <br>
              <br>

              <?php
$sql = "SELECT AL1.TS_DATE, AL1.TASK, AL4.JobName, AL1.hours, AL1.Employee_id, AL5.Login
        FROM Timesheets AL1
        JOIN Projects AL4 ON AL4.proj_id = AL1.proj_id
        LEFT JOIN Staff AL5 ON AL5.Employee_id = AL1.Employee_id
        WHERE 1=1";

$params = [];

if ($varDATE1 !== "" && $varDATE2 !== "") {
    $d1 = to_mysql_date(str_replace("%2F", "/", $varDATE1));
    $d2 = to_mysql_date(str_replace("%2F", "/", $varDATE2));
    if ($d1 && $d2) {
        $sql .= " AND AL1.TS_DATE BETWEEN ? AND ?";
        $params[] = $d1;
        $params[] = $d2;
    }
}

if (!empty($projectFilter)) {
    $sql .= " AND AL1.proj_id = ?";
    $params[] = $projectFilter;
}

$staffName = '';
if (!empty($staffFilter)) {
    $sql .= " AND AL1.Employee_id = ?";
    $params[] = $staffFilter;
    // Look up staff name
    $stmtUser = $pdo->prepare("SELECT Login FROM Staff WHERE Employee_id = ?");
    $stmtUser->execute([$staffFilter]);
    $userRow = $stmtUser->fetch(PDO::FETCH_ASSOC);
    if ($userRow) {
        $staffName = $userRow['Login'];
        echo "Staff Name = " . htmlspecialchars($staffName);
    }
}

$sql .= " ORDER BY AL1.TS_DATE;";

$stmtRS = $pdo->prepare($sql);
$stmtRS->execute($params);

// ── Auto-total the hours so Jen/Erik never add them up by hand ──────────
// One pass over the rows builds the grand total + per-staff + per-project
// subtotals. (Hours can be NULL on old rows — COALESCE to 0.)
$tsRows = $stmtRS->fetchAll(PDO::FETCH_ASSOC);
$hours_total = 0.0; $byStaff = []; $byProject = [];
foreach ($tsRows as $r) {
    $h = (float)($r['hours'] ?? 0);
    $hours_total += $h;
    $sName = (string)($r['Login'] ?? ('#' . (int)($r['Employee_id'] ?? 0)));
    $pName = (string)($r['JobName'] ?? '(no project)');
    $byStaff[$sName]   = ($byStaff[$sName]   ?? 0) + $h;
    $byProject[$pName] = ($byProject[$pName] ?? 0) + $h;
}
arsort($byStaff); arsort($byProject);
// Trim trailing zeros for display: 7.50 -> 7.5, 8.00 -> 8.
$ts_fmt = function ($n) { return rtrim(rtrim(number_format((float)$n, 2), '0'), '.'); };
?>
            </font>
            </p>

            <!-- Summary: hours by staff and by project (auto-totalled) -->
            <table border="0" cellpadding="8" align="center"><tr valign="top">
              <td>
                <table border="1" cellpadding="3" style="border-collapse:collapse;font-family:Arial;font-size:13px;">
                  <tr bgcolor="#EBEBEB"><td colspan="2"><b>Hours by staff</b></td></tr>
                  <?php foreach ($byStaff as $name => $h): ?>
                  <tr><td><?= htmlspecialchars($name) ?></td><td align="right"><?= $ts_fmt($h) ?></td></tr>
                  <?php endforeach; ?>
                  <tr bgcolor="#EBEBEB"><td><b>Total</b></td><td align="right"><b><?= $ts_fmt($hours_total) ?></b></td></tr>
                </table>
              </td>
              <td>
                <table border="1" cellpadding="3" style="border-collapse:collapse;font-family:Arial;font-size:13px;">
                  <tr bgcolor="#EBEBEB"><td colspan="2"><b>Hours by project</b></td></tr>
                  <?php foreach ($byProject as $name => $h): ?>
                  <tr><td><?= htmlspecialchars($name) ?></td><td align="right"><?= $ts_fmt($h) ?></td></tr>
                  <?php endforeach; ?>
                  <tr bgcolor="#EBEBEB"><td><b>Total</b></td><td align="right"><b><?= $ts_fmt($hours_total) ?></b></td></tr>
                </table>
              </td>
            </tr></table>
            <table border="1" width="95%" cellpadding="2">
              <tr>
                <td><p><font face="Arial" color='#000000'><b>Project</b></font></p></td>
                <td><p><font face="Arial" color='#000000'><b>Staff</b></font></p></td>
                <td><p><font face="Arial" color='#000000'><b>Task</b></font></p></td>
                <td><p><font face="Arial" color='#000000'><b>Day</b></font></p></td>
                <td><p><font face="Arial" color='#000000'><b>Date</b></font></p></td>
                <td><p><font face="Arial" color='#000000'><b>Hours</b></font></p></td>
              </tr>
              <?php
foreach ($tsRows as $row) {
    $tsDate   = $row['TS_DATE'];
    $dayName  = $tsDate ? date('D', strtotime($tsDate)) : '';            // e.g. Mon
    $dateFull = $tsDate ? date('l, d F Y', strtotime($tsDate)) : '';
    echo "<tr><td><font size=2 color='#000000'>" . htmlspecialchars((string)$row['JobName']) . "</td>";
    echo "<td><font size=2 color='#000000'>" . htmlspecialchars((string)($row['Login'] ?? '')) . "</td>";
    echo "<td><font size=2 color='#000000'>" . htmlspecialchars((string)$row['TASK']) . "</td>";
    echo "<td><font size=2 color='#000000'>" . htmlspecialchars($dayName) . "</td>";
    echo "<td><font size=2 color='#000000'>" . htmlspecialchars($dateFull) . "</td>";
    echo "<td align='right'><font size=2 color='#000000'>" . htmlspecialchars($ts_fmt($row['hours'] ?? 0)) . "</td></tr>";
}
?>
            </table>

            <table width="100%" border="0" cellspacing="0" cellpadding="0">
              <tr>
                <td align="right">
                  <font face="Arial">
                  &nbsp;
                  <?php echo "<font size=2 color='#000000'><b><br><br>The total hours = " . $hours_total . "</font></b>"; ?>
                  &nbsp; &nbsp; &nbsp;&nbsp; &nbsp; &nbsp; &nbsp;&nbsp;
                	</font>
                </td>
              </tr>
            </table>
            <div align="right"></div>
            <div align="right"></div>
            <p>&nbsp;</p>
         </td>
        </tr>
      </table>
    	<p align="left">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <font face="Arial">
		<a href="timesheet_admin1.php">back to report options</a>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
		<a href="main.php">back to timesheet</a></font></td>
  </tr>
</table>
</body>

</html>
