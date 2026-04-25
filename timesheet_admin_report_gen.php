<?php
session_start();
require_once 'db_connect.php';
require_once 'helpers.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
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
$sql = "SELECT AL1.TS_DATE, AL1.TASK, AL4.JobName, AL1.hours FROM Timesheets AL1, Projects AL4 WHERE AL4.proj_id=AL1.proj_id";

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
?>
            </font>
            </p>
            <table border="1" width="95%" cellpadding="2">
              <tr>
                <td><p><font face="Arial" color='#000000'><b>Project</b></font></p></td>
                <td><p><font face="Arial" color='#000000'><b>Task</b></font></p></td>
                <td><p><font face="Arial" color='#000000'><b>Day</b></font></p></td>
                <td><p><font face="Arial" color='#000000'><b>Date</b></font></p></td>
                <td><p><font face="Arial" color='#000000'><b>Hours</b></font></p></td>
              </tr>
              <?php
$hours_total = 0;
while ($row = $stmtRS->fetch(PDO::FETCH_ASSOC)) {
    $tsDate = $row['TS_DATE'];
    $dayName = date('D', strtotime($tsDate)); // short weekday name e.g. Mon
    echo "<tr><td><font size=2 color='#000000'>" . htmlspecialchars($row['JobName']) . "</td>";
    echo "<td><font size=2 color='#000000'>" . htmlspecialchars($row['TASK']) . "</td>";
    echo "<td><font size=2 color='#000000'>" . htmlspecialchars($dayName) . "</td>";
    echo "<td><font size=2 color='#000000'>" . htmlspecialchars(date('l, d F Y', strtotime($tsDate))) . "</td>";
    echo "<td><font size=2 color='#000000'>" . htmlspecialchars($row['hours']) . "</td></tr>";
    $hours_total += $row['hours'];
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
