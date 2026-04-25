<?php
session_start();
require_once 'db_connect.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}

$pdo = get_db();

$invoice_no = (int)($_GET['Invoice_No'] ?? $_GET['invoice_no'] ?? 0);
if ($invoice_no <= 0) {
    echo "<p>Missing invoice number. <a href=\"invoice_list.php\">Back</a></p>";
    exit;
}

$sql = "SELECT * FROM Invoices LEFT OUTER JOIN Clients on Invoices.Client_ID = Clients.Client_ID LEFT OUTER JOIN Projects ON Invoices.Proj_ID = Projects.Proj_ID WHERE Invoice_No = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$invoice_no]);
$rs = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rs) {
    echo "<p>Invoice $invoice_no not found.</p>";
    exit;
}

$sql2 = "SELECT *, (Rate * Hours) AS amt FROM Timesheets LEFT OUTER JOIN Staff ON Timesheets.Employee_id = Staff.Employee_ID WHERE Invoice_No = ?";
$stmt2 = $pdo->prepare($sql2);
$stmt2->execute([$invoice_no]);
$timesheets = $stmt2->fetchAll(PDO::FETCH_ASSOC);

$Billto = $rs['Billing_Email'] ?? '-';
if ($Billto === '') $Billto = '-';

$subtot = 0;
foreach ($timesheets as $ts) {
    $subtot += (float)$ts['amt'];
}
?>
<script language="Javascript">
	document.onkeydown = function() {
		if (event.keyCode == 13) {
			window.location="invoice_list.php"
		}
	}
</script>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=windows-1252">
<meta http-equiv="Content-Language" content="en-nz">
<!-- style sheet -->
<link href="invoice.css" rel="stylesheet" type="text/css" />
<title>CADviz_INV</title>
<basefont face="arial" size="2">
<style type="text/css">
.style1 { text-align: left; }
.style2 { text-align: right; }
.style3 {
    background-color: #FFFFFF;
    font:Arial, Gadget, sans-serif;
    font-weight:bold;
    font-size:30px;
    color:#000000;
}
.nrml {
    font-family: Verdana, Geneva, sans-serif;
    font-size:9px;
}
</style>
</head>
<body bgcolor="#FFFFFF" text="black">

<table width="653" border="0">
      <tr class="style3">
        <td width="207" rowspan="5" valign="top"><img src="cadviz_logo_bw1.gif" width="200" height="89" alt="CADVIZ"></td>
        <td width="274" rowspan="5" class="style3"><div align="center">CREDIT NOTICE</div></td>
        <td width="158"><p align="right" class="nrml">&nbsp;</p></td>
      </tr>
      <tr class="style3">
        <td><div align="right"><strong><span class="nrml">GST No: 82-090-630</span></strong></div></td>
      </tr>
      <tr class="style3">
        <td><div align="right"><div align="right"><span class="nrml">CADViz Ltd </span></div></div></td>
      </tr>
      <tr class="style3">
        <td><div align="right" class="nrml">
          <div align="right">PO Box 302387<br>North Harbour<br>Auckland 0751</div>
        </div></td>
      </tr>
      <tr class="style3">
        <td><div align="right"></div></td>
      </tr>
</table>
<form method="POST" name="project" action="mailto:<?= htmlspecialchars($Billto) ?>">
  <table width="651" border="0">
    <tr>
      <td width="100">Client:</td>
      <td width="293"><?= htmlspecialchars($rs['CLIENT_NAME']) ?></td>
      <td width="71">&nbsp;</td>
      <td width="69">Credit No:&nbsp;</td>
      <td width="96"><div align="right">
<?php
echo '<a href="invoice_edit.php?invoice_no=' . htmlspecialchars($rs['invoice_no']) . '">';
echo htmlspecialchars($rs['Invoice_No']);
echo '</a>';
?>
      </div></td>
    </tr>
    <tr>
      <td height="38" valign="top">Address:<br>Email:</td>
      <td valign="top"><?= htmlspecialchars($rs['ADDRESS1']) ?><br>
       <a href="mailto:<?= htmlspecialchars($Billto) ?>"><?= htmlspecialchars($Billto) ?></a></td>
      <td>&nbsp;</td>
      <td valign="top">Date:&nbsp;</td>
      <td valign="top"><div align="right"><?= htmlspecialchars(date('d/m/Y', strtotime($rs['DATE']))) ?></div></td>
    </tr>
    <tr>
      <td>Job Name:</td>
      <td><?= htmlspecialchars($rs['JobName']) ?></td>
      <td>&nbsp;</td>
      <td>Order No:</td>
      <td><div align="right"><?= htmlspecialchars($rs['Order_No']) ?></div></td>
    </tr>
  </table>
</form>
<table width="657" border="1">
<tr>
<td width="88" align="Center">Date</td>
<td width="50" align="Center">ID</td>
<td width="261" align="Center">Item</td>
<td width="60" align="Center">Hours/Qty</td>
<td width="60" align="Center">Rate</td>
<td width="70" align="Center">Subtotal</td>
</tr>
</table>

