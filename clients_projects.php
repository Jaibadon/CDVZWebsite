<?php
session_start();
require_once 'db_connect.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}

$client_id = $_GET['client_id'] ?? '';
?>
<html>

<head>
<meta http-equiv="Content-Type" content="text/html; charset=windows-1252">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="Content-Language" content="en-nz">
<link href="site.css" rel="stylesheet">
<link href="global.css" rel="stylesheet" type="text/css" />
<title>Projects for Client= <?= htmlspecialchars($client_id) ?> </title>
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
                <b>&nbsp;<font size="3">&nbsp;</font><font color="#FFFFFF" size="3">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; Active Projects for Client_ID = <?= htmlspecialchars($client_id) ?> </font></b></td>
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
              <?php
if ($_SESSION['UserID'] == "erik") {
    echo "<a href='projects.php'><font color='#FFFFFF' size='2'>Projects Admin</font></a>";
}
if ($_SESSION['UserID'] == "jen") {
    echo "<a href='projects.php'><font color='#FFFFFF' size='2'>Projects Admin</font></a>";
}
?>
              </td>
              <td align="center">&nbsp;<?php
if ($_SESSION['UserID'] == "erik") {
    echo "<a href='clients.php'><font color='#FFFFFF' size='2'>Client List</font></a>";
}
if ($_SESSION['UserID'] == "jen") {
    echo "<a href='clients.php'><font color='#FFFFFF' size='2'>Client List</font></a>";
}
?></td>
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
$stmt = $pdo->prepare("SELECT * FROM Projects WHERE ACTIVE <> 0 AND Client_id = ? ORDER BY JOBNAME");
$stmt->execute([$client_id]);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "<br>";
    echo "<a href=\"updateform1.php?proj_id=" . htmlspecialchars((string)($row['proj_id'] ?? '')) . "\">";
    echo htmlspecialchars((string)($row['JobName'] ?? $row['JOBNAME'] ?? ''));
    echo "</a>";
    echo "&nbsp;&nbsp;";

    $finalDate = $row['Final_Date'] ?? $row['FINAL_DATE'] ?? $row['final_date'] ?? null;
    $od = 0;
    if (!empty($finalDate)) {
        $dd = (int)((strtotime(date('Y-m-d')) - strtotime($finalDate)) / 86400);
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

    echo "</font>";
    echo "&nbsp;&nbsp;";
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
    echo "<br>";
}
?>


</body>

</html>
