<?php
session_start();
require_once 'db_connect.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}
// Editing invoices is admin-only. Without this guard ANY logged-in staff
// member could view/modify all invoices — confidential client data.
if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403);
    echo '<p>Admin only. <a href="menu.php">Back to menu</a></p>';
    exit;
}

$pdo = get_db();

// Accept either ?Invoice_No=... or ?invoice_no=...
$invoice_no = (int)($_GET['Invoice_No'] ?? $_GET['invoice_no'] ?? 0);
if ($invoice_no <= 0) {
    echo "<p>Missing invoice number. <a href=\"invoice_list.php\">Back</a></p>";
    exit;
}

$sql = "SELECT Invoices.Invoice_No    AS Invoice_No,
               Invoices.Date          AS Date,
               Invoices.DatePaid      AS DatePaid,
               Invoices.Subtotal      AS Subtotal,
               Invoices.Tax_Rate      AS Tax_Rate,
               Invoices.Paid          AS Paid,
               Invoices.PaymentOption AS PaymentOption,
               Invoices.PayBy         AS PayBy,
               Invoices.Notes         AS InvNotes,
               Invoices.Sent          AS Sent,
               Invoices.date_sent     AS date_sent,
               Invoices.Order_No_INV  AS Order_No_INV,
               Invoices.Status_INV    AS Status_INV,
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
    echo "<p>Invoice $invoice_no not found. <a href=\"invoice_list.php\">Back</a></p>";
    exit;
}

$sql2 = "SELECT Timesheets.TS_ID       AS TS_ID,
                Timesheets.TS_DATE     AS TS_DATE,
                Timesheets.Invoice_No  AS Invoice_No,
                Timesheets.proj_id     AS proj_id,
                Timesheets.Task        AS Task,
                Timesheets.Hours       AS Hours,
                Timesheets.Rate        AS Rate,
                Timesheets.Employee_id AS Employee_id,
                Staff.Login            AS Login,
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

// Format a stored date safely for the d/m/Y inputs. strtotime(null) returns
// false → date('d/m/Y', false) prints 01/01/1970, which is what was showing
// up across this page. Treat empty/null/zero-date as blank.
function fmt_date_safe($v) {
    if ($v === null || $v === '' || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') return '';
    $t = strtotime((string)$v);
    return $t ? date('d/m/Y', $t) : '';
}

// Helper function to print a dropdown box from a table
function print_dd_box($pdo, $table_name, $index_name, $display_name, $default_value, $obj_name) {
    $sql = "SELECT " . $index_name . ", " . $display_name . " FROM " . $table_name . " ORDER BY " . $display_name;
    $stmt = $pdo->query($sql);
    echo '<SELECT name="' . htmlspecialchars($obj_name) . '">';
    echo '<OPTION VALUE=""> ';
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($row[$index_name] == $default_value) {
            echo '<OPTION SELECTED VALUE="' . htmlspecialchars($row[$index_name]) . '">' . htmlspecialchars($row[$display_name]);
        } else {
            if (($row["active"] ?? 1) != 0) {
                echo '<OPTION VALUE="' . htmlspecialchars($row[$index_name]) . '">' . htmlspecialchars($row[$display_name]);
            }
        }
    }
    echo '</SELECT>';
}

// Enter-key target — go back to the referring page (e.g. a filtered
// invoice_list.php?client=950, monthly_invoicing.php, invoices_for_job.php)
// instead of always the global list. Falls back to invoice_list.php when
// referer is missing OR would bounce back to this same page (which can
// happen after the form's own POST → redirect cycle).
$enterTarget = $_SERVER['HTTP_REFERER'] ?? '';
$selfBase    = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
if ($enterTarget === '' || ($selfBase !== '' && strpos($enterTarget, $selfBase) !== false)) {
    $enterTarget = 'invoice_list.php';
}
?>
<script language="Javascript">
	document.onkeydown = function() {
		if (event.keyCode == 13) {
			window.location = <?= json_encode($enterTarget) ?>;
		}
	}
