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
<meta http-equiv="Content-Language" content="en-nz">
<title>Invoicing</title>
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
            <p align="left"><b>&nbsp;<font size="3">&nbsp;</font><font color="#FFFFFF" size="3">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;INVOICING FOR LAST 12 MONTHS</font></b></td>
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
// Last 12 months of invoices grouped by year/month
$sql = "SELECT YEAR(`date`) AS yr, MONTH(`date`) AS mnth, SUM(subtotal) AS subtotal
        FROM Invoices
        WHERE `date` > DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY YEAR(`date`), MONTH(`date`)
        ORDER BY YEAR(`date`), MONTH(`date`)";

$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$grand = 0.0;
foreach ($rows as $row) {
    $tot    = (float) $row['subtotal'];
    $grand += $tot;
    echo '<br>';
    echo htmlspecialchars($row['yr']) . '/' . htmlspecialchars($row['mnth']);
    echo '&nbsp;=&nbsp;';
    echo '$' . number_format($tot, 2);
}
echo '<br><strong>GRAND TOTAL [excl]......$' . number_format($grand, 2) . '</strong>';
?>

</body>
</html>
