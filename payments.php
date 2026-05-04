<?php
session_start();
require_once 'db_connect.php';

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
<!-- style sheet -->
<link href="site.css" rel="stylesheet">
<link href="invoice.css" rel="stylesheet" type="text/css" />
<title>PAYMENTS</title>
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
<?php
if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}
?>
<body bgcolor="#FFFFFF" text="black">

<?php
$pdo = get_db();

// Helper function: print dropdown from table
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

$sql = "SELECT * FROM Payments WHERE (Payments.Invoice_No IN (SELECT Invoice_No FROM Invoices WHERE Paid=0) OR Payments.Invoice_No=0)";
$stmt = $pdo->query($sql);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Case-insensitive lookup helper for variant date column names
function ci(array $row, array $keys, $default = '') {
    foreach ($keys as $key) {
        foreach ($row as $k => $v) {
            if (strcasecmp($k, $key) === 0) return $v;
        }
    }
    return $default;
}

// Robust date parser — accepts ISO (Y-m-d) AND UK (d/m/Y) forms
function parseDate($s) {
    if (empty($s)) return false;
    if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})#', $s, $m)) {
        return mktime(0, 0, 0, (int)$m[2], (int)$m[1], (int)$m[3]); // d/m/Y
    }
    return strtotime($s);
}
?>

<form method="POST" name="payment_update_form" action="payment_update.php">
<table width="810" border="1">
<tr>
<td width="88" align="Center">Date</td>
<td width="40" align="Center">INV</td>
<td width="50" align="Center">Client</td>
<td width="261" align="Center">Notes</td>
<td width="60" align="Center">Amount</td>
</tr>

<?php
$a = 0;
if (!empty($payments)) {
    foreach ($payments as $ts) {
        $a++;
        echo "<tr>";
        // Look for Date_Received under any of its likely names + parse robustly
        $rawDate = ci($ts, ['Date_Received', 'Date_Recieved', 'DateReceived', 'date_received', 'Date']);
        $t = parseDate($rawDate);
        $dateRcvd = $t ? date('d/m/Y', $t) : '';
        echo "<td width='88'><input size='10' name='Date_Received_box" . $a . "' value='" . htmlspecialchars($dateRcvd) . "'></td>";
        echo "<td width='40'>";
        echo "<input size='5' type='hidden' name='Payment_ID_" . $a . "' value='" . htmlspecialchars((string)ci($ts, ['Payment_ID','PaymentID','payment_id'])) . "'>";
        echo "<input size='5' name='Inv_box" . $a . "' value='" . htmlspecialchars((string)ci($ts, ['Invoice_No','invoice_no'])) . "'>";
        echo "</td>";
        echo "<td width='50'>";
        print_dd_box($pdo, 'Clients', 'client_id', 'Client_name', ci($ts, ['Client_ID','client_id'], 0), 'Client_box' . $a);
        echo "</td>";
        echo "<td><input size='35' name='notes_box" . $a . "' value='" . htmlspecialchars((string)ci($ts, ['Notes','notes'])) . "'></td>";
        echo "<td width='60' align='Right'><input size='5' name='amount" . $a . "' value='" . htmlspecialchars((string)ci($ts, ['Amount','amount'])) . "'></td>";
        echo "</tr>";
    }
    echo "<input size='5' type='hidden' name='rowcount' value='" . $a . "'>";
} else {
    echo "<input size='5' type='hidden' name='rowcount' value='0'>";
}
?>

</table>
<input type="submit" name="Update_Entries" value="Update_Entries">
</form>



<form method="POST" name="payment_add_form" action="payment_add.php">
<table width="810" border="1">
<tr>
<td width="88" align="Center">Date</td>
<td width="40" align="Center">INV</td>
<td width="50" align="Center">Client</td>
<td width="261" align="Center">Notes</td>
<td width="60" align="Center">Amount</td>
</tr>
<?php
echo "<tr>";
echo "<td width='88'><input size='5' name='Date_Received_new' value='" . date('d/m/Y') . "'></td>";
echo "<td width='40'><input size='5' name='Inv_box_new' value='0'></td>";
echo "<td width='50'>";
print_dd_box($pdo, 'Clients', 'client_id', 'Client_name', null, 'Client_box_new');
echo "</td>";
echo "<td><input size='35' name='Notes_box_new' value='Partial Payment'></td>";
echo "<td width='60' align='Right'><input size='5' name='amount_new' value=''></td>";
echo "</tr>";
?>
</table>
<input type="submit" name="Add_Entry" value="Add_Entry">
<input name="Invoice_Number" type="hidden" value="0">
</form>

</body>
</html>