</script>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=windows-1252">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="Content-Language" content="en-nz">
<!-- style sheet -->
<link href="site.css" rel="stylesheet">
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

<table width="799" border="0">
      <tr class="style3">
        <td width="207" rowspan="5" valign="top"><img src="cadviz_logo_bw1.gif" width="200" height="89" alt="CADVIZ"></td>
        <td width="397" rowspan="5" class="style3"><div align="center">TAX INVOICE</div></td>
        <td width="181"><p align="right" class="nrml">&nbsp;</p></td>
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
<form method="POST" name="invoice_update_form" action="invoice_update.php">
  <table width="810" border="0">
    <tr>
      <td width="100">Client:</td>
      <td width="293">
<?php print_dd_box($pdo, 'Clients', 'client_id', 'CLIENT_NAME', $rs['Client_ID'], 'CLIENT_BOX'); ?>
      </td>
      <td width="191">&lt;-Only updates for this invoice</td>
      <td width="76">Invoice No:&nbsp;</td>
      <td width="113"><div align="right">
        <input name="Invoice_No" readonly align="right" value="<?= htmlspecialchars($rs['Invoice_No']) ?>">
      </div></td>
    </tr>
    <tr>
      <td height="38" valign="top">Address:<br>Email:</td>
      <td valign="top"><?= htmlspecialchars((string)($rs['Address1'] ?? $rs['ADDRESS1'] ?? '')) ?><br>
       <a href="mailto:<?= htmlspecialchars($Billto) ?>"><?= htmlspecialchars($Billto) ?></a></td>
      <td>&nbsp;</td>
      <td valign="top">Date:&nbsp;</td>
      <td valign="top" align="right">
      <input size="7" name="INV_DATE" value="<?= htmlspecialchars(fmt_date_safe($rs['Date'] ?? null)) ?>">
    </tr>
    <tr>
      <td>Project:</td>
      <td><?= htmlspecialchars($rs['JobName']) ?></td>
      <td>&nbsp;</td>
      <td>Order No:</td>
      <td><div align="right">
        <?= htmlspecialchars($rs['Order_No']) ?>
        <input size="12" name="ORDER_NO_INV" value="<?= htmlspecialchars((string)($rs['Order_No_INV'] ?? '')) ?>">
      </div></td>
    </tr>
    <tr>
      <td><strong>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Sent</strong>:</td>
      <td>
<?php
if ($rs['Sent'] != 0) {
    echo "<input type='checkbox' name='SENT' value='ON' checked>";
    $dateSentFmt = fmt_date_safe($rs['date_sent'] ?? null);
    if ($dateSentFmt !== '') echo "&nbsp;&nbsp;" . htmlspecialchars($dateSentFmt);
} else {
    echo "<input type='checkbox' name='SENT' value='ON'>";
}
?>
      </td>
      <td>&nbsp;</td>
      <td>&nbsp;</td>
      <td>&nbsp;</td>
    </tr>
    <tr>
      <td><strong>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Paid</strong>:</td>
      <td>
<?php
if ($rs['Paid'] != 0) {
    echo "<input type='checkbox' name='PAID' value='ON' checked>";
    $datePaidFmt = fmt_date_safe($rs['DatePaid'] ?? null);
    if ($datePaidFmt !== '') echo "&nbsp;&nbsp;" . htmlspecialchars($datePaidFmt);
} else {
    echo "<input type='checkbox' name='PAID' value='ON'>";
}
?>
      </td>
      <td>&nbsp;</td>
      <td>&nbsp;</td>
      <td>&nbsp;</td>
    </tr>
    <tr>
      <td><strong>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Payment due:</strong></td>
      <td><strong>
        <select size="1" name="Payment_Opt">
