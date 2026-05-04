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
<title>Invoicing </title>
<link href="site.css" rel="stylesheet">
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
if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403);
    echo '<p>Admin only. <a href="menu.php">Back to menu</a></p>';
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
try {
$pdo = get_db();
$grand = 0;

$client_id = (int)($_POST['Client_ID'] ?? $_POST['Client_box'] ?? $_GET['client_id'] ?? 0);

$sql = "SELECT Invoices.Invoice_No  AS Invoice_No,
               Invoices.Date         AS InvDate,
               Invoices.Subtotal     AS Subtotal,
               Invoices.Tax_Rate     AS Tax_Rate,
               Invoices.Paid         AS Paid,
               Clients.Client_Name   AS Client_Name,
               Projects.JobName      AS JobName,
               Projects.proj_id      AS Proj_ID
          FROM Invoices
          LEFT OUTER JOIN Clients  ON Invoices.Client_ID = Clients.Client_id
          LEFT OUTER JOIN Projects ON Invoices.Proj_ID   = Projects.proj_id
         WHERE Invoices.Client_ID = ?
         ORDER BY Invoices.Invoice_No";
$stmt = $pdo->prepare($sql);
$stmt->execute([$client_id]);

$count = 0;
while ($rs = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $count++;
    echo "<br>";
    echo "<a href=\"invoice.php?Invoice_No=" . htmlspecialchars((string)$rs['Invoice_No']) . "\">";
    echo htmlspecialchars((string)$rs['Invoice_No']);
    echo "</a>";
    echo "&nbsp;-&nbsp;";
    echo htmlspecialchars($rs['InvDate'] ? date('d/m/Y', strtotime($rs['InvDate'])) : '');
    echo "&nbsp;-&nbsp;";
    if (is_null($rs['Subtotal'])) {
        echo "*unprocessed*";
    } else {
        $line = (float)$rs['Subtotal'] + ((float)$rs['Subtotal'] * (float)$rs['Tax_Rate']);
        echo '$' . number_format($line, 2);
        $grand += $line;
    }
    echo "&nbsp;-&nbsp;";
    echo htmlspecialchars((string)($rs['Client_Name'] ?? ''));
    echo "&nbsp;-&nbsp;";
    echo htmlspecialchars((string)($rs['JobName'] ?? ''));
    echo "&nbsp;-&nbsp;";
    if ((int)$rs['Paid'] === 0) {
        echo "<strong>*UNPAID*</strong>";
    } else {
        echo "<span style='color:green'>Paid</span>";
    }
}
if ($count === 0) {
    echo '<p style="color:#888">No invoices found for this client.</p>';
}
echo "<br><strong>&nbsp;&nbsp;TOTAL INVOICED......";
echo '$' . number_format($grand, 2);
echo "</strong>";
} catch (Exception $e) {
    echo '<p style="color:red">DB Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
?>

</body>
</html>
