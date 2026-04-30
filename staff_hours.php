<?php
require_once 'auth_check.php';
require_once 'db_connect.php';

$userID = $_SESSION['UserID'];

// Only allow access for authorised users (mirrors original ASP logic)
$allowed = ['erik', 'jen'];
if (!in_array($userID, $allowed)) {
    echo "<p>Access denied.</p>";
    exit;
}

$pdo = get_db();
?>
<script language="Javascript">
    document.onkeydown = function() {
        if (event.keyCode == 13) {
            window.location = "more.php"
        }
    }
</script>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="Content-Language" content="en-nz">
<title>Uninvoiced Staff Hours</title>
<link href="site.css" rel="stylesheet">
<link href="global2.css" rel="stylesheet" type="text/css" />
<basefont face="arial">
<style type="text/css">
.style1 { background-color: #9B9B1B; }
</style>
</head>
<body bgcolor="#EBEBEB" text="black">

<table width="600" border="0" cellspacing="0" cellpadding="0" bgcolor="#EBEBEB" id="table8">
  <tr>
    <td>
      <table border="0" cellspacing="0" width="644" cellpadding="0" id="table9" class="style1">
        <tr>
          <td align="center" colspan="4" height="26">
            <p align="left"><b>&nbsp;<font size="3">&nbsp;</font><font color="#FFFFFF" size="3">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;UNINVOICED STAFF HOURS</font></b></td>
          <td align="center" colspan="3" height="26">
            <a href="logout.php"><font color="#FFFFFF" size="2">logout</font></a></td>
        </tr>
        <tr>
          <td align="center"><a href="projects.php"><font color="#FFFFFF">My Projects</font></a></td>
          <td align="center"><a href="main.php"><font color="#FFFFFF">My Timesheet</font></a></td>
          <td align="center"><a href="login.php"><font color="#FFFFFF">Login Again</font></a></td>
          <td align="center" colspan="2"><a href="report.php"><font color="#FFFFFF">Reports</font></a></td>
          <td align="center">&nbsp;</td>
          <td align="center">&nbsp;</td>
        </tr>
        <tr>
          <td align="center">&nbsp;</td>
          <td align="center">&nbsp;</td>
          <td align="center"></td>
          <td align="center">&nbsp;</td>
          <td align="center">&nbsp;
            <?php if ($userID === 'erik' || $userID === 'jen'): ?>
              <a href='more.php'><font color='#FFFFFF' size='2'>More...</font></a>
            <?php endif; ?>
          </td>
          <td align="center">&nbsp;</td>
          <td align="center">&nbsp;</td>
          <td align="center">&nbsp;</td>
        </tr>
      </table>
    </td>
  </tr>
</table>
<p></p>

<?php
try {
    // Uninvoiced hours per staff member, all time
    $sql = "SELECT s.Employee_ID, s.Login, SUM(t.Hours) AS tothours
              FROM Timesheets t
              LEFT JOIN Staff s ON t.Employee_id = s.Employee_ID
             WHERE t.Invoice_No = 0
             GROUP BY s.Employee_ID, s.Login
             ORDER BY tothours DESC";
    $stmt  = $pdo->query($sql);
    $rows  = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $grand = 0.0;

    if (count($rows) === 0) {
        echo '<p style="color:#888">No uninvoiced timesheet entries found.</p>';
    } else {
        echo '<table cellpadding="4" cellspacing="0" border="0" style="margin:10px">';
        foreach ($rows as $row) {
            $h = (float)($row['tothours'] ?? 0);
            $grand += $h;
            echo '<tr><td>' . htmlspecialchars((string)($row['Login'] ?? '(unknown)')) . '</td>';
            echo '<td align="right" style="padding-left:20px"><b>' . number_format($h, 2) . '</b> hours</td></tr>';
        }
        echo '<tr><td colspan="2"><hr></td></tr>';
        echo '<tr><td><b>TOTAL</b></td>';
        echo '<td align="right" style="padding-left:20px"><b>' . number_format($grand, 2) . '</b> hours</td></tr>';
        echo '</table>';
    }
} catch (Exception $e) {
    echo '<p style="color:red">DB Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
?>

</body>
</html>