<?php
$opts = [1 => '20th of Next Month', 2 => '7 Days', 3 => 'Today'];
foreach ($opts as $val => $label) {
    $sel = ($rs['PaymentOption'] == $val) ? ' SELECTED' : '';
    echo "<OPTION{$sel} VALUE=\"{$val}\">{$label}";
}
?>
        </select>
      </strong>&nbsp;&nbsp;</td>
      <td><strong>
        <select size="1" name="Status_INV">
<?php
$statuses = [0 => 'Not Checked', 1 => 'Ready to Send', 2 => 'Sent'];
foreach ($statuses as $val => $label) {
    $sel = ($rs['Status_INV'] == $val) ? ' SELECTED' : '';
    echo "<OPTION{$sel} VALUE=\"{$val}\">{$label}";
}
?>
        </select>
      </strong>&nbsp;&nbsp;</td>
      <td>&nbsp;</td>
      <td>&nbsp;</td>
      <td>&nbsp;</td>
    </tr>
    <tr>
      <td valign="top"><strong>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Notes:</strong></td>
      <td colspan="4"><textarea name="Notes" rows="5" cols="90"><?= htmlspecialchars($rs['InvNotes']) ?></textarea></td>
    </tr>
    <tr>
      <td><input type="submit" name="Update_Invoice" value="Update_Invoice"></td>
      <td>&nbsp;</td>
      <td>&nbsp;</td>
      <td>&nbsp;</td>
      <td>&nbsp;</td>
    </tr>
  </table>
</form>
<form method="POST" name="ts_update_form" action="ts_update.php">
<table width="810" border="1">
<tr>
<td width="88" align="Center">Date</td>
<td width="40" align="Center">INV</td>
<td width="50" align="Center">&nbsp;</td>
<td width="50" align="Center">STAFF</td>
<td width="261" align="Center">Item</td>
<td width="60" align="Center">Hours/Qty</td>
<td width="60" align="Center">Rate</td>
<td width="80" align="Center">Subtotal</td>
</tr>

<input name="Invoice_Num" type="hidden" value="<?= htmlspecialchars($rs['Invoice_No']) ?>">
<?php
$subtot = 0;
$a = 0;
foreach ($timesheets as $ts) {
    $a++;
    echo "<tr>";
    echo "<td width='88'><input size='5' name='TS_Date_box" . $a . "' value='" . htmlspecialchars(fmt_date_safe($ts['TS_DATE'] ?? null)) . "'></td>";
    echo "<td width='40'>";
    echo "<input size='5' type='hidden' name='TS_ID_" . $a . "' value='" . htmlspecialchars((string)$ts['TS_ID']) . "'>";
    echo "<input size='5' name='TS_Inv_box" . $a . "' value='" . htmlspecialchars((string)($ts['Invoice_No'] ?? '')) . "'>";
    echo "</td>";
    echo "<td width='50'>";
    echo "<input size='5' type='hidden' name='Project_box" . $a . "' value='" . htmlspecialchars((string)$ts['proj_id']) . "'>";
    echo "</td>";
    echo "<td width='50'>";
    print_dd_box($pdo, 'Staff', 'Employee_id', 'Login', $ts['Employee_id'], 'staff_box' . $a);
    echo "</td>";
    echo "<td><input size='35' name='desc_box" . $a . "' value='" . htmlspecialchars((string)$ts['Task']) . "'></td>";
    echo "<td width='60' align='Right'><input size='5' name='TS_HOURS" . $a . "' value='" . htmlspecialchars($ts['Hours']) . "'></td>";
    echo "<td width='60' align='Right'><input size='5' name='TS_RATE" . $a . "' value='" . htmlspecialchars($ts['Rate']) . "'></td>";
    echo "<td width='70' align='Right'>" . '$' . number_format((float)$ts['amt'], 2) . "</td>";
    echo "</tr>";
    $subtot += (float)$ts['amt'];
}
echo "<input size='5' type='hidden' name='rowcount' value='" . $a . "'>";

