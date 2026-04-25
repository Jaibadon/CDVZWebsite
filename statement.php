<?php
require_once 'auth_check.php';
require_once 'db_connect.php';

$pdo = get_db();

$client_id = (int) $_POST['CLIENT_BOX'];

$sql = "SELECT Invoices.*, Invoices.Invoice_No AS NUM, Invoices.Client_ID AS CID,
               Clients.Client_Name, Clients.Address1, Clients.Billing_Email,
               Projects.JobName, Projects.Order_No,
               PAYMENTS.Amount, PAYMENTS.Date_received
        FROM Invoices
        LEFT JOIN Clients  ON Invoices.Client_ID = Clients.Client_ID
        LEFT JOIN Projects ON Invoices.Proj_ID   = Projects.Proj_ID
        LEFT JOIN Payments ON Invoices.Invoice_No = Payments.Invoice_No
        WHERE Invoices.Paid = 0
          AND Invoices.Client_ID = ?";

$stmt = $pdo->prepare($sql);
$stmt->execute([$client_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$billto = '';
if (!empty($rows)) {
    $billto = $rows[0]['Billing_Email'] ?? '-';
}

$today = date('d/m/Y');
$grand = 0.0;
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
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta http-equiv="Content-Language" content="en-nz">
<link href="invoice.css" rel="stylesheet" type="text/css" />
<title>Statement</title>
<basefont face="arial" size="2">
<style type="text/css">
.style1 { text-align: left; }
.style2 { text-align: right; }
.style3 {
    background-color: #FFFFFF;
    font: Arial, Gadget, sans-serif;
    font-weight: bold;
    font-size: 30px;
    color: #000000;
}
.nrml {
    font-family: Verdana, Geneva, sans-serif;
    font-size: 9px;
}
</style>
</head>
<body bgcolor="#FFFFFF" text="black">

<table width="653" border="0">
  <tr class="style3">
    <td width="207" rowspan="5" valign="top"><img src="cadviz_logo_bw1.gif" width="200" height="89" alt="CADVIZ"></td>
    <td width="274" rowspan="5" class="style3"><div align="center">STATEMENT</div></td>
    <td width="158"><p align="right" class="nrml">&nbsp;</p></td>
  </tr>
  <tr class="style3">
    <td><div align="right"><strong><span class="nrml">GST No: 82-090-630</span></strong></div></td>
  </tr>
  <tr class="style3">
    <td><div align="right"><div align="right"><span class="nrml">CADViz Ltd </span></div></div></td>
  </tr>
  <tr class="style3">
    <td><div align="right" class="nrml"><div align="right">PO Box 302387<br>North Harbour<br>Auckland 0751</div></div></td>
  </tr>
  <tr class="style3">
    <td><div align="right"></div></td>
  </tr>
</table>

<form method="POST" name="project" action="mailto:<?php echo htmlspecialchars($billto); ?>">
  <table width="651" border="0">
    <tr>
      <td width="100">Client:</td>
      <td width="293">
        <?php
        if (!empty($rows)) {
            echo '<a href="client_updateform.php?client_id=' . (int)$rows[0]['CID'] . '">';
            echo htmlspecialchars($rows[0]['Client_Name'] ?? '');
            echo '</a>';
        }
        ?>
      </td>
      <td width="71">&nbsp;</td>
      <td width="69">Date:&nbsp;</td>
      <td width="96"><div align="right"><?php echo $today; ?></div></td>
    </tr>
    <tr>
      <td height="38" valign="top">Address:<br>Email:</td>
      <td colspan="4" valign="top">
        <?php echo htmlspecialchars($rows[0]['Address1'] ?? ''); ?><br>
        <a href="mailto:<?php echo htmlspecialchars($billto); ?>"><?php echo htmlspecialchars($billto); ?></a>
        <div align="right"></div>
      </td>
    </tr>
  </table>
</form>

<table width="657" border="1">
  <tr>
    <td width="78" align="Center">Inv. Date</td>
    <td width="50" align="Center">Inv. No.</td>
    <td width="245" align="Center">Order No / JobName</td>
    <td width="60" align="Center">Due Date</td>
    <td width="86" align="Center">Status</td>
    <td width="70" align="Center">Amount</td>
  </tr>
</table>

<table width="647" border="1">
  <tr>
    <td width="647" height="480" valign="top">
      <table width="647" border="0">
<?php
foreach ($rows as $row) {
    $subtotal  = (float)($row['Subtotal']  ?? 0);
    $tax_rate  = (float)($row['Tax_Rate']  ?? 0);
    $total     = $subtotal + ($subtotal * $tax_rate);

    $payopt    = (int)($row['PaymentOption'] ?? 0);
    $inv_date  = $row['DATE'] ?? null;
    if ($payopt === 1) {
        $ddate = date('d/m/Y', strtotime('+1 month', strtotime($inv_date)));
    } elseif ($payopt === 2) {
        $ddate = date('d/m/Y', strtotime('+7 days', strtotime($inv_date)));
    } else {
        $ddate = $inv_date ? date('d/m/Y', strtotime($inv_date)) : '';
    }

    $diff = (int) floor((time() - strtotime($ddate)) / 86400);
    if ($diff < 0) {
        $status_label = 'Current';
    } elseif ($diff < 7) {
        $status_label = 'Due Now';
    } else {
        $status_label = '<strong>' . $diff . 'd OVERDUE</strong>';
    }

    $inv_date_fmt = $inv_date ? date('d/m/Y', strtotime($inv_date)) : '';

    echo "<tr>";
    echo "<td width='78'>" . htmlspecialchars($inv_date_fmt) . "</td>";
    echo "<td width='50'><a href='invoice.php?invoice_no=" . (int)$row['NUM'] . "'>" . (int)$row['NUM'] . "</a></td>";
    echo "<td width='245'>";
    if (!empty($row['Order_No'])) echo htmlspecialchars($row['Order_No']) . " / ";
    echo htmlspecialchars($row['JobName'] ?? '') . "</td>";
    echo "<td width='60' align='Right'>" . htmlspecialchars($ddate) . "</td>";
    echo "<td width='86' align='Right'>" . $status_label . "</td>";
    echo "<td width='70' align='Right'>$" . number_format($total, 2) . "</td>";
    echo "</tr>";

    $grand += $total;

    $payment_amount = (float)($row['Amount'] ?? 0);
    if ($payment_amount > 0) {
        $dr_fmt = !empty($row['Date_received']) ? date('d/m/Y', strtotime($row['Date_received'])) : '';
        echo "<tr>";
        echo "<td width='78'>" . htmlspecialchars($dr_fmt) . "</td>";
        echo "<td width='50'>PYMNT</td>";
        echo "<td width='245'>Partial payment(s) received for above.....</td>";
        echo "<td width='60' align='Right'>.............</td>";
        echo "<td width='86' align='Right'>.............</td>";
        echo "<td width='70' align='Right'>-$" . number_format($payment_amount, 2) . "</td>";
        echo "</tr>";
        $grand -= $payment_amount;
    }
}
?>
      </table>
    </td>
  </tr>
</table>

<table width="651" border="0">
  <tr>
    <td width="496">&nbsp;</td>
    <td width="60" align="right"><strong>Total Due:</strong></td>
    <td width="81" align="right">
      <font size="+1"><strong>$<?php echo number_format($grand, 2); ?></strong></font>
    </td>
  </tr>
  <tr>
    <td>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</td>
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
          <td width="255"><font size="1">Please Detach this portion if posting your payment.</font></td>
          <td width="69"></td>
          <td width="96"></td>
        </tr>
        <tr>
          <td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>
        </tr>
        <tr>
          <td>&nbsp;</td>
          <td>Make cheques payable to:</td>
          <td>CADViz Ltd</td>
          <td>&nbsp;</td><td>&nbsp;</td>
        </tr>
        <tr>
          <td>&nbsp;</td>
          <td>&nbsp;</td>
          <td>PO Box 302387, North Harbour<br>Auckland 0751</td>
          <td><div align="right"><strong>Total Due:</strong></div></td>
          <td><div align="right"><font size="+1"><strong>$<?php echo number_format($grand, 2); ?></strong></font></div></td>
        </tr>
        <tr>
          <td>&nbsp;</td><td>&nbsp;</td><td></td><td>&nbsp;</td><td>&nbsp;</td>
        </tr>
        <tr>
          <td>&nbsp;</td>
          <td>Internet Bank Transfer:</td>
          <td><strong>03 0275 0551274 00</strong></td>
          <td>Reference:</td>
          <td><div align="right">ST<?php echo $client_id; ?></div></td>
        </tr>
        <tr>
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
