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

$sql = "SELECT Invoices.Invoice_No    AS Invoice_No,
               Invoices.Date          AS Date,
               Invoices.Subtotal      AS Subtotal,
               Invoices.Tax_Rate      AS Tax_Rate,
               Invoices.PaymentOption AS PaymentOption,
               Invoices.Order_No_INV  AS Order_No_INV,
               Invoices.Notes         AS InvNotes,
               Clients.Client_id      AS Client_ID,
               Clients.Client_Name    AS Client_Name,
               Clients.Address1       AS Address1,
               Clients.Billing_Email  AS Billing_Email,
               Projects.proj_id       AS proj_id,
               Projects.JobName       AS JobName,
               Projects.Order_No      AS Order_No
          FROM Invoices
          LEFT OUTER JOIN Clients  ON Invoices.Client_ID = Clients.Client_id
          LEFT OUTER JOIN Projects ON Invoices.Proj_ID   = Projects.proj_id
         WHERE Invoices.Invoice_No = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$invoice_no]);
$rs = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rs) {
    echo "<p>Invoice $invoice_no not found.</p>";
    exit;
}

$sql2 = "SELECT Timesheets.TS_ID   AS TS_ID,
                Timesheets.TS_DATE AS TS_DATE,
                Timesheets.Task    AS Task,
                Timesheets.Hours   AS Hours,
                Timesheets.Rate    AS Rate,
                Staff.Login        AS Login,
                (Timesheets.Rate * Timesheets.Hours) AS amt
           FROM Timesheets
           LEFT OUTER JOIN Staff ON Timesheets.Employee_id = Staff.Employee_ID
          WHERE Timesheets.Invoice_No = ?
          ORDER BY Timesheets.TS_DATE";
$stmt2 = $pdo->prepare($sql2);
$stmt2->execute([$invoice_no]);
$timesheets = $stmt2->fetchAll(PDO::FETCH_ASSOC);

$Billto = $rs['Billing_Email'] ?? '-';
if ($Billto === '') $Billto = '-';

// Resolve Date column from any case (Date / DATE / date)
function ci(array $row, array $keys, $default = '') {
    foreach ($keys as $key) {
        foreach ($row as $k => $v) {
            if (strcasecmp($k, $key) === 0) return $v;
        }
    }
    return $default;
}
function fmtDate($v) {
    if (empty($v)) return '';
    $t = strtotime($v);
    return $t ? date('d/m/Y', $t) : '';
}

$invDate = ci($rs, ['Date', 'DATE', 'InvDate', 'date']);

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
<title>CADviz_INV<?= htmlspecialchars($rs['Invoice_No']) ?></title>
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
        <td width="207" rowspan="4" valign="top"><img src="cadviz_logo_bw1.gif" width="200" height="89" alt="CADVIZ"></td>
        <td width="274" rowspan="4" class="style3"><div align="center">TAX INVOICE</div></td>
        <td width="158"><p align="right" class="nrml">&nbsp;</p></td>
      </tr>
      <tr class="style3">
        <td><div align="right"><strong><span class="nrml">GST No: 82-090-630</span></strong></div></td>
      </tr>
      <tr class="style3">
        <td><div align="right"><div align="right"><span class="nrml">CADViz Ltd </span></div></div></td>
      </tr>
      <tr class="style3">
        <td valign="top"><div align="right" class="nrml">
          <div align="right">PO Box 302387<br>North Harbour<br>Auckland 0751</div>
        </div></td>
      </tr>
</table>
      <tr>
        <td height="10">&nbsp;</td>
      </tr>
<table></table>
<form method="POST" name="project" action="mailto:<?= htmlspecialchars($Billto) ?>">
  <table width="651" border="0">
    <tr>
      <td width="100">Client:</td>
      <td width="293">
<?php
echo '<a href="client_updateform.php?client_id=' . htmlspecialchars($rs['Client_ID']) . '">';
echo htmlspecialchars($rs['CLIENT_NAME']);
echo '</a>';
?>
      </td>
      <td width="71">&nbsp;</td>
      <td width="69">Invoice No:&nbsp;</td>
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
      <td valign="top"><?= htmlspecialchars((string)ci($rs, ['Address1','ADDRESS1','address1'])) ?><br>
       <a href="mailto:<?= htmlspecialchars($Billto) ?>"><?= htmlspecialchars($Billto) ?></a></td>
      <td>&nbsp;</td>
      <td valign="top">Date:&nbsp;</td>
      <td valign="top"><div align="right"><?= htmlspecialchars(fmtDate($invDate)) ?></div></td>
    </tr>
    <tr>
      <td>Job Name:</td>
      <td><?= htmlspecialchars($rs['JobName']) ?></td>
      <td>&nbsp;</td>
      <td>Order No:</td>
      <td><div align="right">
        <?= htmlspecialchars($rs['Order_No']) ?><?= htmlspecialchars($rs['Order_No_INV']) ?>
      </div></td>
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
<?php foreach ($timesheets as $ts): $tsDate = ci($ts, ['TS_DATE','TS_Date','ts_date']); ?>
<tr>
<td width="88"><?= htmlspecialchars(fmtDate($tsDate)) ?></td>
<td width="50"><?= htmlspecialchars((string)ci($ts, ['Login'])) ?></td>
<td width="261"><?= htmlspecialchars((string)ci($ts, ['Task'])) ?></td>
<td width="60" align="Right"><?= htmlspecialchars((string)ci($ts, ['Hours'])) ?></td>
<td width="60" align="Right"><?= '$' . number_format((float)ci($ts, ['Rate'], 0), 2) ?></td>
<td width="70" align="Right"><?= '$' . number_format((float)($ts['amt'] ?? 0), 2) ?></td>
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
if ($subtot >= 0) {
    echo '$' . number_format((float)$rs['Subtotal'], 2);
} else {
    echo "REDIRECTING TO CREDIT NOTICE...";
    header('Location: credit_notice.php?invoice_no=' . $rs['invoice_no']);
    exit;
}
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
$baseTs = $invDate ? strtotime($invDate) : false;
if ($baseTs) {
    if ($rs['PaymentOption'] == 1) {
        $next = strtotime('+1 months', $baseTs);
        echo '20/' . (int)date('n', $next) . '/' . (int)date('Y', $next);
    } elseif ($rs['PaymentOption'] == 2) {
        echo date('d/m/Y', strtotime('+7 days', $baseTs));
    } elseif ($rs['PaymentOption'] == 3) {
        echo date('d/m/Y', $baseTs);
    }
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
    <td width="27"><font size=4>&nbsp;</font></td>
    <td width="182" valign="top"><strong>REMITTANCE ADVICE</strong></td>
    <td width="255" valign="top"><font size=1>Please Detach this portion if posting your payment.</font></td>
    <td width="69"></td>
    <td width="96"></td>
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
