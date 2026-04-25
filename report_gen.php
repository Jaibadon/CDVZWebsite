<?php
require_once 'auth_check.php';
require_once 'db_connect.php';
require_once 'helpers.php';

$pdo = get_db();

$userID      = $_SESSION['UserID'];
$employee_id = $_SESSION['Employee_id'] ?? 0;

$startDate = trim($_POST['StartDate'] ?? '');
$endDate   = trim($_POST['EndDate']   ?? '');
$project   = trim($_POST['Project']   ?? '');
?>
<html>
<head>
<META HTTP-EQUIV="Window-target" CONTENT="_top">
<title>Timesheet Report</title>
<link rel="stylesheet" href="global.css" type="text/css">
</head>
<body>
<center></center>
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
              <h1><font face="Arial"><b>Timesheet Report</b></font></h1>
            </center>
            <hr noshade size="1">
            <p><font face="Arial" color="#000000"><br>
              Timesheet Report for: <b><?php echo htmlspecialchars($userID); ?></b><br>
              <br>
              Date Range:
              <?php
              echo htmlspecialchars($startDate) . '<font color="blue"> to </font>' . htmlspecialchars($endDate);
              ?>
              <br><br>
              Project:
              <?php echo ($project !== '') ? htmlspecialchars($project) : 'not limited'; ?>
              <br><br>
            </font></p>
<?php
// Build query with PDO prepared statements
$params = [(int) $employee_id];

$sql = "SELECT AL1.TS_DATE, AL1.TASK, AL4.JobName, AL1.hours
        FROM Timesheets AL1
        JOIN Projects AL4 ON AL4.proj_id = AL1.proj_id
        WHERE AL1.Employee_id = ?";

if ($startDate !== '' && $endDate !== '') {
    $d1 = to_mysql_date($startDate);
    $d2 = to_mysql_date($endDate);
    if ($d1 && $d2) {
        $sql    .= " AND AL1.TS_DATE BETWEEN ? AND ?";
        $params[] = $d1;
        $params[] = $d2;
    }
}

if ($project !== '') {
    $sql    .= " AND AL1.proj_id = ?";
    $params[] = (int) $project;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
            <table border="1" width="95%" cellpadding="2">
              <tr>
                <td><p><font face="Arial" color="#000000"><b>Project</b></font></p></td>
                <td><p><font face="Arial" color="#000000"><b>Task</b></font></p></td>
                <td><p><font face="Arial" color="#000000"><b>Date</b></font></p></td>
                <td><p><font face="Arial" color="#000000"><b>Hours</b></font></p></td>
              </tr>
<?php
$hours_total = 0.0;
foreach ($results as $row) {
    $ts_date = !empty($row['TS_DATE']) ? date('l, d F Y', strtotime($row['TS_DATE'])) : '';
    echo "<tr>";
    echo "<td><font size='2' color='#000000'>" . htmlspecialchars($row['JobName'] ?? '') . "</font></td>";
    echo "<td><font size='2' color='#000000'>" . htmlspecialchars($row['TASK']    ?? '') . "</font></td>";
    echo "<td><font size='2' color='#000000'>" . htmlspecialchars($ts_date)               . "</font></td>";
    echo "<td><font size='2' color='#000000'>" . htmlspecialchars($row['hours']   ?? '') . "</font></td>";
    echo "</tr>";
    $hours_total += (float)($row['hours'] ?? 0);
}
?>
            </table>

            <table width="100%" border="0" cellspacing="0" cellpadding="0">
              <tr>
                <td align="right">
                  <font face="Arial">
                  &nbsp;
                  <font size="2" color="#000000"><b><br><br>The total hours = <?php echo $hours_total; ?></b></font>
                  &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
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
      <p align="left">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
        <font face="Arial">
          <a href="report.php">back to report options</a>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
          <a href="main.php">back to timesheet</a>
        </font>
      </p>
    </td>
  </tr>
</table>
</body>
</html>
