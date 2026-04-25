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
<meta http-equiv="Content-Language" content="en-nz">
<title>Invoicing </title>
<link href="global2.css" rel="stylesheet" type="text/css" />
<basefont face="arial">
<style type="text/css">
.style1 { background-color: #9B9B1B; }
</style>
</head>
<?php
if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}
?>
<body bgcolor="#EBEBEB" text="black">

    <table width="600" border="0" cellspacing="0" cellpadding="0" bgcolor="#EBEBEB" id="table8">
      <tr>
        <td>
          <table border="0" cellspacing="0" width="644" cellpadding="0" id="table9" class="style1">
            <tr>
              <td align="center" colspan="4" height="26">
                <p align="left">
              <b>&nbsp;<font size="3">&nbsp;</font><font color="#FFFFFF" size="3">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;INVOICE LIST</font></b></td>
              <td align="center" colspan="3" height="26">
                <a href="logout.php"><font color="#FFFFFF" size="2">logout</font></a></td>
            </tr>
            <tr>
              <td align="center"><a href="projects.php"><font color="#FFFFFF">My Projects</font></a></td>
              <td align="center"><a href="main.php" onMouseOver="window.status='Go to the input table'; return true" onMouseOut="window.status=''; return true"><font color="#FFFFFF">My Timesheet</font></a></td>
              <td align="center"><a onMouseOver="window.status='Click here to re-login'; return true" onMouseOut="window.status=''; return true" href="login.php"><font color="#FFFFFF">Login Again</font></a></td>
              <td align="center" colspan="2"><a href="report.php" onMouseOver="window.status='Run some basic reports'; return true" onMouseOut="window.status=''; return true"><font color="#FFFFFF">Reports</font></a></td>
              <td align="center">&nbsp;</td>
              <td align="center">&nbsp;</td>
            </tr>
            <tr>
            <td align="center">&nbsp;
<?php
if ($_SESSION['UserID'] == 'erik') {
    echo "<a href='invoice_archive.php'><font color='#FFFFFF' size='2'>Invoice Archive</font></a>";
}
if ($_SESSION['UserID'] == 'jen') {
    echo "<a href='invoice_archive.php'><font color='#FFFFFF' size='2'>Invoice Archive</font></a>";
}
?>
            </td>
              <td align="center">&nbsp;</td>
              <td align="center"></td>
              <td align="center">&nbsp;</td>
              <td align="center">&nbsp;
<?php
if ($_SESSION['UserID'] == 'erik') {
    echo "<a href='more.php'><font color='#FFFFFF' size='2'>More...</font></a>";
}
if ($_SESSION['UserID'] == 'jen') {
    echo "<a href='more.php'><font color='#FFFFFF' size='2'>More...</font></a>";
}
?>
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
$pdo = get_db();
$grand = 0;

$proj_id = (int)$_POST['proj_id'];

$sql = "SELECT * FROM Invoices LEFT OUTER JOIN Clients on Invoices.Client_ID = Clients.Client_ID LEFT OUTER JOIN Projects ON Invoices.Proj_ID = Projects.Proj_ID WHERE Invoices.proj_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$proj_id]);

while ($rs = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "<br>";
    echo "<a href=\"invoice.php?invoice_no=" . htmlspecialchars($rs['invoice_no']) . "\">";
    echo htmlspecialchars($rs['invoice_no']);
    echo "</a>";
    echo "&nbsp;-&nbsp;";
    echo htmlspecialchars(date('d/m/Y', strtotime($rs['Date'])));
    echo "&nbsp;-&nbsp;";
    if (is_null($rs['Subtotal'])) {
        echo "*unprocessed*";
    } else {
        $tot = '$' . number_format((float)$rs['Subtotal'] + ((float)$rs['Subtotal'] * (float)$rs['Tax_Rate']), 2);
        echo $tot;
        $grand += (float)$rs['Subtotal'] + ((float)$rs['Subtotal'] * (float)$rs['Tax_Rate']);
    }
    echo "&nbsp;-&nbsp;";
    echo htmlspecialchars($rs['Client_name']);
    echo "&nbsp;-&nbsp;";
    echo htmlspecialchars($rs['JOBNAME']);
    echo "&nbsp;-&nbsp;";
    if ($rs['PAID'] == 0) {
        echo "<strong>*UNPAID*</strong>";
    } else {
        echo "Paid";
    }
}
echo "<br><strong>&nbsp;&nbsp;TOTAL INVOICED......";
echo '$' . number_format($grand, 2);
echo "</strong>";
?>

</body>
</html>