// Update the subtotal
$pdo->exec("UPDATE Invoices SET Subtotal = $subtot WHERE Invoice_No = " . (int)$invoice_no);
// Re-fetch updated row
$stmt3 = $pdo->prepare("SELECT Subtotal, Tax_Rate FROM Invoices WHERE Invoice_No = ?");
$stmt3->execute([$invoice_no]);
$inv = $stmt3->fetch(PDO::FETCH_ASSOC);
$dbSubtotal = (float)$inv['Subtotal'];
$taxRate = (float)$inv['Tax_Rate'];
?>
<tr>
<td></td><td></td><td></td><td></td><td></td><td></td>
<td width="66" align="right">Subtotal:</td>
<td width="54" align="right"><?= '$' . number_format($dbSubtotal, 2) ?></td>
</tr>
<tr>
<td></td><td></td><td></td><td></td><td></td><td></td>
<td align="right">GST:</td>
<td align="right"><?= '$' . number_format($dbSubtotal * $taxRate, 2) ?></td>
</tr>
<tr>
<td></td><td></td><td></td><td></td><td></td><td></td>
<td align="right"><strong>Total:</strong></td>
<td align="right"><font size="+1"><strong><?= '$' . number_format($dbSubtotal + ($dbSubtotal * $taxRate), 2) ?></strong></font></td>
</tr>
</table>
<input type="submit" name="Update_Entries" value="Update_Entries">
</form>



<form method="POST" name="ts_add_form" action="ts_add.php">
<table width="810" border="1">
<tr>
<td width="88" align="Center">Date</td>
<td width="40" align="Center">INV</td>
<td width="50" align="Center">PROJ</td>
<td width="50" align="Center">STAFF</td>
<td width="261" align="Center">Item</td>
<td width="60" align="Center">Hours/Qty</td>
<td width="60" align="Center">Rate</td>
<td width="80" align="Center">Subtotal</td>
</tr>
<?php
echo "<tr>";
echo "<td width='88'><input size='5' name='TS_date_new' value='" . date('d/m/Y') . "'></td>";
echo "<td width='40'><input size='5' name='TS_Inv_box_new' value='" . htmlspecialchars($rs['Invoice_No']) . "'></td>";
echo "<td width='50'>";
print_dd_box($pdo, 'Projects', 'proj_id', 'JobName', $rs['proj_id'], 'Project_box_new');
echo "</td>";
echo "<td width='50'>";
print_dd_box($pdo, 'Staff', 'Employee_id', 'Login', 7, 'staff_box_new');
echo "</td>";
echo "<td><input size='35' name='desc_box_new' value='Services Rendered'></td>";
echo "<td width='60' align='Right'><input size='5' name='TS_HOURS_new' value=''></td>";
echo "<td width='60' align='Right'>calculated</td>";
echo "<td width='70' align='Right'>calculated</td>";
echo "</tr>";
?>
</table>
<input type="submit" name="Add_Entry" value="Add_Entry">
<input name="Invoice_Number" type="hidden" value="<?= htmlspecialchars($rs['Invoice_No']) ?>">
</form>

<table>
    <tr>
      <td><?php
echo '<a href="invoice.php?Invoice_No=' . htmlspecialchars((string)($rs['Invoice_No'] ?? $invoice_no)) . '">Preview Invoice</a>';
?></td>
      <td width="200">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</td>
      <td><?php
echo '<a href="invoice_edit.php?Invoice_No=' . ((int)($rs['Invoice_No'] ?? $invoice_no) - 1) . '">&lt;&lt; Previous</a>';
?></td>
      <td>&nbsp;</td>
      <td><?php
echo '<a href="invoice_edit.php?Invoice_No=' . ((int)($rs['Invoice_No'] ?? $invoice_no) + 1) . '">Next &gt;&gt;</a>';
?></td>
    </tr>
</table>

</body>
</html>
