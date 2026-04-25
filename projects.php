<?php
session_start();
require_once 'db_connect.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}
?>
<html>

<head>
<meta http-equiv="Content-Type" content="text/html; charset=windows-1252">
<meta http-equiv="Content-Language" content="en-nz">
<link href="global2.css" rel="stylesheet" type="text/css" />
<title>Active Projects for <?= htmlspecialchars($_SESSION['UserID']) ?> </title>
<basefont face="arial">
<style type="text/css">
.style1 {
	background-color: #9B9B1B;
}
</style>
</head>
<body bgcolor="#EBEBEB" text="black">

    <table width="600" border="0" cellspacing="0" cellpadding="0" bgcolor="#EBEBEB" id="table8">
      <tr>
        <td>
          <table border="0" cellspacing="0" width="644" cellpadding="0" id="table9" class="style1">
            <tr>
              <td align="center" colspan="4" height="26">
                <p align="left">
              <b>&nbsp;<font size="3">&nbsp;</font><font color="#FFFFFF" size="3">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; Project List</font></b></td>
              <td align="center" colspan="3" height="26">
                <a href="logout.php"><font color="#FFFFFF" size="2">logout</font></a></td>
            </tr>
            <tr>
              <td align="center"><a href="projects.php"><font color="#FFFFFF">My Projects</font></a></td>
              <td align="center">
				<a href="main.php"
	onMouseOver="window.status='Go to the input table'; return true",
	onMouseOut="window.status=''; return true"><font color="#FFFFFF">My Timesheet</font></a></td>
              <td align="center">
				<a onMouseOver="window.status='Click here to re-login'; return true" , onMouseOut="window.status=''; return true" href="login.php"><font color="#FFFFFF">Login Again</font></a></td>
              <td align="center" colspan="2"><a href="report.php"
	onMouseOver="window.status='Run some basic reports'; return true",
	onMouseOut="window.status=''; return true"><font color="#FFFFFF">Reports</font></a></td>
              <td align="center">&nbsp;</td>
              <td align="center">&nbsp;</td>
            </tr>
            <tr>
            <td align="center">&nbsp;
			<a href='projects_archive1.php'><font color='#FFFFFF' size='2'>Projects Archive</font></a>
              </td>
              <td align="center">&nbsp;
</td>
              <td align="center">&nbsp;</td>
              <td align="center">&nbsp;</td>
              <td align="center" colspan="2"><?php
if ($_SESSION['UserID'] == "erik") {
    echo "<a href='more.php'><font color='#FFFFFF' size='2'>More...</font></a>";
}
if ($_SESSION['UserID'] == "jen") {
    echo "<a href='more.php'><font color='#FFFFFF' size='2'>More...</font></a>";
}
?></td>
              <td align="center">&nbsp;</td>
              <td align="center">&nbsp;</td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
<p></p>

<?php
$pdo = get_db();

if ($_SESSION['UserID'] == "erik") {
    $strSQL = "SELECT * FROM Projects WHERE ACTIVE <> 0 AND Client_ID <> 195 ORDER BY JOBNAME";
    $stmt = $pdo->query($strSQL);
} else {
    $emp_id = (int)$_SESSION['Employee_id'];
    $stmt = $pdo->prepare("SELECT * FROM Projects WHERE (ACTIVE <> 0 AND Client_ID <> 195) AND (MANAGER = ? OR DP1 = ? OR DP2 = ? OR DP3 = ?) ORDER BY JOBNAME");
    $stmt->execute([$emp_id, $emp_id, $emp_id, $emp_id]);
}

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "<br>";
    echo "<a href=\"updateform_admin1.php?proj_id=" . htmlspecialchars($row['proj_id']) . "\">";
    echo htmlspecialchars($row['JOBNAME']);
    echo "</a>";
    echo "&nbsp;&nbsp;&nbsp;- &nbsp;";
    echo "&nbsp;&nbsp;&nbsp;Priority:&nbsp;";

    $od = 0;
    if (!empty($row['final_date'])) {
        $dd = (int)((strtotime(date('Y-m-d')) - strtotime($row['final_date'])) / 86400);
        if ($dd > 7) {
            $od = 3;
        } elseif ($dd > 0) {
            $od = 2;
        } elseif ($dd > -2) {
            $od = 1;
        } else {
            $od = 0;
        }
    }

    $priority = $row['Initial_Priority'] ?? '';

    if (strpos($priority, 'Hold') !== false) {
        echo "<font size=3 color=Silver>";
        echo "Normal - On Hold";
    } else {
        if (strpos($priority, 'Normal') !== false) {
            switch ($od) {
                case 0: echo "<font size=3 color=black>"; echo "Normal"; break;
                case 1: echo "<font size=3 color=orange>"; echo "Normal - Almost Due"; break;
                case 2: echo "<font size=3 color=orange>"; echo "Normal - Overdue"; break;
                case 3: echo "<font size=3 color=Red>"; echo "Urgent - Overdue"; break;
            }
        } elseif (strpos($priority, 'High') !== false) {
            echo "<font size=3 color=Red>";
            switch ($od) {
                case 0: echo "High"; break;
                case 1: echo "High - Almost Due"; break;
                case 2: echo "Critical - Overdue"; break;
                case 3: echo "Critical - Overdue"; break;
            }
        } else {
            echo "<font size=3 color=black>";
            echo htmlspecialchars($priority);
        }
    }

    echo ",</font>&nbsp;";

    // Hours used
    $tsStmt = $pdo->prepare("SELECT SUM(HOURS) AS tot FROM Timesheets WHERE proj_id = ?");
    $tsStmt->execute([$row['proj_id']]);
    $tsRow = $tsStmt->fetch(PDO::FETCH_ASSOC);
    $tot = $tsRow['tot'];

    $strSQL2 = "SELECT SUM(Estimated_Time * Project_Tasks.Weight) AS EST_HOURS FROM Tasks_Types RIGHT JOIN (Project_Tasks RIGHT JOIN (Project_Stages RIGHT JOIN Projects ON Project_Stages.Proj_ID = Projects.proj_id) ON Project_Tasks.Project_Stage_ID = Project_Stages.Project_Stage_ID) ON Tasks_Types.Task_ID = Project_Tasks.Task_Type_ID WHERE Projects.proj_id=" . (int)$row['proj_id'];
    $rs2Stmt = $pdo->query($strSQL2);
    $rs2Row = $rs2Stmt->fetch(PDO::FETCH_ASSOC);
    $estHours = $rs2Row['EST_HOURS'];

    if (($tot + 8) > $estHours) {
        echo "<font size=4 color=Red>***";
    } else {
        echo "<font size=3 color=Green>";
    }
    echo $tot;
    echo "&nbsp;Hours used out of&nbsp;";
    echo $estHours;
    echo "&nbsp;allowed,";
    echo "</font>";
    echo "&nbsp;&nbsp;&nbsp;Current Status:&nbsp;";
    echo htmlspecialchars($row['Status'] ?? '');
    echo "&nbsp;&nbsp;";
    if (!empty($row['Draft_Date'])) {
        echo "Draft: ";
        echo htmlspecialchars($row['Draft_Date']);
        echo "&nbsp;&nbsp;";
    }
    if (!empty($row['Final_Date'])) {
        echo "Final: ";
        echo htmlspecialchars($row['Final_Date']);
        echo "&nbsp;&nbsp;";
    }
    echo "<a href=\"jobdone.php?proj_id=" . htmlspecialchars($row['proj_id']) . "\">Click to set Job Done!</a>";
    echo "<br>";
}
?>


</body>

</html>
