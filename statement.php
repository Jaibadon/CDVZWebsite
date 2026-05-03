<?php
require_once 'auth_check.php';
require_once 'db_connect.php';
require_once 'xero_client.php';
// Refresh local Xero_* columns before drawing the statement so the
// figures match Xero's source of truth (paid invoices drop off).
define('XERO_SYNC_LIBRARY_ONLY', true);
require_once 'xero_sync.php';

if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403);
    die('Admin only.');
}

$pdo = get_db();

// Accept client id from any of: POST CLIENT_BOX (legacy form), POST/GET
// client_id (used by the Send Statement button + direct links).
$client_id = (int)(
    $_POST['client_id']  ?? $_POST['CLIENT_BOX'] ?? $_POST['client_box']
    ?? $_GET['client_id'] ?? $_GET['CLIENT_BOX'] ?? 0
);

if ($client_id <= 0) {
    // Render a tiny picker instead of crashing on the next query.
    $clients = $pdo->query("SELECT Client_id, Client_Name FROM Clients WHERE COALESCE(Active,1) <> 0 ORDER BY Client_Name")->fetchAll(PDO::FETCH_ASSOC);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><link href="site.css" rel="stylesheet"><title>Statement</title></head><body><div class="page"><div class="card">';
    echo '<h2>Statement &mdash; pick a client</h2>';
    echo '<form method="get" action="statement.php"><select name="client_id">';
    echo '<option value="">-- choose --</option>';
    foreach ($clients as $c) {
        echo '<option value="' . (int)$c['Client_id'] . '">' . htmlspecialchars($c['Client_Name']) . '</option>';
    }
    echo '</select> <button type="submit" class="btn-primary">Show statement</button></form>';
    echo '<p><a href="invoice_list.php">Back to invoice list</a></p>';
    echo '</div></div></body></html>';
    exit;
}

// Sync from Xero so the unpaid set is current. Errors are non-fatal —
// we still render the statement from local data.
$syncResult = run_xero_sync($pdo);

// Pull every unpaid invoice for this client. Keep payments OUT of this
// query — joining Payments here multiplies rows when an invoice has more
// than one payment, which both inflates totals and visually duplicates
// invoices on the statement.
$sql = "SELECT i.Invoice_No      AS NUM,
               i.Client_ID       AS CID,
               i.Date            AS InvDate,
               i.Subtotal        AS Subtotal,
               i.Tax_Rate        AS Tax_Rate,
               i.PaymentOption   AS PaymentOption,
               i.Xero_AmountDue  AS XeroAmountDue,
               i.Xero_DueDate    AS XeroDueDate,
               c.Client_Name     AS Client_Name,
               c.Address1        AS Address1,
               c.Billing_Email   AS Billing_Email,
               p.JobName         AS JobName,
               p.Order_No        AS Order_No
          FROM Invoices i
          LEFT JOIN Clients  c ON i.Client_ID = c.Client_id
          LEFT JOIN Projects p ON i.Proj_ID   = p.proj_id
         WHERE i.Paid       = 0
           AND i.Client_ID  = ?
         ORDER BY i.Invoice_No";

