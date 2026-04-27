<?php
session_start();
require_once 'db_connect.php';
require_once 'helpers.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}
?>
<html>
<head>
<STYLE TYPE="text/css">
<!--
	.style1 {
	background-color: #9B9B1B;
}
	-->
</STYLE>
<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1">
<title>CADViz Timesheet</title>
<link rel="stylesheet" href="global.css" type="text/css">
</head>
<body bgcolor=#DFEFEF>
<?php
// If accessed directly without form POST, redirect to main.php
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['hidden_week'])) {
    header('Location: main.php');
    exit;
}

$pdo = get_db();

$error = "successful";
$locked = "false";

// get proper format for start and end dates
$weekStart = $_POST['hidden_week'];
$weekEnd = date('Y-m-d', strtotime("+6 days", strtotime($weekStart)));

// MySQL ISO date strings for DELETE query
$weekStartISO = date('Y-m-d', strtotime($weekStart));
$weekEndISO   = date('Y-m-d', strtotime($weekEnd));

$tday = date('Y-m-d');
$lockbackdate = date('Y-m-d', strtotime("-2 months", strtotime($tday)));
$editperiod = $_POST['hidden_week'];

// DateDiff "m" equivalent: negative means editperiod is before lockbackdate
$lockMonths = ((int)date('Y', strtotime($editperiod)) - (int)date('Y', strtotime($lockbackdate))) * 12
            + ((int)date('n', strtotime($editperiod)) - (int)date('n', strtotime($lockbackdate)));

if ($lockMonths < 0) {
    $locked = "true";
    $error = "<font color=red><b>FAILED</b></font>";
}

$totaltime = 0;
$errorShown = false;