<table width="647" border="1">
<tr>
<td width="647" height="478" valign="top">
<table width="647" border="0">
<?php foreach ($timesheets as $ts): ?>
<tr>
<td width="88"><?= htmlspecialchars(date('d/m/Y', strtotime($ts['TS_Date']))) ?></td>
<td width="50"><?= htmlspecialchars($ts['Login']) ?></td>
<td width="261"><?= htmlspecialchars($ts['Task']) ?></td>
<td width="60" align="Right"><?= htmlspecialchars($ts['Hours']) ?></td>
<td width="60" align="Right"><?= '$' . number_format((float)$ts['Rate'], 2) ?></td>
<td width="70" align="Right"><?= '$' . number_format((float)$ts['amt'], 2) ?></td>
</tr>
<?php endforeach; ?>
</table>
</td>
</tr>
</table>
<table width="651" border="0">
  <tr>
    <td width="496">&nbsp;</td>
    <td width="60" align="right">Subtotal:</td>
    <td width="81" align="right">
<?php
if (is_null($rs['Subtotal'])) {
    $pdo->exec("UPDATE Invoices SET Subtotal = $subtot WHERE Invoice_No = " . (int)$invoice_no);
    $rs['Subtotal'] = $subtot;
}
echo '$' . number_format((float)$rs['Subtotal'], 2);
?>
    </td>
  </tr>
  <tr>
    <td>&nbsp;</td>
    <td align="right">GST:</td>
    <td align="right"><?= '$' . number_format((float)$rs['Subtotal'] * (float)$rs['Tax_Rate'], 2) ?></td>
  </tr>
  <tr>
    <td>&nbsp;</td>
    <td align="right"><strong>Total:</strong></td>
    <td align="right">
      <font size="+1"><strong><?= '$' . number_format((float)$rs['Subtotal'] + ((float)$rs['Subtotal'] * (float)$rs['Tax_Rate']), 2) ?></strong></font>
    </td>
  </tr>
  <tr>
    <td><strong>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Payment due by:&nbsp;
<?php
if ($rs['PaymentOption'] == 1) {
    $next = date('Y-m-d', strtotime('+1 months', strtotime($rs['DATE'])));
    echo '20/' . (int)date('n', strtotime($next)) . '/' . (int)date('Y', strtotime($next));
} elseif ($rs['PaymentOption'] == 2) {
    echo date('d/m/Y', strtotime('+7 days', strtotime($rs['DATE'])));
}
?>
    </strong>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<font size=1>Thank you for your valued custom</font></td>
    <td align="right">&nbsp;</td>
    <td align="right">&nbsp;</td>
  </tr>
</table>
<table width="651" border="1">
  <tr>
<td>
<table width="651" border="0">
  <tr>
    <td width="27">&nbsp;</td>
    <td width="182"><strong>REMITTANCE ADVICE</strong></td>
    <td width="255"><font size=1>Please Detach this portion if posting your payment.</font></td>
    <td width="69"></td>
    <td width="96"></td>
  </tr>
  <tr>
    <td>&nbsp;</td>
    <td>&nbsp;</td>
    <td>&nbsp;</td>
    <td>&nbsp;</td>
    <td>&nbsp;</td>
  </tr>
  <tr>
    <td>&nbsp;</td>
    <td>Make cheques payable to:</td>
    <td>CADViz Ltd</td>
    <td>&nbsp;</td>
    <td>&nbsp;</td>
  </tr>
  <tr>
    <td>&nbsp;</td>
    <td>&nbsp;</td>
    <td> PO Box 302387, North Harbour <br>Auckland 0751</td>
    <td><div align="right"><strong>Total Due:</strong></div></td>
    <td><div align="right">
      <font size="+1"><strong><?= '$' . number_format((float)$rs['Subtotal'] + ((float)$rs['Subtotal'] * (float)$rs['Tax_Rate']), 2) ?></strong></font>
    </div></td>
  </tr>
  <tr>
    <td>&nbsp;</td>
    <td>&nbsp;</td>
    <td></td>
    <td>&nbsp;</td>
    <td>&nbsp;</td>
  </tr>
  <tr>
    <td>&nbsp;</td>
    <td>Internet Bank Transfer:</td>
    <td><strong> 03 0275 0551274 00</strong></td>
    <td>Reference:</td>
    <td><div align="right"><?= htmlspecialchars($rs['Invoice_No']) ?></div></td>
  </tr>
    <td>&nbsp;</td>
    <td colspan="3">Please email any queries to:&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href="mailto:accounts@cadviz.co.nz">accounts@cadviz.co.nz</a></td>
    <td>&nbsp;</td>
  </tr>
</table>
</td>
</tr>
</table>
</body>
</html>