$stmt = $pdo->prepare($sql);
$stmt->execute([$client_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Payments — fetch per invoice once, then sum locally, so we never inflate
// the statement when an invoice has multiple receipts.
$invNos = array_map(fn($r) => (int)$r['NUM'], $rows);
$paymentTotals = [];
if (!empty($invNos)) {
    $in = implode(',', array_fill(0, count($invNos), '?'));
    $ps = $pdo->prepare("SELECT Invoice_No, SUM(Amount) AS paid, MAX(Date_received) AS last_date FROM Payments WHERE Invoice_No IN ($in) GROUP BY Invoice_No");
    $ps->execute($invNos);
    foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $paymentTotals[(int)$p['Invoice_No']] = ['paid' => (float)$p['paid'], 'last_date' => $p['last_date']];
    }
}

// Look up the client header even when there are no unpaid invoices, so the
// page doesn't crash and we can still display "No outstanding invoices".
if (empty($rows)) {
    $cli = $pdo->prepare("SELECT Client_id AS CID, Client_Name, Address1, Billing_Email FROM Clients WHERE Client_id = ?");
    $cli->execute([$client_id]);
    $clientHeader = $cli->fetch(PDO::FETCH_ASSOC) ?: ['CID' => $client_id, 'Client_Name' => '?', 'Address1' => '', 'Billing_Email' => '-'];
} else {
    $clientHeader = [
        'CID'           => $rows[0]['CID'],
        'Client_Name'   => $rows[0]['Client_Name'],
        'Address1'      => $rows[0]['Address1'],
        'Billing_Email' => $rows[0]['Billing_Email'],
    ];
}

$billto = $clientHeader['Billing_Email'] ?: '-';
if ($billto === '') $billto = '-';

$today = date('d/m/Y');

// Try Xero for the authoritative outstanding balance for this contact.
// Falls back to the local sum if Xero isn't connected or the contact
// doesn't exist there yet.
$xeroOutstanding = null;
$xeroError = null;
if (XeroClient::isConfigured() && XeroClient::isConnected($pdo) && !empty($clientHeader['Client_Name'])) {
    try {
        $xc = new XeroClient($pdo);
        $xeroOutstanding = $xc->getContactOutstanding($clientHeader['Client_Name']);
    } catch (Exception $e) {
        $xeroError = $e->getMessage();
    }
}

$grand = 0.0;
?>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="Content-Language" content="en-nz">
<link href="site.css" rel="stylesheet">
<link href="invoice.css" rel="stylesheet" type="text/css" />
<title>Statement &mdash; <?= htmlspecialchars($clientHeader['Client_Name'] ?? '') ?></title>
<basefont face="arial" size="2">
<style type="text/css">
.style3 { background-color: #FFFFFF; font: Arial, Gadget, sans-serif; font-weight: bold; font-size: 30px; color: #000000; }
.nrml  { font-family: Verdana, Geneva, sans-serif; font-size: 9px; }
.overdue { background:#ffe5e5; }
</style>
</head>
<body bgcolor="#FFFFFF" text="black">

<table width="653" border="0">
  <tr class="style3">
    <td width="207" rowspan="5" valign="top"><img src="cadviz_logo_bw1.gif" width="200" height="89" alt="CADVIZ"></td>
    <td width="274" rowspan="5" class="style3"><div align="center">OVERDUE STATEMENT</div></td>
    <td width="158"><p align="right" class="nrml">&nbsp;</p></td>
  </tr>
  <tr class="style3"><td><div align="right"><strong><span class="nrml">GST No: 82-090-630</span></strong></div></td></tr>
  <tr class="style3"><td><div align="right"><span class="nrml">CADViz Ltd </span></div></td></tr>
  <tr class="style3"><td><div align="right" class="nrml">PO Box 302387<br>North Harbour<br>Auckland 0751</div></td></tr>
  <tr class="style3"><td>&nbsp;</td></tr>
</table>

<table width="651" border="0">
  <tr>
    <td width="100">Client:</td>
    <td width="293">
      <a href="client_updateform.php?client_id=<?= (int)$clientHeader['CID'] ?>"><?= htmlspecialchars($clientHeader['Client_Name'] ?? '') ?></a>
    </td>
    <td width="71">&nbsp;</td>
    <td width="69">Date:&nbsp;</td>
    <td width="96"><div align="right"><?= $today ?></div></td>
  </tr>
  <tr>
    <td height="38" valign="top">Address:<br>Email:</td>
    <td colspan="4" valign="top">
      <?= htmlspecialchars($clientHeader['Address1'] ?? '') ?><br>
      <a href="mailto:<?= htmlspecialchars($billto) ?>"><?= htmlspecialchars($billto) ?></a>
    </td>
  </tr>
</table>

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
    <td width="647" valign="top">
      <table width="647" border="0">
<?php
if (empty($rows)) {
    echo '<tr><td colspan="6" align="center" style="padding:10px;color:#1a6b1a"><strong>No outstanding invoices for this client.</strong></td></tr>';
}

foreach ($rows as $row) {
    $invNo     = (int)$row['NUM'];
    $subtotal  = (float)($row['Subtotal'] ?? 0);
    $tax_rate  = (float)($row['Tax_Rate'] ?? 0);
    $localTotal = $subtotal + ($subtotal * $tax_rate);

    // Prefer Xero's balance when we have it (matches what client sees if they
    // log into the Xero portal). Fall back to local computed total.
    $xeroDue   = isset($row['XeroAmountDue']) && $row['XeroAmountDue'] !== null ? (float)$row['XeroAmountDue'] : null;
    $amountDue = $xeroDue !== null && $xeroDue > 0 ? $xeroDue : $localTotal;

    // Subtract any payments captured locally that Xero hasn't been told about.
    $paymentInfo = $paymentTotals[$invNo] ?? null;
    if ($paymentInfo && $xeroDue === null) {
        $amountDue -= (float)$paymentInfo['paid'];
        if ($amountDue < 0) $amountDue = 0;
    }

    $payopt   = (int)($row['PaymentOption'] ?? 0);
    $inv_date = $row['InvDate'] ?? null;
    $baseTs   = $inv_date ? strtotime($inv_date) : false;

    // Prefer Xero's due date if we have it; otherwise compute from PaymentOption.
    if (!empty($row['XeroDueDate'])) {
        $ddateTs = strtotime($row['XeroDueDate']);
    } elseif ($payopt === 1 && $baseTs) {
        $ddateTs = strtotime('+1 month', $baseTs);
    } elseif ($payopt === 2 && $baseTs) {
        $ddateTs = strtotime('+7 days', $baseTs);
    } elseif ($baseTs) {
        $ddateTs = $baseTs;
    } else {
        $ddateTs = false;
    }
    $ddate = $ddateTs ? date('d/m/Y', $ddateTs) : '';

    $diff = $ddateTs ? (int)floor((time() - $ddateTs) / 86400) : -999;
    if ($diff < 0) {
        $status_label = 'Current';
        $rowClass     = '';
    } elseif ($diff < 7) {
        $status_label = 'Due Now';
        $rowClass     = '';
    } else {
        $status_label = '<strong>' . $diff . 'd OVERDUE</strong>';
        $rowClass     = 'overdue';
    }

    $inv_date_fmt = $baseTs ? date('d/m/Y', $baseTs) : '';

    echo '<tr class="' . $rowClass . '">';
    echo '<td width="78">' . htmlspecialchars($inv_date_fmt) . '</td>';
    echo '<td width="50"><a href="invoice.php?Invoice_No=' . $invNo . '">CAD-' . str_pad((string)$invNo, 5, '0', STR_PAD_LEFT) . '</a></td>';
    echo '<td width="245">';
    if (!empty($row['Order_No'])) echo htmlspecialchars($row['Order_No']) . ' / ';
    echo htmlspecialchars($row['JobName'] ?? '') . '</td>';
    echo '<td width="60" align="Right">' . htmlspecialchars($ddate) . '</td>';
    echo '<td width="86" align="Right">' . $status_label . '</td>';
    echo '<td width="70" align="Right">$' . number_format($amountDue, 2) . '</td>';
    echo '</tr>';

    $grand += $amountDue;

    if ($paymentInfo && (float)$paymentInfo['paid'] > 0 && $xeroDue === null) {
        $dr_fmt = !empty($paymentInfo['last_date']) ? date('d/m/Y', strtotime($paymentInfo['last_date'])) : '';
        echo '<tr>';
        echo '<td width="78">' . htmlspecialchars($dr_fmt) . '</td>';
        echo '<td width="50">PYMNT</td>';
        echo '<td width="245">Partial payment(s) received for above.....</td>';
        echo '<td width="60" align="Right">.............</td>';
        echo '<td width="86" align="Right">.............</td>';
        echo '<td width="70" align="Right">-$' . number_format((float)$paymentInfo['paid'], 2) . '</td>';
        echo '</tr>';
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
    <td width="60" align="right"><strong>Total Due (local):</strong></td>
    <td width="81" align="right">
      <font size="+1"><strong>$<?= number_format($grand, 2) ?></strong></font>
    </td>
  </tr>
<?php if ($xeroOutstanding !== null): ?>
  <tr>
    <td>&nbsp;</td>
    <td align="right"><strong>Xero balance:</strong></td>
    <td align="right">
      <font size="+1"><strong>$<?= number_format($xeroOutstanding, 2) ?></strong></font>
    </td>
  </tr>
  <tr>
    <td colspan="3" align="right"><font size="1" color="#666">(authoritative outstanding balance from Xero, refreshed just now)</font></td>
  </tr>
<?php elseif ($xeroError): ?>
  <tr>
    <td colspan="3" align="right"><font size="1" color="#a00">Xero balance unavailable: <?= htmlspecialchars($xeroError) ?></font></td>
  </tr>
<?php endif; ?>
</table>

<table width="651" border="1">
  <tr>
    <td>
      <table width="651" border="0">
        <tr>
          <td width="27">&nbsp;</td>
          <td width="182"><strong>REMITTANCE ADVICE</strong></td>
          <td width="255"><font size="1">Please pay each invoice individually using the invoice number as the bank-transfer reference.</font></td>
          <td width="69"></td>
          <td width="96"></td>
        </tr>
        <tr><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>
        <tr>
          <td>&nbsp;</td>
          <td>Internet Bank Transfer:</td>
          <td><strong>03 0275 0551274 00</strong></td>
          <td><div align="right"><strong>Total Due:</strong></div></td>
          <td><div align="right"><font size="+1"><strong>$<?= number_format($xeroOutstanding ?? $grand, 2) ?></strong></font></div></td>
        </tr>
        <tr>
          <td>&nbsp;</td>
          <td colspan="4"><font color="#a00000"><strong>Important:</strong> please pay each outstanding invoice <em>individually</em> and quote that invoice's reference (e.g. <strong>CAD-0XXXXX</strong>, shown above next to each invoice number) on your bank transfer so we can match each payment to the correct invoice.</font></td>
        </tr>
        <tr>
          <td>&nbsp;</td>
          <td colspan="3">Please email any queries to:&nbsp;&nbsp;<a href="mailto:accounts@cadviz.co.nz">accounts@cadviz.co.nz</a></td>
          <td>&nbsp;</td>
        </tr>
      </table>
    </td>
  </tr>
</table>

<p class="no-print" style="margin-top:14px">
  <a href="invoice_list.php" class="btn-secondary">Back to invoice list</a>
  <?php if (!empty($rows)): ?>
    <form method="post" action="send_statement.php" style="display:inline;margin-left:8px" onsubmit="return confirm('Email this statement plus PDFs of every unpaid invoice to <?= htmlspecialchars($billto) ?>?');">
      <input type="hidden" name="client_id" value="<?= (int)$client_id ?>">
      <button type="submit" class="btn-primary">&#9993; Send Statement to Client</button>
    </form>
  <?php endif; ?>
</p>

</body>
</html>