if ($locked === "true") {
    echo "<br><br><font color=red size=2 face=tahoma><b>You are attempting to modify your timesheet in an unauthorised period!  You may only modify your timesheet for the last couple months.<br><br></font></b>";
} else {
    // Wrap delete + all inserts in a single transaction — much faster than
    // 30 individual auto-commits, and atomic if anything fails.
    $pdo->beginTransaction();
    try {
        $delStmt = $pdo->prepare("DELETE FROM Timesheets WHERE TS_DATE BETWEEN ? AND ? AND Employee_id = ? AND Invoice_No = 0");
        $delStmt->execute([$weekStartISO, $weekEndISO, (int)$_SESSION['Employee_id']]);

        // Compute next TS_ID once. If the column is now AUTO_INCREMENT this is
        // still safe — MySQL will use whichever is higher.
        $nextStmt = $pdo->query("SELECT COALESCE(MAX(TS_ID), 0) + 1 AS nxt FROM Timesheets");
        $nextTsId = (int)$nextStmt->fetch(PDO::FETCH_ASSOC)['nxt'];

        $insStmt = $pdo->prepare("INSERT INTO Timesheets (TS_ID, TS_DATE, Employee_id, proj_id, Task, Hours, Invoice_No) VALUES (?, ?, ?, ?, ?, ?, 0)");

        for ($a = 1; $a <= 40; $a++) {
            if (!isset($_POST['Project' . $a])
                || $_POST['Project' . $a] === ""
                || ($_POST['Invoice_No' . $a] ?? '') !== "0") {
                continue;
            }
            for ($b = 1; $b <= 7; $b++) {
                $dayKey = "D" . $b . "_" . $a;
                if (!isset($_POST[$dayKey]) || $_POST[$dayKey] === "") continue;

                $hours = (float)$_POST[$dayKey];
                $totaltime += $hours;

                $descKey = "Desc" . $a;
                if (!isset($_POST[$descKey]) || $_POST[$descKey] === "") {
                    $descKey = "desc" . $a;
                }
                if (!isset($_POST[$descKey]) || $_POST[$descKey] === "") {
                    if (!$errorShown) {
                        echo "<font face=tahoma size=2 color=red><br><hr>There was an error in your submission.  Hit the back button and correct your data.<br>Remember you MUST fill in a description.<br><hr></font>";
                        $errorShown = true;
                    }
                    $error = "not cool";
                    continue;
                }

                $tsDate = date('Y-m-d', strtotime("+" . ($b - 1) . " days", strtotime($weekStart)));
                $projId = (int)$_POST['Project' . $a];
                $desc   = $_POST[$descKey];
                $empId  = (int)$_SESSION['Employee_id'];

                $insStmt->execute([$nextTsId, $tsDate, $empId, $projId, $desc, $hours]);
                $nextTsId++;
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "<font color=red><b>FAILED: " . htmlspecialchars($e->getMessage()) . "</b></font>";
    }
}
?>
<?php if ($locked === "false" || $error === "successful"): ?>
<p>&nbsp;</p><p>&nbsp;</p>
<?php endif; ?>
          <table align="center" border="0" cellspacing="0" width="90%" cellpadding="0" id="table1" class="style1">
            <tr>
              <td align="center" colspan="7">
                <h1><b>&nbsp;<br>
                  <font color="#FFFFFF">CADViz Timesheet Data for <?= htmlspecialchars($_SESSION['UserID']) ?></font></b>
                </h1>
              </td>
            </tr>
            <tr>
              <td align="center"><a href="http://www.cadviz.co.nz" onMouseOver="window.status='www.cadviz.co.nz'; return true" onMouseOut="window.status=''; return true">
				<font color="#FFFFFF">CADViz Website</font></a>
				<td align="center"><a href="mailto:mail.cadviz.co.nz"><font color="#FFFFFF">Mail Queries</font></a></td>
              <td align="center"><a href="main.php" onMouseOver="window.status='Go to the input table'; return true" onMouseOut="window.status=''; return true"><font color="#FFFFFF">Main Timesheet
                Screen</font></a></td>
              <td align="center">
				<a onMouseOver="window.status='Click here to re-login'; return true" onMouseOut="window.status=''; return true" href="login.php"><font color="#FFFFFF">Login Again</font></a></td>
              <td align="center"><a href="report.php" onMouseOver="window.status='Run some basic reports'; return true" onMouseOut="window.status=''; return true"><font color="#FFFFFF">Reports</font></a></td>
              <td align="center">&nbsp;</td>
              <td align="center">&nbsp;</td>
            </tr>
            <tr>
              <td align="center">&nbsp;</td>
              <td align="center">&nbsp;</td>
              <td align="center">&nbsp;</td>
              <td align="center">&nbsp;</td>
              <td align="center">&nbsp;</td>
              <td align="center">&nbsp;</td>
              <td align="center">&nbsp;</td>
            </tr>
          </table>
<center>
  <table width="90%" border="0" cellspacing="0" cellpadding="0" bgcolor="#EBEBEB">
    <tr>
      <td>
        <div align="center">
          <p>&nbsp;</p>
          <p><font color="#515559">Submit <?= $error ?> for </font><b>
			<font color="#515559"><?= htmlspecialchars($_SESSION['UserID']) ?></font></b><font color="#515559">, Week Beginning
			</font><b> <font color="#515559"><?= date('l, d F Y', strtotime($weekStart)) ?></font></b><font color="#515559"><br>
            <br>
            Total Hours Submitted (exluding locked/invoiced rows):</font><b><font color="#515559"><?= $totaltime ?></font></b><font color="#515559"> <br>
            <br>
            Retrieve Again?</font>
            <?php retrieve_part(date('Y-m-d', strtotime("+7 days", strtotime($weekStart)))); ?>
          </p>
          </div>
      </td>
    </tr>
  </table>
  <p>&nbsp;</p>
</center></body>
</html>
<?php
// do the retrieve part: week dd box and retrieve button
function retrieve_part($currentWeek) {
?>
<form action="main.php" name="retrieve_form" method="POST">
  <p><b>Week starting:</b>
    <SELECT name="week">
      <?php
    $curdate = date('Y-m-d');
    $wd = (int)date('N', strtotime($curdate)); // 1=Mon, 7=Sun
    $startdate = date('Y-m-d', strtotime("-" . ($wd - 1) . " days", strtotime($curdate)));
    for ($a = -4; $a <= 2; $a++) {
        $curdate = date('Y-m-d', strtotime("+" . (7 * $a) . " days", strtotime($startdate)));
        $oput = date('l, d F Y', strtotime($curdate));
        if ($currentWeek === $curdate) {
            echo "<OPTION SELECTED VALUE=\"$curdate\">$oput";
        } else {
            echo "<OPTION VALUE=\"$curdate\">$oput";
        }
    }
?>
    </SELECT>
    <input type="submit" name="Retrieve" value="Retrieve">
  </p>
</form>
<div align="left">
  <p><br>
  </p>
</div>
<?php
} // end retrieve_part
?>
